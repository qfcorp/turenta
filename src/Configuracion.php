<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Configuración del negocio (solo el dueño): datos y página pública, reglas comerciales,
 * cobros en línea, plantilla de tareas, enlace del calendario de Google y WhatsApp de transmisiones.
 * Cada método valida y devuelve mensajes claros.
 */
final class Configuracion
{
    public function __construct(private PDO $db) {}

    public function negocio(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT * FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $n = $st->fetch();
        if (!$n) throw new RuntimeException('Negocio no encontrado.');
        return $n;
    }

    private static function texto(array $d, string $k, int $max): ?string
    {
        $v = trim((string) ($d[$k] ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private static function enlace(?string $v, string $que): ?string
    {
        if ($v === null) return null;
        if (!preg_match('#^https?://#i', $v)) $v = 'https://' . $v;
        if (!filter_var($v, FILTER_VALIDATE_URL)) throw new RuntimeException("El enlace de $que no es válido. Cópialo y pégalo completo.");
        return $v;
    }

    private static function telefono(?string $v, string $que): ?string
    {
        if ($v === null) return null;
        if (WhatsApp::normalizarTelefono($v) === null) throw new RuntimeException("El $que no es válido. Escríbelo así: 0991234567.");
        return $v;
    }

    /** Datos del negocio y de la página pública. */
    public function guardarDatos(int $negocioId, array $d): void
    {
        $nombre = self::texto($d, 'nombre', 120);
        if ($nombre === null || mb_strlen($nombre) < 3) throw new RuntimeException('Escribe el nombre de tu salón, quinta o hacienda.');
        $ruc = preg_replace('/\D+/', '', (string) ($d['ruc'] ?? ''));
        if ($ruc !== '' && strlen($ruc) !== 13) throw new RuntimeException('El RUC debe tener 13 dígitos (termina en 001).');
        $email = strtolower((string) self::texto($d, 'email', 120));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo del negocio no es válido.');
        $color = trim((string) ($d['color'] ?? '#6B2D3C'));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) throw new RuntimeException('Elige un color de la lista.');
        $instagram = self::texto($d, 'instagram', 120);
        if ($instagram !== null) {
            // Acepta @usuario, usuario o el enlace completo
            if (preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $instagram, $m)) $instagram = $m[1];
            $instagram = ltrim($instagram, '@');
            if (!preg_match('/^[A-Za-z0-9._]{1,30}$/', $instagram)) throw new RuntimeException('Escribe tu usuario de Instagram, por ejemplo @quintalosarrayanes.');
        }
        $ciudad = self::texto($d, 'ciudad', 80) ?? 'Quito';
        $this->db->prepare('UPDATE negocios SET nombre = ?, razon_social = ?, ruc = ?, telefono = ?, whatsapp = ?, email = ?, direccion = ?,
                                   ciudad = ?, mapa_url = ?, instagram = ?, facebook = ?, descripcion = ?, color = ? WHERE id = ?')
                 ->execute([$nombre, self::texto($d, 'razon_social', 150), $ruc ?: null,
                            self::telefono(self::texto($d, 'telefono', 20), 'teléfono'),
                            self::telefono(self::texto($d, 'whatsapp', 20), 'WhatsApp'),
                            $email ?: null, self::texto($d, 'direccion', 250), $ciudad,
                            self::enlace(self::texto($d, 'mapa_url', 400), 'Google Maps'), $instagram,
                            self::enlace(self::texto($d, 'facebook', 200), 'Facebook'),
                            self::texto($d, 'descripcion', 3000), strtoupper($color), $negocioId]);
        if (array_key_exists('resenas_url', $d)) {
            $this->db->prepare('UPDATE negocios SET resenas_url = ? WHERE id = ?')
                     ->execute([self::enlace(self::texto($d, 'resenas_url', 300), 'reseñas de Google'), $negocioId]);
        }
    }

    /** Sube el logo (reemplaza el anterior). */
    public function guardarLogo(int $negocioId, array $archivo): string
    {
        $nuevo = Fotos::guardarImagen($archivo, Fotos::carpeta('logos'), 600);
        $anterior = $this->negocio($negocioId)['logo'];
        $this->db->prepare('UPDATE negocios SET logo = ? WHERE id = ?')->execute([$nuevo, $negocioId]);
        if ($anterior) Fotos::borrar('logos', $anterior);
        return $nuevo;
    }

    public function quitarLogo(int $negocioId): void
    {
        $anterior = $this->negocio($negocioId)['logo'];
        $this->db->prepare('UPDATE negocios SET logo = NULL WHERE id = ?')->execute([$negocioId]);
        if ($anterior) Fotos::borrar('logos', $anterior);
    }

    /** Número dentro de un rango; acepta coma o punto. */
    private static function num(array $d, string $k, float $min, float $max, string $que): float
    {
        $v = trim((string) ($d[$k] ?? ''));
        if ($v === '' || !is_numeric(str_replace(',', '.', $v))) throw new RuntimeException("Escribe $que.");
        $n = (float) str_replace(',', '.', $v);
        if ($n < $min || $n > $max) {
            $f = fn($x) => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',');
            throw new RuntimeException(ucfirst($que) . ' debe estar entre ' . $f($min) . ' y ' . $f($max) . '.');
        }
        return $n;
    }

    /** Reglas comerciales: IVA, validez, retención, reserva, cuotas, garantía, horarios, comisión, condiciones. */
    public function guardarReglas(int $negocioId, array $d): void
    {
        $hora = trim((string) ($d['hora_limite'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', substr($hora, 0, 5))) throw new RuntimeException('Escribe la hora límite, por ejemplo 02:00.');
        $this->db->prepare('UPDATE negocios SET iva_pct = ?, validez_cotizacion = ?, dias_retencion = ?, reserva_pct = ?, cuotas_defecto = ?,
                                   dias_saldo_antes = ?, garantia_defecto = ?, horas_montaje = ?, horas_desmontaje = ?, hora_limite = ?,
                                   invitados_por_mesero = ?, meses_revisar_precio = ?, comision_vendedor_pct = ?, condiciones = ?
                             WHERE id = ?')
                 ->execute([
                     self::num($d, 'iva_pct', 0, 30, 'el IVA (%)'),
                     (int) self::num($d, 'validez_cotizacion', 1, 120, 'los días que vale una cotización'),
                     (int) self::num($d, 'dias_retencion', 0, 60, 'los días que se retiene una fecha cotizada'),
                     self::num($d, 'reserva_pct', 1, 100, 'el % de la reserva'),
                     (int) self::num($d, 'cuotas_defecto', 1, 24, 'el número de pagos'),
                     (int) self::num($d, 'dias_saldo_antes', 0, 120, 'los días antes del evento para el saldo'),
                     round(self::num($d, 'garantia_defecto', 0, 100000, 'la garantía por daños'), 2),
                     self::num($d, 'horas_montaje', 0, 24, 'las horas de montaje'),
                     self::num($d, 'horas_desmontaje', 0, 24, 'las horas de desmontaje'),
                     substr($hora, 0, 5),
                     (int) self::num($d, 'invitados_por_mesero', 1, 200, 'los invitados por mesero'),
                     (int) self::num($d, 'meses_revisar_precio', 1, 36, 'los meses para revisar el precio'),
                     self::num($d, 'comision_vendedor_pct', 0, 50, 'la comisión del vendedor (%)'),
                     self::texto($d, 'condiciones', 5000),
                     $negocioId,
                 ]);
    }

    /**
     * Cobros en línea: datos de transferencia, DeUna y Payphone.
     * Si el campo del token de Payphone viene vacío y ya había uno, se conserva (no se muestra en pantalla).
     */
    public function guardarCobros(int $negocioId, array $d): void
    {
        $deuna = self::texto($d, 'pago_deuna', 20);
        if ($deuna !== null && WhatsApp::normalizarTelefono($deuna) === null) throw new RuntimeException('El celular de DeUna no es válido. Escríbelo así: 0991234567.');
        $token = trim((string) ($d['payphone_token'] ?? ''));
        $store = trim((string) ($d['payphone_store_id'] ?? ''));
        $quitar = !empty($d['quitar_payphone']);
        if ($quitar) {
            $token = '';
            $store = '';
        } else {
            if ($token === '') $token = (string) ($this->negocio($negocioId)['payphone_token'] ?? '');
            if (($token === '') !== ($store === '')) {
                throw new RuntimeException('Para Payphone necesitas las dos claves: el "Token" y el "Store ID". Las encuentras en Payphone Developer → Configuración.');
            }
            if ($token !== '' && (strlen($token) < 20 || preg_match('/\s/', $token))) throw new RuntimeException('El token de Payphone parece incompleto. Cópialo otra vez completo, sin espacios.');
            if ($store !== '' && !preg_match('/^[A-Za-z0-9-]{4,80}$/', $store)) throw new RuntimeException('El Store ID de Payphone no es válido. Cópialo otra vez.');
        }
        $this->db->prepare('UPDATE negocios SET pago_banco = ?, pago_deuna = ?, payphone_token = ?, payphone_store_id = ? WHERE id = ?')
                 ->execute([self::texto($d, 'pago_banco', 500), $deuna, $token ?: null, $store ?: null, $negocioId]);
    }

    /**
     * TuRenta (migración 200): reserva en línea y claves de Stripe.
     * Las claves secretas no se muestran: si el campo viene vacío y ya había una, se conserva. "quitar_stripe" las borra.
     */
    public function guardarReserva(int $negocioId, array $d): void
    {
        $n = $this->negocio($negocioId);
        $idioma = (string) ($d['idioma_publico'] ?? 'es') === 'en' ? 'en' : 'es';
        $hora = function (string $k, string $def) use ($d): string {
            $h = substr(trim((string) ($d[$k] ?? '')), 0, 5);
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) ? $h : $def;
        };
        $desde = $hora('reserva_hora_desde', '09:00');
        $hasta = $hora('reserva_hora_hasta', '19:00');
        if ($hasta < $desde) throw new RuntimeException('La última hora de inicio debe ser después de la primera.');
        $paso = (int) ($d['reserva_paso_min'] ?? 30);
        if ($paso < 10 || $paso > 240) throw new RuntimeException('Cada cuántos minutos se ofrece una hora: entre 10 y 240.');
        $diasMin = (int) ($d['reserva_dias_min'] ?? 2);
        if ($diasMin < 0 || $diasMin > 60) throw new RuntimeException('El aviso mínimo debe ser entre 0 y 60 días.');
        $url = function (string $k) use ($d): ?string {
            $v = trim((string) ($d[$k] ?? ''));
            if ($v === '') return null;
            if (!preg_match('#^https://[^\s"<>]{4,390}$#', $v)) throw new RuntimeException('Las direcciones web deben empezar con https://');
            return $v;
        };
        $sk = trim((string) ($d['stripe_secret_key'] ?? ''));
        $pk = trim((string) ($d['stripe_publishable_key'] ?? ''));
        $wh = trim((string) ($d['stripe_webhook_secret'] ?? ''));
        if (!empty($d['quitar_stripe'])) {
            $sk = $pk = $wh = '';
        } else {
            // Se revisa solo lo que se escribió ahora; lo vacío conserva lo guardado
            if ($sk !== '' && !Stripe::claveValida($sk)) {
                throw new RuntimeException('La clave secreta de Stripe empieza con sk_test_ (prueba) o sk_live_ (real). Cópiala otra vez completa.');
            }
            if ($pk !== '' && !preg_match('/^pk_(test|live)_[A-Za-z0-9]{10,190}$/', $pk)) throw new RuntimeException('La clave publicable de Stripe empieza con pk_test_ o pk_live_.');
            if ($wh !== '' && !preg_match('/^whsec_[A-Za-z0-9]{10,190}$/', $wh)) throw new RuntimeException('El secreto del webhook de Stripe empieza con whsec_.');
            if ($sk === '') $sk = (string) ($n['stripe_secret_key'] ?? '');
            if ($wh === '') $wh = (string) ($n['stripe_webhook_secret'] ?? '');
            if ($pk === '') $pk = (string) ($n['stripe_publishable_key'] ?? '');
            if ($sk !== '' && $pk !== '' && (str_contains($sk, '_test_') !== str_contains($pk, '_test_'))) {
                throw new RuntimeException('Las dos claves de Stripe deben ser del mismo modo: las dos de prueba (test) o las dos reales (live).');
            }
        }
        $this->db->prepare('UPDATE negocios SET reserva_en_linea = ?, idioma_publico = ?, reserva_hora_desde = ?, reserva_hora_hasta = ?,
                                   reserva_paso_min = ?, reserva_dias_min = ?, reserva_condiciones = ?, sitio_externo = ?, logo_url = ?,
                                   stripe_secret_key = ?, stripe_publishable_key = ?, stripe_webhook_secret = ? WHERE id = ?')
                 ->execute([!empty($d['reserva_en_linea']) ? 'true' : 'false', $idioma, $desde, $hasta, $paso, $diasMin,
                            self::texto($d, 'reserva_condiciones', 5000), $url('sitio_externo'), $url('logo_url'),
                            $sk ?: null, $pk ?: null, $wh ?: null, $negocioId]);
    }

    public function guardarTransmision(int $negocioId, string $whatsapp): void
    {
        $n = WhatsApp::normalizarTelefono($whatsapp);
        if ($n === null) throw new RuntimeException('El WhatsApp de transmisiones no es válido. Escríbelo así: 0991234567.');
        $this->db->prepare('UPDATE negocios SET whatsapp_transmision = ? WHERE id = ?')->execute([$n, $negocioId]);
    }

    /** Nuevo enlace secreto del calendario: el anterior deja de funcionar. */
    public function cambiarTokenIcal(int $negocioId): string
    {
        $t = bin2hex(random_bytes(16));
        $this->db->prepare('UPDATE negocios SET token_ical = ? WHERE id = ?')->execute([$t, $negocioId]);
        return $t;
    }

    public function marcarPreciosRevisados(int $negocioId): void
    {
        $this->db->prepare('UPDATE negocios SET precios_revisados = true WHERE id = ?')->execute([$negocioId]);
    }

    public function ocultarPasos(int $negocioId, bool $ocultar = true): void
    {
        $this->db->prepare('UPDATE negocios SET pasos_ocultos = ? WHERE id = ?')->execute([$ocultar ? 'true' : 'false', $negocioId]);
    }

    // ------------------------------------------------------------------
    // Plantilla de tareas (se copian a cada evento nuevo)
    // ------------------------------------------------------------------

    public function tareas(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT * FROM plantilla_tareas WHERE negocio_id = ? ORDER BY dias_antes DESC, orden, id');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function guardarTarea(int $negocioId, array $d, ?int $id = null): int
    {
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if (mb_strlen($titulo) < 3) throw new RuntimeException('Escribe la tarea, por ejemplo "Degustación del menú".');
        $dias = trim((string) ($d['dias_antes'] ?? ''));
        if (!preg_match('/^-?\d{1,3}$/', $dias)) throw new RuntimeException('Escribe cuántos días antes del evento (0 = el mismo día; negativo = después).');
        $dias = (int) $dias;
        if ($dias < -60 || $dias > 400) throw new RuntimeException('Los días deben estar entre -60 (después del evento) y 400 (antes).');
        $tipo = (string) ($d['tipo_evento'] ?? '');
        if ($tipo !== '' && !isset(Eventos::TIPOS[$tipo])) throw new RuntimeException('Elige el tipo de evento.');
        if ($id) {
            $st = $this->db->prepare('UPDATE plantilla_tareas SET titulo = ?, dias_antes = ?, tipo_evento = ? WHERE id = ? AND negocio_id = ?');
            $st->execute([mb_substr($titulo, 0, 150), $dias, $tipo ?: null, $id, $negocioId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró esa tarea.');
            return $id;
        }
        $st = $this->db->prepare('INSERT INTO plantilla_tareas (negocio_id, tipo_evento, titulo, dias_antes, orden)
                                  VALUES (?,?,?,?, (SELECT COALESCE(max(orden),0)+1 FROM plantilla_tareas WHERE negocio_id = ?)) RETURNING id');
        $st->execute([$negocioId, $tipo ?: null, mb_substr($titulo, 0, 150), $dias, $negocioId]);
        return (int) $st->fetchColumn();
    }

    public function borrarTarea(int $negocioId, int $id): void
    {
        $st = $this->db->prepare('DELETE FROM plantilla_tareas WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró esa tarea.');
    }

    // ------------------------------------------------------------------
    // Primeros pasos de una cuenta nueva (se marcan solos)
    // ------------------------------------------------------------------

    /** Igual que url() del sistema (aquí no se carga bootstrap en las pruebas). */
    private static function ruta(string $r, array $p = []): string
    {
        return '/?' . http_build_query(array_merge(['r' => $r], $p));
    }

    /** @return list<array{clave:string, texto:string, ayuda:string, enlace:string, hecho:bool}> */
    public function primerosPasos(int $negocioId, int $usuarioId): array
    {
        $n = $this->negocio($negocioId);
        $q = fn(string $sql) => (function () use ($sql, $negocioId) {
            $st = $this->db->prepare($sql);
            $st->execute([$negocioId]);
            return (int) $st->fetchColumn();
        })();
        $eventos = $q('SELECT count(*) FROM eventos WHERE negocio_id = ?');
        $espaciosCambiados = $q("SELECT count(*) FROM espacios WHERE negocio_id = ? AND (foto IS NOT NULL OR nombre NOT IN ('Salón principal','Jardín'))");
        $st = $this->db->prepare('SELECT count(*) FROM usuarios WHERE id = ? AND telegram_chat_id IS NOT NULL');
        $st->execute([$usuarioId]);
        $telegram = (int) $st->fetchColumn() > 0;
        $pasos = [
            ['clave' => 'datos', 'texto' => 'Completa los datos de tu negocio', 'ayuda' => 'Dirección, RUC y WhatsApp: salen en las cotizaciones y contratos.',
             'enlace' => self::ruta('configuracion', ['tab' => 'datos']), 'hecho' => !empty($n['direccion']) && !empty($n['ruc']) && !empty($n['whatsapp'])],
            ['clave' => 'logo', 'texto' => 'Sube tu logo', 'ayuda' => 'Aparece en la cotización, el contrato y tu página pública.',
             'enlace' => self::ruta('configuracion', ['tab' => 'datos']) . '#logo', 'hecho' => !empty($n['logo'])],
            ['clave' => 'precios', 'texto' => 'Revisa tus espacios y precios', 'ayuda' => 'Cargamos ejemplos: cambia los nombres, fotos y precios por los tuyos.',
             'enlace' => self::ruta('catalogo'), 'hecho' => (bool) $n['precios_revisados'] || $espaciosCambiados > 0],
            ['clave' => 'evento', 'texto' => 'Crea tu primer evento', 'ayuda' => 'Anota una consulta real y cotízala en 2 minutos.',
             'enlace' => self::ruta('evento_nuevo'), 'hecho' => $eventos > 0],
            ['clave' => 'telegram', 'texto' => 'Conecta tu Telegram', 'ayuda' => 'Recibe al instante los pagos, firmas y consultas nuevas.',
             'enlace' => self::ruta('mi_cuenta') . '#telegram', 'hecho' => $telegram],
        ];
        // Anexo B: sin el módulo de Telegram, ese paso no aplica
        if (!(new Modulos($this->db))->activo($negocioId, 'telegram')) $pasos = array_values(array_filter($pasos, fn($p) => $p['clave'] !== 'telegram'));
        return $pasos;
    }
}
