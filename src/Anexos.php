<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Anexos de contrato (migración 090).
 *  - Se preparan SOLOS al aprobar extras del portal (Extras::aprobar) si el evento ya tiene un contrato FIRMADO.
 *    Si no hay contrato firmado, no hace falta anexo: los extras ya están en la cotización aceptada y entran
 *    en el contrato cuando se genere.
 *  - El texto sale de la plantilla de tipo "anexo" del negocio (plantillas_contrato.tipo = 'anexo', una por negocio,
 *    se crea con el texto de fábrica) con el mismo motor de {{variables}} que el contrato. Lleva exactamente los
 *    montos de la aprobación: líneas del pedido, lo que subió el evento (= la cuota "Extras") y el nuevo total.
 *  - Queda listo para firmar ("enviado"): el texto no cambia y se guarda su huella SHA-256. El cliente firma con el
 *    dedo desde /?r=firmar_anexo&t=<token>; el PDF lleva el QR de verificación (/?r=verificar&c=<código>).
 *  - Numeración propia: "Anexo 1 al contrato N.º 3". Tabla aparte (contrato_anexos) para no tocar el contrato vigente.
 *  - Si el contrato del anexo se anula, el anexo pendiente ya no se puede firmar ni se recuerda (el contrato nuevo
 *    sale con los extras, porque los toma de la cotización aceptada).
 */
final class Anexos
{
    public const ESTADOS = ['enviado' => 'Por firmar', 'firmado' => 'Firmado', 'anulado' => 'Anulado'];

