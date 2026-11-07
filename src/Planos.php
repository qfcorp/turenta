<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Plano visual del salón (diagrama de distribución), como el de Tripleseat o Cvent pero sencillo:
 *   - Plantillas por espacio ("Boda 150 redondas", "Corporativo escuela"): las arma el dueño.
 *   - Plano de cada evento: copia de una plantilla o desde cero. Lo editan dueño, vendedor y coordinador.
 * Medidas en metros. Cada elemento: centro (x, y), ancho, alto, rotación, etiqueta y puestos (mesas).
 *
 * Mesas del plano <-> mesas de invitados (tabla mesas), en el plano de un evento:
 *   - Una mesa nueva en el plano crea su mesa en invitados (nombre = etiqueta, capacidad = puestos).
 *   - Cambiar nombre o puestos en el plano cambia la mesa (y al revés: el plano siempre muestra lo de invitados).
 *   - Las mesas creadas en invitados aparecen como "mesas sin ubicar" para ponerlas en el plano.
 *   - Quitar una mesa del plano borra su mesa si está vacía; si tiene invitados, la mesa se queda (sin ubicar).
 *   - Borrar la mesa en invitados la quita del plano (ON DELETE CASCADE).
 * Todo se filtra por negocio: un negocio nunca abre ni guarda el plano de otro.
 */
final class Planos
{
    /** Tipos de elemento: nombre, medidas por defecto (m) y puestos por defecto (solo mesas). */
    public const TIPOS = [
        'redonda'     => ['nombre' => 'Mesa redonda',        'corto' => 'Redonda',     'ancho' => 1.8, 'alto' => 1.8, 'puestos' => 10],
        'rectangular' => ['nombre' => 'Mesa rectangular',    'corto' => 'Rectangular', 'ancho' => 2.4, 'alto' => 0.9, 'puestos' => 8],
        'imperial'    => ['nombre' => 'Mesa imperial o de honor', 'corto' => 'Imperial', 'ancho' => 6.0, 'alto' => 1.2, 'puestos' => 14],
        'pista'       => ['nombre' => 'Pista de baile',      'corto' => 'Pista',       'ancho' => 6.0, 'alto' => 6.0],
        'tarima'      => ['nombre' => 'Tarima o escenario',  'corto' => 'Tarima',      'ancho' => 6.0, 'alto' => 3.0],
        'barra'       => ['nombre' => 'Barra',               'corto' => 'Barra',       'ancho' => 4.0, 'alto' => 0.8],
        'buffet'      => ['nombre' => 'Buffet',              'corto' => 'Buffet',      'ancho' => 4.0, 'alto' => 1.0],
        'dulces'      => ['nombre' => 'Mesa de dulces',      'corto' => 'Dulces',      'ancho' => 2.0, 'alto' => 1.0],
        'dj'          => ['nombre' => 'Cabina DJ',           'corto' => 'DJ',          'ancho' => 2.0, 'alto' => 1.5],
        'entrada'     => ['nombre' => 'Entrada',             'corto' => 'Entrada',     'ancho' => 2.0, 'alto' => 0.4],
        'banos'       => ['nombre' => 'Baños',               'corto' => 'Baños',       'ancho' => 3.0, 'alto' => 3.0],
        'columna'     => ['nombre' => 'Columna u obstáculo', 'corto' => 'Columna',     'ancho' => 0.6, 'alto' => 0.6],
        'texto'       => ['nombre' => 'Texto libre',         'corto' => 'Texto',       'ancho' => 3.0, 'alto' => 0.8],
    ];
    public const MESAS = ['redonda', 'rectangular', 'imperial'];
    public const MAX_ELEMENTOS = 300;
    public const MAX_PLANTILLAS = 30;      // por espacio
    public const MAX_MESAS = 300;          // igual que Invitados::crearMesas

    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    private function enTransaccion(callable $f): mixed
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $r = $f();
            if ($propia) $this->db->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Ayudas
    // ------------------------------------------------------------------

    /** Metros escritos con coma o punto ("12,5") -> float, dentro del rango, o error. */
    public static function medida(mixed $v, string $que, float $min = 3, float $max = 300): float
    {
        $t = str_replace([' ', 'm', 'M'], '', trim((string) $v));
        $t = str_replace(',', '.', $t);
        if ($t === '' || !is_numeric($t)) throw new RuntimeException("Escribe $que en metros (ejemplo: 20).");
        $n = round((float) $t, 2);
        if ($n < $min || $n > $max) throw new RuntimeException(ucfirst($que) . ' debe estar entre ' . self::m($min) . ' y ' . self::m($max) . ' metros.');
        return $n;
    }

    /** 12.5 -> "12,5" (sin ceros de más). */
    public static function m(float|string|null $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    }

