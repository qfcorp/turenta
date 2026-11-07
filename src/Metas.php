<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Metas de ventas (como Planning Pod o Perfect Venue): el dueño pone cuánto quiere vender cada mes
 * (monto y, si quiere, número de eventos). Hay una meta "de siempre" para todos los meses y se puede
 * poner otra para un mes en particular (diciembre, temporada alta). También una meta por vendedor.
 *
 * "Vendido" es EXACTAMENTE lo mismo que en Reportes::ventas(): eventos separados, confirmados o realizados,
 * contados en el mes en que se vendieron (Reportes::vendidos(..., 'venta')), por su total con IVA.
 * Se compara cada mes con el mismo mes del año anterior.
 */
final class Metas
{
    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    /** "2026-10" válido o error. */
    public static function mesValido(?string $mes): ?string
    {
        if ($mes === null || $mes === '') return null;
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $mes, $m) || (int) $m[1] < 2000 || (int) $m[1] > 2100) {
            throw new RuntimeException('Revisa el mes de la meta.');
        }
        return $mes;
    }

    /** Monto escrito como 1.234,50 · 1234,50 · 1234.50 · 12.000 -> float (o null si está vacío). */
    public static function monto(mixed $v): ?float
    {
        $v = str_replace([' ', '$'], '', trim((string) $v));
        if ($v === '') return null;
        if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace('.', '', $v);
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
        if (!is_numeric($v)) throw new RuntimeException('Escribe la meta en dólares (ejemplo: 15000).');
        $n = round((float) $v, 2);
        if ($n < 0 || $n > 100000000) throw new RuntimeException('Revisa el valor de la meta.');
        return $n;
    }

    private function vendedor(int $negocioId, int $usuarioId): array
    {
        $v = $this->q("SELECT id, nombre, rol FROM usuarios WHERE id = ? AND negocio_id = ? AND rol IN ('dueno','admin','vendedor')", [$usuarioId, $negocioId])->fetch();
        if (!$v) throw new RuntimeException('Ese vendedor no es de tu negocio.');
        return $v;
    }

    /** Quienes venden: el dueño, los administradores y los vendedores (activos, o con meta puesta). */
    public function vendedores(int $negocioId): array
    {
        return $this->q("SELECT u.id, u.nombre, u.rol, u.activo FROM usuarios u
                          WHERE u.negocio_id = ? AND u.rol IN ('dueno','admin','vendedor')
                            AND (u.activo OR EXISTS (SELECT 1 FROM metas_ventas m WHERE m.vendedor_id = u.id))
                       ORDER BY (u.rol = 'dueno') DESC, (u.rol = 'admin') DESC, u.nombre", [$negocioId])->fetchAll();
    }

    /**
     * Guarda una meta. $vendedorId null = del local; $mes null = la de siempre.
     * Monto vacío (o 0) y sin número de eventos = se quita esa meta.
     */
    public function guardar(int $negocioId, ?int $vendedorId, ?string $mes, mixed $monto, mixed $eventos = null): void
    {
        $mes = self::mesValido($mes);
        if ($vendedorId !== null) $this->vendedor($negocioId, $vendedorId);
        $m = self::monto($monto);
        $ev = trim((string) $eventos);
        if ($ev !== '' && (!ctype_digit($ev) || (int) $ev > 10000)) throw new RuntimeException('El número de eventos de la meta va de 0 a 10.000.');
        $ev = $ev === '' ? null : (int) $ev;
        if (!$m || $m <= 0) {
            if ($ev) throw new RuntimeException('Pon también la meta en dólares (el número de eventos es un extra).');
            $this->q('DELETE FROM metas_ventas WHERE negocio_id = ? AND vendedor_id IS NOT DISTINCT FROM ? AND mes IS NOT DISTINCT FROM ?', [$negocioId, $vendedorId, $mes]);
            return;
        }
        $this->q('INSERT INTO metas_ventas (negocio_id, vendedor_id, mes, monto, eventos) VALUES (?,?,?,?,?)
                  ON CONFLICT ON CONSTRAINT metas_ventas_unica DO UPDATE SET monto = EXCLUDED.monto, eventos = EXCLUDED.eventos, actualizado_en = now()',
                 [$negocioId, $vendedorId, $mes, $m ?? 0, $ev]);
    }

    /** Todas las metas del negocio, para la pantalla de metas: ['local' => [mes|'' => fila], 'vendedores' => [id => [mes|'' => fila]]]. */
    public function todas(int $negocioId): array
    {
        $res = ['local' => [], 'vendedores' => []];
        foreach ($this->q('SELECT vendedor_id, mes, monto, eventos FROM metas_ventas WHERE negocio_id = ? ORDER BY mes NULLS FIRST', [$negocioId])->fetchAll() as $r) {
            $fila = ['monto' => (float) $r['monto'], 'eventos' => $r['eventos'] !== null ? (int) $r['eventos'] : null];
            if ($r['vendedor_id'] === null) $res['local'][(string) $r['mes']] = $fila;
            else $res['vendedores'][(int) $r['vendedor_id']][(string) $r['mes']] = $fila;
        }
        return $res;
    }

    /** Meta de un mes: la del mes si hay, si no la de siempre. null si no hay ninguna. */
    public function metaDe(int $negocioId, ?int $vendedorId, string $mes): ?array
    {
        $r = $this->q('SELECT mes, monto, eventos FROM metas_ventas WHERE negocio_id = ? AND vendedor_id IS NOT DISTINCT FROM ? AND (mes = ? OR mes IS NULL)
                       ORDER BY mes NULLS LAST LIMIT 1', [$negocioId, $vendedorId, $mes])->fetch();
        if (!$r) return null;
        return ['monto' => (float) $r['monto'], 'eventos' => $r['eventos'] !== null ? (int) $r['eventos'] : null, 'del_mes' => $r['mes'] !== null];
    }

    /** Meses AAAA-MM entre dos fechas (máximo 36). */
    public static function meses(string $desde, string $hasta): array
    {
        $ini = (new DateTimeImmutable($desde))->modify('first day of this month');
        $fin = (new DateTimeImmutable($hasta))->modify('first day of this month');
        $res = [];
        for ($m = $ini; $m <= $fin && count($res) < 36; $m = $m->modify('+1 month')) $res[] = $m->format('Y-m');
        return $res;
    }

    private static function mesAnterior(string $mes): string
    {
        return ((int) substr($mes, 0, 4) - 1) . substr($mes, 4);
    }

    /**
     * Vendido por mes (y por vendedor) entre dos meses, con la misma regla de Reportes.
     * @return array<string, array<string, array{valor: float, cantidad: int}>>  [mes][vendedor_id|'' => …] y [mes]['*'] = todo el local
     */
    private function vendidoPorMes(int $negocioId, string $mesDesde, string $mesHasta): array
    {
        $hasta = (new DateTimeImmutable($mesHasta . '-01'))->modify('last day of this month')->format('Y-m-d');
        $res = [];
        foreach ((new Reportes($this->db))->vendidos($negocioId, $mesDesde . '-01', $hasta, 'venta') as $r) {
            $mes = substr((string) $r['fecha_venta'], 0, 7);
            foreach (['*', (string) ($r['vendedor_id'] ?? '')] as $k) {
                $res[$mes][$k] ??= ['valor' => 0.0, 'cantidad' => 0];
                $res[$mes][$k]['valor'] = round($res[$mes][$k]['valor'] + (float) $r['total'], 2);
                $res[$mes][$k]['cantidad']++;
            }
        }
        return $res;
    }

    private static function pct(float $valor, float $meta): ?int
    {
        return $meta > 0 ? (int) floor($valor * 100 / $meta + 0.0001) : null;
    }

    /** % de cambio contra el año anterior (null si el año anterior no hubo ventas). */
    public static function variacion(float $ahora, float $antes): ?int
    {
        return $antes > 0 ? (int) round(($ahora - $antes) * 100 / $antes) : null;
    }

    /**
     * Avance del mes de $hoy contra la meta (del local si $vendedorId es null). null si no hay meta.
     * Incluye cuánto falta, los días que faltan y dónde "debería" ir a esta altura del mes.
     */
    public function progreso(int $negocioId, ?int $vendedorId, ?DateTimeImmutable $hoy = null): ?array
    {
        $hoy ??= new DateTimeImmutable('today');
        $mes = $hoy->format('Y-m');
        $meta = $this->metaDe($negocioId, $vendedorId, $mes);
        if (!$meta) return null;
        $ventas = $this->vendidoPorMes($negocioId, self::mesAnterior($mes), $mes);
        $k = $vendedorId === null ? '*' : (string) $vendedorId;
        $vendido = $ventas[$mes][$k] ?? ['valor' => 0.0, 'cantidad' => 0];
        $anterior = $ventas[self::mesAnterior($mes)][$k] ?? ['valor' => 0.0, 'cantidad' => 0];
        $diasMes = (int) $hoy->format('t');
        $dia = (int) $hoy->format('j');
        return [
            'mes' => $mes, 'meta' => $meta['monto'], 'meta_eventos' => $meta['eventos'], 'del_mes' => $meta['del_mes'],
            'vendido' => $vendido['valor'], 'cantidad' => $vendido['cantidad'],
            'pct' => self::pct($vendido['valor'], $meta['monto']),
            'pct_eventos' => $meta['eventos'] ? self::pct((float) $vendido['cantidad'], (float) $meta['eventos']) : null,
            'falta' => round(max(0, $meta['monto'] - $vendido['valor']), 2),
            'faltan_eventos' => $meta['eventos'] ? max(0, $meta['eventos'] - $vendido['cantidad']) : null,
            'dias_mes' => $diasMes, 'dias_faltan' => $diasMes - $dia,
            'pct_mes' => (int) round($dia * 100 / $diasMes),           // cuánto del mes ya pasó (ritmo esperado)
            'anio_pasado' => $anterior['valor'], 'variacion' => self::variacion($vendido['valor'], $anterior['valor']),
        ];
    }

    /**
     * Reporte de metas de un periodo: cada mes (completo) con meta, vendido, % de cumplimiento, el mismo mes del año anterior
     * y el cambio; totales; y por vendedor. Si $soloVendedor, solo la fila de ese vendedor.
     * Los meses que todavía no llegan (después del mes de $hoy) muestran su meta, pero no cuentan en los totales ni en el %.
     */
    public function reporte(int $negocioId, string $desde, string $hasta, ?int $soloVendedor = null, ?DateTimeImmutable $hoy = null): array
    {
        $meses = self::meses($desde, $hasta);
        $mesHoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m');   // los meses que vienen no cuentan en el total
        $ventas = $this->vendidoPorMes($negocioId, self::mesAnterior($meses[0]), end($meses));
        $metas = $this->todas($negocioId);
        $metaDe = function (array $porMes, string $mes): ?array { return $porMes[$mes] ?? $porMes[''] ?? null; };
        $filas = [];
        $tot = ['meta' => 0.0, 'meta_eventos' => 0, 'vendido' => 0.0, 'cantidad' => 0, 'anterior' => 0.0, 'anterior_cantidad' => 0, 'meses_con_meta' => 0, 'meses_futuros' => 0];
        foreach ($meses as $mes) {
            $meta = $metaDe($metas['local'], $mes);
            $v = $ventas[$mes]['*'] ?? ['valor' => 0.0, 'cantidad' => 0];
            $a = $ventas[self::mesAnterior($mes)]['*'] ?? ['valor' => 0.0, 'cantidad' => 0];
            $futuro = $mes > $mesHoy;
            $filas[$mes] = [
                'mes' => $mes, 'futuro' => $futuro, 'meta' => $meta['monto'] ?? null, 'meta_eventos' => $meta['eventos'] ?? null,
                'vendido' => $v['valor'], 'cantidad' => $v['cantidad'], 'pct' => $meta && !$futuro ? self::pct($v['valor'], $meta['monto']) : null,
                'pct_eventos' => !empty($meta['eventos']) && !$futuro ? self::pct((float) $v['cantidad'], (float) $meta['eventos']) : null,
                'anterior' => $a['valor'], 'anterior_cantidad' => $a['cantidad'], 'variacion' => $futuro ? null : self::variacion($v['valor'], $a['valor']),
            ];
            if ($futuro) { $tot['meses_futuros']++; continue; }
            if ($meta) { $tot['meta'] += $meta['monto']; $tot['meta_eventos'] += (int) $meta['eventos']; $tot['meses_con_meta']++; }
            $tot['vendido'] += $v['valor'];
            $tot['cantidad'] += $v['cantidad'];
            $tot['anterior'] += $a['valor'];
            $tot['anterior_cantidad'] += $a['cantidad'];
        }
        $tot = array_map(fn($x) => is_float($x) ? round($x, 2) : $x, $tot);
        $tot['pct'] = $tot['meses_con_meta'] ? self::pct($tot['vendido'], $tot['meta']) : null;
        $tot['variacion'] = self::variacion($tot['vendido'], $tot['anterior']);

        // Por vendedor: el dueño, los vendedores y "sin vendedor" si hubo ventas sin asignar
        $personas = [];
        foreach ($this->vendedores($negocioId) as $p) $personas[(string) $p['id']] = ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'rol' => $p['rol']];
        foreach ($ventas as $porVend) foreach (array_keys($porVend) as $k) {
            if ($k === '*' || isset($personas[(string) $k])) continue;
            if ($k === '') { $personas[''] = ['id' => null, 'nombre' => 'Sin vendedor', 'rol' => null]; continue; }
            $u = $this->q('SELECT id, nombre, rol FROM usuarios WHERE id = ? AND negocio_id = ?', [(int) $k, $negocioId])->fetch();
            if ($u) $personas[(string) $k] = ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']];
        }
        $vendedores = [];
        foreach ($personas as $k => $p) {
            if ($soloVendedor !== null && $p['id'] !== $soloVendedor) continue;
            $fila = $p + ['meta' => 0.0, 'meta_eventos' => 0, 'con_meta' => false, 'vendido' => 0.0, 'cantidad' => 0, 'anterior' => 0.0, 'meses' => []];
            foreach ($meses as $mes) {
                $meta = $p['id'] !== null ? $metaDe($metas['vendedores'][$p['id']] ?? [], $mes) : null;
                $v = $ventas[$mes][(string) $k] ?? ['valor' => 0.0, 'cantidad' => 0];
                $a = $ventas[self::mesAnterior($mes)][(string) $k] ?? ['valor' => 0.0, 'cantidad' => 0];
                $futuro = $mes > $mesHoy;
                $fila['meses'][$mes] = ['meta' => $meta['monto'] ?? null, 'vendido' => $v['valor'], 'cantidad' => $v['cantidad'],
                                        'pct' => $meta && !$futuro ? self::pct($v['valor'], $meta['monto']) : null];
                if ($futuro) continue;
                if ($meta) { $fila['meta'] += $meta['monto']; $fila['meta_eventos'] += (int) $meta['eventos']; $fila['con_meta'] = true; }
                $fila['vendido'] += $v['valor'];
                $fila['cantidad'] += $v['cantidad'];
                $fila['anterior'] += $a['valor'];
            }
            $fila['meta'] = round($fila['meta'], 2);
            $fila['vendido'] = round($fila['vendido'], 2);
            $fila['anterior'] = round($fila['anterior'], 2);
            $fila['pct'] = $fila['con_meta'] ? self::pct($fila['vendido'], $fila['meta']) : null;
            $fila['variacion'] = self::variacion($fila['vendido'], $fila['anterior']);
            if (!$fila['con_meta'] && $fila['vendido'] <= 0 && $fila['anterior'] <= 0 && $p['rol'] !== 'vendedor') continue;   // dueño sin ventas ni meta
            $vendedores[] = $fila;
        }
        usort($vendedores, fn($a, $b) => [$b['vendido'], $a['nombre']] <=> [$a['vendido'], $b['nombre']]);
        return ['meses' => $filas, 'total' => $tot, 'vendedores' => $vendedores, 'hay_metas' => (bool) ($metas['local'] || $metas['vendedores'])];
    }

    /** Tabla para Excel (CSV con Reportes::csv). @return array{0: list<string>, 1: list<list<mixed>>} */
    public function exportar(int $negocioId, string $desde, string $hasta, ?int $soloVendedor = null, ?DateTimeImmutable $hoy = null): array
    {
        $r = $this->reporte($negocioId, $desde, $hasta, $soloVendedor, $hoy);
        $n = fn($x) => $x === null ? '' : number_format((float) $x, 2, ',', '');
        $p = fn($x) => $x === null ? '' : $x . ' %';
        $filas = [];
        if ($soloVendedor === null) {
            foreach ($r['meses'] as $f) {
                $filas[] = ['Todo el local', ucfirst(Reportes::nombreMes($f['mes'])), $n($f['meta']), $f['meta_eventos'] ?? '', $n($f['vendido']), $f['cantidad'],
                            $p($f['pct']), $n($f['anterior']), $f['anterior_cantidad'], $p($f['variacion'])];
            }
        }
        foreach ($r['vendedores'] as $v) {
            foreach ($v['meses'] as $mes => $f) {
                $filas[] = [$v['nombre'], ucfirst(Reportes::nombreMes($mes)), $n($f['meta']), '', $n($f['vendido']), $f['cantidad'], $p($f['pct']), '', '', ''];
            }
        }
        return [['Quién', 'Mes', 'Meta', 'Meta de eventos', 'Vendido', 'Eventos vendidos', 'Cumplimiento', 'Mismo mes del año anterior',
                 'Eventos año anterior', 'Cambio contra el año anterior'], $filas];
    }
}
