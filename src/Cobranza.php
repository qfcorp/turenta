<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cobranza del negocio (módulo Cobros y portal del cliente):
 *  - Ganancia de cada evento: lo que se cobra sin IVA menos el costo estimado (cotización) y menos lo gastado de verdad
 *    (gastos del evento + pagos al personal).
 *  - Gastos por evento (proveedores, compras).
 *  - Garantía por daños: monto pedido y garantías que el local todavía tiene que devolver.
 *  - Tablero de cobranza: comprobantes por revisar, cuotas vencidas y por vencer, cobrado del mes, por cobrar.
 *  - "Mensajes por WhatsApp de hoy": recordatorios listos para enviar con un toque, que se marcan como enviados
 *    (tabla mensajes_enviados de la migración 030) para no repetirlos.
 */
final class Cobranza
{
    /** Categorías de gastos del evento. */
    public const CATEGORIAS_GASTO = [
        'menu' => 'Comida', 'bebidas' => 'Bebidas', 'decoracion' => 'Decoración y flores', 'musica' => 'Música y sonido',
        'foto_video' => 'Foto y video', 'mobiliario' => 'Alquiler de mobiliario', 'personal' => 'Personal extra',
        'transporte' => 'Transporte', 'limpieza' => 'Limpieza', 'compras' => 'Compras varias', 'otros' => 'Otros',
    ];

    /** Mensajes del día: tipo => [título de la sección, cada cuántos días se puede repetir (0 = una sola vez)] */
    public const MENSAJES = [
        'cuota'      => ['Cuotas que vencen o ya vencieron', 3],
        'contrato'   => ['Contratos enviados que falta firmar', 3],
        'anexo'      => ['Anexos de contrato que falta firmar', 3],   // migración 090 (Anexos)
        'cotizacion' => ['Cotizaciones sin respuesta', 4],
        'staff'      => ['Personal sin confirmar para mañana', 0],
        'invitados'  => ['Pedir la lista de invitados', 0],
        'cuestionario' => ['Formularios del evento sin llenar', 4],   // migración 070 (Cuestionarios)
        'resena'     => ['Agradecer y pedir una reseña', 0],
    ];

    /** @var callable fn(string $ruta, array $params): string — enlace completo (para los mensajes) */
    private $url;

    public function __construct(private PDO $db, ?callable $url = null)
    {
        $this->url = $url ?? static function (string $ruta, array $params = []): string {
            if (function_exists('url_completa')) return url_completa($ruta, $params);
            $base = rtrim((string) (getenv('TURENTA_URL') ?: 'https://turenta.tukanec.com'), '/');
            return $base . '/?' . http_build_query(array_merge(['r' => $ruta], $params));
        };
    }

    private function enlace(string $ruta, array $params): string
    {
        return ($this->url)($ruta, $params);
    }

    private static function primerNombre(string $nombre): string
    {
        return trim(explode(' ', trim($nombre))[0]);
    }

