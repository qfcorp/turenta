<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Cómo se calcula el puntaje de los proveedores, configurable por negocio (migración 091).
 *
 * Puntaje (0 a 100) = promedio ponderado de las partes que se pudieron medir:
 *   calificación   = promedio de estrellas × 20          (5★ = 100)
 *   puntualidad    = % de llegadas y bloques del cronograma a tiempo (dentro de la tolerancia)
 *   cumplimiento   = % de eventos en que cumplió (cumplió = 1, con observaciones = ½)
 *   incidencias    = 100 − lo que resta cada incidencia según su gravedad (mínimo 0)
 * Las partes sin datos o con peso 0 no cuentan: su peso se reparte entre las demás.
 * La parte de incidencias solo cuenta si hay alguna otra parte medida (las incidencias solas no dan puntaje),
 * salvo que las demás partes tengan peso 0: entonces basta con un evento trabajado.
 * Nivel por umbrales (Excelente / Bueno / Regular / Malo); con una incidencia grave el nivel puede tener un tope.
 * Opcional: solo los últimos 6 o 12 meses y un mínimo de eventos trabajados (si no, "Sin datos suficientes").
 * Sin configuración guardada se usan los valores recomendados (RECOMENDADO), que son los de siempre.
 */
final class PuntajeProveedores
{
    public const RECOMENDADO = [
        'peso_calificacion' => 40, 'peso_puntualidad' => 30, 'peso_cumplimiento' => 20, 'peso_incidencias' => 10,
        'tolerancia_min' => 10, 'resta_leve' => 10, 'resta_media' => 25, 'resta_grave' => 50,
        'tope_grave' => true, 'tope_nivel' => 'bueno',
        'umbral_excelente' => 85, 'umbral_bueno' => 70, 'umbral_regular' => 50,
        'periodo_meses' => 0, 'minimo_eventos' => 0,
    ];
    public const PESOS = ['peso_calificacion' => 'Calificación (estrellas)', 'peso_puntualidad' => 'Puntualidad',
                          'peso_cumplimiento' => 'Cumplimiento', 'peso_incidencias' => 'Sin incidencias'];
    public const PERIODOS = [0 => 'Todo el historial', 12 => 'Últimos 12 meses', 6 => 'Últimos 6 meses'];
    public const NIVELES = ['excelente' => 'Excelente', 'bueno' => 'Bueno', 'regular' => 'Regular', 'malo' => 'Malo'];
    /** Para el registro de cambios. */
    public const ETIQUETAS = [
        'peso_calificacion' => 'peso de la calificación', 'peso_puntualidad' => 'peso de la puntualidad',
        'peso_cumplimiento' => 'peso del cumplimiento', 'peso_incidencias' => 'peso de las incidencias',
        'tolerancia_min' => 'tolerancia', 'resta_leve' => 'resta por incidencia leve', 'resta_media' => 'resta por incidencia media',
        'resta_grave' => 'resta por incidencia grave', 'tope_grave' => 'tope con incidencia grave', 'tope_nivel' => 'nivel máximo con incidencia grave',
        'umbral_excelente' => 'Excelente desde', 'umbral_bueno' => 'Bueno desde', 'umbral_regular' => 'Regular desde',
        'periodo_meses' => 'periodo', 'minimo_eventos' => 'mínimo de eventos',
    ];
    private const ORDEN_NIVEL = ['excelente' => 4, 'bueno' => 3, 'regular' => 2, 'malo' => 1];

    public function __construct(private PDO $db) {}

    /** Configuración del negocio (o la recomendada si nunca la cambió). */
    public function config(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT * FROM puntaje_proveedores_config WHERE negocio_id = ?');
        $st->execute([$negocioId]);
        $f = $st->fetch();
        if (!$f) return self::RECOMENDADO;
        $c = [];
        foreach (self::RECOMENDADO as $k => $def) {
            $c[$k] = is_bool($def) ? (bool) $f[$k] : (is_int($def) ? (int) $f[$k] : (string) $f[$k]);
        }
        return $c;
    }

    public function esRecomendada(int $negocioId): bool
    {
        return $this->config($negocioId) == self::RECOMENDADO;
    }

    private static function entero(mixed $v, string $que, int $min, int $max): int
    {
        $t = trim((string) $v);
        if ($t === '' || !preg_match('/^\d{1,3}$/', $t)) throw new RuntimeException("Escribe un número entero para $que.");
        $n = (int) $t;
        if ($n < $min || $n > $max) throw new RuntimeException(ucfirst($que) . " va de $min a $max.");
        return $n;
    }

