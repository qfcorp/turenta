<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Encuesta de satisfacción después del evento (migración 070).
 *  - Página pública con el enlace secreto del evento (eventos.token_encuesta): ¿nos recomendarías? (0 a 10, NPS),
 *    calificación de 1 a 5 de la comida, la atención, las instalaciones y la organización, y un comentario.
 *  - Una sola respuesta por evento. Freno anti-abuso: campo trampa y máximo 10 respuestas por hora desde la misma IP.
 *  - Si recomienda con 9 o 10 y el negocio tiene enlace de reseñas (negocios.resenas_url), se le muestra el botón de Google.
 *  - Si responde 6 o menos, se avisa al dueño al instante (campana y Telegram).
 *  - El enlace va en el mensaje "Agradecer y pedir una reseña" de Mensajes de hoy (Cobranza) y en el portal después del evento.
 *  - NPS = % de promotores (9-10) − % de detractores (0-6).
 */
final class Encuestas
{
    public const ASPECTOS = ['comida' => 'La comida', 'atencion' => 'La atención del personal', 'instalaciones' => 'Las instalaciones',
                             'organizacion' => 'La organización del evento'];
    /** Estados en los que se puede responder (desde el día del evento). */
    public const ESTADOS = ['separado', 'confirmado', 'realizado'];
    public const DIAS_ABIERTA = 120;
    public const MAX_POR_IP_HORA = 10;
    public const NPS_BAJO = 6;

    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    /** Evento (con datos del negocio) por el enlace secreto de la encuesta. */
    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $e = $this->q('SELECT e.id, e.negocio_id, e.codigo, e.nombre, e.fecha, e.estado, e.tipo, e.token_cliente, e.token_encuesta, c.nombre AS cliente,
                              n.nombre AS negocio, n.color, n.slug, n.logo, n.resenas_url, n.whatsapp, n.telefono, n.estado AS cuenta
                         FROM eventos e JOIN clientes c ON c.id = e.cliente_id JOIN negocios n ON n.id = e.negocio_id
                        WHERE e.token_encuesta = ?', [$token])->fetch();
        return $e ?: null;
    }

    /** ¿Se puede responder? null si sí; si no, el motivo para mostrar. */
    public static function motivoCerrada(array $ev, ?DateTimeImmutable $hoy = null): ?string
    {
        $hoy ??= new DateTimeImmutable('today');
        if (in_array($ev['cuenta'] ?? '', ['cancelado'], true)) return 'Esta encuesta ya no está disponible.';
        if (!in_array($ev['estado'], self::ESTADOS, true)) return 'Esta encuesta no está disponible para este evento.';
        if ($ev['fecha'] > $hoy->format('Y-m-d')) return 'La encuesta se abre el día de tu evento. ¡Te esperamos!';
        if ($ev['fecha'] < $hoy->modify('-' . self::DIAS_ABIERTA . ' days')->format('Y-m-d')) return 'Esta encuesta ya se cerró. ¡Gracias por celebrar con nosotros!';
        return null;
    }

    public function deEvento(int $negocioId, int $eventoId): ?array
    {
        return $this->q('SELECT * FROM encuestas WHERE negocio_id = ? AND evento_id = ?', [$negocioId, $eventoId])->fetch() ?: null;
    }

    /** promotor (9-10), pasivo (7-8) o detractor (0-6). */
    public static function grupo(int $nps): string
    {
        return $nps >= 9 ? 'promotor' : ($nps >= 7 ? 'pasivo' : 'detractor');
    }

    /** NPS de una lista de calificaciones 0-10 (null si no hay ninguna). */
    public static function nps(array $valores): ?int
    {
        if (!$valores) return null;
        $n = count($valores);
        $prom = count(array_filter($valores, fn($v) => (int) $v >= 9));
        $det = count(array_filter($valores, fn($v) => (int) $v <= 6));
        return (int) round(($prom - $det) * 100 / $n);
    }

