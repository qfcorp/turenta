<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Contratos con firma del cliente en su celular.
 *  - Se arma desde una plantilla del negocio, con los datos del evento, la cotización aceptada y el plan de pagos.
 *  - Al enviarlo, el texto queda fijo y se guarda su huella (SHA-256).
 *  - El cliente firma con el dedo; se guarda la imagen, la fecha, su IP y su navegador.
 *  - El PDF lleva un QR que abre la página de verificación: si el texto cambió, la huella no coincide.
 * (La firma electrónica con certificado .p12 del ERP QF firma al NEGOCIO; aquí firma el CLIENTE.)
 */
final class Contratos
{
    public const ESTADOS = ['borrador' => 'Borrador', 'enviado' => 'Enviado para firmar', 'firmado' => 'Firmado', 'anulado' => 'Anulado'];

    /** Variables que se pueden usar en la plantilla. */
    public const VARIABLES = [
        'negocio' => 'Nombre comercial del local', 'razon_social' => 'Razón social', 'ruc' => 'RUC del local',
        'direccion_negocio' => 'Dirección del local', 'ciudad' => 'Ciudad', 'cliente' => 'Nombre del cliente',
        'identificacion' => 'Cédula o RUC del cliente', 'telefono_cliente' => 'Celular del cliente', 'direccion_cliente' => 'Dirección del cliente',
        'evento' => 'Nombre del evento', 'tipo_evento' => 'Tipo de evento', 'fecha' => 'Fecha del evento (larga)',
        'hora_inicio' => 'Hora de inicio', 'hora_fin' => 'Hora de fin', 'espacios' => 'Espacios contratados', 'invitados' => 'Número de invitados',
        'total' => 'Valor total ($)', 'total_letras' => 'Valor total en letras', 'detalle' => 'Lista de lo contratado',
        'plan_pagos' => 'Plan de pagos', 'garantia' => 'Garantía por daños ($)', 'hora_limite' => 'Hora máxima de fin',
        'fecha_contrato' => 'Fecha de hoy', 'codigo' => 'Código del evento',
    ];

    public function __construct(private PDO $db) {}

    public static function plantillaDefecto(): string
    {
        return <<<TXT
CONTRATO DE PRESTACIÓN DE SERVICIOS PARA EVENTO SOCIAL

En la ciudad de {{ciudad}}, a {{fecha_contrato}}, comparecen por una parte {{razon_social}}, con RUC {{ruc}}, propietaria del local "{{negocio}}", ubicado en {{direccion_negocio}}, a quien en adelante se denominará "EL LOCAL"; y por otra parte {{cliente}}, con cédula/RUC {{identificacion}}, a quien en adelante se denominará "EL CLIENTE". Ambas partes acuerdan celebrar el presente contrato al tenor de las siguientes cláusulas:

PRIMERA — OBJETO. EL LOCAL se compromete a prestar a EL CLIENTE los servicios para el evento "{{evento}}" ({{tipo_evento}}), el día {{fecha}}, desde las {{hora_inicio}} hasta las {{hora_fin}}, en {{espacios}}, para aproximadamente {{invitados}} invitados.

SEGUNDA — SERVICIOS CONTRATADOS. Los servicios incluidos son los detallados a continuación, según la cotización aceptada por EL CLIENTE:
{{detalle}}

TERCERA — PRECIO. El valor total del evento es de {{total}} ({{total_letras}}), impuestos incluidos.

CUARTA — FORMA DE PAGO. EL CLIENTE pagará el valor total según el siguiente plan:
{{plan_pagos}}
La fecha queda separada únicamente cuando se ha pagado la reserva. Si EL CLIENTE se atrasa más de quince (15) días en una cuota, EL LOCAL podrá dar por terminado el contrato aplicando la cláusula de cancelación. El saldo debe estar cancelado en su totalidad antes del evento.

QUINTA — NÚMERO DE INVITADOS. EL CLIENTE confirmará el número final de invitados al menos quince (15) días antes del evento. Ese número será el mínimo a facturar. Los invitados adicionales se cobrarán al precio por persona vigente.

SEXTA — GARANTÍA POR DAÑOS. EL CLIENTE entregará una garantía de {{garantia}}, que será devuelta dentro de los ocho (8) días posteriores al evento, descontando el valor de cualquier daño o pérdida de mobiliario, menaje o instalaciones causado por EL CLIENTE, sus invitados o sus proveedores.

SÉPTIMA — HORARIO. El evento no podrá extenderse más allá de las {{hora_limite}}, conforme a las ordenanzas municipales. Cada hora adicional autorizada por EL LOCAL tendrá un costo adicional.

OCTAVA — PROVEEDORES EXTERNOS. Los proveedores que contrate EL CLIENTE deberán coordinar con EL LOCAL su ingreso, montaje y retiro, y cumplir sus normas. EL LOCAL no se responsabiliza por los servicios que preste un proveedor externo.

NOVENA — CANCELACIÓN Y CAMBIO DE FECHA. Si EL CLIENTE cancela el evento, la reserva no será reembolsable. Si la cancelación se produce con menos de treinta (30) días de anticipación, EL LOCAL retendrá además el cincuenta por ciento (50 %) de lo pagado. El cambio de fecha podrá realizarse una sola vez, con al menos sesenta (60) días de anticipación y sujeto a disponibilidad.

DÉCIMA — FUERZA MAYOR. Ninguna de las partes será responsable por incumplimientos causados por fuerza mayor o caso fortuito debidamente comprobados. En ese caso, las partes acordarán una nueva fecha sin penalidad.

DÉCIMA PRIMERA — CONTROVERSIAS. Para cualquier controversia, las partes se someten a la mediación en el Centro de Mediación de la Cámara de Comercio de {{ciudad}} y, de no llegar a un acuerdo, a los jueces competentes de {{ciudad}}.

Las partes aceptan el contenido de este contrato y lo firman electrónicamente.
Código del evento: {{codigo}}
TXT;
    }