    /** Revisa lo que llega del formulario y devuelve la configuración limpia (o un error en español). */
    public static function validar(array $d): array
    {
        $c = [];
        foreach (self::PESOS as $k => $t) $c[$k] = self::entero($d[$k] ?? '', 'el peso de ' . mb_strtolower($t), 0, 100);
        $suma = array_sum(array_intersect_key($c, self::PESOS));
        if ($suma !== 100) throw new RuntimeException("Los pesos deben sumar 100 %. Ahora suman $suma %.");
        $c['tolerancia_min'] = self::entero($d['tolerancia_min'] ?? '', 'la tolerancia (minutos)', 0, 120);
        $c['resta_leve'] = self::entero($d['resta_leve'] ?? '', 'lo que resta una incidencia leve', 0, 100);
        $c['resta_media'] = self::entero($d['resta_media'] ?? '', 'lo que resta una incidencia media', 0, 100);
        $c['resta_grave'] = self::entero($d['resta_grave'] ?? '', 'lo que resta una incidencia grave', 0, 100);
        if ($c['resta_leve'] > $c['resta_media'] || $c['resta_media'] > $c['resta_grave']) {
            throw new RuntimeException('Una incidencia más grave debe restar igual o más: leve ≤ media ≤ grave.');
        }
        $c['tope_grave'] = !empty($d['tope_grave']);
        $tope = (string) ($d['tope_nivel'] ?? 'bueno');
        if (!in_array($tope, ['bueno', 'regular', 'malo'], true)) throw new RuntimeException('Elige el nivel máximo con una incidencia grave.');
        $c['tope_nivel'] = $tope;
        $c['umbral_excelente'] = self::entero($d['umbral_excelente'] ?? '', 'el puntaje para Excelente', 1, 100);
        $c['umbral_bueno'] = self::entero($d['umbral_bueno'] ?? '', 'el puntaje para Bueno', 1, 100);
        $c['umbral_regular'] = self::entero($d['umbral_regular'] ?? '', 'el puntaje para Regular', 1, 100);
        if (!($c['umbral_excelente'] > $c['umbral_bueno'] && $c['umbral_bueno'] > $c['umbral_regular'])) {
            throw new RuntimeException('Los niveles van de mayor a menor: Excelente más que Bueno, y Bueno más que Regular.');
        }
        $per = (int) ($d['periodo_meses'] ?? 0);
        if (!isset(self::PERIODOS[$per]) || !preg_match('/^\d+$/', trim((string) ($d['periodo_meses'] ?? '0')))) throw new RuntimeException('Elige el periodo que se evalúa.');
        $c['periodo_meses'] = $per;
        $c['minimo_eventos'] = self::entero($d['minimo_eventos'] ?? '', 'el mínimo de eventos', 0, 50);
        return array_merge(self::RECOMENDADO, $c);
    }

    private static function valorTexto(string $k, mixed $v): string
    {
        return match (true) {
            str_starts_with($k, 'peso_') => $v . ' %',
            $k === 'tolerancia_min' => $v . ' min',
            str_starts_with($k, 'resta_'), str_starts_with($k, 'umbral_') => $v . ' pts',
            $k === 'tope_grave' => $v ? 'sí' : 'no',
            $k === 'tope_nivel' => self::NIVELES[$v] ?? (string) $v,
            $k === 'periodo_meses' => mb_strtolower(self::PERIODOS[(int) $v] ?? (string) $v),
            $k === 'minimo_eventos' => (string) $v,
            default => (string) $v,
        };
    }