    private static function limpiarTexto(mixed $v, int $max): string
    {
        $t = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $v) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $t) ?? ''), 0, $max);
    }

    /** "Mesa 12" -> "12" (para que se lea dentro del círculo); lo demás igual. */
    public static function etiquetaCorta(?string $etq): string
    {
        $etq = trim((string) $etq);
        return preg_match('/^mesa\s+(\d{1,3})$/iu', $etq, $m) ? $m[1] : $etq;
    }

    /** Corta un texto largo con "…" (para que quepa dentro de una mesa). */
    public static function recortar(string $t, int $max): string
    {
        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }

    public static function esMesa(string $tipo): bool
    {
        return in_array($tipo, self::MESAS, true);
    }

    private function cambio(int $negocioId, ?int $eventoId, string $accion, ?array $usuario): void
    {
        $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                 [$negocioId, $eventoId, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', mb_substr($accion, 0, 400)]);
    }

    // ------------------------------------------------------------------
    // Planos
    // ------------------------------------------------------------------

    /** Plano del negocio (o error), con el nombre del espacio y del evento. */
    public function plano(int $negocioId, int $id): array
    {
        $p = $this->q('SELECT p.*, es.nombre AS espacio, e.nombre AS evento, e.codigo AS evento_codigo, e.fecha AS evento_fecha
                         FROM planos p LEFT JOIN espacios es ON es.id = p.espacio_id LEFT JOIN eventos e ON e.id = p.evento_id
                        WHERE p.id = ? AND p.negocio_id = ?', [$id, $negocioId])->fetch();
        if (!$p) throw new RuntimeException('No se encontró el plano.');
        $p['ancho'] = (float) $p['ancho'];
        $p['largo'] = (float) $p['largo'];
        $p['version'] = (int) $p['version'];
        return $p;
    }

    /** Elementos del plano, en orden, con números de verdad. */
    public function elementos(int $planoId): array
    {
        $res = [];
        foreach ($this->q('SELECT * FROM plano_elementos WHERE plano_id = ? ORDER BY orden, id', [$planoId])->fetchAll() as $r) {
            $res[] = [
                'id' => (int) $r['id'], 'tipo' => $r['tipo'], 'x' => (float) $r['x'], 'y' => (float) $r['y'],
                'an' => (float) $r['ancho'], 'al' => (float) $r['alto'], 'rot' => (int) $r['rotacion'],
                'etq' => (string) ($r['etiqueta'] ?? ''), 'puestos' => $r['puestos'] !== null ? (int) $r['puestos'] : null,
                'mesa_id' => $r['mesa_id'] !== null ? (int) $r['mesa_id'] : null,
            ];
        }
        return $res;
    }

    /** Plantillas del negocio (de un espacio o de todos), con cuántas mesas y puestos tienen. */
    public function plantillas(int $negocioId, ?int $espacioId = null): array
    {
        $sql = "SELECT p.id, p.nombre, p.ancho, p.largo, p.espacio_id, p.actualizado_en, es.nombre AS espacio, es.color AS espacio_color,
                       count(el.id) AS elementos,
                       count(el.id) FILTER (WHERE el.tipo IN ('redonda','rectangular','imperial')) AS mesas,
                       COALESCE(sum(el.puestos) FILTER (WHERE el.tipo IN ('redonda','rectangular','imperial')), 0) AS puestos
                  FROM planos p JOIN espacios es ON es.id = p.espacio_id LEFT JOIN plano_elementos el ON el.plano_id = p.id
                 WHERE p.negocio_id = ? AND p.tipo = 'plantilla'" . ($espacioId ? ' AND p.espacio_id = ?' : '') . "
              GROUP BY p.id, es.id ORDER BY es.orden, es.id, p.nombre, p.id";
        return $this->q($sql, $espacioId ? [$negocioId, $espacioId] : [$negocioId])->fetchAll();
    }

    private function espacio(int $negocioId, int $espacioId): array
    {
        $e = $this->q('SELECT * FROM espacios WHERE id = ? AND negocio_id = ?', [$espacioId, $negocioId])->fetch();
        if (!$e) throw new RuntimeException('No se encontró el espacio.');
        return $e;
    }

    private static function nombrePlano(string $nombre): string
    {
        $nombre = self::limpiarTexto($nombre, 80);
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Ponle un nombre a la distribución (ejemplo: Boda 150 en mesas redondas).');
        return $nombre;
    }

    public const EJEMPLOS = ['' => 'Vacía (la armo yo)', 'boda' => 'Ejemplo de boda o quince: mesas redondas, pista y tarima',
                             'escuela' => 'Ejemplo corporativo: mesas en filas mirando a la tarima'];

    /** Nueva plantilla de distribución para un espacio. $ejemplo: '' (vacía), 'boda' o 'escuela' (ya viene armada). */
    public function crearPlantilla(int $negocioId, int $espacioId, string $nombre, mixed $ancho, mixed $largo, ?int $usuarioId = null, string $ejemplo = ''): int
    {
        $this->espacio($negocioId, $espacioId);
        $nombre = self::nombrePlano($nombre);
        $a = self::medida($ancho, 'el ancho');
        $l = self::medida($largo, 'el largo');
        if (!isset(self::EJEMPLOS[$ejemplo])) throw new RuntimeException('Elige cómo empezar la distribución.');
        $capacidad = (int) ($this->espacio($negocioId, $espacioId)['capacidad_banquete'] ?? 0);
        $hay = (int) $this->q("SELECT count(*) FROM planos WHERE negocio_id = ? AND tipo = 'plantilla' AND espacio_id = ?", [$negocioId, $espacioId])->fetchColumn();
        if ($hay >= self::MAX_PLANTILLAS) throw new RuntimeException('Este espacio ya tiene ' . self::MAX_PLANTILLAS . ' distribuciones. Borra una que ya no uses.');
        return $this->enTransaccion(function () use ($negocioId, $espacioId, $nombre, $a, $l, $usuarioId, $ejemplo, $capacidad) {
            $id = (int) $this->q("INSERT INTO planos (negocio_id, tipo, espacio_id, nombre, ancho, largo, creado_por) VALUES (?, 'plantilla', ?, ?, ?, ?, ?) RETURNING id",
                                 [$negocioId, $espacioId, $nombre, $a, $l, $usuarioId])->fetchColumn();
            if ($ejemplo !== '') $this->guardar($negocioId, $id, self::ejemplo($ejemplo, $a, $l, $capacidad));
            return $id;
        });
    }

    /**
     * Distribución de ejemplo para empezar (no se pisa nada): 'boda' = tarima, pista, mesa de honor, barra, buffet y mesas redondas
     * en lo que queda; 'escuela' = tarima y filas de mesas angostas con pasillo al centro.
     * $capacidad = sentados del espacio (si se sabe, no pone más puestos que eso).
     */
    public static function ejemplo(string $cual, float $A, float $L, int $capacidad = 0): array
    {
        $tope = $capacidad > 0 ? $capacidad : 100000;
        $els = [];
        $ocupado = [];   // rectángulos [x1, y1, x2, y2] ya usados
        $pon = function (string $tipo, float $x, float $y, float $an, float $al, int $rot = 0, string $etq = '', ?int $puestos = null) use (&$els, &$ocupado, $A, $L): bool {
            [$w, $h] = $rot % 180 === 90 ? [$al, $an] : [$an, $al];
            if ($w > $A || $h > $L) return false;
            $x = max($w / 2, min($A - $w / 2, $x));
            $y = max($h / 2, min($L - $h / 2, $y));
            $els[] = ['tipo' => $tipo, 'x' => round($x, 2), 'y' => round($y, 2), 'an' => $an, 'al' => $al, 'rot' => $rot, 'etq' => $etq,
                      'puestos' => $puestos ?? (self::TIPOS[$tipo]['puestos'] ?? null)];
            $ocupado[] = [$x - $w / 2, $y - $h / 2, $x + $w / 2, $y + $h / 2];
            return true;
        };
        $libre = function (float $x1, float $y1, float $x2, float $y2) use (&$ocupado, $A, $L): bool {
            if ($x1 < 0 || $y1 < 0 || $x2 > $A || $y2 > $L) return false;
            foreach ($ocupado as [$a1, $b1, $a2, $b2]) if ($x1 < $a2 && $x2 > $a1 && $y1 < $b2 && $y2 > $b1) return false;
            return true;
        };
        $tarAn = min(6.0, round($A * 0.4, 1));
        $tarAl = $L >= 12 ? 3.0 : 2.0;
        if ($cual === 'boda') {
            $pon('tarima', $A / 2, $tarAl / 2 + 0.3, $tarAn, $tarAl, 0, 'Tarima');
            if ($A >= 12) $pon('dj', $A / 2 + $tarAn / 2 + 1.6, 1.2, 2.0, 1.5, 0, 'DJ');
            $pis = min(7.0, round(min($A, $L) * 0.35 * 2) / 2);
            $yPista = $tarAl + 0.6 + $pis / 2 + 0.3;
            $pon('pista', $A / 2, $yPista, $pis, $pis, 0, 'Pista de baile');
            $pon('imperial', $A / 2, $yPista + $pis / 2 + 1.5, min(6.0, $A * 0.4), 1.2, 0, 'Mesa de honor', 10);
            if ($A >= 14) {
                $pon('barra', $A - 0.6, $L * 0.55, 4.0, 0.8, 90, 'Barra');
                $pon('buffet', 0.7, $L * 0.55, 4.0, 1.0, 90, 'Buffet');
                $pon('dulces', 1.3, 1.0, 2.0, 1.0, 0, 'Mesa de dulces');
            }
            $pon('entrada', $A / 2, $L - 0.2, 2.4, 0.4, 0, 'Entrada');
            if ($A >= 14 && $L >= 14) $pon('banos', $A - 1.6, $L - 1.6, 3.0, 3.0, 0, 'Baños');
            // Mesas redondas de 10 en lo que queda (1,8 m + sillas + pasillo = 3,2 m entre centros)
            $n = 0;
            $maxMesas = min(40, (int) ceil(max(0, $tope - 10) / 10));   // la mesa de honor ya tiene 10
            for ($y = 1.6; $y <= $L - 1.6 && $n < $maxMesas; $y += 3.2) {
                for ($x = 1.6; $x <= $A - 1.6 && $n < $maxMesas; $x += 3.2) {
                    if ($libre($x - 1.45, $y - 1.45, $x + 1.45, $y + 1.45)) $pon('redonda', $x, $y, 1.8, 1.8, 0, 'Mesa ' . ++$n, 10);
                }
            }
            if ($A < 14 && $n === 0 && $libre(0.2, $L / 2, $A - 0.2, $L - 1)) $pon('rectangular', $A / 2, $L * 0.7, 2.4, 0.9, 0, 'Mesa 1', 8);
        } elseif ($cual === 'escuela') {
            $pon('tarima', $A / 2, $tarAl / 2 + 0.3, $tarAn, $tarAl, 0, 'Tarima');
            $pon('entrada', $A / 2, $L - 0.2, 2.4, 0.4, 0, 'Entrada');
            $n = 0;
            $pasillo = 1.6;
            $maxMesas = min(80, (int) ceil($tope / 3));
            for ($y = $tarAl + 2.4; $y <= $L - 2.0 && $n < $maxMesas; $y += 1.7) {
                $fila = [];
                foreach ([-1, 1] as $lado) {
                    for ($k = 0; $k < 60; $k++) {
                        $x = $A / 2 + $lado * ($pasillo / 2 + 0.9 + $k * 2.1);
                        if ($x - 0.9 < 0.8 || $x + 0.9 > $A - 0.8) break;
                        $fila[] = $x;
                    }
                }
                sort($fila);   // se numeran de izquierda a derecha
                foreach ($fila as $x) {
                    if ($n >= $maxMesas) break;
                    if ($libre($x - 0.95, $y - 0.3, $x + 0.95, $y + 0.85)) $pon('rectangular', $x, $y, 1.8, 0.5, 0, 'Mesa ' . ++$n, 3);
                }
            }
        }
        return $els;
    }

    /** Cambia el nombre o el tamaño del salón. Si se achica, los elementos que quedan afuera se acercan a la pared. */
    public function editarDatos(int $negocioId, int $planoId, string $nombre, mixed $ancho, mixed $largo): void
    {
        $this->plano($negocioId, $planoId);
        $nombre = self::nombrePlano($nombre);
        $a = self::medida($ancho, 'el ancho');
        $l = self::medida($largo, 'el largo');
        $this->enTransaccion(function () use ($planoId, $nombre, $a, $l) {
            $this->q('UPDATE planos SET nombre = ?, ancho = ?, largo = ?, version = version + 1, actualizado_en = now() WHERE id = ?', [$nombre, $a, $l, $planoId]);
            $this->q('UPDATE plano_elementos SET x = LEAST(GREATEST(x, 0), ?), y = LEAST(GREATEST(y, 0), ?) WHERE plano_id = ? AND (x > ? OR y > ? OR x < 0 OR y < 0)',
                     [$a, $l, $planoId, $a, $l]);
        });
    }

    /** Copia una plantilla ("… (copia)") en el mismo espacio. */
    public function duplicarPlantilla(int $negocioId, int $planoId, ?int $usuarioId = null): int
    {
        $p = $this->plano($negocioId, $planoId);
        if ($p['tipo'] !== 'plantilla') throw new RuntimeException('Solo se pueden copiar las distribuciones de los espacios.');
        return $this->enTransaccion(function () use ($negocioId, $p, $usuarioId) {
            $nuevo = $this->crearPlantilla($negocioId, (int) $p['espacio_id'], mb_substr($p['nombre'], 0, 72) . ' (copia)', $p['ancho'], $p['largo'], $usuarioId);
            $this->q('INSERT INTO plano_elementos (plano_id, tipo, x, y, ancho, alto, rotacion, etiqueta, puestos, orden)
                      SELECT ?, tipo, x, y, ancho, alto, rotacion, etiqueta, puestos, orden FROM plano_elementos WHERE plano_id = ?', [$nuevo, $p['id']]);
            return $nuevo;
        });
    }

    /** Borra un plano (plantilla o del evento). Las mesas de invitados se quedan como estaban. */
    public function borrar(int $negocioId, int $planoId, ?array $usuario = null): void
    {
        $p = $this->plano($negocioId, $planoId);
        $this->q('DELETE FROM planos WHERE id = ?', [$planoId]);
        if ($p['tipo'] === 'evento') $this->cambio($negocioId, (int) $p['evento_id'], 'Borró el plano del salón (las mesas de invitados se mantienen)', $usuario);
    }

    // ------------------------------------------------------------------
    // Plano del evento
    // ------------------------------------------------------------------

    private function evento(int $negocioId, int $eventoId): array
    {
        $e = $this->q('SELECT * FROM eventos WHERE id = ? AND negocio_id = ?', [$eventoId, $negocioId])->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    /** Plano del evento (o null si todavía no tiene). */
    public function delEvento(int $negocioId, int $eventoId): ?array
    {
        $this->evento($negocioId, $eventoId);
        $id = $this->q("SELECT id FROM planos WHERE negocio_id = ? AND tipo = 'evento' AND evento_id = ?", [$negocioId, $eventoId])->fetchColumn();
        return $id ? $this->plano($negocioId, (int) $id) : null;
    }

    /** Plantillas para elegir al armar el plano del evento: primero las de los espacios del evento. */
    public function plantillasParaEvento(int $negocioId, int $eventoId): array
    {
        $this->evento($negocioId, $eventoId);
        $suyos = array_map('intval', $this->q('SELECT espacio_id FROM evento_espacios WHERE evento_id = ?', [$eventoId])->fetchAll(PDO::FETCH_COLUMN));
        $lista = $this->plantillas($negocioId);
        foreach ($lista as &$p) $p['del_evento'] = in_array((int) $p['espacio_id'], $suyos, true);
        unset($p);
        usort($lista, fn($a, $b) => (int) $b['del_evento'] <=> (int) $a['del_evento']);
        return $lista;
    }

    /** "Mesa N" que sigue, sin repetir los nombres que ya hay. */
    private static function siguienteMesa(array $nombres): string
    {
        $max = 0;
        foreach ($nombres as $n) if (preg_match('/^mesa\s+(\d{1,4})$/iu', trim((string) $n), $m)) $max = max($max, (int) $m[1]);
        return 'Mesa ' . ($max + 1);
    }

    private function crearMesa(int $eventoId, string $nombre, int $capacidad): int
    {
        $n = $this->q('SELECT count(*), COALESCE(max(orden), 0) FROM mesas WHERE evento_id = ?', [$eventoId])->fetch(PDO::FETCH_NUM);
        if ((int) $n[0] >= self::MAX_MESAS) throw new RuntimeException('Un evento puede tener hasta ' . self::MAX_MESAS . ' mesas.');
        return (int) $this->q('INSERT INTO mesas (evento_id, nombre, capacidad, orden) VALUES (?,?,?,?) RETURNING id',
                              [$eventoId, mb_substr($nombre, 0, 60), $capacidad, (int) $n[1] + 1])->fetchColumn();
    }

    /**
     * Arma el plano del evento: desde una plantilla (se copia) o desde cero ($ancho × $largo).
     * Al copiar, las mesas de la plantilla toman las mesas que el evento ya tiene en invitados (primero las del mismo
     * nombre, después en orden); las que sobran se crean como mesas nuevas.
     */
    public function crearParaEvento(int $negocioId, int $eventoId, ?int $plantillaId, mixed $ancho = null, mixed $largo = null, ?array $usuario = null): int
    {
        $ev = $this->evento($negocioId, $eventoId);
        if ($this->delEvento($negocioId, $eventoId)) throw new RuntimeException('Este evento ya tiene su plano. Ábrelo para cambiarlo.');
        $base = null;
        if ($plantillaId) {
            $base = $this->plano($negocioId, $plantillaId);
            if ($base['tipo'] !== 'plantilla') throw new RuntimeException('Elige una distribución de tus espacios.');
            $a = $base['ancho'];
            $l = $base['largo'];
            $espacioId = (int) $base['espacio_id'];
            $nombre = $base['nombre'];
        } else {
            $a = self::medida($ancho, 'el ancho');
            $l = self::medida($largo, 'el largo');
            $espacioId = (int) ($this->q('SELECT ee.espacio_id FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = ? ORDER BY es.orden, es.id LIMIT 1',
                                         [$eventoId])->fetchColumn() ?: 0) ?: null;
            $nombre = 'Plano de ' . $ev['nombre'];
        }
        return $this->enTransaccion(function () use ($negocioId, $eventoId, $base, $a, $l, $espacioId, $nombre, $usuario) {
            $id = (int) $this->q("INSERT INTO planos (negocio_id, tipo, espacio_id, evento_id, plantilla_id, nombre, ancho, largo, creado_por)
                                  VALUES (?, 'evento', ?, ?, ?, ?, ?, ?, ?) RETURNING id",
                                 [$negocioId, $espacioId, $eventoId, $base['id'] ?? null, mb_substr($nombre, 0, 80), $a, $l, $usuario['id'] ?? null])->fetchColumn();
            if ($base) {
                $libres = $this->q('SELECT id, nombre, capacidad FROM mesas WHERE evento_id = ? ORDER BY orden, id', [$eventoId])->fetchAll();
                $nombres = array_column($libres, 'nombre');
                $ins = $this->db->prepare('INSERT INTO plano_elementos (plano_id, tipo, x, y, ancho, alto, rotacion, etiqueta, puestos, mesa_id, orden) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $orden = 0;
                $elementos = $this->elementos((int) $base['id']);
                // 1) Mesas con el mismo nombre ("Mesa 5" con "Mesa 5"); 2) las demás, en orden
                $porNombre = [];
                foreach ($elementos as $i => $el) {
                    if (!self::esMesa($el['tipo']) || $el['etq'] === '') continue;
                    foreach ($libres as $j => $m) {
                        if (mb_strtolower(trim($m['nombre'])) === mb_strtolower($el['etq'])) { $porNombre[$i] = $m; unset($libres[$j]); break; }
                    }
                }
                $libres = array_values($libres);
                foreach ($elementos as $i => $el) {
                    $mesaId = null;
                    if (self::esMesa($el['tipo'])) {
                        if ($m = ($porNombre[$i] ?? array_shift($libres))) {
                            $mesaId = (int) $m['id'];
                            $el['etq'] = $m['nombre'];
                            $el['puestos'] = (int) $m['capacidad'];
                        } else {
                            $etq = $el['etq'] !== '' && !in_array(mb_strtolower($el['etq']), array_map('mb_strtolower', $nombres), true) ? $el['etq'] : self::siguienteMesa($nombres);
                            $mesaId = $this->crearMesa($eventoId, $etq, (int) ($el['puestos'] ?? 10));
                            $el['etq'] = $etq;
                            $nombres[] = $etq;
                        }
                    }
                    $ins->execute([$id, $el['tipo'], $el['x'], $el['y'], $el['an'], $el['al'], $el['rot'], $el['etq'] !== '' ? $el['etq'] : null,
                                   $el['puestos'], $mesaId, $orden++]);
                }
            }
            $this->cambio($negocioId, $eventoId, $base ? "Armó el plano del salón con la distribución «{$base['nombre']}»" : 'Armó el plano del salón desde cero', $usuario);
            return $id;
        });
    }

    // ------------------------------------------------------------------
    // Guardar (lo que manda el editor)
    // ------------------------------------------------------------------

    /**
     * Revisa y limpia los elementos que llegan del editor (JSON). Lanza un error claro si algo no cuadra.
     * Cada elemento: id?, tipo, x, y, an, al, rot, etq, puestos, mesa_id?
     * @return list<array>
     */
    public static function validar(mixed $elementos, float $ancho, float $largo, bool $esEvento): array
    {
        if (!is_array($elementos) || !array_is_list($elementos)) throw new RuntimeException('El plano llegó incompleto. Recarga la página e intenta otra vez.');
        if (count($elementos) > self::MAX_ELEMENTOS) throw new RuntimeException('Un plano puede tener hasta ' . self::MAX_ELEMENTOS . ' elementos.');
        $maxLado = max($ancho, $largo);
        $res = [];
        foreach ($elementos as $i => $el) {
            if (!is_array($el)) throw new RuntimeException('El plano llegó incompleto. Recarga la página e intenta otra vez.');
            $tipo = (string) ($el['tipo'] ?? '');
            if (!isset(self::TIPOS[$tipo])) throw new RuntimeException('Hay un elemento de un tipo que no existe. Recarga la página.');
            $def = self::TIPOS[$tipo];
            $etq = self::limpiarTexto($el['etq'] ?? '', 60);
            $nombre = $etq !== '' ? "«{$etq}»" : 'Un elemento (' . mb_strtolower($def['nombre']) . ')';
            foreach (['x', 'y', 'an', 'al'] as $k) {
                if (!isset($el[$k]) || !is_numeric($el[$k]) || !is_finite((float) $el[$k])) throw new RuntimeException("$nombre no tiene bien su posición o su tamaño.");
            }
            $x = round((float) $el['x'], 2);
            $y = round((float) $el['y'], 2);
            if ($x < -0.01 || $y < -0.01 || $x > $ancho + 0.01 || $y > $largo + 0.01) {
                throw new RuntimeException("$nombre quedó fuera del salón (" . self::m($ancho) . ' × ' . self::m($largo) . ' m). Muévelo adentro.');
            }
            $an = round((float) $el['an'], 2);
            $al = round((float) $el['al'], 2);
            if ($tipo === 'redonda') $al = $an;
            if ($an < 0.2 || $al < 0.2 || $an > $maxLado || $al > $maxLado) {
                throw new RuntimeException("El tamaño de $nombre no es válido: va de 0,2 m hasta " . self::m($maxLado) . ' m.');
            }
            $rot = (int) round((float) ($el['rot'] ?? 0));
            $rot = (($rot % 360) + 360) % 360;
            $puestos = null;
            $mesaId = null;
            if (self::esMesa($tipo)) {
                $p = $el['puestos'] ?? $def['puestos'];
                if (!is_numeric($p) || (int) $p != $p || (int) $p < 1 || (int) $p > 100) throw new RuntimeException("Los puestos de $nombre van de 1 a 100.");
                $puestos = (int) $p;
                if ($esEvento && isset($el['mesa_id']) && is_numeric($el['mesa_id']) && (int) $el['mesa_id'] > 0) $mesaId = (int) $el['mesa_id'];
            }
            $id = isset($el['id']) && is_numeric($el['id']) && (int) $el['id'] > 0 ? (int) $el['id'] : null;
            $res[] = ['id' => $id, 'tipo' => $tipo, 'x' => max(0.0, min($ancho, $x)), 'y' => max(0.0, min($largo, $y)), 'an' => $an, 'al' => $al,
                      'rot' => $rot, 'etq' => $etq, 'puestos' => $puestos, 'mesa_id' => $mesaId];
        }
        return $res;
    }

    /**
     * Guarda todo el plano (lo que hay en pantalla). $version = la que tenía el editor al abrir (si otra persona guardó antes, avisa).
     * Devuelve los datos frescos para el editor (datos()) + 'avisos' (mesas que se pasan, mesas con invitados que salieron del plano).
     */
    public function guardar(int $negocioId, int $planoId, mixed $elementos, ?int $version = null, ?array $usuario = null): array
    {
        $p = $this->plano($negocioId, $planoId);
        $esEvento = $p['tipo'] === 'evento';
        $limpios = self::validar($elementos, $p['ancho'], $p['largo'], $esEvento);
        $avisos = $this->enTransaccion(function () use ($p, $planoId, $version, $esEvento, &$limpios, $usuario, $negocioId) {
            $actual = (int) $this->q('SELECT version FROM planos WHERE id = ? FOR UPDATE', [$planoId])->fetchColumn();
            if ($version !== null && $version !== $actual) {
                throw new RuntimeException('Otra persona cambió este plano mientras lo editabas. Recarga la página para ver lo último (tus cambios de ahora no se guardaron).');
            }
            $existentes = [];
            foreach ($this->q('SELECT id, mesa_id FROM plano_elementos WHERE plano_id = ?', [$planoId])->fetchAll() as $r) $existentes[(int) $r['id']] = $r;
            [$avisos, $mesasBorrar] = $esEvento ? $this->sincronizarMesas((int) $p['evento_id'], $limpios, $existentes) : [[], []];
            // Primero se sueltan los enlaces con las mesas (así una mesa puede pasar de un elemento a otro sin chocar)
            $this->q('UPDATE plano_elementos SET mesa_id = NULL WHERE plano_id = ? AND mesa_id IS NOT NULL', [$planoId]);
            $upd = $this->db->prepare('UPDATE plano_elementos SET tipo = ?, x = ?, y = ?, ancho = ?, alto = ?, rotacion = ?, etiqueta = ?, puestos = ?, mesa_id = ?, orden = ?
                                        WHERE id = ? AND plano_id = ?');
            $ins = $this->db->prepare('INSERT INTO plano_elementos (plano_id, tipo, x, y, ancho, alto, rotacion, etiqueta, puestos, mesa_id, orden)
                                       VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
            $quedan = [];
            foreach ($limpios as $orden => &$el) {
                $vals = [$el['tipo'], $el['x'], $el['y'], $el['an'], $el['al'], $el['rot'], $el['etq'] !== '' ? $el['etq'] : null, $el['puestos'], $el['mesa_id'], $orden];
                if ($el['id'] !== null && isset($existentes[$el['id']]) && !isset($quedan[$el['id']])) {
                    $upd->execute([...$vals, $el['id'], $planoId]);
                } else {
                    $ins->execute([$planoId, ...$vals]);
                    $el['id'] = (int) $ins->fetchColumn();
                }
                $quedan[$el['id']] = true;
            }
            unset($el);
            $sobran = array_diff(array_keys($existentes), array_keys($quedan));
            if ($sobran) $this->q('DELETE FROM plano_elementos WHERE plano_id = ? AND id IN (' . implode(',', array_map('intval', $sobran)) . ')', [$planoId]);
            if ($mesasBorrar) $this->q('DELETE FROM mesas WHERE evento_id = ? AND id IN (' . implode(',', array_map('intval', $mesasBorrar)) . ')
                                         AND NOT EXISTS (SELECT 1 FROM invitados i WHERE i.mesa_id = mesas.id)', [(int) $p['evento_id']]);
            $this->q('UPDATE planos SET version = version + 1, actualizado_en = now() WHERE id = ?', [$planoId]);
            if ($esEvento && (int) $actual === 1) $this->cambio($negocioId, (int) $p['evento_id'], 'Guardó el plano del salón', $usuario);
            return $avisos;
        });
        return $this->datos($negocioId, $planoId) + ['avisos' => $avisos];
    }

    /**
     * Enlaza cada mesa del plano con su mesa de invitados (o la crea), y avisa de lo que hay que mirar.
     * Cambia $limpios: pone mesa_id y, si la etiqueta está vacía, el nombre de la mesa.
     * @return array{0: list<string>, 1: list<int>}  avisos y mesas vacías que salieron del plano (para borrar)
     */
    private function sincronizarMesas(int $eventoId, array &$limpios, array $existentes): array
    {
        $mesas = [];
        foreach ($this->q("SELECT m.id, m.nombre, m.capacidad,
                                  COALESCE(sum(CASE WHEN i.estado = 'confirmado' THEN i.confirmados WHEN i.estado = 'pendiente' THEN i.pases ELSE 0 END), 0) AS ocupados,
                                  count(i.id) AS invitados
                             FROM mesas m LEFT JOIN invitados i ON i.mesa_id = m.id WHERE m.evento_id = ? GROUP BY m.id ORDER BY m.orden, m.id", [$eventoId])->fetchAll() as $m) {
            $mesas[(int) $m['id']] = $m;
        }
        $antes = array_values(array_filter(array_map(fn($r) => (int) $r['mesa_id'], $existentes)));
        $nombres = array_column($mesas, 'nombre');
        $usadas = [];
        $avisos = [];
        $editar = $this->db->prepare('UPDATE mesas SET nombre = ?, capacidad = ? WHERE id = ? AND evento_id = ?');
        foreach ($limpios as &$el) {
            if (!self::esMesa($el['tipo'])) { $el['mesa_id'] = null; continue; }
            $id = $el['mesa_id'];
            if ($id !== null && isset($mesas[$id]) && !isset($usadas[$id])) {
                $m = $mesas[$id];
                if ($el['etq'] === '') $el['etq'] = $m['nombre'];
                if ($el['etq'] !== $m['nombre'] || $el['puestos'] !== (int) $m['capacidad']) $editar->execute([$el['etq'], $el['puestos'], $id, $eventoId]);
                if ((int) $m['ocupados'] > $el['puestos']) {
                    $avisos[] = "{$el['etq']} tiene {$m['ocupados']} personas sentadas y solo {$el['puestos']} puestos.";
                }
            } else {
                // Mesa nueva (o una que ya no existe, por ejemplo al deshacer): se crea en invitados
                if ($el['etq'] === '') $el['etq'] = self::siguienteMesa($nombres);
                $id = $this->crearMesa($eventoId, $el['etq'], $el['puestos']);
                $nombres[] = $el['etq'];
            }
            $el['mesa_id'] = $id;
            $usadas[$id] = true;
        }
        unset($el);
        $borrar = [];
        foreach (array_diff($antes, array_keys($usadas)) as $id) {
            if (!isset($mesas[$id])) continue;
            if ((int) $mesas[$id]['invitados'] > 0) {
                $avisos[] = "{$mesas[$id]['nombre']} tiene invitados sentados: salió del plano, pero sigue en la lista de mesas (está en «Mesas sin ubicar»).";
            } else {
                $borrar[] = $id;
            }
        }
        return [$avisos, $borrar];
    }

    // ------------------------------------------------------------------
    // Datos para el editor y para mirar
    // ------------------------------------------------------------------

    /**
     * Todo lo que necesita el editor: plano, elementos y, si es de un evento, la ocupación de cada mesa
     * (de invitados), las mesas sin ubicar y el resumen de puestos contra invitados.
     */
    public function datos(int $negocioId, int $planoId): array
    {
        $p = $this->plano($negocioId, $planoId);
        $elementos = $this->elementos($planoId);
        $res = [
            'plano' => ['id' => (int) $p['id'], 'tipo' => $p['tipo'], 'nombre' => $p['nombre'], 'ancho' => $p['ancho'], 'largo' => $p['largo'],
                        'version' => $p['version'], 'espacio' => $p['espacio'], 'evento_id' => $p['evento_id'] !== null ? (int) $p['evento_id'] : null],
            'elementos' => $elementos, 'sin_ubicar' => [], 'resumen' => null,
        ];
        $puestos = 0;
        $mesasPlano = 0;
        foreach ($elementos as $el) if (self::esMesa($el['tipo'])) { $puestos += (int) $el['puestos']; $mesasPlano++; }
        if ($p['tipo'] !== 'evento') {
            $res['resumen'] = ['mesas' => $mesasPlano, 'puestos' => $puestos];
            return $res;
        }
        $inv = new Invitados($this->db);
        $eventoId = (int) $p['evento_id'];
        $plano = $inv->plano($negocioId, $eventoId);
        $porMesa = [];
        foreach ($plano['mesas'] as $m) $porMesa[(int) $m['id']] = $m;
        $ubicadas = [];
        foreach ($res['elementos'] as &$el) {
            if (!self::esMesa($el['tipo'])) continue;
            $m = $el['mesa_id'] !== null ? ($porMesa[$el['mesa_id']] ?? null) : null;
            if (!$m) { $el['ocupados'] = 0; $el['invitados'] = []; continue; }
            $ubicadas[$el['mesa_id']] = true;
            $el['etq'] = $m['nombre'];          // invitados manda: si cambiaron el nombre o los puestos allá, se ve aquí
            $el['puestos'] = (int) $m['capacidad'];
            $el['ocupados'] = (int) $m['ocupados'];
            $el['invitados'] = self::listaCorta($m['lista']);
        }
        unset($el);
        $puestos = 0;
        foreach ($res['elementos'] as $el) if (self::esMesa($el['tipo'])) $puestos += (int) $el['puestos'];
        foreach ($plano['mesas'] as $m) {
            if (isset($ubicadas[(int) $m['id']])) continue;
            $res['sin_ubicar'][] = ['mesa_id' => (int) $m['id'], 'etq' => $m['nombre'], 'puestos' => (int) $m['capacidad'], 'ocupados' => (int) $m['ocupados'],
                                    'invitados' => self::listaCorta($m['lista'])];
        }
        $c = $inv->contadores($negocioId, $eventoId);
        $res['resumen'] = [
            'mesas' => $mesasPlano, 'puestos' => $puestos, 'confirmados' => $c['confirmados'], 'esperados' => $c['esperados'],
            'pases' => $c['pases'], 'sin_mesa' => $c['sin_mesa'], 'sin_ubicar' => count($res['sin_ubicar']),
            'ocupados' => array_sum(array_map(fn($el) => (int) ($el['ocupados'] ?? 0), $res['elementos'])),
        ];
        return $res;
    }

    /** Invitados de una mesa para mostrar: nombre, cuántos van y si todavía no confirma. */
    private static function listaCorta(array $lista): array
    {
        return array_map(fn($i) => ['n' => $i['nombre'], 'c' => (int) ($i['estado'] === 'confirmado' ? $i['confirmados'] : $i['pases']), 'p' => $i['estado'] === 'pendiente'],
                         $lista);
    }

    /** Estado de ocupación de una mesa: vacia, parcial, llena, pasa (para el color). */
    public static function ocupacion(array $el): string
    {
        $o = (int) ($el['ocupados'] ?? 0);
        $c = (int) ($el['puestos'] ?? 0);
        return match (true) { $o <= 0 => 'vacia', $o > $c => 'pasa', $o === $c => 'llena', default => 'parcial' };
    }

    /** Colores de cada tipo: [relleno, borde, texto]. Los mismos que usa el editor (mod_plano.js). */
    public const COLORES = [
        'mesa'    => ['#FFFFFF', '#6B2D3C', '#1F1A1C'],
        'vacia'   => ['#FFFFFF', '#9A8E92', '#1F1A1C'],
        'parcial' => ['#FFF1CC', '#C9952F', '#5A420C'],
        'llena'   => ['#DDEFE3', '#3E7C59', '#1C5A31'],
        'pasa'    => ['#F8E5E2', '#B53D33', '#7E2A23'],
        'pista'   => ['#EFE4CF', '#B8862B', '#6A4D0D'],
        'tarima'  => ['#4A3F43', '#2E2629', '#FFFFFF'],
        'barra'   => ['#C99A48', '#8C6420', '#FFFFFF'],
        'buffet'  => ['#F1E2C4', '#B8862B', '#5A420C'],
        'dulces'  => ['#F7D9E3', '#C46A8A', '#7A2D48'],
        'dj'      => ['#2E2A2C', '#111111', '#FFFFFF'],
        'entrada' => ['#3E7C59', '#2C5C41', '#FFFFFF'],
        'banos'   => ['#DCE7F2', '#5E7FA3', '#22457A'],
        'columna' => ['#8C8286', '#5B5054', '#FFFFFF'],
        'texto'   => ['none', 'none', '#4A1E2A'],
    ];

    /**
     * Dibujo del plano en SVG (solo para mirar: portal de los novios, vista previa, plantillas).
     * $op: 'id' (prefijo único), 'ocupacion' (colorear las mesas por invitados), 'clase'.
     */
    public static function svg(array $datos, array $op = []): string
    {
        $p = $datos['plano'];
        $A = (float) $p['ancho'];
        $L = (float) $p['largo'];
        $uid = preg_replace('/[^a-z0-9]/', '', (string) ($op['id'] ?? ('p' . $p['id'])));
        $conOcupacion = ($op['ocupacion'] ?? false) && $p['tipo'] === 'evento';
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $n = fn(float $v) => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');
        $margen = 0.8;
        $svg = '<svg class="' . $h($op['clase'] ?? 'pl-svg-fijo') . '" viewBox="' . $n(-$margen) . ' ' . $n(-$margen) . ' ' . $n($A + 2 * $margen) . ' ' . $n($L + 2 * $margen)
             . '" role="img" aria-label="' . $h('Plano: ' . $p['nombre'] . ', ' . self::m($A) . ' por ' . self::m($L) . ' metros') . '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">'
             . '<defs><pattern id="pl-pista-' . $uid . '" width="1" height="1" patternUnits="userSpaceOnUse"><rect width="1" height="1" fill="#EFE4CF"/><rect width=".5" height=".5" fill="#E4D4B4"/><rect x=".5" y=".5" width=".5" height=".5" fill="#E4D4B4"/></pattern></defs>'
             . '<rect x="0" y="0" width="' . $n($A) . '" height="' . $n($L) . '" fill="#FBF9F8" stroke="#4A1E2A" stroke-width=".12"/>';
        // Cuadrícula cada metro (más marcada cada 5)
        for ($x = 1; $x < $A; $x++) $svg .= '<line x1="' . $x . '" y1="0" x2="' . $x . '" y2="' . $n($L) . '" stroke="' . ($x % 5 ? '#EFE8E9' : '#E2D6D8') . '" stroke-width=".03"/>';
        for ($y = 1; $y < $L; $y++) $svg .= '<line x1="0" y1="' . $y . '" x2="' . $n($A) . '" y2="' . $y . '" stroke="' . ($y % 5 ? '#EFE8E9' : '#E2D6D8') . '" stroke-width=".03"/>';
        foreach ($datos['elementos'] as $el) {
            $svg .= self::svgElemento($el, $conOcupacion, $uid, $h, $n);
        }
        return $svg . '</svg>';
    }

    private static function svgElemento(array $el, bool $conOcupacion, string $uid, callable $h, callable $n): string
    {
        $tipo = $el['tipo'];
        $an = (float) $el['an'];
        $al = (float) $el['al'];
        $mesa = self::esMesa($tipo);
        $estado = $mesa ? ($conOcupacion ? self::ocupacion($el) : 'mesa') : $tipo;
        [$relleno, $borde, $tinta] = self::COLORES[$estado] ?? self::COLORES['mesa'];
        $g = '<g transform="translate(' . $n((float) $el['x']) . ' ' . $n((float) $el['y']) . ')' . ((int) $el['rot'] ? ' rotate(' . (int) $el['rot'] . ')' : '') . '">';
        if ($mesa) {
            foreach (self::sillas($tipo, $an, $al, (int) $el['puestos']) as [$sx, $sy]) {
                $g .= '<circle cx="' . $n($sx) . '" cy="' . $n($sy) . '" r=".2" fill="#FFFFFF" stroke="#B9AEB1" stroke-width=".04"/>';
            }
        }
        if ($tipo === 'redonda') {
            $g .= '<circle r="' . $n($an / 2) . '" fill="' . $relleno . '" stroke="' . $borde . '" stroke-width=".07"/>';
        } elseif ($tipo !== 'texto') {
            $fill = $tipo === 'pista' ? 'url(#pl-pista-' . $uid . ')' : $relleno;
            $g .= '<rect x="' . $n(-$an / 2) . '" y="' . $n(-$al / 2) . '" width="' . $n($an) . '" height="' . $n($al) . '" rx="' . ($tipo === 'columna' ? '0' : '.08')
                . '" fill="' . $fill . '" stroke="' . $borde . '" stroke-width=".06"/>';
        }
        $texto = $mesa ? self::etiquetaCorta($el['etq']) : ($el['etq'] !== '' ? $el['etq'] : ($tipo === 'columna' ? '' : self::TIPOS[$tipo]['corto']));
        if ($texto !== '') {
            $lado = $tipo === 'texto' ? $al : min($an, $al);
            $tam = max(0.22, min($mesa ? 0.62 : 0.55, $lado * ($mesa ? 0.36 : 0.42)));
            if (!$mesa && $tipo !== 'texto') $tam = min($tam, $an / max(1, mb_strlen($texto)) * 1.7);
            if ($mesa) { $texto = self::recortar($texto, 14); $tam = max(0.16, min($tam, $an * 0.95 / max(1, mb_strlen($texto) * 0.56))); }
            if ($tipo === 'texto') $tam = min(0.9, max(0.3, $al * 0.6));
            $dy = $mesa && $conOcupacion ? -$tam * 0.15 : $tam * 0.35;
            $giro = self::giroTexto($tipo, (int) $el['rot']);
            $tr = $giro ? ' transform="rotate(' . $giro . ')"' : '';
            $g .= '<text x="0" y="' . $n($dy) . '" text-anchor="middle" font-family="DM Sans, Arial, sans-serif" font-weight="700" font-size="' . $n($tam) . '" fill="' . $tinta . '"'
                . $tr . '>' . $h($texto) . '</text>';
            if ($mesa && $conOcupacion) {
                $g .= '<text x="0" y="' . $n($tam * 0.95) . '" text-anchor="middle" font-family="DM Sans, Arial, sans-serif" font-size="' . $n($tam * 0.62) . '" fill="' . $tinta . '"'
                    . $tr . '>' . (int) ($el['ocupados'] ?? 0) . '/' . (int) $el['puestos'] . '</text>';
            }
        }
        return $g . '</g>';
    }

    /**
     * Giro del texto dentro del elemento: en las mesas siempre derecho; en lo demás va a lo largo del elemento
     * (así "Barra" se lee en una barra parada), pero nunca de cabeza. Igual en mod_plano.js y PdfPlano.
     */
    public static function giroTexto(string $tipo, int $rot): int
    {
        $rot = (($rot % 360) + 360) % 360;
        if (self::esMesa($tipo)) return $rot ? -$rot : 0;
        return $rot > 90 && $rot <= 270 ? 180 : 0;
    }

    /**
     * Posiciones de las sillas alrededor de una mesa (relativas a su centro). Más de 24 puestos: no se dibujan.
     * Las usan el SVG, el PDF y el editor (misma lógica en mod_plano.js).
     * @return list<array{0: float, 1: float}>
     */
    public static function sillas(string $tipo, float $an, float $al, int $puestos): array
    {
        if ($puestos < 1 || $puestos > 24) return [];
        $res = [];
        if ($tipo === 'redonda') {
            $r = $an / 2 + 0.28;
            for ($i = 0; $i < $puestos; $i++) {
                $a = -M_PI / 2 + 2 * M_PI * $i / $puestos;
                $res[] = [round($r * cos($a), 3), round($r * sin($a), 3)];
            }
            return $res;
        }
        // Mesa angosta (estilo escuela): todas las sillas de un lado, mirando al frente
        if ($al <= 0.65) {
            for ($i = 0; $i < $puestos; $i++) $res[] = [round(-$an / 2 + $an * ($i + 0.5) / $puestos, 3), round($al / 2 + 0.28, 3)];
            return $res;
        }
        // Rectangular e imperial: la mitad arriba y la mitad abajo (lo que sobra, arriba)
        $arriba = (int) ceil($puestos / 2);
        $abajo = $puestos - $arriba;
        foreach ([[$arriba, -$al / 2 - 0.28], [$abajo, $al / 2 + 0.28]] as [$cant, $y]) {
            for ($i = 0; $i < $cant; $i++) $res[] = [round(-$an / 2 + $an * ($i + 0.5) / $cant, 3), round($y, 3)];
        }
        return $res;
    }
}