    private static function dinero(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    private static function fechaTexto(string $fecha): string
    {
        $d = new DateTimeImmutable($fecha);
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . $meses[(int) $d->format('n')];
    }

    private function evento(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT e.*, n.iva_pct, n.nombre AS negocio FROM eventos e JOIN negocios n ON n.id = e.negocio_id WHERE e.id = ? AND e.negocio_id = ?');
        $st->execute([$eventoId, $negocioId]);
        $e = $st->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    // ------------------------------------------------------------------
    // Ganancia del evento
    // ------------------------------------------------------------------

    /**
     * Ganancia del evento.
     *  ingreso       = lo que se cobra sin IVA (subtotal − descuento de la cotización aceptada; si no hay, el total sin IVA)
     *  estimada      = ingreso − costo estimado de la cotización
     *  real          = ingreso − gastos del evento − compras a proveedores del evento − pagos al personal (los que no faltaron)
     */
    public function ganancia(int $negocioId, int $eventoId): array
    {
        $ev = $this->evento($negocioId, $eventoId);
        $st = $this->db->prepare("SELECT subtotal - descuento FROM cotizaciones WHERE evento_id = ? AND estado = 'aceptada' ORDER BY version DESC LIMIT 1");
        $st->execute([$eventoId]);
        $sinIva = $st->fetchColumn();
        $total = (float) $ev['total'];
        $ingreso = $sinIva !== false ? round((float) $sinIva, 2) : round($total / (1 + (float) $ev['iva_pct'] / 100), 2);
        $st = $this->db->prepare('SELECT COALESCE(sum(monto),0) FROM gastos WHERE evento_id = ? AND negocio_id = ?');
        $st->execute([$eventoId, $negocioId]);
        $gastos = round((float) $st->fetchColumn(), 2);
        $st = $this->db->prepare('SELECT COALESCE(sum(pago),0) AS total, COALESCE(sum(pago) FILTER (WHERE pagado),0) AS pagado, count(*) AS personas
                                    FROM evento_personal WHERE evento_id = ? AND asistio IS DISTINCT FROM false');
        $st->execute([$eventoId]);
        $per = $st->fetch();
        $personal = round((float) $per['total'], 2);
        // Compras a proveedores del evento (pedidas, confirmadas o entregadas; no las canceladas ni los pedidos de precio).
        // Son aparte de los gastos: una compra no crea gastos, así que no se cuenta dos veces.
        $compras = (new Compras($this->db))->costoEvento($negocioId, $eventoId);
        $costo = round((float) $ev['costo_estimado'], 2);
        $real = round($ingreso - $gastos - $compras - $personal, 2);
        $estimada = round($ingreso - $costo, 2);
        return [
            'total' => round($total, 2), 'iva' => round($total - $ingreso, 2), 'ingreso' => $ingreso,
            'costo_estimado' => $costo, 'ganancia_estimada' => $estimada,
            'margen_estimado' => $ingreso > 0 ? round($estimada * 100 / $ingreso, 1) : 0.0,
            'gastos' => $gastos, 'personal' => $personal, 'personal_pagado' => round((float) $per['pagado'], 2), 'personas' => (int) $per['personas'],
            'compras' => $compras,
            'costo_real' => round($gastos + $compras + $personal, 2), 'ganancia_real' => $real,
            'margen_real' => $ingreso > 0 ? round($real * 100 / $ingreso, 1) : 0.0,
            'diferencia' => round($real - $estimada, 2),
        ];
    }

    // ------------------------------------------------------------------
    // Gastos del evento
    // ------------------------------------------------------------------

    public function gastos(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT g.*, p.nombre AS proveedor, u.nombre AS registrado FROM gastos g
                               LEFT JOIN proveedores p ON p.id = g.proveedor_id LEFT JOIN usuarios u ON u.id = g.registrado_por
                                   WHERE g.negocio_id = ? AND g.evento_id = ? ORDER BY g.fecha DESC, g.id DESC');
        $st->execute([$negocioId, $eventoId]);
        return $st->fetchAll();
    }

    /** $d: concepto, monto, categoria, fecha (Y-m-d), proveedor_id. Devuelve el id. */
    public function registrarGasto(int $negocioId, int $eventoId, array $d, ?array $usuario = null): int
    {
        $this->evento($negocioId, $eventoId);
        $concepto = trim((string) ($d['concepto'] ?? ''));
        if (mb_strlen($concepto) < 2) throw new RuntimeException('Escribe en qué se gastó (por ejemplo: "Flores para la ceremonia").');
        $monto = round((float) str_replace(',', '.', (string) ($d['monto'] ?? 0)), 2);
        if ($monto <= 0) throw new RuntimeException('Escribe el valor del gasto.');
        $categoria = (string) ($d['categoria'] ?? 'otros');
        if (!isset(self::CATEGORIAS_GASTO[$categoria])) $categoria = 'otros';
        $fecha = trim((string) ($d['fecha'] ?? ''));
        if ($fecha === '') {
            $fecha = date('Y-m-d');
        } elseif (!DateTimeImmutable::createFromFormat('!Y-m-d', $fecha)) {
            throw new RuntimeException('Revisa la fecha del gasto.');
        }
        $proveedor = (int) ($d['proveedor_id'] ?? 0) ?: null;
        if ($proveedor) {
            $st = $this->db->prepare('SELECT 1 FROM proveedores WHERE id = ? AND negocio_id = ?');
            $st->execute([$proveedor, $negocioId]);
            if (!$st->fetchColumn()) $proveedor = null;
        }
        $st = $this->db->prepare('INSERT INTO gastos (negocio_id, evento_id, fecha, categoria, concepto, proveedor_id, monto, registrado_por)
                                  VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$negocioId, $eventoId, $fecha, $categoria, mb_substr($concepto, 0, 200), $proveedor, $monto, $usuario['id'] ?? null]);
        $id = (int) $st->fetchColumn();
        $this->historial($negocioId, $eventoId, $usuario, 'Registró un gasto: ' . mb_substr($concepto, 0, 120) . ' por ' . number_format($monto, 2, ',', '.') . ' dólares');
        return $id;
    }

    public function borrarGasto(int $negocioId, int $gastoId, ?array $usuario = null): void
    {
        $st = $this->db->prepare('DELETE FROM gastos WHERE id = ? AND negocio_id = ? RETURNING evento_id, concepto, monto');
        $st->execute([$gastoId, $negocioId]);
        $g = $st->fetch();
        if (!$g) throw new RuntimeException('Ese gasto ya no existe.');
        $this->historial($negocioId, $g['evento_id'] ? (int) $g['evento_id'] : null, $usuario,
            'Borró el gasto "' . mb_substr((string) $g['concepto'], 0, 120) . '" de ' . number_format((float) $g['monto'], 2, ',', '.') . ' dólares');
    }

    public function proveedores(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT id, nombre, servicio FROM proveedores WHERE negocio_id = ? AND activo ORDER BY nombre');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------
    // Garantía por daños
    // ------------------------------------------------------------------

    /** Cambia el valor de la garantía que se le pide al cliente. */
    public function cambiarGarantia(int $negocioId, int $eventoId, float $monto, ?array $usuario = null): void
    {
        $monto = round($monto, 2);
        if ($monto < 0 || $monto > 100000) throw new RuntimeException('Escribe un valor de garantía válido (0 si no se pide).');
        $ev = $this->evento($negocioId, $eventoId);
        $this->db->prepare('UPDATE eventos SET garantia = ?, actualizado_en = now() WHERE id = ? AND negocio_id = ?')->execute([$monto, $eventoId, $negocioId]);
        if (abs((float) $ev['garantia'] - $monto) > 0.009) {
            $this->historial($negocioId, $eventoId, $usuario, 'Cambió la garantía por daños de ' . number_format((float) $ev['garantia'], 2, ',', '.')
                . ' a ' . number_format($monto, 2, ',', '.') . ' dólares');
        }
    }

    /** Eventos que ya pasaron (o se cancelaron) y cuya garantía sigue en manos del local. */
    public function garantiasPorDevolver(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare("SELECT e.id, e.codigo, e.nombre AS evento, e.fecha, e.estado, e.token_cliente, c.nombre AS cliente, c.telefono,
                                         round(COALESCE(sum(p.monto) FILTER (WHERE p.tipo = 'garantia'),0)
                                             - COALESCE(sum(p.monto) FILTER (WHERE p.tipo IN ('devolucion_garantia','cargo_danos')),0), 2) AS saldo
                                    FROM eventos e JOIN clientes c ON c.id = e.cliente_id
                                    JOIN pagos p ON p.evento_id = e.id AND p.estado = 'confirmado'
                                               AND p.tipo IN ('garantia','devolucion_garantia','cargo_danos')
                                   WHERE e.negocio_id = ?
                                     AND (e.estado IN ('realizado','cancelado','perdido') OR (e.estado IN ('separado','confirmado') AND e.fecha < ?::date))
                                GROUP BY e.id, c.id
                                  HAVING round(COALESCE(sum(p.monto) FILTER (WHERE p.tipo = 'garantia'),0)
                                             - COALESCE(sum(p.monto) FILTER (WHERE p.tipo IN ('devolucion_garantia','cargo_danos')),0), 2) > 0
                                ORDER BY e.fecha");
        $st->execute([$negocioId, $hoy->format('Y-m-d')]);
        $res = [];
        foreach ($st->fetchAll() as $g) {
            $g['saldo'] = (float) $g['saldo'];
            $g['dias'] = (int) (new DateTimeImmutable($g['fecha']))->diff($hoy)->format('%r%a');
            $g['texto'] = 'Hola ' . self::primerNombre($g['cliente']) . ', te saludamos de ' . $this->nombreNegocio($negocioId)
                . '. Queremos coordinar la devolución de tu garantía de ' . self::dinero($g['saldo'])
                . ' del evento "' . $g['evento'] . '". ¿A qué cuenta te la transferimos? ¡Gracias!';
            $res[] = $g;
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Tablero de cobranza
    // ------------------------------------------------------------------

    /** Pagos del evento cobrados (confirmados) entre dos fechas: [total, cuantos]. */
    public function cobradoEntre(int $negocioId, string $desde, string $hasta): array
    {
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) AS total, count(*) AS cuantos FROM pagos
                                   WHERE negocio_id = ? AND tipo = 'evento' AND estado = 'confirmado' AND fecha >= ?::date AND fecha < ?::date + 1");
        $st->execute([$negocioId, $desde, $hasta]);
        $r = $st->fetch();
        return ['total' => round((float) $r['total'], 2), 'cuantos' => (int) $r['cuantos']];
    }

    public function tablero(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $pagos = new Pagos($this->db);
        $vencidas = [];
        $porVencer = [];
        $tramos = [7 => 0.0, 15 => 0.0, 30 => 0.0];
        foreach ($pagos->porCobrar($negocioId, 30, $hoy) as $c) {
            $c['saldo'] = (float) $c['saldo'];
            if ($c['vencida']) {
                $vencidas[] = $c;
            } else {
                $porVencer[] = $c;
                foreach ($tramos as $d => $_) if ($c['dias'] <= $d) $tramos[$d] += $c['saldo'];
            }
        }
        $ini = $hoy->modify('first day of this month');
        $iniAnt = $ini->modify('-1 month');
        $st = $this->db->prepare("SELECT
                COALESCE(sum(GREATEST(e.total - COALESCE(p.pagado,0), 0)) FILTER (WHERE e.estado IN ('separado','confirmado','realizado')),0) AS por_cobrar,
                COALESCE(sum(GREATEST(e.total - COALESCE(p.pagado,0), 0)) FILTER (WHERE e.estado = 'cotizado' AND e.total > 0),0) AS cotizados,
                count(*) FILTER (WHERE e.estado IN ('separado','confirmado','realizado') AND e.total - COALESCE(p.pagado,0) > 0) AS eventos
              FROM eventos e
         LEFT JOIN (SELECT evento_id, sum(monto) AS pagado FROM pagos WHERE negocio_id = ? AND tipo = 'evento' AND estado = 'confirmado' GROUP BY evento_id) p
                ON p.evento_id = e.id
             WHERE e.negocio_id = ?");
        $st->execute([$negocioId, $negocioId]);
        $pc = $st->fetch();
        return [
            'por_revisar' => $pagos->porRevisar($negocioId),
            'vencidas' => $vencidas,
            'total_vencido' => round(array_sum(array_column($vencidas, 'saldo')), 2),
            'por_vencer' => $porVencer,
            'tramos' => array_map(fn($v) => round($v, 2), $tramos),
            'cobrado_mes' => $this->cobradoEntre($negocioId, $ini->format('Y-m-d'), $hoy->modify('last day of this month')->format('Y-m-d')),
            'cobrado_anterior' => $this->cobradoEntre($negocioId, $iniAnt->format('Y-m-d'), $ini->modify('-1 day')->format('Y-m-d')),
            'por_cobrar' => round((float) $pc['por_cobrar'], 2),
            'eventos_por_cobrar' => (int) $pc['eventos'],
            'por_cobrar_cotizados' => round((float) $pc['cotizados'], 2),
            'garantias' => $this->garantiasPorDevolver($negocioId, $hoy),
        ];
    }

    /**
     * Mensaje para mostrar después de registrar un pago: si la fecha quedó separada (o confirmada),
     * o si se pagó la reserva pero no se pudo separar (otro evento tiene el espacio).
     */
    public function mensajeSeparacion(int $negocioId, int $eventoId, string $estadoAntes): ?string
    {
        $ev = $this->evento($negocioId, $eventoId);
        if (in_array($estadoAntes, ['consulta', 'cotizado'], true)) {
            if ($ev['estado'] === 'separado') return 'Reserva pagada: la fecha quedó separada.';
            if ($ev['estado'] === 'confirmado') return 'Reserva pagada y contrato firmado: el evento quedó confirmado.';
            $st = $this->db->prepare("SELECT texto FROM notificaciones WHERE negocio_id = ? AND evento_id = ? AND texto LIKE '%NO se pudo separar%'
                                         AND creada_en > now() - interval '1 minute' ORDER BY id DESC LIMIT 1");
            $st->execute([$negocioId, $eventoId]);
            $t = $st->fetchColumn();
            if ($t) {
                $motivo = trim((string) substr((string) $t, (int) strpos((string) $t, 'fecha:') + 6));
                return 'Se registró el pago, pero no se pudo separar la fecha: ' . $motivo;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Mensajes por WhatsApp de hoy
    // ------------------------------------------------------------------

    private function nombreNegocio(int $negocioId): string
    {
        static $cache = [];
        if (!isset($cache[$negocioId])) {
            $st = $this->db->prepare('SELECT nombre FROM negocios WHERE id = ?');
            $st->execute([$negocioId]);
            $cache[$negocioId] = (string) $st->fetchColumn();
        }
        return $cache[$negocioId];
    }

    /** Condición SQL: el mensaje no se marcó como enviado (o se marcó hace más de N días). */
    private static function noEnviado(string $tipo, string $refCol): string
    {
        $dias = self::MENSAJES[$tipo][1];
        return "NOT EXISTS (SELECT 1 FROM mensajes_enviados me WHERE me.negocio_id = e.negocio_id AND me.tipo = '$tipo' AND me.ref_id = $refCol"
             . ($dias > 0 ? " AND me.enviado_en > :hoy::date - $dias" : '') . ')';
    }

    /**
     * Mensajes listos para enviar hoy. Cada uno: tipo, ref_id, evento_id, codigo, evento, para, telefono, motivo, texto, wa.
     * @return array<string, array> agrupados por tipo (en el orden de MENSAJES)
     */
    public function mensajesDelDia(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $h = $hoy->format('Y-m-d');
        $neg = $this->nombreNegocio($negocioId);
        $st = $this->db->prepare('SELECT resenas_url FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $n = $st->fetch() ?: ['resenas_url' => null];
        $mods = (new Modulos($this->db))->activos($negocioId);   // Anexo B: cada grupo y cada enlace, según los módulos encendidos
        $con = fn(string $m) => in_array($m, $mods, true);
        $res = array_fill_keys(array_keys(self::MENSAJES), []);
        $agregar = function (string $tipo, array $fila, string $para, ?string $tel, string $motivo, string $texto) use (&$res): void {
            $res[$tipo][] = [
                'tipo' => $tipo, 'ref_id' => (int) $fila['ref_id'], 'evento_id' => (int) $fila['evento_id'], 'codigo' => $fila['codigo'],
                'evento' => $fila['evento'], 'para' => $para, 'telefono' => $tel, 'motivo' => $motivo, 'texto' => $texto,
                'wa' => WhatsApp::enlace($tel, $texto),
            ];
        };

        // 1) Cuotas que vencen en 3 días o ya vencieron (una por evento: la más antigua sin pagar)
        $sql = "SELECT * FROM (
                    SELECT DISTINCT ON (e.id) cu.id AS ref_id, cu.concepto, cu.vence, cu.aviso_enviado, round(cu.monto - cu.pagado, 2) AS saldo,
                           e.id AS evento_id, e.codigo, e.nombre AS evento, e.token_cliente, c.nombre AS cliente, c.telefono,
                           (SELECT round(COALESCE(sum(c2.monto - c2.pagado),0), 2) FROM cuotas c2 WHERE c2.evento_id = e.id AND c2.pagado < c2.monto AND c2.vence < :hoy::date) AS vencido
                      FROM cuotas cu JOIN eventos e ON e.id = cu.evento_id JOIN clientes c ON c.id = e.cliente_id
                     WHERE e.negocio_id = :n AND cu.pagado < cu.monto AND cu.vence <= :hoy::date + 3
                       AND e.estado IN ('cotizado','separado','confirmado','realizado')
                  ORDER BY e.id, cu.vence, cu.numero) x
                 WHERE (aviso_enviado IS NULL OR aviso_enviado <= :hoy::date - 3)
              ORDER BY vence";
        $st = $this->db->prepare($sql);
        $st->execute(['n' => $negocioId, 'hoy' => $h]);
        foreach ($st->fetchAll() as $f) {
            $portal = $con('portal_cliente') ? $this->enlace('portal', ['t' => $f['token_cliente']]) : '';
            $texto = Pagos::textoRecordatorio($f['cliente'], $neg, $f['concepto'], (float) $f['saldo'], $f['vence'], $portal);
            $dias = (int) $hoy->diff(new DateTimeImmutable($f['vence']))->format('%r%a');
            $motivo = $f['concepto'] . ' · ' . self::dinero((float) $f['saldo']) . ' · '
                . ($dias < 0 ? 'venció hace ' . -$dias . ($dias === -1 ? ' día' : ' días') : ($dias === 0 ? 'vence hoy' : 'vence en ' . $dias . ($dias === 1 ? ' día' : ' días')));
            $agregar('cuota', $f, $f['cliente'], $f['telefono'], $motivo, $texto);
        }

        // 2) Contratos enviados que falta firmar (de eventos que no han pasado)
        $st = $this->db->prepare("SELECT ct.id AS ref_id, ct.numero, ct.token, e.id AS evento_id, e.codigo, e.nombre AS evento, e.fecha, c.nombre AS cliente, c.telefono
                                    FROM contratos ct JOIN eventos e ON e.id = ct.evento_id JOIN clientes c ON c.id = e.cliente_id
                                   WHERE e.negocio_id = :n AND ct.estado = 'enviado' AND ct.creado_en::date < :hoy::date
                                     AND e.estado IN ('consulta','cotizado','separado','confirmado') AND e.fecha >= :hoy::date
                                     AND " . self::noEnviado('contrato', 'ct.id') . ' ORDER BY e.fecha');
        $st->execute(['n' => $negocioId, 'hoy' => $h]);
        foreach ($st->fetchAll() as $f) {
            $texto = 'Hola ' . self::primerNombre($f['cliente']) . ', te saludamos de ' . $neg . '. Tu contrato para "' . $f['evento']
                . '" está listo para firmar desde tu celular, toma un minuto: ' . $this->enlace('firmar', ['t' => $f['token']]) . ' Cualquier duda, nos escribes. ¡Gracias!';
            $agregar('contrato', $f, $f['cliente'], $f['telefono'], 'Contrato N.º ' . $f['numero'] . ' sin firmar · evento el ' . self::fechaTexto($f['fecha']), $texto);
        }

        // 2b) Anexos de contrato por extras aprobados, sin firmar (migración 090): cada 3 días mientras sigan pendientes
        foreach ((new Anexos($this->db))->porRecordar($negocioId, $hoy, self::MENSAJES['anexo'][1]) as $f) {
            $f['ref_id'] = $f['id'];
            $f['codigo'] = $f['evento_codigo'];
            $texto = Anexos::textoFirma($f, $neg, $this->enlace('firmar_anexo', ['t' => $f['token']]));
            $agregar('anexo', $f, $f['cliente'], $f['cliente_telefono'], Anexos::titulo($f) . ' sin firmar · extras por ' . self::dinero((float) $f['monto']), $texto);
        }

        // 3) Cotizaciones enviadas hace 3 días o más, sin respuesta
        $st = $this->db->prepare("SELECT co.id AS ref_id, co.numero, co.token, co.total, co.fecha AS enviada, co.valida_hasta, e.id AS evento_id, e.codigo,
                                         e.nombre AS evento, e.fecha, c.nombre AS cliente, c.telefono
                                    FROM cotizaciones co JOIN eventos e ON e.id = co.evento_id JOIN clientes c ON c.id = e.cliente_id
                                   WHERE e.negocio_id = :n AND co.estado = 'enviada' AND co.fecha <= :hoy::date - 3
                                     AND e.estado IN ('consulta','cotizado') AND e.fecha >= :hoy::date
                                     AND " . self::noEnviado('cotizacion', 'co.id') . ' ORDER BY co.fecha');
        $st->execute(['n' => $negocioId, 'hoy' => $h]);
        foreach ($st->fetchAll() as $f) {
            $vencida = $f['valida_hasta'] < $h;
            $texto = 'Hola ' . self::primerNombre($f['cliente']) . ', te saludamos de ' . $neg . '. ¿Pudiste revisar la cotización para tu evento del '
                . self::fechaTexto($f['fecha']) . '? Aquí la tienes: ' . $this->enlace('cot', ['t' => $f['token']])
                . ($vencida ? ' Ya pasó su fecha de validez; si quieres te la actualizamos.' : ' Recuerda que la fecha se separa con el pago de la reserva.')
                . ' ¿Te ayudamos con alguna duda?';
            $dias = (int) (new DateTimeImmutable($f['enviada']))->diff($hoy)->days;
            $agregar('cotizacion', $f, $f['cliente'], $f['telefono'], 'Cotización N.º ' . $f['numero'] . ' por ' . self::dinero((float) $f['total']) . ' · enviada hace ' . $dias . ' días', $texto);
        }

        // 4) Personal sin confirmar para los eventos de mañana
        $st = $this->db->prepare("SELECT ep.id AS ref_id, ep.rol, ep.hora_entrada, p.nombre, p.telefono, p.token, e.id AS evento_id, e.codigo, e.nombre AS evento,
                                         e.fecha, e.hora_inicio
                                    FROM evento_personal ep JOIN personal p ON p.id = ep.personal_id JOIN eventos e ON e.id = ep.evento_id
                                   WHERE e.negocio_id = :n AND e.fecha = :hoy::date + 1 AND e.estado IN ('separado','confirmado')
                                     AND NOT ep.confirmado AND p.activo AND " . self::noEnviado('staff', 'ep.id') . ' ORDER BY ep.hora_entrada NULLS LAST, p.nombre');
        $st->execute(['n' => $negocioId, 'hoy' => $h]);
        foreach ($st->fetchAll() as $f) {
            $hora = substr((string) ($f['hora_entrada'] ?: $f['hora_inicio']), 0, 5);
            $texto = 'Hola ' . self::primerNombre($f['nombre']) . ', te escribimos de ' . $neg . '. Mañana ' . self::fechaTexto($f['fecha'])
                . ' tienes el evento "' . $f['evento'] . '"' . ($f['hora_entrada'] ? ', entrada a las ' . $hora : '')
                . '. Por favor confirma que vas aquí: ' . $this->enlace('staff', ['t' => $f['token']]) . ' ¡Gracias!';
            $agregar('staff', $f, $f['nombre'], $f['telefono'], ucfirst((string) $f['rol']) . ' · ' . $f['evento'] . ($f['hora_entrada'] ? ' · entra ' . $hora : ''), $texto);
        }

        // 5) Pedir la lista de invitados (desde 10 días antes)
        $st = $this->db->prepare("SELECT e.id AS ref_id, e.id AS evento_id, e.codigo, e.nombre AS evento, e.fecha, e.invitados, e.token_cliente, c.nombre AS cliente, c.telefono
                                    FROM eventos e JOIN clientes c ON c.id = e.cliente_id
                                   WHERE e.negocio_id = :n AND e.estado IN ('separado','confirmado')
                                     AND e.fecha BETWEEN :hoy::date + 1 AND :hoy::date + 10
                                     AND " . self::noEnviado('invitados', 'e.id') . ' ORDER BY e.fecha');
        $st->execute(['n' => $negocioId, 'hoy' => $h]);
        foreach ($st->fetchAll() as $f) {
            $dias = (int) $hoy->diff(new DateTimeImmutable($f['fecha']))->days;
            $texto = 'Hola ' . self::primerNombre($f['cliente']) . ', te saludamos de ' . $neg . '. ¡Faltan ' . $dias . ' días para tu evento! '
                . ($con('invitados')
                    ? 'Para organizar las mesas y la puerta, carga tu lista de invitados ' . ($con('portal_cliente') ? 'en tu portal: ' . $this->enlace('portal', ['t' => $f['token_cliente']])
                                                                                                         : 'aquí: ' . $this->enlace('portal_invitados', ['t' => $f['token_cliente']]))
                    : 'Para organizar las mesas, envíanos por aquí tu lista de invitados (nombre y cuántas personas).'
                      . ($con('portal_cliente') ? ' Tu portal: ' . $this->enlace('portal', ['t' => $f['token_cliente']]) : ''))
                . ' ¡Gracias!';
            $agregar('invitados', $f, $f['cliente'], $f['telefono'], 'Evento el ' . self::fechaTexto($f['fecha']) . ' · ' . (int) $f['invitados'] . ' invitados', $texto);
        }

        // 5b) Formulario del evento sin llenar (migración 070): desde N días antes hasta el cierre, cada 4 días
        foreach ((new Cuestionarios($this->db))->porRecordar($negocioId, $hoy, self::MENSAJES['cuestionario'][1]) as $f) {
            $texto = 'Hola ' . self::primerNombre($f['cliente']) . ', te saludamos de ' . $neg . '. Para preparar cada detalle de tu evento del '
                . self::fechaTexto($f['fecha']) . ', llena el formulario en tu portal (música, decoración, menú, horarios…): '
                . $this->enlace('portal_cuestionario', ['t' => $f['token_cliente']]) . ' Se guarda solo y lo puedes completar hasta el '
                . self::fechaTexto($f['cierre_fecha']) . '. ¡Gracias!';
            $avance = $f['copiado'] ? (int) $f['respondidas'] . ' de ' . (int) $f['total'] . ' respuestas' : 'sin empezar';
            $agregar('cuestionario', $f, $f['cliente'], $f['telefono'], 'Evento el ' . self::fechaTexto($f['fecha']) . ' · ' . $avance, $texto);
        }

        // 6) Agradecer y pedir una reseña (del día siguiente hasta una semana después), con el enlace de la encuesta (070)
        $st = $this->db->prepare("SELECT e.id AS ref_id, e.id AS evento_id, e.codigo, e.nombre AS evento, e.fecha, c.nombre AS cliente, c.telefono,
                                         e.token_encuesta, EXISTS (SELECT 1 FROM encuestas x WHERE x.evento_id = e.id) AS encuesta_hecha
                                    FROM eventos e JOIN clientes c ON c.id = e.cliente_id
                                   WHERE e.negocio_id = :n AND e.estado IN ('confirmado','realizado')
                                     AND e.fecha BETWEEN :hoy::date - 7 AND :hoy::date - 1
                                     AND " . self::noEnviado('resena', 'e.id') . ' ORDER BY e.fecha DESC');
        $st->execute(['n' => $negocioId, 'hoy' => $h]);
        foreach ($st->fetchAll() as $f) {
            $url = trim((string) $n['resenas_url']);
            $texto = 'Hola ' . self::primerNombre($f['cliente']) . ', gracias por celebrar "' . $f['evento'] . '" con nosotros en ' . $neg . '. ¡Fue un gusto! '
                . (!$f['encuesta_hecha'] && $con('cuestionarios')
                    ? '¿Nos cuentas cómo te fue? Toma un minuto: ' . $this->enlace('encuesta', ['t' => $f['token_encuesta']])
                      . ($url !== '' ? ' Y si te gustó, déjanos tu reseña en Google: ' . $url : ' O respóndenos este mensaje con tu opinión.')
                    : ($url !== '' ? 'Nos ayudarías mucho dejando tu opinión aquí: ' . $url : 'Nos encantaría saber cómo te fue; respóndenos este mensaje con tu opinión.'))
                . ' ¡Gracias!';
            $agregar('resena', $f, $f['cliente'], $f['telefono'], 'Evento del ' . self::fechaTexto($f['fecha']), $texto);
        }
        foreach (Modulos::MENSAJES as $tipo => $m) if ($m !== '' && !$con($m)) $res[$tipo] = [];
        return $res;
    }

    /** Busca el evento al que pertenece el mensaje (y verifica que sea del negocio). */
    private function eventoDeMensaje(int $negocioId, string $tipo, int $refId): ?int
    {
        $sql = match ($tipo) {
            'cuota'      => 'SELECT e.id FROM cuotas x JOIN eventos e ON e.id = x.evento_id WHERE x.id = ? AND e.negocio_id = ?',
            'contrato'   => 'SELECT e.id FROM contratos x JOIN eventos e ON e.id = x.evento_id WHERE x.id = ? AND e.negocio_id = ?',
            'anexo'      => 'SELECT e.id FROM contrato_anexos x JOIN eventos e ON e.id = x.evento_id WHERE x.id = ? AND e.negocio_id = ?',
            'cotizacion' => 'SELECT e.id FROM cotizaciones x JOIN eventos e ON e.id = x.evento_id WHERE x.id = ? AND e.negocio_id = ?',
            'staff'      => 'SELECT e.id FROM evento_personal x JOIN eventos e ON e.id = x.evento_id WHERE x.id = ? AND e.negocio_id = ?',
            'invitados', 'resena', 'cuestionario' => 'SELECT id FROM eventos WHERE id = ? AND negocio_id = ?',
            default      => null,
        };
        if ($sql === null) throw new RuntimeException('Tipo de mensaje no válido.');
        $st = $this->db->prepare($sql);
        $st->execute([$refId, $negocioId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Marca un mensaje como enviado hoy (no vuelve a salir; los de cuotas y contratos vuelven a salir en unos días si siguen pendientes). */
    public function marcarEnviado(int $negocioId, string $tipo, int $refId, ?array $usuario = null, ?DateTimeImmutable $hoy = null): void
    {
        $hoy ??= new DateTimeImmutable('today');
        $eventoId = $this->eventoDeMensaje($negocioId, $tipo, $refId);
        if ($eventoId === null) throw new RuntimeException('Ese mensaje ya no está en la lista.');
        $this->db->prepare('INSERT INTO mensajes_enviados (negocio_id, evento_id, tipo, ref_id, enviado_en, usuario_id) VALUES (?,?,?,?,?,?)
                            ON CONFLICT (negocio_id, tipo, ref_id, enviado_en) DO NOTHING')
                 ->execute([$negocioId, $eventoId, $tipo, $refId, $hoy->format('Y-m-d'), $usuario['id'] ?? null]);
        if ($tipo === 'cuota') {
            // Todas las cuotas del evento que estaban en el recordatorio
            $this->db->prepare('UPDATE cuotas SET aviso_enviado = ?::date WHERE evento_id = ? AND pagado < monto AND vence <= ?::date + 3')
                     ->execute([$hoy->format('Y-m-d'), $eventoId, $hoy->format('Y-m-d')]);
        }
        $this->historial($negocioId, $eventoId, $usuario, 'Envió por WhatsApp: ' . mb_strtolower(self::MENSAJES[$tipo][0]));
    }

    /** Deshace "marcar como enviado" de hoy (por si se tocó por error). */
    public function desmarcarEnviado(int $negocioId, string $tipo, int $refId, ?DateTimeImmutable $hoy = null): void
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare('DELETE FROM mensajes_enviados WHERE negocio_id = ? AND tipo = ? AND ref_id = ? AND enviado_en = ? RETURNING evento_id');
        $st->execute([$negocioId, $tipo, $refId, $hoy->format('Y-m-d')]);
        $eventoId = $st->fetchColumn();
        if ($eventoId === false) throw new RuntimeException('Ese mensaje no estaba marcado hoy.');
        if ($tipo === 'cuota') {
            $st = $this->db->prepare("SELECT max(enviado_en) FROM mensajes_enviados WHERE negocio_id = ? AND tipo = 'cuota' AND evento_id = ?");
            $st->execute([$negocioId, $eventoId]);
            $antes = $st->fetchColumn() ?: null;
            $this->db->prepare('UPDATE cuotas SET aviso_enviado = ? WHERE evento_id = ? AND aviso_enviado = ?')->execute([$antes, $eventoId, $hoy->format('Y-m-d')]);
        }
    }

    /** Mensajes que ya se marcaron hoy (para mostrarlos tachados y poder deshacer). */
    public function enviadosHoy(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare('SELECT me.tipo, me.ref_id, me.creado_en, e.codigo, e.nombre AS evento, u.nombre AS usuario
                                    FROM mensajes_enviados me LEFT JOIN eventos e ON e.id = me.evento_id LEFT JOIN usuarios u ON u.id = me.usuario_id
                                   WHERE me.negocio_id = ? AND me.enviado_en = ? ORDER BY me.creado_en DESC');
        $st->execute([$negocioId, $hoy->format('Y-m-d')]);
        return $st->fetchAll();
    }

    /** Cuántos mensajes hay listos hoy (para un contador en el menú o el inicio). */
    public function cuantosMensajes(int $negocioId, ?DateTimeImmutable $hoy = null): int
    {
        return array_sum(array_map('count', $this->mensajesDelDia($negocioId, $hoy)));
    }

    private function historial(int $negocioId, ?int $eventoId, ?array $usuario, string $accion): void
    {
        $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                 ->execute([$negocioId, $eventoId, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', mb_substr($accion, 0, 400)]);
    }
}