    /**
     * Guarda la configuración (solo el dueño, lo revisa la página) y la anota en el registro de cambios del negocio
     * con quién, cuándo y los valores de antes y después. Devuelve la lista de cambios (vacía si no cambió nada).
     * @return list<string>
     */
    public function guardar(int $negocioId, array $d, ?array $usuario = null): array
    {
        $nuevo = self::validar($d);
        $antes = $this->config($negocioId);
        $cambios = [];
        foreach (self::RECOMENDADO as $k => $_) {
            if ($antes[$k] !== $nuevo[$k]) $cambios[] = self::ETIQUETAS[$k] . ': ' . self::valorTexto($k, $antes[$k]) . ' → ' . self::valorTexto($k, $nuevo[$k]);
        }
        if (!$cambios) return [];
        $cols = array_keys(self::RECOMENDADO);
        $vals = array_map(fn($k) => is_bool($nuevo[$k]) ? ($nuevo[$k] ? 'true' : 'false') : $nuevo[$k], $cols);
        $this->db->prepare('INSERT INTO puntaje_proveedores_config (negocio_id, ' . implode(', ', $cols) . ', actualizado_por, actualizado_en)
                            VALUES (?' . str_repeat(', ?', count($cols)) . ', ?, now())
                            ON CONFLICT (negocio_id) DO UPDATE SET ' . implode(', ', array_map(fn($k) => "$k = EXCLUDED.$k", $cols)) . ',
                                   actualizado_por = EXCLUDED.actualizado_por, actualizado_en = now()')
                 ->execute([$negocioId, ...$vals, $usuario['id'] ?? null]);
        // Registro de cambios del negocio (sin evento); si es largo, se parte en varias líneas
        $ins = $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?, NULL, ?, ?, ?)');
        $linea = '';
        foreach ($cambios as $c) {
            if ($linea !== '' && mb_strlen($linea . '; ' . $c) > 360) {
                $ins->execute([$negocioId, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', 'Puntaje de proveedores: ' . $linea]);
                $linea = '';
            }
            $linea .= ($linea === '' ? '' : '; ') . $c;
        }
        $ins->execute([$negocioId, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', mb_substr('Puntaje de proveedores: ' . $linea, 0, 400)]);
        return $cambios;
    }

    /** Vuelve a los valores recomendados. */
    public function recomendados(int $negocioId, ?array $usuario = null): array
    {
        return $this->guardar($negocioId, self::RECOMENDADO, $usuario);
    }

    /** Últimos cambios de la configuración (quién, cuándo, antes → después). */
    public function historial(int $negocioId, int $limite = 15): array
    {
        $st = $this->db->prepare("SELECT quien, accion, creado_en FROM registro_cambios
                                   WHERE negocio_id = ? AND evento_id IS NULL AND accion LIKE 'Puntaje de proveedores:%' ORDER BY creado_en DESC, id DESC LIMIT " . max(1, $limite));
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    /** Nivel según el puntaje y los umbrales (sin el tope). */
    public static function nivel(int $puntaje, array $cfg): string
    {
        return $puntaje >= $cfg['umbral_excelente'] ? 'excelente' : ($puntaje >= $cfg['umbral_bueno'] ? 'bueno' : ($puntaje >= $cfg['umbral_regular'] ? 'regular' : 'malo'));
    }

    /**
     * Calcula el puntaje de una tarjeta con los datos ya contados:
     *   calificacion (promedio 1-5 o null), puntualidad (% o null), cumplimiento (% o null),
     *   leves, medias, graves (incidencias), eventos (trabajados en el periodo).
     * Devuelve puntaje (o null), nivel, pocos_datos y el detalle de cada parte con su peso real.
     */
    public static function calcular(array $r, array $cfg): array
    {
        $valores = [
            'peso_calificacion' => $r['calificacion'] !== null ? (float) $r['calificacion'] * 20 : null,
            'peso_puntualidad' => $r['puntualidad'],
            'peso_cumplimiento' => $r['cumplimiento'],
        ];
        $partes = [];
        foreach ($valores as $k => $v) if ($cfg[$k] > 0 && $v !== null) $partes[$k] = [(float) $v, $cfg[$k]];
        $sinOtras = $cfg['peso_calificacion'] + $cfg['peso_puntualidad'] + $cfg['peso_cumplimiento'] === 0;
        $hayDatos = $partes || ($sinOtras && (int) $r['eventos'] > 0);
        $res = ['puntaje' => null, 'nivel' => null, 'pocos_datos' => false, 'detalle' => [], 'tope_aplicado' => false];
        if (!$hayDatos) return $res;
        if ($cfg['peso_incidencias'] > 0) {
            $castigo = min(100, (int) $r['graves'] * $cfg['resta_grave'] + (int) $r['medias'] * $cfg['resta_media'] + (int) $r['leves'] * $cfg['resta_leve']);
            $partes['peso_incidencias'] = [100.0 - $castigo, $cfg['peso_incidencias']];
        }
        $peso = array_sum(array_column($partes, 1));
        foreach ($partes as $k => [$v, $w]) $res['detalle'][$k] = ['valor' => (int) round($v), 'peso' => round($w * 100 / $peso, 1)];
        if ((int) $r['eventos'] < $cfg['minimo_eventos']) {
            $res['pocos_datos'] = true;
            return $res;
        }
        $res['puntaje'] = (int) round(array_sum(array_map(fn($x) => $x[0] * $x[1], $partes)) / $peso);
        $res['nivel'] = self::nivel($res['puntaje'], $cfg);
        if ($cfg['tope_grave'] && (int) $r['graves'] > 0 && self::ORDEN_NIVEL[$res['nivel']] > self::ORDEN_NIVEL[$cfg['tope_nivel']]) {
            $res['nivel'] = $cfg['tope_nivel'];
            $res['tope_aplicado'] = true;
        }
        return $res;
    }

    /** Resumen en una línea: "Calificación 40 %, puntualidad 30 %… · tolerancia 10 min · …" */
    public static function resumen(array $cfg): string
    {
        $pesos = implode(', ', array_map(fn($k) => self::PESOS[$k] . ' ' . $cfg[$k] . ' %', array_keys(self::PESOS)));
        return $pesos . ' · tolerancia ' . $cfg['tolerancia_min'] . ' min · incidencias leve ' . $cfg['resta_leve'] . ', media ' . $cfg['resta_media'] . ', grave ' . $cfg['resta_grave']
             . ' · Excelente desde ' . $cfg['umbral_excelente'] . ', Bueno desde ' . $cfg['umbral_bueno'] . ', Regular desde ' . $cfg['umbral_regular']
             . ($cfg['tope_grave'] ? ' · con incidencia grave no pasa de ' . self::NIVELES[$cfg['tope_nivel']] : ' · sin tope por incidencias graves')
             . ' · ' . mb_strtolower(self::PERIODOS[$cfg['periodo_meses']]) . ($cfg['minimo_eventos'] ? ' · desde ' . $cfg['minimo_eventos'] . ' eventos' : '') . '.';
    }

    /** Explicación en palabras sencillas (para la pantalla de solo lectura y la ficha). @return list<string> */
    public static function explicar(array $cfg): array
    {
        $t = [];
        $pesos = array_filter(array_intersect_key($cfg, self::PESOS));
        $t[] = 'El puntaje va de 0 a 100 y junta: ' . implode(', ', array_map(fn($k) => mb_strtolower(self::PESOS[$k]) . ' ' . $cfg[$k] . ' %', array_keys($pesos))) . '.';
        $cero = array_keys(array_diff_key(self::PESOS, $pesos));
        if ($cero) $t[] = 'No se toma en cuenta: ' . implode(', ', array_map(fn($k) => mb_strtolower(self::PESOS[$k]), $cero)) . '.';
        $t[] = 'Calificación: el promedio de estrellas (5 estrellas = 100). Puntualidad: el % de llegadas y momentos del cronograma a tiempo, con '
             . $cfg['tolerancia_min'] . ' minutos de tolerancia. Cumplimiento: el % de eventos en que cumplió (con observaciones cuenta la mitad).';
        if ($cfg['peso_incidencias'] > 0) {
            $t[] = 'Incidencias: empieza en 100 y cada problema resta: leve ' . $cfg['resta_leve'] . ', media ' . $cfg['resta_media'] . ', grave ' . $cfg['resta_grave'] . ' puntos.';
        }
        $t[] = 'Si algo todavía no se pudo medir (por ejemplo, nadie lo ha calificado), su peso se reparte entre lo demás.';
        $t[] = 'Niveles: Excelente desde ' . $cfg['umbral_excelente'] . ', Bueno desde ' . $cfg['umbral_bueno'] . ', Regular desde ' . $cfg['umbral_regular'] . ', y menos es Malo.'
             . ($cfg['tope_grave'] ? ' Con una incidencia grave, el nivel no pasa de ' . self::NIVELES[$cfg['tope_nivel']] . '.' : '');
        $t[] = 'Se evalúa: ' . mb_strtolower(self::PERIODOS[$cfg['periodo_meses']]) . '.'
             . ($cfg['minimo_eventos'] > 0 ? ' Se muestra el puntaje desde ' . $cfg['minimo_eventos'] . ($cfg['minimo_eventos'] === 1 ? ' evento trabajado.' : ' eventos trabajados.') : '');
        return $t;
    }
}