    private static function entero(mixed $v, int $min, int $max): ?int
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (!preg_match('/^\d{1,2}$/', $v) || (int) $v < $min || (int) $v > $max) return -1;
        return (int) $v;
    }

    /**
     * Guarda la respuesta del cliente. $d: nps, comida, atencion, instalaciones, organizacion, comentario, sitio_web (trampa).
     * Devuelve la encuesta guardada.
     */
    public function responder(string $token, array $d, string $ip = '', ?DateTimeImmutable $hoy = null): array
    {
        $ev = $this->porToken($token);
        if (!$ev) throw new RuntimeException('Este enlace no es válido.');
        $motivo = self::motivoCerrada($ev, $hoy);
        if ($motivo) throw new RuntimeException($motivo);
        if (trim((string) ($d['sitio_web'] ?? '')) !== '') throw new RuntimeException('No se pudo enviar. Intenta de nuevo.');
        $nps = self::entero($d['nps'] ?? '', 0, 10);
        if ($nps === null || $nps < 0) throw new RuntimeException('Elige del 0 al 10 qué tanto nos recomendarías.');
        $notas = [];
        foreach (array_keys(self::ASPECTOS) as $k) {
            $v = self::entero($d[$k] ?? '', 1, 5);
            if ($v === -1) throw new RuntimeException('Las calificaciones van de 1 a 5 estrellas.');
            $notas[$k] = $v;
        }
        $comentario = mb_substr(trim(str_replace("\r\n", "\n", (string) ($d['comentario'] ?? ''))), 0, 1000);
        $ip = mb_substr($ip, 0, 45);
        if ($ip !== '') {
            $n = (int) $this->q("SELECT count(*) FROM encuestas WHERE ip = ? AND creado_en > now() - interval '1 hour'", [$ip])->fetchColumn();
            if ($n >= self::MAX_POR_IP_HORA) throw new RuntimeException('Recibimos muchas respuestas desde tu conexión. Intenta más tarde.');
        }
        $st = $this->q('INSERT INTO encuestas (negocio_id, evento_id, nps, comida, atencion, instalaciones, organizacion, comentario, ip)
                        VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT (evento_id) DO NOTHING RETURNING *',
                       [$ev['negocio_id'], $ev['id'], $nps, $notas['comida'], $notas['atencion'], $notas['instalaciones'], $notas['organizacion'],
                        $comentario ?: null, $ip ?: null]);
        $fila = $st->fetch();
        if (!$fila) throw new RuntimeException('Ya recibimos tu opinión sobre este evento. ¡Gracias!');
        $nid = (int) $ev['negocio_id'];
        $this->q("INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?, 'Cliente', ?)",
                 [$nid, $ev['id'], 'Respondió la encuesta de satisfacción: nos recomendaría con ' . $nps . ' de 10']);
        if ($nps <= self::NPS_BAJO) {
            $txt = '⚠️ Opinión baja de ' . $ev['cliente'] . ' (' . $ev['nombre'] . ', ' . $ev['codigo'] . '): nos recomendaría con ' . $nps . ' de 10.'
                 . ($comentario !== '' ? ' Dijo: «' . mb_substr($comentario, 0, 160) . (mb_strlen($comentario) > 160 ? '…' : '') . '».' : '')
                 . ' Llámale hoy para saber qué pasó.';
            (new Notificaciones($this->db))->alNegocio($nid, $txt, (int) $ev['id'], ['dueno']);
        }
        return $fila;
    }

    /**
     * Reporte de satisfacción de los eventos con fecha en el periodo: NPS, promedios, por mes y comentarios.
     */
    public function reporte(int $negocioId, string $desde, string $hasta, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $filas = $this->q('SELECT x.*, e.codigo, e.nombre AS evento, e.fecha, e.tipo, c.nombre AS cliente
                             FROM encuestas x JOIN eventos e ON e.id = x.evento_id JOIN clientes c ON c.id = e.cliente_id
                            WHERE x.negocio_id = ? AND e.negocio_id = ? AND e.fecha BETWEEN ?::date AND ?::date
                         ORDER BY x.creado_en DESC, x.id DESC', [$negocioId, $negocioId, $desde, $hasta])->fetchAll();
        $eventos = (int) $this->q("SELECT count(*) FROM eventos WHERE negocio_id = ? AND estado IN ('separado','confirmado','realizado')
                                     AND fecha BETWEEN ?::date AND LEAST(?::date, ?::date)", [$negocioId, $desde, $hasta, $hoy->format('Y-m-d')])->fetchColumn();
        $valores = array_map(fn($f) => (int) $f['nps'], $filas);
        $n = count($valores);
        $cuenta = ['promotor' => 0, 'pasivo' => 0, 'detractor' => 0];
        foreach ($valores as $v) $cuenta[self::grupo($v)]++;
        $promedios = [];
        foreach (array_keys(self::ASPECTOS) as $k) {
            $vals = array_values(array_filter(array_map(fn($f) => $f[$k], $filas), fn($v) => $v !== null));
            $promedios[$k] = $vals ? round(array_sum(array_map('intval', $vals)) / count($vals), 1) : null;
        }
        $porMes = [];
        foreach ($filas as $f) $porMes[substr((string) $f['fecha'], 0, 7)][] = (int) $f['nps'];
        ksort($porMes);
        $meses = [];
        foreach ($porMes as $mes => $vals) $meses[$mes] = ['respuestas' => count($vals), 'nps' => self::nps($vals)];
        return [
            'respuestas' => $n, 'eventos' => $eventos, 'tasa' => $eventos > 0 ? min(100, (int) round($n * 100 / $eventos)) : 0,
            'nps' => self::nps($valores), 'promotores' => $cuenta['promotor'], 'pasivos' => $cuenta['pasivo'], 'detractores' => $cuenta['detractor'],
            'pct_promotores' => $n ? (int) round($cuenta['promotor'] * 100 / $n) : 0,
            'pct_pasivos' => $n ? (int) round($cuenta['pasivo'] * 100 / $n) : 0,
            'pct_detractores' => $n ? (int) round($cuenta['detractor'] * 100 / $n) : 0,
            'promedio_nps' => $n ? round(array_sum($valores) / $n, 1) : null,
            'promedios' => $promedios, 'por_mes' => $meses,
            'comentarios' => array_slice(array_values(array_filter($filas, fn($f) => trim((string) $f['comentario']) !== '')), 0, 50),
            'lista' => $filas,
        ];
    }

    /** Para el reporte del mes: cuántas encuestas y el NPS de los eventos del mes (Y-m). */
    public function resumenMes(int $negocioId, string $mes): array
    {
        $desde = $mes . '-01';
        $hasta = (new DateTimeImmutable($desde))->modify('last day of this month')->format('Y-m-d');
        $vals = array_map('intval', $this->q('SELECT x.nps FROM encuestas x JOIN eventos e ON e.id = x.evento_id
                                               WHERE x.negocio_id = ? AND e.fecha BETWEEN ?::date AND ?::date', [$negocioId, $desde, $hasta])->fetchAll(PDO::FETCH_COLUMN));
        return ['encuestas' => count($vals), 'nps' => self::nps($vals)];
    }
}