    private function negocio(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT * FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $n = $st->fetch();
        if (!$n) throw new RuntimeException('Negocio no encontrado.');
        return $n;
    }

    public function plantillas(int $negocioId): array
    {
        $st = $this->db->prepare("SELECT * FROM plantillas_contrato WHERE negocio_id = ? AND tipo = 'contrato' ORDER BY predeterminada DESC, nombre");   // el anexo va aparte (Anexos)
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function guardarPlantilla(int $negocioId, ?int $id, string $nombre, string $texto, bool $predeterminada): int
    {
        if (mb_strlen(trim($nombre)) < 3) throw new RuntimeException('Escribe un nombre para la plantilla.');
        if (mb_strlen(trim($texto)) < 50) throw new RuntimeException('El texto del contrato es muy corto.');
        if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $texto, $m)) {
            $malas = array_diff(array_unique($m[1]), array_keys(self::VARIABLES));
            if ($malas) throw new RuntimeException('Estas variables no existen: {{' . implode('}}, {{', $malas) . '}}.');
        }
        if ($predeterminada) $this->db->prepare("UPDATE plantillas_contrato SET predeterminada = false WHERE negocio_id = ? AND tipo = 'contrato'")->execute([$negocioId]);
        if ($id) {
            $st = $this->db->prepare("UPDATE plantillas_contrato SET nombre = ?, texto = ?, predeterminada = ? WHERE id = ? AND negocio_id = ? AND tipo = 'contrato'");
            $st->execute([trim($nombre), trim($texto), $predeterminada ? 'true' : 'false', $id, $negocioId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró la plantilla.');
            return $id;
        }
        $st = $this->db->prepare('INSERT INTO plantillas_contrato (negocio_id, nombre, texto, predeterminada) VALUES (?,?,?,?) RETURNING id');
        $st->execute([$negocioId, trim($nombre), trim($texto), $predeterminada ? 'true' : 'false']);
        return (int) $st->fetchColumn();
    }

    /** Valores de las variables para un evento. */
    public function valores(int $negocioId, int $eventoId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $n = $this->negocio($negocioId);
        $ev = (new Eventos($this->db))->obtener($negocioId, $eventoId);
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        $cot = (new Cotizaciones($this->db))->aceptadaDe($eventoId);
        $detalle = [];
        if ($cot) {
            foreach ($cot['items'] as $i) {
                $cant = rtrim(rtrim(number_format((float) $i['cantidad'], 2, ',', '.'), '0'), ',');
                $detalle[] = '• ' . $i['descripcion'] . ' — ' . $cant . ' ' . (Cotizaciones::UNIDADES[$i['unidad']] === 'por persona' ? 'personas' : 'x') . ' · $' . number_format((float) $i['total'], 2, ',', '.');
            }
            if ((float) $cot['descuento'] > 0) $detalle[] = '• Descuento: −$' . number_format((float) $cot['descuento'], 2, ',', '.');
            if ((float) $cot['iva'] > 0) $detalle[] = '• IVA ' . rtrim(rtrim((string) $cot['iva_pct'], '0'), '.') . ' %: $' . number_format((float) $cot['iva'], 2, ',', '.');
        }
        $plan = [];
        foreach ((new Pagos($this->db))->cuotas($eventoId, $hoy) as $c) {
            $plan[] = '• ' . $c['concepto'] . ': $' . number_format((float) $c['monto'], 2, ',', '.') . ' hasta el ' . (new DateTimeImmutable($c['vence']))->format('d/m/Y');
        }
        $fechaTxt = function (string $f): string {
            $d = new DateTimeImmutable($f);
            $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
            $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . $meses[(int) $d->format('n')] . ' de ' . $d->format('Y');
        };
        $total = (float) $ev['total'];
        return [
            'negocio' => $n['nombre'], 'razon_social' => $n['razon_social'] ?: $n['nombre'], 'ruc' => $n['ruc'] ?: '—',
            'direccion_negocio' => $n['direccion'] ?: '—', 'ciudad' => $n['ciudad'] ?: 'Quito',
            'cliente' => $ev['cliente'], 'identificacion' => $ev['cliente_identificacion'] ?: '—',
            'telefono_cliente' => $ev['cliente_telefono'] ?: '—', 'direccion_cliente' => $ev['cliente_direccion'] ?: '—',
            'evento' => $ev['nombre'], 'tipo_evento' => Eventos::TIPOS[$ev['tipo']], 'fecha' => $fechaTxt($ev['fecha']),
            'hora_inicio' => substr($ev['hora_inicio'], 0, 5), 'hora_fin' => substr($ev['hora_fin'], 0, 5),
            'espacios' => $ev['espacios_texto'] ?: '—', 'invitados' => (string) $ev['invitados'],
            'total' => '$' . number_format($total, 2, ',', '.'), 'total_letras' => Letras::dolares($total),
            'detalle' => $detalle ? implode("\n", $detalle) : '(según cotización)', 'plan_pagos' => $plan ? implode("\n", $plan) : '(por definir)',
            'garantia' => '$' . number_format((float) $ev['garantia'], 2, ',', '.'), 'hora_limite' => substr($n['hora_limite'], 0, 5),
            'fecha_contrato' => $fechaTxt($hoy->format('Y-m-d')), 'codigo' => $ev['codigo'],
        ];
    }

    public static function reemplazar(string $texto, array $valores): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => array_key_exists($m[1], $valores) ? (string) $valores[$m[1]] : $m[0], $texto);
    }

    public static function huella(string $texto): string
    {
        return hash('sha256', $texto);
    }

    /** Arma un contrato nuevo (borrador) desde una plantilla (o la predeterminada / la de fábrica). */
    public function generar(int $negocioId, int $eventoId, ?int $plantillaId = null, ?array $usuario = null, ?DateTimeImmutable $hoy = null): int
    {
        $ev = (new Eventos($this->db))->obtener($negocioId, $eventoId);
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        if ((float) $ev['total'] <= 0) throw new RuntimeException('Primero acepta una cotización: el contrato necesita el valor del evento.');
        if (in_array($ev['estado'], ['perdido', 'cancelado'], true)) throw new RuntimeException('Este evento no está activo.');
        $texto = null;
        if ($plantillaId) {
            $st = $this->db->prepare("SELECT texto FROM plantillas_contrato WHERE id = ? AND negocio_id = ? AND tipo = 'contrato'");
            $st->execute([$plantillaId, $negocioId]);
            $texto = $st->fetchColumn() ?: null;
            if ($texto === null) throw new RuntimeException('No se encontró la plantilla.');
        } else {
            $st = $this->db->prepare("SELECT texto FROM plantillas_contrato WHERE negocio_id = ? AND tipo = 'contrato' ORDER BY predeterminada DESC, id LIMIT 1");
            $st->execute([$negocioId]);
            $texto = $st->fetchColumn() ?: self::plantillaDefecto();
        }
        $final = self::reemplazar($texto, $this->valores($negocioId, $eventoId, $hoy));
        $cot = (new Cotizaciones($this->db))->aceptadaDe($eventoId);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM negocios WHERE id = ? FOR UPDATE')->execute([$negocioId]);
            $st = $this->db->prepare('SELECT COALESCE(max(numero),0) + 1 FROM contratos WHERE negocio_id = ?');
            $st->execute([$negocioId]);
            $numero = (int) $st->fetchColumn();
            $this->db->prepare("UPDATE contratos SET estado = 'anulado' WHERE evento_id = ? AND estado IN ('borrador','enviado')")->execute([$eventoId]);
            $st = $this->db->prepare('INSERT INTO contratos (negocio_id, evento_id, cotizacion_id, numero, codigo_verificacion, texto, hash_sha256, creado_por)
                                      VALUES (?,?,?,?,?,?,?,?) RETURNING id');
            $st->execute([$negocioId, $eventoId, $cot['id'] ?? null, $numero, strtoupper(bin2hex(random_bytes(6))),
                          $final, self::huella($final), $usuario['id'] ?? null]);
            $id = (int) $st->fetchColumn();
            $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                     ->execute([$negocioId, $eventoId, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', "Preparó el contrato N.º $numero"]);
            if ($propia) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Solo en borrador se puede corregir el texto (se recalcula la huella). */
    public function editarTexto(int $negocioId, int $contratoId, string $texto): void
    {
        if (mb_strlen(trim($texto)) < 50) throw new RuntimeException('El texto del contrato es muy corto.');
        $st = $this->db->prepare("UPDATE contratos SET texto = ?, hash_sha256 = ? WHERE id = ? AND negocio_id = ? AND estado = 'borrador'");
        $st->execute([trim($texto), self::huella(trim($texto)), $contratoId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Solo se puede cambiar un contrato en borrador.');
    }

    /** Lo deja listo para firmar: el texto ya no cambia. */
    public function enviar(int $negocioId, int $contratoId, ?array $usuario = null): void
    {
        $st = $this->db->prepare("UPDATE contratos SET estado = 'enviado' WHERE id = ? AND negocio_id = ? AND estado = 'borrador' RETURNING evento_id, numero");
        $st->execute([$contratoId, $negocioId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Este contrato no está en borrador.');
        $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                 ->execute([$negocioId, $r['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', "Envió para firmar el contrato N.º {$r['numero']}"]);
    }

    public function obtener(int $negocioId, int $contratoId): ?array
    {
        $st = $this->db->prepare('SELECT ct.*, e.codigo AS evento_codigo, e.nombre AS evento, e.fecha AS evento_fecha, e.estado AS evento_estado,
                                         c.nombre AS cliente, c.identificacion AS cliente_identificacion, c.telefono AS cliente_telefono
                                    FROM contratos ct JOIN eventos e ON e.id = ct.evento_id JOIN clientes c ON c.id = e.cliente_id
                                   WHERE ct.id = ? AND ct.negocio_id = ?');
        $st->execute([$contratoId, $negocioId]);
        $c = $st->fetch();
        if (!$c) return null;
        $c['integro'] = hash_equals($c['hash_sha256'], self::huella($c['texto']));
        return $c;
    }

    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $st = $this->db->prepare('SELECT negocio_id, id FROM contratos WHERE token = ?');
        $st->execute([$token]);
        $r = $st->fetch();
        return $r ? $this->obtener((int) $r['negocio_id'], (int) $r['id']) : null;
    }

    public function deEvento(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT * FROM contratos WHERE negocio_id = ? AND evento_id = ? ORDER BY numero DESC');
        $st->execute([$negocioId, $eventoId]);
        return $st->fetchAll();
    }

    /** Contrato vigente del evento (el último que no está anulado). */
    public function vigente(int $negocioId, int $eventoId): ?array
    {
        $st = $this->db->prepare("SELECT id FROM contratos WHERE negocio_id = ? AND evento_id = ? AND estado <> 'anulado' ORDER BY numero DESC LIMIT 1");
        $st->execute([$negocioId, $eventoId]);
        $id = $st->fetchColumn();
        return $id ? $this->obtener($negocioId, (int) $id) : null;
    }

    /**
     * El cliente firma desde su enlace. $firma = imagen PNG en base64 (data URL) dibujada en el celular.
     * Si la reserva ya está pagada, el evento queda confirmado.
     */
    public function firmar(string $token, string $nombre, string $identificacion, string $firma, bool $acepta, string $ip = '', string $navegador = '', ?string $carpeta = null): array
    {
        $c = $this->porToken($token);
        if (!$c) throw new RuntimeException('El enlace del contrato no es válido.');
        if ($c['estado'] === 'firmado') throw new RuntimeException('Este contrato ya fue firmado.');
        if ($c['estado'] !== 'enviado') throw new RuntimeException('Este contrato todavía no está listo para firmar (o fue reemplazado).');
        if (!$c['integro']) throw new RuntimeException('El texto del contrato fue alterado. Pide al local un contrato nuevo.');
        if (!$acepta) throw new RuntimeException('Marca la casilla para aceptar el contrato.');
        $nombre = trim($nombre);
        $identificacion = preg_replace('/\D+/', '', $identificacion);
        if (mb_strlen($nombre) < 5) throw new RuntimeException('Escribe tu nombre completo.');
        if (!in_array(strlen($identificacion), [10, 13], true)) throw new RuntimeException('Escribe tu cédula (10 dígitos) o RUC (13).');
        $archivo = Fotos::guardarFirma($firma, $carpeta ?? Fotos::carpeta('firmas'));
        $st = $this->db->prepare("UPDATE contratos SET estado = 'firmado', firmante_nombre = ?, firmante_identificacion = ?, firma_archivo = ?,
                                         firmado_en = now(), firma_ip = ?, firma_navegador = ?
                                   WHERE id = ? AND estado = 'enviado'");
        $st->execute([mb_substr($nombre, 0, 150), $identificacion, $archivo, mb_substr($ip, 0, 45) ?: null, mb_substr($navegador, 0, 300) ?: null, $c['id']]);
        if ($st->rowCount() !== 1) {
            Fotos::borrar('firmas', $archivo);
            throw new RuntimeException('Este contrato ya fue firmado.');
        }
        $negocioId = (int) $c['negocio_id'];
        $this->db->prepare("INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?,?,?)")
                 ->execute([$negocioId, $c['evento_id'], $nombre, "Firmó el contrato N.º {$c['numero']} desde su celular"]);
        $st = $this->db->prepare('SELECT estado FROM eventos WHERE id = ?');
        $st->execute([$c['evento_id']]);
        if ($st->fetchColumn() === 'separado') {
            (new Eventos($this->db))->cambiarEstado($negocioId, (int) $c['evento_id'], 'confirmado', [], ['id' => null, 'nombre' => 'Sistema']);
        }
        (new Notificaciones($this->db))->alNegocio($negocioId, "{$nombre} firmó el contrato de {$c['evento_codigo']} ({$c['evento']}).", (int) $c['evento_id']);
        return $this->obtener($negocioId, (int) $c['id']);
    }

    /** Página pública de verificación (por el código del QR). */
    public function verificar(string $codigo): ?array
    {
        $codigo = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $codigo));
        if (strlen($codigo) !== 12) return null;
        $st = $this->db->prepare('SELECT ct.negocio_id, ct.id, n.nombre AS negocio FROM contratos ct JOIN negocios n ON n.id = ct.negocio_id WHERE ct.codigo_verificacion = ?');
        $st->execute([$codigo]);
        $r = $st->fetch();
        if (!$r) return null;
        $c = $this->obtener((int) $r['negocio_id'], (int) $r['id']);
        $c['negocio'] = $r['negocio'];
        return $c;
    }

    public function anular(int $negocioId, int $contratoId, string $motivo, ?array $usuario = null): void
    {
        if (mb_strlen(trim($motivo)) < 3) throw new RuntimeException('Escribe por qué se anula.');
        $st = $this->db->prepare("UPDATE contratos SET estado = 'anulado' WHERE id = ? AND negocio_id = ? AND estado <> 'anulado' RETURNING evento_id, numero");
        $st->execute([$contratoId, $negocioId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Este contrato ya está anulado.');
        $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                 ->execute([$negocioId, $r['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', "Anuló el contrato N.º {$r['numero']} ($motivo)"]);
    }

    public function rutaFirma(int $negocioId, int $contratoId): ?string
    {
        $st = $this->db->prepare('SELECT firma_archivo FROM contratos WHERE id = ? AND negocio_id = ?');
        $st->execute([$contratoId, $negocioId]);
        return Fotos::ruta('firmas', (string) $st->fetchColumn());
    }
}