    public const VARIABLES = [
        'negocio' => 'Nombre comercial del local', 'razon_social' => 'Razón social', 'ruc' => 'RUC del local', 'ciudad' => 'Ciudad',
        'cliente' => 'Nombre del cliente', 'identificacion' => 'Cédula o RUC del cliente', 'evento' => 'Nombre del evento',
        'fecha' => 'Fecha del evento (larga)', 'codigo' => 'Código del evento',
        'numero_anexo' => 'Número del anexo', 'numero_contrato' => 'Número del contrato original',
        'fecha_contrato' => 'Fecha en que se firmó el contrato original', 'fecha_anexo' => 'Fecha de hoy',
        'extras' => 'Lista de extras (descripción, cantidad, precio y subtotal)', 'subtotal_extras' => 'Extras sin IVA ($)',
        'iva_extras' => 'IVA de los extras ($)', 'total_extras' => 'Total de los extras con IVA ($)', 'total_extras_letras' => 'Total de los extras en letras',
        'total_anterior' => 'Valor del evento antes del anexo ($)', 'nuevo_total' => 'Nuevo valor total del evento ($)',
        'nuevo_total_letras' => 'Nuevo valor total en letras', 'cuota_extras' => 'Nombre de la cuota nueva de extras',
        'cuota_monto' => 'Valor de la cuota de extras ($)', 'cuota_fecha' => 'Fecha de pago de la cuota de extras',
        'plan_pagos' => 'Plan de pagos completo (con la cuota nueva)',
    ];

    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    private static function plata(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    private static function fechaLarga(string $f): string
    {
        $d = new DateTimeImmutable($f);
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . $meses[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }

    // ------------------------------------------------------------------
    // Plantilla (una por negocio)
    // ------------------------------------------------------------------

    public static function plantillaDefecto(): string
    {
        return <<<TXT
ANEXO {{numero_anexo}} AL CONTRATO N.º {{numero_contrato}}
EXTRAS PARA EL EVENTO "{{evento}}"

En la ciudad de {{ciudad}}, a {{fecha_anexo}}, comparecen {{razon_social}}, con RUC {{ruc}}, propietaria del local "{{negocio}}", a quien en adelante se denominará "EL LOCAL"; y {{cliente}}, con cédula/RUC {{identificacion}}, a quien en adelante se denominará "EL CLIENTE". Ambas partes acuerdan este anexo al contrato N.º {{numero_contrato}}, firmado el {{fecha_contrato}}, para el evento "{{evento}}" del {{fecha}} (código {{codigo}}).

PRIMERA — EXTRAS QUE SE AGREGAN. A pedido de EL CLIENTE, EL LOCAL agrega al evento los siguientes servicios:
{{extras}}

SEGUNDA — VALOR. Los extras suman {{subtotal_extras}} más {{iva_extras}} de IVA, es decir {{total_extras}} ({{total_extras_letras}}). Con este anexo, el valor total del evento pasa de {{total_anterior}} a {{nuevo_total}} ({{nuevo_total_letras}}), impuestos incluidos.

TERCERA — FORMA DE PAGO. El valor de los extras se paga en la cuota "{{cuota_extras}}" por {{cuota_monto}}, hasta el {{cuota_fecha}}. El plan de pagos completo queda así:
{{plan_pagos}}

CUARTA — LO DEMÁS NO CAMBIA. Todas las demás cláusulas, condiciones y obligaciones del contrato N.º {{numero_contrato}} se mantienen igual y siguen vigentes. Este anexo forma parte de ese contrato.

Las partes aceptan el contenido de este anexo y lo firman electrónicamente.
Código del evento: {{codigo}}
TXT;
    }

    /** Plantilla de anexo del negocio (la crea con el texto de fábrica si todavía no existe). */
    public function plantilla(int $negocioId): array
    {
        $this->q("INSERT INTO plantillas_contrato (negocio_id, nombre, texto, predeterminada, tipo) VALUES (?, 'Anexo por extras', ?, false, 'anexo')
                  ON CONFLICT (negocio_id) WHERE tipo = 'anexo' DO NOTHING", [$negocioId, self::plantillaDefecto()]);
        return $this->q("SELECT * FROM plantillas_contrato WHERE negocio_id = ? AND tipo = 'anexo'", [$negocioId])->fetch();
    }

    public function guardarPlantilla(int $negocioId, string $texto): void
    {
        $texto = trim(str_replace("\r\n", "\n", $texto));
        if (mb_strlen($texto) < 50) throw new RuntimeException('El texto del anexo es muy corto.');
        if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $texto, $m)) {
            $malas = array_diff(array_unique($m[1]), array_keys(self::VARIABLES));
            if ($malas) throw new RuntimeException('Estas variables no existen en el anexo: {{' . implode('}}, {{', $malas) . '}}.');
        }
        $this->plantilla($negocioId);
        $this->q("UPDATE plantillas_contrato SET texto = ? WHERE negocio_id = ? AND tipo = 'anexo'", [$texto, $negocioId]);
    }

    public function restaurarPlantilla(int $negocioId): void
    {
        $this->guardarPlantilla($negocioId, self::plantillaDefecto());
    }

    // ------------------------------------------------------------------
    // Crear el anexo al aprobar extras
    // ------------------------------------------------------------------

    /** Último contrato firmado del evento (o null). */
    public function contratoFirmado(int $negocioId, int $eventoId): ?array
    {
        return $this->q("SELECT * FROM contratos WHERE negocio_id = ? AND evento_id = ? AND estado = 'firmado' ORDER BY numero DESC LIMIT 1",
                        [$negocioId, $eventoId])->fetch() ?: null;
    }

    /**
     * Valores de las variables para un pedido aprobado. $p = fila de extras_pedidos ya aprobada (cotizacion_id, cuota_id, monto_agregado).
     */
    public function valores(int $negocioId, array $p, array $items, array $contrato, int $numeroAnexo, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $n = $this->q('SELECT * FROM negocios WHERE id = ?', [$negocioId])->fetch();
        $ev = (new Eventos($this->db))->obtener($negocioId, (int) $p['evento_id']);
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        $cot = $this->q('SELECT total FROM cotizaciones WHERE id = ? AND negocio_id = ?', [$p['cotizacion_id'], $negocioId])->fetch();
        $monto = round((float) $p['monto_agregado'], 2);
        $nuevo = round((float) ($cot['total'] ?? $ev['total']), 2);
        $subtotal = round(array_sum(array_map(fn($i) => (float) $i['total'], $items)), 2);
        $lineas = array_map(function ($i) {
            $cant = rtrim(rtrim(number_format((float) $i['cantidad'], 2, ',', '.'), '0'), ',');
            return '• ' . $i['descripcion'] . ' — ' . $cant . ' × ' . self::plata((float) $i['precio_unitario'])
                 . ($i['con_iva'] ? ' + IVA' : ' (0 % IVA)') . ' = ' . self::plata((float) $i['total']);
        }, $items);
        $cuota = $p['cuota_id'] ? $this->q('SELECT * FROM cuotas WHERE id = ?', [$p['cuota_id']])->fetch() : null;
        $plan = array_map(fn($c) => '• ' . $c['concepto'] . ': ' . self::plata((float) $c['monto']) . ' hasta el ' . (new DateTimeImmutable($c['vence']))->format('d/m/Y'),
                          (new Pagos($this->db))->cuotas((int) $p['evento_id'], $hoy));
        return [
            'negocio' => $n['nombre'], 'razon_social' => $n['razon_social'] ?: $n['nombre'], 'ruc' => $n['ruc'] ?: '—', 'ciudad' => $n['ciudad'] ?: 'Quito',
            'cliente' => $ev['cliente'], 'identificacion' => $ev['cliente_identificacion'] ?: '—', 'evento' => $ev['nombre'],
            'fecha' => self::fechaLarga($ev['fecha']), 'codigo' => $ev['codigo'],
            'numero_anexo' => (string) $numeroAnexo, 'numero_contrato' => (string) $contrato['numero'],
            'fecha_contrato' => self::fechaLarga(substr((string) ($contrato['firmado_en'] ?: $contrato['creado_en']), 0, 10)),
            'fecha_anexo' => self::fechaLarga($hoy->format('Y-m-d')),
            'extras' => implode("\n", $lineas), 'subtotal_extras' => self::plata($subtotal), 'iva_extras' => self::plata(round($monto - $subtotal, 2)),
            'total_extras' => self::plata($monto), 'total_extras_letras' => Letras::dolares($monto),
            'total_anterior' => self::plata(round($nuevo - $monto, 2)), 'nuevo_total' => self::plata($nuevo), 'nuevo_total_letras' => Letras::dolares($nuevo),
            'cuota_extras' => $cuota ? $cuota['concepto'] : 'sin cuota nueva', 'cuota_monto' => self::plata($cuota ? (float) $cuota['monto'] : 0.0),
            'cuota_fecha' => $cuota ? (new DateTimeImmutable($cuota['vence']))->format('d/m/Y') : '—',
            'plan_pagos' => $plan ? implode("\n", $plan) : '(por definir)',
        ];
    }

    /**
     * Prepara el anexo de un pedido aprobado si el evento tiene contrato firmado (lo llama Extras::aprobar dentro de su transacción).
     * Devuelve el id del anexo, o null si no hace falta (no hay contrato firmado).
     */
    public function crearParaPedido(int $negocioId, int $pedidoId, ?array $usuario = null, ?DateTimeImmutable $hoy = null): ?int
    {
        $p = $this->q("SELECT * FROM extras_pedidos WHERE id = ? AND negocio_id = ? AND estado = 'aprobado'", [$pedidoId, $negocioId])->fetch();
        if (!$p) throw new RuntimeException('El pedido no está aprobado.');
        $ya = $this->q('SELECT id FROM contrato_anexos WHERE pedido_id = ?', [$pedidoId])->fetchColumn();
        if ($ya) return (int) $ya;
        $contrato = $this->contratoFirmado($negocioId, (int) $p['evento_id']);
        if (!$contrato) return null;
        $this->q('SELECT id FROM contratos WHERE id = ? FOR UPDATE', [$contrato['id']]);
        $numero = (int) $this->q('SELECT COALESCE(max(numero),0) + 1 FROM contrato_anexos WHERE contrato_id = ?', [$contrato['id']])->fetchColumn();
        $items = (new Extras($this->db))->items($pedidoId);
        $texto = trim(Contratos::reemplazar((string) $this->plantilla($negocioId)['texto'], $this->valores($negocioId, $p, $items, $contrato, $numero, $hoy)));
        $id = (int) $this->q('INSERT INTO contrato_anexos (negocio_id, evento_id, contrato_id, pedido_id, numero, codigo_verificacion, texto, hash_sha256, monto, creado_por)
                              VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id',
                             [$negocioId, $p['evento_id'], $contrato['id'], $pedidoId, $numero, strtoupper(bin2hex(random_bytes(6))), $texto,
                              Contratos::huella($texto), $p['monto_agregado'], $usuario['id'] ?? null])->fetchColumn();
        $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                 [$negocioId, $p['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema',
                  "Preparó el anexo $numero al contrato N.º {$contrato['numero']} por los extras aprobados (" . self::plata((float) $p['monto_agregado']) . '), listo para que el cliente lo firme']);
        return $id;
    }

    // ------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------

    /** "Anexo 1 al contrato N.º 3" */
    public static function titulo(array $a): string
    {
        return 'Anexo ' . (int) $a['numero'] . ' al contrato N.º ' . (int) $a['contrato_numero'];
    }

    private const SELECT = 'SELECT a.*, ct.numero AS contrato_numero, ct.estado AS contrato_estado, ct.firmado_en AS contrato_firmado_en,
                                   e.codigo AS evento_codigo, e.nombre AS evento, e.fecha AS evento_fecha, e.estado AS evento_estado, e.token_cliente,
                                   c.nombre AS cliente, c.identificacion AS cliente_identificacion, c.telefono AS cliente_telefono
                              FROM contrato_anexos a JOIN contratos ct ON ct.id = a.contrato_id JOIN eventos e ON e.id = a.evento_id
                              JOIN clientes c ON c.id = e.cliente_id';

    private static function completar(array $a): array
    {
        $a['integro'] = hash_equals((string) $a['hash_sha256'], Contratos::huella((string) $a['texto']));
        $a['titulo'] = self::titulo($a);
        $a['firmable'] = $a['estado'] === 'enviado' && $a['contrato_estado'] === 'firmado';
        // Para el PDF del contrato (PdfDocumentos::contrato)
        $a['documento'] = $a['titulo'];
        $a['documento_corto'] = 'Anexo';
        return $a;
    }

    public function obtener(int $negocioId, int $anexoId): ?array
    {
        $a = $this->q(self::SELECT . ' WHERE a.id = ? AND a.negocio_id = ?', [$anexoId, $negocioId])->fetch();
        return $a ? self::completar($a) : null;
    }

    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $a = $this->q(self::SELECT . ' WHERE a.token = ?', [$token])->fetch();
        return $a ? self::completar($a) : null;
    }

