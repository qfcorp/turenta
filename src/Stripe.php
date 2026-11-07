<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Cobro con tarjeta por Stripe Checkout (página de pago alojada por Stripe; los datos de la tarjeta nunca pasan por TuRenta).
 *  1. crearCheckout(): crea un pago "pendiente" (método stripe) y una sesión de Checkout; el cliente va a la URL de Stripe.
 *  2. Stripe devuelve al cliente a /?r=stripe&session_id=cs_... -> confirmarSesionPorId() pregunta a Stripe si se pagó.
 *  3. Además Stripe avisa por webhook (/?r=stripe_webhook&s=<slug>) -> webhook(): firma verificada y se confirma igual.
 *     Confirmar dos veces no pasa nada (Pagos::confirmar no repite).
 * Cada negocio pone SUS claves (Configuración). Las claves sk_test_ / pk_test_ son de prueba (no se cobra de verdad).
 */
final class Stripe
{
    public const API = 'https://api.stripe.com';
    /** Tolerancia de la firma del webhook (segundos), igual que la librería oficial. */
    public const TOLERANCIA = 300;

    /** @var callable fn(string $metodo, string $url, array $campos, string $clave): ?array */
    private $http;

    public function __construct(private PDO $db, ?callable $http = null)
    {
        $this->http = $http ?? [self::class, 'llamar'];
    }

    public static function api(): string
    {
        return rtrim((string) (getenv('STRIPE_API_BASE') ?: self::API), '/');
    }

    private function negocio(int $negocioId): ?array
    {
        $st = $this->db->prepare('SELECT id, nombre, slug, stripe_secret_key, stripe_publishable_key, stripe_webhook_secret FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        return $st->fetch() ?: null;
    }

    public static function claveValida(string $clave): bool
    {
        return (bool) preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,190}$/', $clave);
    }

    /** ¿El negocio puede cobrar con Stripe? (clave secreta puesta y módulo "Cobros en línea" encendido) */
    public function listo(int $negocioId): bool
    {
        $n = $this->negocio($negocioId);
        return $n && self::claveValida(trim((string) $n['stripe_secret_key']))
            && (new Modulos($this->db))->activo($negocioId, 'cobros_linea');
    }

    /** ¿Las claves son de prueba? (se avisa en pantalla que no se cobra de verdad) */
    public function modoPrueba(int $negocioId): bool
    {
        $n = $this->negocio($negocioId);
        return $n && str_contains((string) $n['stripe_secret_key'], '_test_');
    }

