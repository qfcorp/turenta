<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Reportes del negocio: ventas, cobrado, por cobrar, ocupación, embudo y conversión,
 * ticket promedio, rentabilidad por evento y comisión de vendedores.
 * También: cifras del inicio, exportación CSV (para Excel en español) y calendario iCal para Google Calendar.
 *
 * "Vendido" = el evento llegó a separarse (separado, confirmado o realizado).
 * Fecha de venta = cuando se separó por primera vez o se confirmó (lo que pasó primero).
 */
final class Reportes
{
    public const VENDIDOS = ['separado', 'confirmado', 'realizado'];
    public const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                          'septiembre', 'octubre', 'noviembre', 'diciembre'];
    public const MESES_CORTOS = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    public const PERIODOS = ['mes' => 'Este mes', 'mes_pasado' => 'Mes pasado', 'anio' => 'Este año', 'rango' => 'Otras fechas'];
    public const EXPORTES = [
        'eventos' => 'Eventos', 'pagos' => 'Pagos recibidos', 'clientes' => 'Clientes',
        'cuotas' => 'Cuotas por cobrar', 'gastos' => 'Gastos',
    ];

    /** Fecha (timestamp) en que se vendió el evento. */
    private const SQL_FECHA_VENTA = "COALESCE(LEAST(e.confirmado_en,
            (SELECT min(rc.creado_en) FROM registro_cambios rc WHERE rc.evento_id = e.id AND rc.accion LIKE 'Cambió el estado de % a Separado%')),
            e.creado_en)";
    /** Valor sin IVA: el de la cotización aceptada o, si no hay, el total sin el IVA del negocio. */
    private const SQL_SIN_IVA = "COALESCE((SELECT c.total - c.iva FROM cotizaciones c WHERE c.evento_id = e.id AND c.estado = 'aceptada' ORDER BY c.version DESC LIMIT 1),
            round(e.total / (1 + n.iva_pct / 100), 2))";

    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    // ------------------------------------------------------------------
    // Periodo
    // ------------------------------------------------------------------

    /** @return array{clave:string, desde:string, hasta:string, nombre:string} */
    public static function periodo(string $clave, ?string $desde = null, ?string $hasta = null, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $valida = function (?string $f): ?DateTimeImmutable {
            $d = $f ? DateTimeImmutable::createFromFormat('!Y-m-d', $f) : false;
            return $d && $d->format('Y-m-d') === $f ? $d : null;
        };
        switch ($clave) {
            case 'mes_pasado':
                $d = $hoy->modify('first day of last month');
                return ['clave' => $clave, 'desde' => $d->format('Y-m-d'), 'hasta' => $d->modify('last day of this month')->format('Y-m-d'),
                        'nombre' => ucfirst(self::nombreMes($d->format('Y-m')))];
            case 'anio':
                return ['clave' => $clave, 'desde' => $hoy->format('Y') . '-01-01', 'hasta' => $hoy->format('Y') . '-12-31', 'nombre' => 'Año ' . $hoy->format('Y')];
            case 'rango':
                $d1 = $valida($desde);
                $d2 = $valida($hasta);
                if ($d1 && $d2) {
                    if ($d2 < $d1) [$d1, $d2] = [$d2, $d1];
                    if ($d1->modify('+10 years') < $d2) $d2 = $d1->modify('+10 years');
                    return ['clave' => $clave, 'desde' => $d1->format('Y-m-d'), 'hasta' => $d2->format('Y-m-d'),
                            'nombre' => 'Del ' . $d1->format('d/m/Y') . ' al ' . $d2->format('d/m/Y')];
                }
                // sin fechas válidas: este mes
            default:
                $d = $hoy->modify('first day of this month');
                return ['clave' => 'mes', 'desde' => $d->format('Y-m-d'), 'hasta' => $d->modify('last day of this month')->format('Y-m-d'),
                        'nombre' => ucfirst(self::nombreMes($d->format('Y-m')))];
        }
    }

    public static function nombreMes(string $mes): string
    {
        return self::MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4);
    }

    public static function mesCorto(string $mes): string
    {
        return self::MESES_CORTOS[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 2, 2);
    }

    /** Meses (AAAA-MM) entre dos fechas. Si es un solo mes, se muestran los 6 anteriores para comparar. */
    public static function mesesDe(string $desde, string $hasta): array
    {
        $ini = (new DateTimeImmutable($desde))->modify('first day of this month');
        $fin = (new DateTimeImmutable($hasta))->modify('first day of this month');
        if ($ini == $fin) $ini = $ini->modify('-5 months');
        $meses = [];
        for ($m = $ini; $m <= $fin && count($meses) < 48; $m = $m->modify('+1 month')) $meses[] = $m->format('Y-m');
        return $meses;
    }

    // ------------------------------------------------------------------
    // Ventas
    // ------------------------------------------------------------------

    /** Eventos vendidos con su fecha de venta, valor y valor sin IVA. Filtro por fecha de venta o del evento. */
    public function vendidos(int $negocioId, string $desde, string $hasta, string $por = 'venta'): array
    {
        $col = $por === 'evento' ? 'v.fecha' : 'v.fecha_venta::date';
        return $this->q(
            "SELECT * FROM (
                SELECT e.id, e.codigo, e.nombre, e.tipo, e.fecha, e.estado, e.invitados, e.total, e.origen, e.vendedor_id,
                       u.nombre AS vendedor, u.rol AS vendedor_rol, c.nombre AS cliente, " . self::SQL_FECHA_VENTA . " AS fecha_venta, " . self::SQL_SIN_IVA . " AS sin_iva
                  FROM eventos e JOIN negocios n ON n.id = e.negocio_id JOIN clientes c ON c.id = e.cliente_id
             LEFT JOIN usuarios u ON u.id = e.vendedor_id
                 WHERE e.negocio_id = ? AND e.estado IN ('separado','confirmado','realizado')) v
              WHERE $col BETWEEN ?::date AND ?::date
           ORDER BY v.fecha_venta",
            [$negocioId, $desde, $hasta]
        )->fetchAll();
    }

    /** Resumen de ventas del periodo: por fecha de venta, por fecha del evento y por mes (para el gráfico). */
    public function ventas(int $negocioId, string $desde, string $hasta): array
    {
        $porVenta = $this->vendidos($negocioId, $desde, $hasta, 'venta');
        $porEvento = $this->vendidos($negocioId, $desde, $hasta, 'evento');
        $suma = fn(array $l, string $k) => round(array_sum(array_map(fn($r) => (float) $r[$k], $l)), 2);
        $meses = self::mesesDe($desde, $hasta);
        $todos = $this->vendidos($negocioId, $meses[0] . '-01', $hasta, 'venta');
        $porMes = array_fill_keys($meses, ['cantidad' => 0, 'valor' => 0.0]);
        foreach ($todos as $r) {
            $m = substr((string) $r['fecha_venta'], 0, 7);
            if (isset($porMes[$m])) {
                $porMes[$m]['cantidad']++;
                $porMes[$m]['valor'] = round($porMes[$m]['valor'] + (float) $r['total'], 2);
            }
        }
        $invitados = array_sum(array_map(fn($r) => (int) $r['invitados'], $porVenta));
        $valor = $suma($porVenta, 'total');
        return [
            'cantidad' => count($porVenta), 'valor' => $valor, 'sin_iva' => $suma($porVenta, 'sin_iva'),
            'lista' => $porVenta,
            'por_evento' => ['cantidad' => count($porEvento), 'valor' => $suma($porEvento, 'total'), 'lista' => $porEvento],
            'por_mes' => $porMes,
            'ticket' => count($porVenta) ? round($valor / count($porVenta), 2) : 0.0,
            'por_invitado' => $invitados > 0 ? round($valor / $invitados, 2) : 0.0,
            'invitados' => $invitados,
        ];
    }

    // ------------------------------------------------------------------
    // Cobrado y por cobrar
    // ------------------------------------------------------------------

    public function cobrado(int $negocioId, string $desde, string $hasta): array
    {
        $meses = self::mesesDe($desde, $hasta);
        $filas = $this->q("SELECT to_char(fecha, 'YYYY-MM') AS mes, sum(monto) AS total, count(*) AS pagos FROM pagos
                            WHERE negocio_id = ? AND tipo = 'evento' AND estado = 'confirmado' AND fecha >= ?::date AND fecha < ?::date + 1
                         GROUP BY 1", [$negocioId, $meses[0] . '-01', $hasta])->fetchAll();
        $porMes = array_fill_keys($meses, 0.0);
        foreach ($filas as $f) if (isset($porMes[$f['mes']])) $porMes[$f['mes']] = round((float) $f['total'], 2);
        $periodo = $this->q("SELECT metodo, sum(monto) AS total, count(*) AS pagos FROM pagos
                              WHERE negocio_id = ? AND tipo = 'evento' AND estado = 'confirmado' AND fecha >= ?::date AND fecha < ?::date + 1
                           GROUP BY metodo ORDER BY 2 DESC", [$negocioId, $desde, $hasta])->fetchAll();
        $total = round(array_sum(array_map(fn($r) => (float) $r['total'], $periodo)), 2);
        return ['total' => $total, 'pagos' => array_sum(array_map(fn($r) => (int) $r['pagos'], $periodo)),
                'por_metodo' => $periodo, 'por_mes' => $porMes];
    }

    /** Lo que falta cobrar de los eventos vendidos (y cuánto ya está vencido). */
    public function porCobrar(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoyTxt = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $lista = $this->q(
            "SELECT e.id, e.codigo, e.nombre, e.fecha, e.estado, e.total, c.nombre AS cliente, c.telefono,
                    COALESCE((SELECT sum(p.monto) FROM pagos p WHERE p.evento_id = e.id AND p.tipo = 'evento' AND p.estado = 'confirmado'),0) AS pagado,
                    COALESCE((SELECT sum(cu.monto - cu.pagado) FROM cuotas cu WHERE cu.evento_id = e.id AND cu.vence < ?::date AND cu.pagado < cu.monto),0) AS vencido
               FROM eventos e JOIN clientes c ON c.id = e.cliente_id
              WHERE e.negocio_id = ? AND e.estado IN ('separado','confirmado','realizado') AND e.total > 0
           ORDER BY e.fecha", [$hoyTxt, $negocioId])->fetchAll();
        $res = [];
        $saldo = 0.0;
        $vencido = 0.0;
        foreach ($lista as $r) {
            $r['saldo'] = round((float) $r['total'] - (float) $r['pagado'], 2);
            if ($r['saldo'] <= 0) continue;
            $r['vencido'] = round((float) $r['vencido'], 2);
            $saldo += $r['saldo'];
            $vencido += $r['vencido'];
            $res[] = $r;
        }
        return ['saldo' => round($saldo, 2), 'vencido' => round($vencido, 2), 'lista' => $res];
    }

    // ------------------------------------------------------------------
    // Ocupación de los próximos 12 meses
    // ------------------------------------------------------------------

    public function ocupacion(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $desde = $hoy->format('Y-m-d');
        $hasta = $hoy->modify('+12 months')->modify('-1 day')->format('Y-m-d');
        // Sábados del periodo, por mes
        $sabados = [];
        $d = $hoy;
        while ((int) $d->format('w') !== 6) $d = $d->modify('+1 day');
        for (; $d->format('Y-m-d') <= $hasta; $d = $d->modify('+7 days')) $sabados[$d->format('Y-m')][] = $d->format('Y-m-d');
        $totalSabados = array_sum(array_map('count', $sabados));

        $filas = $this->q(
            "SELECT DISTINCT ee.espacio_id, e.fecha FROM evento_espacios ee JOIN eventos e ON e.id = ee.evento_id
              WHERE ee.negocio_id = ? AND ee.bloquea AND e.estado IN ('separado','confirmado','realizado')
                AND e.fecha BETWEEN ?::date AND ?::date", [$negocioId, $desde, $hasta])->fetchAll();
        $porEspacio = [];
        $fechasNegocio = [];
        foreach ($filas as $f) {
            $porEspacio[(int) $f['espacio_id']][$f['fecha']] = true;
            $fechasNegocio[$f['fecha']] = true;
        }
        $espacios = $this->q('SELECT id, nombre, color FROM espacios WHERE negocio_id = ? AND activo ORDER BY orden, id', [$negocioId])->fetchAll();
        $esSabado = fn(string $f) => (int) (new DateTimeImmutable($f))->format('w') === 6;
        $lista = [];
        foreach ($espacios as $e) {
            $fechas = array_keys($porEspacio[(int) $e['id']] ?? []);
            $sab = count(array_filter($fechas, $esSabado));
            $lista[] = ['id' => (int) $e['id'], 'nombre' => $e['nombre'], 'color' => $e['color'], 'fechas' => count($fechas),
                        'sabados' => $sab, 'pct_sabados' => $totalSabados ? (int) round($sab * 100 / $totalSabados) : 0];
        }
        $porMes = [];
        foreach ($sabados as $mes => $dias) {
            $vendidos = count(array_filter($dias, fn($f) => isset($fechasNegocio[$f])));
            $porMes[$mes] = ['sabados' => count($dias), 'vendidos' => $vendidos, 'pct' => (int) round($vendidos * 100 / count($dias))];
        }
        $sabVendidos = count(array_filter(array_keys($fechasNegocio), $esSabado));
        return [
            'desde' => $desde, 'hasta' => $hasta, 'espacios' => $lista, 'por_mes' => $porMes,
            'sabados' => $totalSabados, 'sabados_vendidos' => $sabVendidos,
            'pct_sabados' => $totalSabados ? (int) round($sabVendidos * 100 / $totalSabados) : 0,
            'fechas_vendidas' => count($fechasNegocio),
        ];
    }

    // ------------------------------------------------------------------
    // Embudo y conversión (consultas que llegaron en el periodo)
    // ------------------------------------------------------------------

    public function conversion(int $negocioId, string $desde, string $hasta): array
    {
        $filas = $this->q(
            "SELECT e.estado, e.origen, e.tipo, e.motivo_perdida, e.total, COALESCE(u.nombre, 'Sin vendedor') AS vendedor
               FROM eventos e LEFT JOIN usuarios u ON u.id = e.vendedor_id
              WHERE e.negocio_id = ? AND e.creado_en >= ?::date AND e.creado_en < ?::date + 1 ORDER BY e.id", [$negocioId, $desde, $hasta])->fetchAll();
        $vacio = fn() => ['consultas' => 0, 'vendidos' => 0, 'perdidos' => 0, 'abiertos' => 0, 'valor' => 0.0, 'pct' => 0];
        $total = $vacio();
        $porOrigen = $porTipo = $porVendedor = [];
        $embudo = array_fill_keys(array_keys(Eventos::ESTADOS), 0);
        $motivos = [];
        $sumar = function (array &$g, array $r) {
            $g['consultas']++;
            if (in_array($r['estado'], self::VENDIDOS, true)) { $g['vendidos']++; $g['valor'] += (float) $r['total']; }
            elseif ($r['estado'] === 'perdido' || $r['estado'] === 'cancelado') $g['perdidos']++;
            else $g['abiertos']++;
        };
        foreach ($filas as $r) {
            $embudo[$r['estado']]++;
            $sumar($total, $r);
            $o = Eventos::ORIGENES[$r['origen'] ?? ''] ?? 'Sin dato';
            $t = Eventos::TIPOS[$r['tipo']] ?? $r['tipo'];
            $porOrigen[$o] ??= $vacio(); $sumar($porOrigen[$o], $r);
            $porTipo[$t] ??= $vacio(); $sumar($porTipo[$t], $r);
            $porVendedor[$r['vendedor']] ??= $vacio(); $sumar($porVendedor[$r['vendedor']], $r);
            if ($r['estado'] === 'perdido') {
                $m = trim((string) $r['motivo_perdida']) ?: 'Sin motivo';
                $clave = mb_strtolower($m);
                $motivos[$clave] ??= ['motivo' => $m, 'cantidad' => 0];
                $motivos[$clave]['cantidad']++;
            }
        }
        $pct = function (array &$g) { $g['pct'] = $g['consultas'] ? (int) round($g['vendidos'] * 100 / $g['consultas']) : 0; $g['valor'] = round($g['valor'], 2); };
        $pct($total);
        foreach ([&$porOrigen, &$porTipo, &$porVendedor] as &$grupo) {
            foreach ($grupo as &$g) $pct($g);
            unset($g);
            uasort($grupo, fn($a, $b) => [$b['vendidos'], $b['consultas']] <=> [$a['vendidos'], $a['consultas']]);
        }
        unset($grupo);
        usort($motivos, fn($a, $b) => $b['cantidad'] <=> $a['cantidad']);
        // Embudo acumulado: cuántas consultas llegaron al menos a cada paso
        $llegaron = [
            'Consultas' => $total['consultas'],
            'Cotizadas' => $total['consultas'] - $embudo['consulta'] - $this->perdidasSinCotizar($negocioId, $desde, $hasta),
            'Vendidas (separaron)' => $total['vendidos'],
            'Confirmadas o realizadas' => $embudo['confirmado'] + $embudo['realizado'],
        ];
        return ['total' => $total, 'embudo' => $embudo, 'llegaron' => $llegaron, 'por_origen' => $porOrigen, 'por_tipo' => $porTipo,
                'por_vendedor' => $porVendedor, 'motivos' => $motivos];
    }

    /** Perdidas que nunca tuvieron una cotización (para el embudo). */
    private function perdidasSinCotizar(int $negocioId, string $desde, string $hasta): int
    {
        return (int) $this->q("SELECT count(*) FROM eventos e WHERE e.negocio_id = ? AND e.estado IN ('perdido','cancelado')
                                 AND e.creado_en >= ?::date AND e.creado_en < ?::date + 1
                                 AND NOT EXISTS (SELECT 1 FROM cotizaciones c WHERE c.evento_id = e.id)", [$negocioId, $desde, $hasta])->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Rentabilidad por evento (eventos del periodo, por fecha del evento)
    // ------------------------------------------------------------------

    public function rentabilidad(int $negocioId, string $desde, string $hasta): array
    {
        $filas = $this->q(
            "SELECT e.id, e.codigo, e.nombre, e.fecha, e.estado, e.invitados, e.total, e.costo_estimado, c.nombre AS cliente,
                    " . self::SQL_SIN_IVA . " AS sin_iva,
                    COALESCE((SELECT sum(g.monto) FROM gastos g WHERE g.evento_id = e.id),0) AS gastos,
                    COALESCE((SELECT sum(ep.pago) FROM evento_personal ep WHERE ep.evento_id = e.id),0) AS personal,
                    " . Compras::sqlCostoEvento('e.id') . " AS compras
               FROM eventos e JOIN negocios n ON n.id = e.negocio_id JOIN clientes c ON c.id = e.cliente_id
              WHERE e.negocio_id = ? AND e.estado IN ('separado','confirmado','realizado') AND e.fecha BETWEEN ?::date AND ?::date
           ORDER BY e.fecha", [$negocioId, $desde, $hasta])->fetchAll();
        $tot = ['sin_iva' => 0.0, 'gastos' => 0.0, 'compras' => 0.0, 'personal' => 0.0, 'costo' => 0.0, 'ganancia' => 0.0];
        foreach ($filas as &$r) {
            $r['sin_iva'] = round((float) $r['sin_iva'], 2);
            $r['gastos'] = round((float) $r['gastos'], 2);
            $r['compras'] = round((float) $r['compras'], 2);   // compras a proveedores del evento (060), aparte de los gastos
            $r['personal'] = round((float) $r['personal'], 2);
            // Si todavía no se registraron gastos ni compras, se usa el costo estimado de la cotización
            $r['usa_estimado'] = $r['gastos'] + $r['compras'] <= 0 && (float) $r['costo_estimado'] > 0;
            $r['costo'] = $r['usa_estimado'] ? round((float) $r['costo_estimado'], 2) : round($r['gastos'] + $r['compras'], 2);
            $r['ganancia'] = round($r['sin_iva'] - $r['costo'] - $r['personal'], 2);
            $r['margen'] = $r['sin_iva'] > 0 ? (int) round($r['ganancia'] * 100 / $r['sin_iva']) : 0;
            foreach (['sin_iva', 'gastos', 'compras', 'personal', 'costo', 'ganancia'] as $k) $tot[$k] += $r[$k];
        }
        unset($r);
        $tot = array_map(fn($v) => round($v, 2), $tot);
        $tot['margen'] = $tot['sin_iva'] > 0 ? (int) round($tot['ganancia'] * 100 / $tot['sin_iva']) : 0;
        $generales = (float) $this->q('SELECT COALESCE(sum(monto),0) FROM gastos WHERE negocio_id = ? AND evento_id IS NULL AND fecha BETWEEN ?::date AND ?::date',
                                      [$negocioId, $desde, $hasta])->fetchColumn();
        // Compras generales del negocio (sin evento) también son gastos generales
        $generales += (float) $this->q("SELECT COALESCE(sum(COALESCE(c.factura_total, c.total) - " . Compras::SQL_DESCUENTOS . "),0) FROM compras c
                                         WHERE c.negocio_id = ? AND c.evento_id IS NULL AND " . Compras::SQL_ACTIVAS . " AND c.fecha BETWEEN ?::date AND ?::date",
                                       [$negocioId, $desde, $hasta])->fetchColumn();
        return ['lista' => $filas, 'total' => $tot, 'gastos_generales' => round($generales, 2),
                'ganancia_neta' => round($tot['ganancia'] - $generales, 2)];
    }

    // ------------------------------------------------------------------
    // Comisión de vendedores (sobre lo vendido sin IVA, por fecha de venta)
    // ------------------------------------------------------------------

    public function comisiones(int $negocioId, string $desde, string $hasta): array
    {
        $pct = (float) $this->q('SELECT comision_vendedor_pct FROM negocios WHERE id = ?', [$negocioId])->fetchColumn();
        $porVendedor = [];
        foreach ($this->vendidos($negocioId, $desde, $hasta, 'venta') as $r) {
            $k = (int) ($r['vendedor_id'] ?? 0);
            $porVendedor[$k] ??= ['vendedor_id' => $k ?: null, 'vendedor' => $r['vendedor'] ?? 'Sin vendedor', 'rol' => $r['vendedor_rol'],
                                  'eventos' => 0, 'vendido' => 0.0, 'sin_iva' => 0.0];
            $porVendedor[$k]['eventos']++;
            $porVendedor[$k]['vendido'] += (float) $r['total'];
            $porVendedor[$k]['sin_iva'] += (float) $r['sin_iva'];
        }
        foreach ($porVendedor as &$v) {
            $v['vendido'] = round($v['vendido'], 2);
            $v['sin_iva'] = round($v['sin_iva'], 2);
            // El dueño no se paga comisión a sí mismo
            $v['comision'] = $v['vendedor_id'] && $v['rol'] === 'vendedor' ? round($v['sin_iva'] * $pct / 100, 2) : 0.0;
        }
        unset($v);
        usort($porVendedor, fn($a, $b) => $b['sin_iva'] <=> $a['sin_iva']);
        return ['pct' => $pct, 'lista' => $porVendedor, 'total' => round(array_sum(array_column($porVendedor, 'comision')), 2)];
    }

    // ------------------------------------------------------------------
    // Inicio y reporte del mes
    // ------------------------------------------------------------------

    /** Cifras del panel de inicio. */
    public function cifrasInicio(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $mesIni = $hoy->modify('first day of this month')->format('Y-m-d');
        $mesFin = $hoy->modify('last day of this month')->format('Y-m-d');
        $c = fn(string $sql, array $p) => (int) $this->q($sql, $p)->fetchColumn();
        $vendidos = "estado IN ('separado','confirmado','realizado')";
        $vencido = (float) $this->q("SELECT COALESCE(sum(cu.monto - cu.pagado),0) FROM cuotas cu JOIN eventos e ON e.id = cu.evento_id
                                      WHERE e.negocio_id = ? AND cu.pagado < cu.monto AND cu.vence < ?::date
                                        AND e.estado IN ('cotizado','separado','confirmado','realizado')", [$negocioId, $hoy->format('Y-m-d')])->fetchColumn();
        return [
            'eventos_mes' => $c("SELECT count(*) FROM eventos WHERE negocio_id = ? AND $vendidos AND fecha BETWEEN ?::date AND ?::date", [$negocioId, $mesIni, $mesFin]),
            'proximos_30' => $c("SELECT count(*) FROM eventos WHERE negocio_id = ? AND estado IN ('separado','confirmado') AND fecha BETWEEN ?::date AND ?::date",
                                [$negocioId, $hoy->format('Y-m-d'), $hoy->modify('+30 days')->format('Y-m-d')]),
            'vencido' => round($vencido, 2),
            'comprobantes' => $c("SELECT count(*) FROM pagos WHERE negocio_id = ? AND estado = 'por_revisar'", [$negocioId]),
            'consultas_nuevas' => count($this->consultasSinAtender($negocioId)),
        ];
    }

    /** Consultas que nadie ha atendido todavía (sin cotización ni nota de seguimiento). */
    public function consultasSinAtender(int $negocioId): array
    {
        return $this->q("SELECT e.id, e.codigo, e.nombre, e.fecha, e.invitados, e.origen, e.creado_en, c.nombre AS cliente, c.telefono
                           FROM eventos e JOIN clientes c ON c.id = e.cliente_id
                          WHERE e.negocio_id = ? AND e.estado = 'consulta'
                            AND NOT EXISTS (SELECT 1 FROM cotizaciones q WHERE q.evento_id = e.id)
                            AND NOT EXISTS (SELECT 1 FROM actividades a WHERE a.evento_id = e.id AND a.tipo <> 'sistema')
                       ORDER BY e.creado_en DESC LIMIT 50", [$negocioId])->fetchAll();
    }

    /** Fechas cotizadas cuya retención vence pronto (hoy hasta dentro de N días). */
    public function retenidasPorVencer(int $negocioId, int $dias = 2, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        return $this->q("SELECT e.id, e.codigo, e.nombre, e.fecha, e.retenida_hasta, c.nombre AS cliente, c.telefono
                           FROM eventos e JOIN clientes c ON c.id = e.cliente_id
                          WHERE e.negocio_id = ? AND e.estado = 'cotizado' AND e.retenida_hasta BETWEEN ?::date AND ?::date
                       ORDER BY e.retenida_hasta, e.fecha",
                        [$negocioId, $hoy->format('Y-m-d'), $hoy->modify("+$dias days")->format('Y-m-d')])->fetchAll();
    }

    /** Resumen de un mes (AAAA-MM) para el reporte que llega al dueño el día 1. */
    public function resumenMes(int $negocioId, string $mes, ?DateTimeImmutable $hoy = null): array
    {
        $desde = $mes . '-01';
        $hasta = (new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
        $v = $this->ventas($negocioId, $desde, $hasta);
        $cob = $this->cobrado($negocioId, $desde, $hasta);
        $conv = $this->conversion($negocioId, $desde, $hasta);
        $realizados = (int) $this->q("SELECT count(*) FROM eventos WHERE negocio_id = ? AND estado = 'realizado' AND fecha BETWEEN ?::date AND ?::date",
                                     [$negocioId, $desde, $hasta])->fetchColumn();
        $rent = $this->rentabilidad($negocioId, $desde, $hasta);
        $ocup = $this->ocupacion($negocioId, $hoy);
        return [
            'mes' => $mes, 'vendidos' => $v['cantidad'], 'vendido' => $v['valor'], 'ticket' => $v['ticket'],
            'cobrado' => $cob['total'], 'consultas' => $conv['total']['consultas'], 'conversion' => $conv['total']['pct'],
            'perdidos' => $conv['total']['perdidos'], 'motivo_principal' => $conv['motivos'][0]['motivo'] ?? null,
            'realizados' => $realizados, 'ganancia' => $rent['total']['ganancia'],
            'por_cobrar' => $this->porCobrar($negocioId, $hoy)['saldo'], 'pct_sabados' => $ocup['pct_sabados'],
            'mejor_origen' => array_key_first(array_filter($conv['por_origen'], fn($g) => $g['vendidos'] > 0)),
        ] + (new Encuestas($this->db))->resumenMes($negocioId, $mes);   // encuestas y NPS de los eventos del mes (migración 070)
    }

    public static function textoResumenMes(array $r, string $negocio): string
    {
        $d = fn($x) => '$' . number_format((float) $x, 2, ',', '.');
        $t = '📊 Reporte de ' . self::nombreMes($r['mes']) . " — $negocio\n\n"
           . "Eventos vendidos: {$r['vendidos']} por {$d($r['vendido'])}" . ($r['vendidos'] ? " (promedio {$d($r['ticket'])})" : '') . "\n"
           . "Cobrado en el mes: {$d($r['cobrado'])}\n"
           . "Consultas nuevas: {$r['consultas']} · se vendió el {$r['conversion']} %\n"
           . "Eventos realizados: {$r['realizados']}" . ($r['realizados'] ? " · ganancia estimada {$d($r['ganancia'])}" : '') . "\n"
           . "Por cobrar de eventos vendidos: {$d($r['por_cobrar'])}\n"
           . "Sábados vendidos en los próximos 12 meses: {$r['pct_sabados']} %\n";
        if ($r['mejor_origen']) $t .= "Lo que más vende: {$r['mejor_origen']}\n";
        if ($r['perdidos'] && $r['motivo_principal']) $t .= "Se perdieron {$r['perdidos']}; el motivo más común: {$r['motivo_principal']}\n";
        if (!empty($r['encuestas'])) $t .= "Satisfacción de los clientes: NPS {$r['nps']} ({$r['encuestas']} " . ($r['encuestas'] === 1 ? 'encuesta' : 'encuestas') . ")\n";
        return $t . "\nMira el detalle en TuRenta → Reportes.";
    }

    // ------------------------------------------------------------------
    // Exportar a CSV (Excel en español: separador ; y BOM UTF-8)
    // ------------------------------------------------------------------

    /** @return array{0:list<string>, 1:list<list<string|int|float|null>>} encabezados y filas */
    public function exportar(int $negocioId, string $tipo, string $desde, string $hasta, ?DateTimeImmutable $hoy = null): array
    {
        $f = fn(?string $x) => $x ? (new DateTimeImmutable($x))->format('d/m/Y') : '';
        $n = fn($x) => number_format((float) $x, 2, ',', '');
        switch ($tipo) {
            case 'eventos':
                $filas = $this->q(
                    "SELECT e.codigo, e.nombre, e.tipo, e.fecha, e.hora_inicio, e.hora_fin, e.estado, e.invitados, c.nombre AS cliente, c.telefono,
                            (SELECT string_agg(es.nombre, ', ' ORDER BY es.orden) FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = e.id) AS espacios,
                            e.origen, u.nombre AS vendedor, e.total,
                            COALESCE((SELECT sum(p.monto) FROM pagos p WHERE p.evento_id = e.id AND p.tipo = 'evento' AND p.estado = 'confirmado'),0) AS pagado,
                            e.motivo_perdida, e.creado_en
                       FROM eventos e JOIN clientes c ON c.id = e.cliente_id LEFT JOIN usuarios u ON u.id = e.vendedor_id
                      WHERE e.negocio_id = ? AND e.fecha BETWEEN ?::date AND ?::date ORDER BY e.fecha, e.hora_inicio", [$negocioId, $desde, $hasta])->fetchAll();
                return [['Código', 'Evento', 'Tipo', 'Fecha', 'Inicio', 'Fin', 'Estado', 'Invitados', 'Cliente', 'Celular', 'Espacios', 'Cómo llegó',
                         'Vendedor', 'Total', 'Pagado', 'Saldo', 'Motivo de pérdida', 'Registrado'],
                        array_map(fn($r) => [$r['codigo'], $r['nombre'], Eventos::TIPOS[$r['tipo']] ?? $r['tipo'], $f($r['fecha']), substr((string) $r['hora_inicio'], 0, 5),
                            substr((string) $r['hora_fin'], 0, 5), Eventos::ESTADOS[$r['estado']] ?? $r['estado'], (int) $r['invitados'], $r['cliente'], $r['telefono'],
                            $r['espacios'], Eventos::ORIGENES[$r['origen'] ?? ''] ?? '', $r['vendedor'], $n($r['total']), $n($r['pagado']),
                            $n(max(0, (float) $r['total'] - (float) $r['pagado'])), $r['motivo_perdida'], $f($r['creado_en'])], $filas)];
            case 'pagos':
                $filas = $this->q(
                    "SELECT p.fecha, p.numero_recibo, p.tipo, p.metodo, p.monto, p.referencia, p.estado, p.origen, e.codigo, e.nombre AS evento, c.nombre AS cliente, u.nombre AS registrado
                       FROM pagos p JOIN eventos e ON e.id = p.evento_id JOIN clientes c ON c.id = e.cliente_id LEFT JOIN usuarios u ON u.id = p.registrado_por
                      WHERE p.negocio_id = ? AND p.fecha >= ?::date AND p.fecha < ?::date + 1 AND p.estado IN ('confirmado','por_revisar','anulado')
                   ORDER BY p.fecha, p.id", [$negocioId, $desde, $hasta])->fetchAll();
                return [['Fecha', 'Recibo', 'Tipo', 'Forma de pago', 'Valor', 'Referencia', 'Estado', 'Quién lo registró', 'Código', 'Evento', 'Cliente'],
                        array_map(fn($r) => [$f($r['fecha']), $r['numero_recibo'], Pagos::TIPOS[$r['tipo']] ?? $r['tipo'], Pagos::METODOS[$r['metodo']] ?? $r['metodo'],
                            $n($r['monto']), $r['referencia'], Pagos::ESTADOS[$r['estado']] ?? $r['estado'],
                            $r['registrado'] ?? ($r['origen'] === 'cliente' ? 'Cliente' : ($r['origen'] === 'payphone' ? 'Payphone' : '')),
                            $r['codigo'], $r['evento'], $r['cliente']], $filas)];
            case 'clientes':
                $filas = $this->q(
                    "SELECT c.nombre, c.identificacion, c.telefono, c.email, c.direccion, c.origen, c.creado_en,
                            count(e.id) AS eventos, COALESCE(sum(e.total) FILTER (WHERE e.estado IN ('separado','confirmado','realizado')),0) AS comprado,
                            max(e.fecha) AS ultimo
                       FROM clientes c LEFT JOIN eventos e ON e.cliente_id = c.id
                      WHERE c.negocio_id = ? AND (c.creado_en >= ?::date AND c.creado_en < ?::date + 1 OR e.fecha BETWEEN ?::date AND ?::date)
                   GROUP BY c.id ORDER BY c.nombre", [$negocioId, $desde, $hasta, $desde, $hasta])->fetchAll();
                return [['Nombre', 'Cédula o RUC', 'Celular', 'Correo', 'Dirección', 'Cómo llegó', 'Registrado', 'Eventos', 'Comprado', 'Último evento'],
                        array_map(fn($r) => [$r['nombre'], $r['identificacion'], $r['telefono'], $r['email'], $r['direccion'],
                            Eventos::ORIGENES[$r['origen'] ?? ''] ?? '', $f($r['creado_en']), (int) $r['eventos'], $n($r['comprado']), $f($r['ultimo'])], $filas)];
            case 'cuotas':
                $filas = $this->q(
                    "SELECT cu.vence, cu.concepto, cu.monto, cu.pagado, e.codigo, e.nombre AS evento, e.fecha, e.estado, c.nombre AS cliente, c.telefono
                       FROM cuotas cu JOIN eventos e ON e.id = cu.evento_id JOIN clientes c ON c.id = e.cliente_id
                      WHERE e.negocio_id = ? AND cu.vence BETWEEN ?::date AND ?::date AND e.estado IN ('cotizado','separado','confirmado','realizado')
                   ORDER BY cu.vence, e.fecha", [$negocioId, $desde, $hasta])->fetchAll();
                $hoyTxt = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
                return [['Vence', 'Concepto', 'Valor', 'Pagado', 'Saldo', 'Situación', 'Código', 'Evento', 'Fecha del evento', 'Cliente', 'Celular'],
                        array_map(fn($r) => [$f($r['vence']), $r['concepto'], $n($r['monto']), $n($r['pagado']), $n((float) $r['monto'] - (float) $r['pagado']),
                            (float) $r['pagado'] >= (float) $r['monto'] ? 'Pagada' : ($r['vence'] < $hoyTxt ? 'Vencida' : 'Pendiente'),
                            $r['codigo'], $r['evento'], $f($r['fecha']), $r['cliente'], $r['telefono']], $filas)];
            case 'gastos':
                $filas = $this->q(
                    "SELECT g.fecha, g.categoria, g.concepto, g.monto, pr.nombre AS proveedor, e.codigo, e.nombre AS evento, u.nombre AS registrado
                       FROM gastos g LEFT JOIN eventos e ON e.id = g.evento_id LEFT JOIN proveedores pr ON pr.id = g.proveedor_id
                  LEFT JOIN usuarios u ON u.id = g.registrado_por
                      WHERE g.negocio_id = ? AND g.fecha BETWEEN ?::date AND ?::date ORDER BY g.fecha, g.id", [$negocioId, $desde, $hasta])->fetchAll();
                return [['Fecha', 'Categoría', 'Concepto', 'Valor', 'Proveedor', 'Código', 'Evento', 'Registrado por'],
                        array_map(fn($r) => [$f($r['fecha']), Cotizaciones::CATEGORIAS[$r['categoria']] ?? $r['categoria'], $r['concepto'], $n($r['monto']),
                            $r['proveedor'], $r['codigo'] ?? 'Gasto general', $r['evento'], $r['registrado']], $filas)];
        }
        throw new RuntimeException('Ese reporte no existe.');
    }

    /** Arma el CSV: BOM UTF-8, separador ";" y fin de línea de Windows (así Excel en español lo abre bien). */
    public static function csv(array $encabezados, array $filas): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        $limpiar = function ($v) {
            $v = (string) ($v ?? '');
            // Evita que Excel ejecute fórmulas escritas en los datos (=, +, -, @)
            if ($v !== '' && in_array($v[0], ['=', '+', '@'], true)) $v = "'" . $v;
            if ($v !== '' && $v[0] === '-' && !is_numeric(str_replace(',', '.', $v))) $v = "'" . $v;
            return str_replace(["\r\n", "\r", "\n"], ' ', $v);
        };
        foreach (array_merge([$encabezados], $filas) as $fila) {
            fputcsv($h, array_map($limpiar, $fila), ';', '"', '');
            fseek($h, -1, SEEK_CUR);
            fwrite($h, "\r\n");
        }
        rewind($h);
        $s = (string) stream_get_contents($h);
        fclose($h);
        return $s;
    }

    // ------------------------------------------------------------------
    // Calendario para Google Calendar (iCalendar, enlace secreto)
    // ------------------------------------------------------------------

    public function negocioPorTokenIcal(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
        $n = $this->q('SELECT id, nombre FROM negocios WHERE token_ical = ?', [$token])->fetch();
        return $n ?: null;
    }

    private static function escIcs(string $t): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', ''], $t);
    }

    /** Corta las líneas a 75 bytes (regla de iCalendar) sin partir letras con tilde. */
    private static function plegar(string $linea): string
    {
        $out = '';
        $actual = '';
        foreach (mb_str_split($linea) as $ch) {
            if (strlen($actual) + strlen($ch) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $actual;
                $actual = '';
            }
            $actual .= $ch;
        }
        return $out . ($out === '' ? '' : "\r\n ") . $actual . "\r\n";
    }

    /**
     * Eventos separados, confirmados y realizados (y cotizados con fecha retenida, marcados RETENIDO)
     * de los últimos 3 meses y los próximos 24, en hora de Ecuador.
     */
    public function calendarioIcs(int $negocioId, string $urlBase, ?DateTimeImmutable $hoy = null): string
    {
        $hoy ??= new DateTimeImmutable('today');
        $n = $this->q('SELECT nombre FROM negocios WHERE id = ?', [$negocioId])->fetch();
        if (!$n) throw new RuntimeException('Negocio no encontrado.');
        $filas = $this->q(
            "SELECT e.id, e.nombre, e.fecha, e.hora_inicio, e.hora_fin, e.estado, e.invitados, e.retenida_hasta, e.actualizado_en, c.nombre AS cliente,
                    (SELECT string_agg(es.nombre, ', ' ORDER BY es.orden) FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = e.id) AS espacios
               FROM eventos e JOIN clientes c ON c.id = e.cliente_id
              WHERE e.negocio_id = ? AND e.fecha BETWEEN ?::date AND ?::date
                AND (e.estado IN ('separado','confirmado','realizado') OR (e.estado = 'cotizado' AND e.retenida_hasta >= ?::date))
           ORDER BY e.fecha, e.hora_inicio",
            [$negocioId, $hoy->modify('-3 months')->format('Y-m-d'), $hoy->modify('+24 months')->format('Y-m-d'), $hoy->format('Y-m-d')]
        )->fetchAll();
        $host = parse_url($urlBase, PHP_URL_HOST) ?: 'turenta.tukanec.com';
        $tz = new \DateTimeZone('America/Guayaquil');
        $s = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tukan//TuRenta//ES\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
           . self::plegar('X-WR-CALNAME:' . self::escIcs($n['nombre'] . ' · TuRenta'))
           . "X-WR-TIMEZONE:America/Guayaquil\r\nREFRESH-INTERVAL;VALUE=DURATION:PT1H\r\nX-PUBLISHED-TTL:PT1H\r\n"
           . "BEGIN:VTIMEZONE\r\nTZID:America/Guayaquil\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:-0500\r\nTZOFFSETTO:-0500\r\nTZNAME:-05\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
        foreach ($filas as $e) {
            $ini = new DateTimeImmutable($e['fecha'] . ' ' . substr((string) $e['hora_inicio'], 0, 5), $tz);
            $fin = new DateTimeImmutable($e['fecha'] . ' ' . substr((string) $e['hora_fin'], 0, 5), $tz);
            if ($fin <= $ini) $fin = $fin->modify('+1 day');   // pasa la medianoche
            $retenido = $e['estado'] === 'cotizado';
            $titulo = ($retenido ? 'RETENIDO · ' : '') . $e['nombre'];
            $desc = 'Cliente: ' . $e['cliente'] . "\nEspacios: " . ($e['espacios'] ?: '—') . "\nInvitados: " . (int) $e['invitados']
                  . "\nEstado: " . (Eventos::ESTADOS[$e['estado']] ?? $e['estado'])
                  . ($retenido ? ' (fecha retenida hasta el ' . (new DateTimeImmutable($e['retenida_hasta']))->format('d/m/Y') . ', sin pago)' : '')
                  . "\nAbrir: " . rtrim($urlBase, '/') . '/?r=evento&id=' . (int) $e['id'];
            $s .= "BEGIN:VEVENT\r\n"
                . 'UID:evento-' . (int) $e['id'] . '@' . $host . "\r\n"
                . 'DTSTAMP:' . (new DateTimeImmutable((string) $e['actualizado_en'], $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z') . "\r\n"
                . 'DTSTART;TZID=America/Guayaquil:' . $ini->format('Ymd\THis') . "\r\n"
                . 'DTEND;TZID=America/Guayaquil:' . $fin->format('Ymd\THis') . "\r\n"
                . self::plegar('SUMMARY:' . self::escIcs($titulo))
                . ($e['espacios'] ? self::plegar('LOCATION:' . self::escIcs((string) $e['espacios'])) : '')
                . self::plegar('DESCRIPTION:' . self::escIcs($desc))
                . self::plegar('URL:' . rtrim($urlBase, '/') . '/?r=evento&id=' . (int) $e['id'])
                . 'STATUS:' . ($retenido ? 'TENTATIVE' : 'CONFIRMED') . "\r\n"
                . 'TRANSP:OPAQUE' . "\r\n"
                . "END:VEVENT\r\n";
        }
        return $s . "END:VCALENDAR\r\n";
    }
}