    public function porPedido(int $negocioId, int $pedidoId): ?array
    {
        $a = $this->q(self::SELECT . ' WHERE a.pedido_id = ? AND a.negocio_id = ?', [$pedidoId, $negocioId])->fetch();
        return $a ? self::completar($a) : null;
    }

    public function deEvento(int $negocioId, int $eventoId): array
    {
        return array_map([self::class, 'completar'],
            $this->q(self::SELECT . ' WHERE a.negocio_id = ? AND a.evento_id = ? ORDER BY ct.numero, a.numero', [$negocioId, $eventoId])->fetchAll());
    }

    /** Anexos que el cliente tiene que firmar (su contrato sigue firmado). */
    public function porFirmar(int $negocioId, int $eventoId): array
    {
        return array_values(array_filter($this->deEvento($negocioId, $eventoId), fn($a) => $a['firmable']));
    }

    /** Página de verificación (código del QR). */
    public function verificar(string $codigo): ?array
    {
        $codigo = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $codigo));
        if (strlen($codigo) !== 12) return null;
        $a = $this->q(self::SELECT . ' WHERE a.codigo_verificacion = ?', [$codigo])->fetch();
        if (!$a) return null;
        $a = self::completar($a);
        $a['negocio'] = (string) $this->q('SELECT nombre FROM negocios WHERE id = ?', [$a['negocio_id']])->fetchColumn();
        return $a;
    }

    // ------------------------------------------------------------------
    // Firma del cliente
    // ------------------------------------------------------------------

    public function firmar(string $token, string $nombre, string $identificacion, string $firma, bool $acepta, string $ip = '', string $navegador = ''): array
    {
        $a = $this->porToken($token);
        if (!$a) throw new RuntimeException('El enlace del anexo no es válido.');
        if ($a['estado'] === 'firmado') throw new RuntimeException('Este anexo ya fue firmado.');
        if ($a['estado'] !== 'enviado' || $a['contrato_estado'] !== 'firmado') {
            throw new RuntimeException('Este anexo ya no se puede firmar: su contrato fue reemplazado. Pide al local el contrato nuevo.');
        }
        if (!$a['integro']) throw new RuntimeException('El texto del anexo fue alterado. Pide al local que lo revise.');
        if (!$acepta) throw new RuntimeException('Marca la casilla para aceptar el anexo.');
        $nombre = trim($nombre);
        $identificacion = preg_replace('/\D+/', '', $identificacion);
        if (mb_strlen($nombre) < 5) throw new RuntimeException('Escribe tu nombre completo.');
        if (!in_array(strlen($identificacion), [10, 13], true)) throw new RuntimeException('Escribe tu cédula (10 dígitos) o RUC (13).');
        $archivo = Fotos::guardarFirma($firma, Fotos::carpeta('firmas'));
        $st = $this->q("UPDATE contrato_anexos SET estado = 'firmado', firmante_nombre = ?, firmante_identificacion = ?, firma_archivo = ?,
                                firmado_en = now(), firma_ip = ?, firma_navegador = ? WHERE id = ? AND estado = 'enviado'",
                       [mb_substr($nombre, 0, 150), $identificacion, $archivo, mb_substr($ip, 0, 45) ?: null, mb_substr($navegador, 0, 300) ?: null, $a['id']]);
        if ($st->rowCount() !== 1) {
            Fotos::borrar('firmas', $archivo);
            throw new RuntimeException('Este anexo ya fue firmado.');
        }
        $nid = (int) $a['negocio_id'];
        $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?,?,?)',
                 [$nid, $a['evento_id'], mb_substr($nombre, 0, 120), 'Firmó el ' . mb_strtolower(mb_substr($a['titulo'], 0, 1)) . mb_substr($a['titulo'], 1) . ' desde su celular']);
        (new Notificaciones($this->db))->alNegocio($nid, '✍️ ' . $nombre . ' firmó el ' . mb_strtolower(mb_substr($a['titulo'], 0, 1)) . mb_substr($a['titulo'], 1)
            . ' de ' . $a['evento_codigo'] . ' (' . $a['evento'] . '): extras por ' . self::plata((float) $a['monto']) . '.', (int) $a['evento_id']);
        return $this->obtener($nid, (int) $a['id']);
    }

    // ------------------------------------------------------------------
    // Recordatorio por WhatsApp (Cobranza::mensajesDelDia, tipo 'anexo')
    // ------------------------------------------------------------------

    /** Anexos sin firmar de eventos que no han pasado, sin recordatorio en los últimos $repetir días. */
    public function porRecordar(int $negocioId, DateTimeImmutable $hoy, int $repetir = 3): array
    {
        return $this->q(self::SELECT . " WHERE a.negocio_id = :n AND a.estado = 'enviado' AND ct.estado = 'firmado'
                                           AND e.estado IN ('cotizado','separado','confirmado') AND e.fecha >= :hoy::date
                                           AND NOT EXISTS (SELECT 1 FROM mensajes_enviados me WHERE me.negocio_id = a.negocio_id AND me.tipo = 'anexo'
                                                              AND me.ref_id = a.id AND me.enviado_en > :hoy::date - (:rep)::int)
                                      ORDER BY e.fecha, a.id", ['n' => $negocioId, 'hoy' => $hoy->format('Y-m-d'), 'rep' => max(1, $repetir)])->fetchAll();
    }

    /** Mensaje de WhatsApp con el enlace de firma. */
    public static function textoFirma(array $a, string $negocio, string $enlace): string
    {
        $nombre = trim(explode(' ', trim((string) $a['cliente']))[0]);
        return "Hola $nombre, te saludamos de $negocio. Para sumar los extras que aprobamos a tu evento \"{$a['evento']}\", necesitamos que firmes el "
             . mb_strtolower(mb_substr(self::titulo($a), 0, 1)) . mb_substr(self::titulo($a), 1) . ' (' . self::plata((float) $a['monto'])
             . '). Toma un minuto desde tu celular: ' . $enlace . ' ¡Gracias!';
    }
}