    /**
     * Prepara el pago de $monto del evento y devuelve la URL de Stripe a la que se manda al cliente.
     * $exito y $cancelar son URL completas; en $exito se agrega session_id={CHECKOUT_SESSION_ID}.
     */
    public function crearCheckout(int $eventoId, float $monto, string $concepto, string $exito, string $cancelar, ?string $email = null): string
    {
        $st = $this->db->prepare('SELECT e.negocio_id, e.codigo, e.nombre, e.estado, e.fecha, n.nombre AS negocio, n.stripe_secret_key
                                    FROM eventos e JOIN negocios n ON n.id = e.negocio_id WHERE e.id = ?');
        $st->execute([$eventoId]);
        $ev = $st->fetch();
        if (!$ev) throw new RuntimeException('Booking not found.');
        if (in_array($ev['estado'], ['perdido', 'cancelado'], true)) throw new RuntimeException('This booking is no longer active.');
        if (!$this->listo((int) $ev['negocio_id'])) throw new RuntimeException('Card payments are not turned on for this business yet.');
        $monto = round($monto, 2);
        $saldo = (new Pagos($this->db))->resumen($eventoId)['saldo'];
        if ($monto < 0.5 || $monto > $saldo + 0.009) {
            throw new RuntimeException('The amount must be between $0.50 and $' . number_format($saldo, 2) . '.');
        }
        $centavos = (int) round($monto * 100);
        // Un solo intento de pago abierto por evento (si el anterior igual se paga, aplicarSesion lo recupera)
        $this->db->prepare("UPDATE pagos SET estado = 'anulado', notas = 'Stripe: reemplazado por un intento nuevo'
                             WHERE evento_id = ? AND metodo = 'stripe' AND estado = 'pendiente'")->execute([$eventoId]);
        $st = $this->db->prepare("INSERT INTO pagos (negocio_id, evento_id, tipo, monto, metodo, estado, origen, notas)
                                  VALUES (?,?, 'evento', ?, 'stripe', 'pendiente', 'stripe', ?) RETURNING id");
        $st->execute([$ev['negocio_id'], $eventoId, $monto, mb_substr('Stripe: ' . $concepto, 0, 300)]);
        $pagoId = (int) $st->fetchColumn();
        $campos = [
            'mode' => 'payment',
            'client_reference_id' => (string) $pagoId,
            'success_url' => $exito . (str_contains($exito, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelar,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => 'usd',
            'line_items[0][price_data][unit_amount]' => (string) $centavos,
            'line_items[0][price_data][product_data][name]' => mb_substr($concepto . ' · ' . $ev['nombre'], 0, 250),
            'line_items[0][price_data][product_data][description]' => mb_substr($ev['negocio'] . ' · ' . $ev['codigo'] . ' · ' . $ev['fecha'], 0, 250),
            'metadata[pago_id]' => (string) $pagoId,
            'metadata[evento]' => (string) $ev['codigo'],
            'payment_intent_data[description]' => mb_substr($ev['negocio'] . ' ' . $ev['codigo'] . ' — ' . $concepto, 0, 250),
            'payment_intent_data[metadata][pago_id]' => (string) $pagoId,
            'expires_at' => (string) (time() + 3600),
        ];
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) $campos['customer_email'] = $email;
        $r = ($this->http)('POST', self::api() . '/v1/checkout/sessions', $campos, (string) $ev['stripe_secret_key']);
        if (!$r || empty($r['id']) || empty($r['url'])) {
            $this->db->prepare("UPDATE pagos SET estado = 'anulado', notas = 'Stripe no respondió' WHERE id = ?")->execute([$pagoId]);
            $msg = (string) ($r['error']['message'] ?? '');
            throw new RuntimeException('We could not open the card payment page. Please try again in a moment.' . ($msg !== '' ? " ($msg)" : ''));
        }
        $this->db->prepare('UPDATE pagos SET referencia = ? WHERE id = ?')->execute([mb_substr((string) $r['id'], 0, 120), $pagoId]);
        return (string) $r['url'];
    }

    /**
     * El cliente volvió de Stripe: se pregunta a Stripe por la sesión. Devuelve [estado, token_cliente].
     * estado: pagado | pendiente | no_pagado
     */
    public function confirmarSesionPorId(string $sesionId): array
    {
        $p = $this->pagoDeSesion($sesionId);
        if ($p['estado'] === 'confirmado') return ['pagado', $p['token_cliente']];
        $s = ($this->http)('GET', self::api() . '/v1/checkout/sessions/' . rawurlencode($sesionId), [], (string) $p['stripe_secret_key']);
        if (!$s) return ['pendiente', $p['token_cliente']];
        return [$this->aplicarSesion($p, $s), $p['token_cliente']];
    }

    /**
     * Webhook de Stripe para el negocio $slug. Verifica la firma (Stripe-Signature) con el secreto whsec_ del negocio.
     * Devuelve un texto corto para el registro. Lanza RuntimeException si la firma no es válida.
     */
    public function webhook(string $slug, string $cuerpo, string $firma, ?int $ahora = null): string
    {
        $st = $this->db->prepare('SELECT id, stripe_webhook_secret FROM negocios WHERE slug = ?');
        $st->execute([$slug]);
        $n = $st->fetch();
        $secreto = trim((string) ($n['stripe_webhook_secret'] ?? ''));
        if (!$n || $secreto === '') throw new RuntimeException('Webhook not set up.');
        if (!self::firmaValida($cuerpo, $firma, $secreto, $ahora ?? time())) throw new RuntimeException('Bad signature.');
        $evento = json_decode($cuerpo, true);
        $tipo = (string) ($evento['type'] ?? '');
        $s = $evento['data']['object'] ?? null;
        if (!is_array($s) || !str_starts_with($tipo, 'checkout.session.')) return 'ignorado';
        $sesionId = (string) ($s['id'] ?? '');
        try {
            $p = $this->pagoDeSesion($sesionId);
        } catch (RuntimeException) {
            return 'sesion_desconocida';
        }
        if ((int) $p['negocio_id'] !== (int) $n['id']) return 'otro_negocio';
        if ($tipo === 'checkout.session.expired') {
            $this->db->prepare("UPDATE pagos SET estado = 'anulado', notas = 'Stripe: el cliente no terminó de pagar' WHERE id = ? AND estado = 'pendiente'")
                     ->execute([$p['id']]);
            return 'vencido';
        }
        if (in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return $this->aplicarSesion($p, $s);
        }
        return 'ignorado';
    }

    public static function firmaValida(string $cuerpo, string $cabecera, string $secreto, int $ahora): bool
    {
        $t = null;
        $firmas = [];
        foreach (explode(',', $cabecera) as $parte) {
            [$k, $v] = array_pad(explode('=', trim($parte), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) $t = (int) $v;
            if ($k === 'v1' && $v !== '') $firmas[] = $v;
        }
        if ($t === null || !$firmas || abs($ahora - $t) > self::TOLERANCIA) return false;
        $esperada = hash_hmac('sha256', $t . '.' . $cuerpo, $secreto);
        foreach ($firmas as $f) {
            if (hash_equals($esperada, $f)) return true;
        }
        return false;
    }

    private function pagoDeSesion(string $sesionId): array
    {
        if (!preg_match('/^cs_(test|live)_[A-Za-z0-9]{10,200}$/', $sesionId)) throw new RuntimeException('Payment not recognized.');
        $st = $this->db->prepare("SELECT p.*, e.token_cliente, e.codigo, n.stripe_secret_key FROM pagos p JOIN eventos e ON e.id = p.evento_id
                                    JOIN negocios n ON n.id = p.negocio_id WHERE p.metodo = 'stripe' AND p.referencia = ?");
        $st->execute([$sesionId]);
        $p = $st->fetch();
        if (!$p) throw new RuntimeException('Payment not recognized.');
        return $p;
    }

    /** Revisa la sesión de Stripe contra el pago guardado y lo confirma si está pagada por el valor exacto. */
    private function aplicarSesion(array $p, array $s): string
    {
        if ($p['estado'] === 'confirmado') return 'pagado';
        $centavos = (int) round((float) $p['monto'] * 100);
        $coincide = ($s['id'] ?? '') === $p['referencia']
            && (string) ($s['client_reference_id'] ?? '') === (string) $p['id']
            && (int) ($s['amount_total'] ?? -1) === $centavos
            && strtolower((string) ($s['currency'] ?? '')) === 'usd';
        if (!$coincide) {
            error_log('TuRenta Stripe: la sesión ' . ($s['id'] ?? '?') . ' no coincide con el pago ' . $p['id']);
            return 'no_pagado';
        }
        if (($s['payment_status'] ?? '') !== 'paid') {
            return ($s['status'] ?? '') === 'expired' ? 'no_pagado' : 'pendiente';
        }
        if (!in_array($p['estado'], ['pendiente', 'anulado'], true)) return 'no_pagado';
        if ($p['estado'] === 'anulado') {   // pagó justo después de vencer: el dinero entró, se recupera el pago
            $this->db->prepare("UPDATE pagos SET estado = 'pendiente' WHERE id = ? AND estado = 'anulado'")->execute([$p['id']]);
        }
        $intento = is_string($s['payment_intent'] ?? null) ? $s['payment_intent'] : (string) ($s['payment_intent']['id'] ?? '');
        $this->db->prepare('UPDATE pagos SET notas = ? WHERE id = ?')
                 ->execute([mb_substr(trim((string) $p['notas'] . ' · ' . ($intento !== '' ? $intento : 'pagado')), 0, 300), $p['id']]);
        (new Reservas($this->db))->asegurarEquipo((int) $p['evento_id']);
        $pagos = new Pagos($this->db);
        $mensaje = $pagos->confirmar((int) $p['negocio_id'], (int) $p['id'], ['id' => null, 'nombre' => 'Stripe']);
        (new Notificaciones($this->db))->alNegocio((int) $p['negocio_id'],
            'Pago con tarjeta (Stripe) de ' . $p['codigo'] . ': ' . number_format((float) $p['monto'], 2, ',', '.') . ' dólares.'
            . ($mensaje ? ' ' . $mensaje : ''), (int) $p['evento_id']);
        return 'pagado';
    }

    /** Llamada real a Stripe (formulario codificado, autenticación con la clave secreta). */
    public static function llamar(string $metodo, string $url, array $campos, string $clave): ?array
    {
        $ch = curl_init($metodo === 'GET' && $campos ? $url . '?' . http_build_query($campos) : $url);
        $opciones = [
            CURLOPT_USERPWD => $clave . ':',
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
        ];
        if ($metodo === 'POST') {
            $opciones[CURLOPT_POST] = true;
            $opciones[CURLOPT_POSTFIELDS] = http_build_query($campos);
        }
        curl_setopt_array($ch, $opciones);
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $datos = $r === false ? null : json_decode((string) $r, true);
        if ($r === false || $codigo >= 400) {
            error_log("TuRenta Stripe: $metodo " . parse_url($url, PHP_URL_PATH) . " falló (HTTP $codigo) " . ($datos['error']['message'] ?? ''));
            return is_array($datos) && isset($datos['error']) ? ['error' => $datos['error']] : null;
        }
        return is_array($datos) ? $datos : null;
    }
}
