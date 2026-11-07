<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cuestionario de planificación (el "formulario del evento") — migración 070.
 *  - El negocio tiene una plantilla por tipo de evento (boda, quince años, bautizo, corporativo...) con preguntas de tipo
 *    texto corto, texto largo, una opción, varias opciones, sí/no, número, fecha u hora. Se crean plantillas de ejemplo
 *    la primera vez (negocios.cuestionarios_listos) y el dueño las cambia.
 *  - Cada evento recibe una COPIA de la plantilla de su tipo (o la de "Otro"): si después se cambia la plantilla,
 *    los formularios ya copiados no cambian. El local puede agregar o quitar preguntas en cada evento.
 *  - El cliente lo llena desde su portal (se guarda solo) hasta X días antes del evento (negocios.cuestionario_dias_cierre).
 *    Al tocar "Listo" se avisa al local. El local ve y corrige las respuestas en el evento (pestaña "Formulario y extras").
 *  - Recordatorio por WhatsApp en "Mensajes de hoy" (Cobranza, tipo 'cuestionario') desde Y días antes (cuestionario_dias_aviso).
 */
final class Cuestionarios
{
    public const TIPOS = [
        'corto' => 'Texto corto', 'largo' => 'Texto largo', 'opcion' => 'Una opción', 'varias' => 'Varias opciones',
        'sino' => 'Sí o no', 'numero' => 'Número', 'fecha' => 'Fecha', 'hora' => 'Hora',
    ];
    /** Estados del evento en los que el cliente ve y llena el formulario. */
    public const ESTADOS_CLIENTE = ['separado', 'confirmado'];
    public const MAX_PREGUNTAS = 80;
    public const MAX_CORTO = 200;
    public const MAX_LARGO = 2000;

    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    // ------------------------------------------------------------------
    // Ajustes del negocio
    // ------------------------------------------------------------------

    /** @return array{cierre:int, aviso:int} */
    public function ajustes(int $negocioId): array
    {
        $r = $this->q('SELECT cuestionario_dias_cierre, cuestionario_dias_aviso FROM negocios WHERE id = ?', [$negocioId])->fetch();
        if (!$r) throw new RuntimeException('Negocio no encontrado.');
        return ['cierre' => (int) $r['cuestionario_dias_cierre'], 'aviso' => (int) $r['cuestionario_dias_aviso']];
    }

    public function guardarAjustes(int $negocioId, int $cierre, int $aviso): void
    {
        if ($cierre < 0 || $cierre > 90) throw new RuntimeException('El cierre del formulario debe ser entre 0 y 90 días antes del evento.');
        if ($aviso < 1 || $aviso > 365) throw new RuntimeException('El recordatorio debe salir entre 1 y 365 días antes del evento.');
        if ($aviso <= $cierre) throw new RuntimeException('El recordatorio tiene que salir antes del cierre: pon más días en "Recordar desde".');
        $this->q('UPDATE negocios SET cuestionario_dias_cierre = ?, cuestionario_dias_aviso = ? WHERE id = ?', [$cierre, $aviso, $negocioId]);
    }

    // ------------------------------------------------------------------
    // Plantillas
    // ------------------------------------------------------------------

    /** Crea las plantillas de ejemplo la primera vez (una sola vez por negocio, aunque se llame muchas veces). */
    public function asegurarPlantillas(int $negocioId): void
    {
        $st = $this->q('UPDATE negocios SET cuestionarios_listos = true WHERE id = ? AND NOT cuestionarios_listos', [$negocioId]);
        if ($st->rowCount() !== 1) return;
        $ins = $this->db->prepare('INSERT INTO cuestionario_plantillas (negocio_id, tipo_evento, nombre) VALUES (?,?,?)
                                   ON CONFLICT (negocio_id, tipo_evento) DO NOTHING RETURNING id');
        foreach (Eventos::TIPOS as $tipo => $nombre) {
            $ins->execute([$negocioId, $tipo, 'Formulario de ' . mb_strtolower($tipo === 'otro' ? 'tu evento' : $nombre)]);
            $id = $ins->fetchColumn();
            if ($id !== false) $this->insertarPreguntas((int) $id, self::preguntasEjemplo($tipo));
        }
    }

    private function insertarPreguntas(int $plantillaId, array $preguntas): void
    {
        $ins = $this->db->prepare('INSERT INTO cuestionario_preguntas (plantilla_id, orden, seccion, texto, ayuda, tipo, opciones, obligatoria) VALUES (?,?,?,?,?,?,?,?)');
        foreach (array_values($preguntas) as $i => $p) {
            $ins->execute([$plantillaId, $i + 1, $p[0], $p[1], $p[5] ?? null, $p[2], $p[3] ?? null, !empty($p[4]) ? 'true' : 'false']);
        }
    }

    /** Plantillas del negocio, en el orden de los tipos de evento, con cuántas preguntas tiene cada una. */
    public function plantillas(int $negocioId): array
    {
        $this->asegurarPlantillas($negocioId);
        $filas = $this->q('SELECT p.*, (SELECT count(*) FROM cuestionario_preguntas q WHERE q.plantilla_id = p.id) AS preguntas
                             FROM cuestionario_plantillas p WHERE p.negocio_id = ?', [$negocioId])->fetchAll();
        $pos = array_flip(array_keys(Eventos::TIPOS));
        usort($filas, fn($a, $b) => ($pos[$a['tipo_evento']] ?? 99) <=> ($pos[$b['tipo_evento']] ?? 99));
        return $filas;
    }

    public function plantilla(int $negocioId, int $id): ?array
    {
        $p = $this->q('SELECT * FROM cuestionario_plantillas WHERE id = ? AND negocio_id = ?', [$id, $negocioId])->fetch();
        if (!$p) return null;
        $p['preguntas'] = $this->q('SELECT * FROM cuestionario_preguntas WHERE plantilla_id = ? ORDER BY orden, id', [$id])->fetchAll();
        return $p;
    }

    /** Plantilla activa para un tipo de evento (si no hay, la de "Otro"). */
    public function plantillaParaTipo(int $negocioId, string $tipo): ?array
    {
        $this->asegurarPlantillas($negocioId);
        $id = $this->q("SELECT id FROM cuestionario_plantillas WHERE negocio_id = ? AND activo AND tipo_evento IN (?, 'otro')
                         ORDER BY (tipo_evento = ?) DESC LIMIT 1", [$negocioId, $tipo, $tipo])->fetchColumn();
        return $id === false ? null : $this->plantilla($negocioId, (int) $id);
    }

    public function guardarPlantilla(int $negocioId, int $id, string $nombre, bool $activo): void
    {
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Escribe el nombre del formulario (por ejemplo "Formulario de boda").');
        $st = $this->q('UPDATE cuestionario_plantillas SET nombre = ?, activo = ? WHERE id = ? AND negocio_id = ?',
                       [mb_substr($nombre, 0, 100), $activo ? 'true' : 'false', $id, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró ese formulario.');
    }

    /** Vuelve a poner las preguntas de ejemplo en una plantilla (borra las que tenía). */
    public function restaurarEjemplo(int $negocioId, int $plantillaId): void
    {
        $p = $this->plantilla($negocioId, $plantillaId);
        if (!$p) throw new RuntimeException('No se encontró ese formulario.');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->q('DELETE FROM cuestionario_preguntas WHERE plantilla_id = ?', [$plantillaId]);
            $this->insertarPreguntas($plantillaId, self::preguntasEjemplo($p['tipo_evento']));
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Revisa los datos de una pregunta. Devuelve [seccion, texto, ayuda, tipo, opciones, obligatoria]. */
    public static function limpiarPregunta(array $d): array
    {
        $texto = trim((string) ($d['texto'] ?? ''));
        if (mb_strlen($texto) < 3) throw new RuntimeException('Escribe la pregunta (por ejemplo "¿Cuál es la canción de entrada?").');
        if (mb_strlen($texto) > 200) throw new RuntimeException('La pregunta es muy larga (máximo 200 letras).');
        $tipo = (string) ($d['tipo'] ?? 'corto');
        if (!isset(self::TIPOS[$tipo])) throw new RuntimeException('Elige el tipo de respuesta.');
        $opciones = null;
        if (in_array($tipo, ['opcion', 'varias'], true)) {
            $lista = self::opciones((string) ($d['opciones'] ?? ''));
            if (count($lista) < 2) throw new RuntimeException('Escribe al menos 2 opciones para "' . $texto . '", una por línea.');
            if (count($lista) > 30) throw new RuntimeException('Máximo 30 opciones por pregunta.');
            $opciones = implode("\n", $lista);
        }
        $seccion = mb_substr(trim((string) ($d['seccion'] ?? '')), 0, 60) ?: null;
        $ayuda = mb_substr(trim((string) ($d['ayuda'] ?? '')), 0, 200) ?: null;
        return [$seccion, $texto, $ayuda, $tipo, $opciones, !empty($d['obligatoria'])];
    }

    /** Opciones escritas una por línea (o separadas por coma si es una sola línea). */
    public static function opciones(?string $texto): array
    {
        $texto = trim((string) $texto);
        if ($texto === '') return [];
        $partes = str_contains($texto, "\n") ? preg_split('/\r?\n/', $texto) : explode(',', $texto);
        $res = [];
        foreach ($partes as $o) {
            $o = mb_substr(trim($o), 0, 100);
            if ($o !== '' && !in_array($o, $res, true)) $res[] = $o;
        }
        return $res;
    }

    public function guardarPregunta(int $negocioId, int $plantillaId, ?int $preguntaId, array $d): int
    {
        $p = $this->plantilla($negocioId, $plantillaId);
        if (!$p) throw new RuntimeException('No se encontró ese formulario.');
        [$seccion, $texto, $ayuda, $tipo, $opciones, $obl] = self::limpiarPregunta($d);
        if ($preguntaId) {
            $st = $this->q('UPDATE cuestionario_preguntas SET seccion = ?, texto = ?, ayuda = ?, tipo = ?, opciones = ?, obligatoria = ?
                             WHERE id = ? AND plantilla_id = ?', [$seccion, $texto, $ayuda, $tipo, $opciones, $obl ? 'true' : 'false', $preguntaId, $plantillaId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró esa pregunta.');
            return $preguntaId;
        }
        if (count($p['preguntas']) >= self::MAX_PREGUNTAS) throw new RuntimeException('El formulario ya tiene ' . self::MAX_PREGUNTAS . ' preguntas: es el máximo.');
        return (int) $this->q('INSERT INTO cuestionario_preguntas (plantilla_id, orden, seccion, texto, ayuda, tipo, opciones, obligatoria)
                               VALUES (?, (SELECT COALESCE(max(orden),0) + 1 FROM cuestionario_preguntas WHERE plantilla_id = ?), ?,?,?,?,?,?) RETURNING id',
                              [$plantillaId, $plantillaId, $seccion, $texto, $ayuda, $tipo, $opciones, $obl ? 'true' : 'false'])->fetchColumn();
    }

    public function borrarPregunta(int $negocioId, int $preguntaId): void
    {
        $st = $this->q('DELETE FROM cuestionario_preguntas q USING cuestionario_plantillas p
                         WHERE q.id = ? AND p.id = q.plantilla_id AND p.negocio_id = ?', [$preguntaId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Esa pregunta ya no existe.');
    }

    /** Sube ($dir = -1) o baja ($dir = 1) una pregunta en la plantilla. */
    public function moverPregunta(int $negocioId, int $preguntaId, int $dir): void
    {
        $q = $this->q('SELECT q.id, q.plantilla_id FROM cuestionario_preguntas q JOIN cuestionario_plantillas p ON p.id = q.plantilla_id
                        WHERE q.id = ? AND p.negocio_id = ?', [$preguntaId, $negocioId])->fetch();
        if (!$q) throw new RuntimeException('Esa pregunta ya no existe.');
        $ids = array_map('intval', $this->q('SELECT id FROM cuestionario_preguntas WHERE plantilla_id = ? ORDER BY orden, id', [$q['plantilla_id']])->fetchAll(PDO::FETCH_COLUMN));
        $i = array_search($preguntaId, $ids, true);
        $j = $i + ($dir < 0 ? -1 : 1);
        if ($j < 0 || $j >= count($ids)) return;
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $up = $this->db->prepare('UPDATE cuestionario_preguntas SET orden = ? WHERE id = ?');
        foreach ($ids as $k => $id) $up->execute([$k + 1, $id]);
    }

    // ------------------------------------------------------------------
    // Formulario de cada evento
    // ------------------------------------------------------------------

    private function evento(int $negocioId, int $eventoId): array
    {
        $e = $this->q('SELECT e.*, c.nombre AS cliente, n.cuestionario_dias_cierre, n.cuestionario_dias_aviso
                         FROM eventos e JOIN clientes c ON c.id = e.cliente_id JOIN negocios n ON n.id = e.negocio_id
                        WHERE e.id = ? AND e.negocio_id = ?', [$eventoId, $negocioId])->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    /** Copia la plantilla del tipo del evento (solo la primera vez). */
    public function copiarPlantilla(int $negocioId, int $eventoId): void
    {
        $ev = $this->evento($negocioId, $eventoId);
        $pl = $this->plantillaParaTipo($negocioId, (string) $ev['tipo']);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->q('INSERT INTO evento_cuestionarios (evento_id, negocio_id, plantilla_id, nombre) VALUES (?,?,?,?)
                            ON CONFLICT (evento_id) DO NOTHING', [$eventoId, $negocioId, $pl['id'] ?? null, $pl['nombre'] ?? 'Formulario del evento']);
            if ($st->rowCount() === 1 && $pl) $this->copiarPreguntas($negocioId, $eventoId, $pl['preguntas']);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function copiarPreguntas(int $negocioId, int $eventoId, array $preguntas): void
    {
        $ins = $this->db->prepare('INSERT INTO evento_preguntas (evento_id, negocio_id, orden, seccion, texto, ayuda, tipo, opciones, obligatoria) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach (array_values($preguntas) as $i => $p) {
            $ins->execute([$eventoId, $negocioId, $i + 1, $p['seccion'], $p['texto'], $p['ayuda'], $p['tipo'], $p['opciones'], $p['obligatoria'] ? 'true' : 'false']);
        }
    }

    /**
     * Formulario del evento con su avance. Lo crea (copiando la plantilla) si todavía no existe y $crear.
     * Devuelve null si no existe y no se pidió crearlo.
     */
    public function deEvento(int $negocioId, int $eventoId, bool $crear = true, ?DateTimeImmutable $hoy = null): ?array
    {
        $hoy ??= new DateTimeImmutable('today');
        $ev = $this->evento($negocioId, $eventoId);
        $c = $this->q('SELECT * FROM evento_cuestionarios WHERE evento_id = ? AND negocio_id = ?', [$eventoId, $negocioId])->fetch();
        if (!$c) {
            if (!$crear) return null;
            $this->copiarPlantilla($negocioId, $eventoId);
            $c = $this->q('SELECT * FROM evento_cuestionarios WHERE evento_id = ? AND negocio_id = ?', [$eventoId, $negocioId])->fetch();
        }
        $preguntas = $this->q('SELECT * FROM evento_preguntas WHERE evento_id = ? AND negocio_id = ? ORDER BY orden, id', [$eventoId, $negocioId])->fetchAll();
        $total = count($preguntas);
        $respondidas = count(array_filter($preguntas, fn($p) => $p['respuesta'] !== null));
        $faltanObligatorias = array_values(array_filter($preguntas, fn($p) => $p['obligatoria'] && $p['respuesta'] === null));
        $cierre = (new DateTimeImmutable($ev['fecha']))->modify('-' . (int) $ev['cuestionario_dias_cierre'] . ' days');
        $secciones = [];
        foreach ($preguntas as $p) $secciones[$p['seccion'] ?: 'Otros detalles'][] = $p;
        return [
            'cuestionario' => $c, 'preguntas' => $preguntas, 'secciones' => $secciones,
            'total' => $total, 'respondidas' => $respondidas, 'pct' => $total ? (int) floor($respondidas * 100 / $total) : 0,
            'faltan_obligatorias' => $faltanObligatorias,
            'cierre' => $cierre->format('Y-m-d'),
            'abierto' => $this->abiertoParaCliente($ev, $hoy),
            'visible_cliente' => in_array($ev['estado'], self::ESTADOS_CLIENTE, true) && $total > 0,
            'enviado_en' => $c['enviado_en'] ?? null,
            'completo' => $total > 0 && $respondidas === $total,
        ];
    }

    /** ¿El cliente todavía puede llenar o cambiar el formulario? (evento separado/confirmado y antes del cierre) */
    private function abiertoParaCliente(array $ev, DateTimeImmutable $hoy): bool
    {
        if (!in_array($ev['estado'], self::ESTADOS_CLIENTE, true)) return false;
        $cierre = (new DateTimeImmutable($ev['fecha']))->modify('-' . (int) $ev['cuestionario_dias_cierre'] . ' days');
        return $hoy <= $cierre;
    }

    /**
     * Revisa y normaliza una respuesta según el tipo de pregunta. Devuelve null si quedó vacía.
     * @param mixed $valor texto, o lista de opciones para "varias"
     */
    public static function normalizar(array $p, mixed $valor): ?string
    {
        $nombre = '"' . mb_substr((string) $p['texto'], 0, 60) . '"';
        if ($p['tipo'] === 'varias') {
            $lista = is_array($valor) ? $valor : ($valor === null || $valor === '' ? [] : [(string) $valor]);
            $ops = self::opciones($p['opciones']);
            $elegidas = [];
            foreach ($lista as $v) {
                $v = trim((string) $v);
                if ($v === '') continue;
                if (!in_array($v, $ops, true)) throw new RuntimeException("Elige una de las opciones de $nombre.");
                if (!in_array($v, $elegidas, true)) $elegidas[] = $v;
            }
            // En el orden de las opciones
            usort($elegidas, fn($a, $b) => array_search($a, $ops, true) <=> array_search($b, $ops, true));
            return $elegidas ? implode("\n", $elegidas) : null;
        }
        if (is_array($valor)) $valor = implode(' ', array_map('strval', $valor));
        $v = trim((string) $valor);
        if ($v === '') return null;
        switch ($p['tipo']) {
            case 'corto':
                if (mb_strlen($v) > self::MAX_CORTO) throw new RuntimeException("La respuesta de $nombre es muy larga (máximo " . self::MAX_CORTO . ' letras).');
                return preg_replace('/\s+/u', ' ', $v);
            case 'largo':
                if (mb_strlen($v) > self::MAX_LARGO) throw new RuntimeException("La respuesta de $nombre es muy larga (máximo " . self::MAX_LARGO . ' letras).');
                return str_replace("\r\n", "\n", $v);
            case 'opcion':
                if (!in_array($v, self::opciones($p['opciones']), true)) throw new RuntimeException("Elige una de las opciones de $nombre.");
                return $v;
            case 'sino':
                $v = mb_strtolower($v);
                if (in_array($v, ['si', 'sí', '1', 'true'], true)) return 'si';
                if (in_array($v, ['no', '0', 'false'], true)) return 'no';
                throw new RuntimeException("Responde sí o no en $nombre.");
            case 'numero':
                $n = self::numeroTexto($v);
                if ($n === null || $n < 0 || $n > 100000) throw new RuntimeException("Escribe un número válido en $nombre.");
                return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
            case 'fecha':
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                if (!$d || $d->format('Y-m-d') !== $v) throw new RuntimeException("Revisa la fecha de $nombre.");
                return $v;
            case 'hora':
                if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:\d\d)?$/', $v, $m)) throw new RuntimeException("Revisa la hora de $nombre (por ejemplo 21:30).");
                return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        return $v;
    }

    /** Respuesta lista para mostrar (Sí/No, fecha en español, opciones separadas por coma). */
    public static function texto(array $p): string
    {
        $r = $p['respuesta'];
        if ($r === null || $r === '') return '';
        return match ($p['tipo']) {
            'sino'   => $r === 'si' ? 'Sí' : 'No',
            'varias' => implode(', ', explode("\n", (string) $r)),
            'fecha'  => (new DateTimeImmutable((string) $r))->format('d/m/Y'),
            'numero' => str_replace('.', ',', (string) $r),
            default  => (string) $r,
        };
    }

    /**
     * Guarda respuestas. $respuestas = [pregunta_id => valor]; $presentes = ids de las preguntas que estaban en el formulario
     * (una pregunta presente que no llega en $respuestas queda vacía: así se pueden desmarcar todas las casillas).
     * $esCliente: respeta el cierre y el estado del evento. Devuelve el formulario actualizado (deEvento).
     */
    public function guardarRespuestas(int $negocioId, int $eventoId, array $respuestas, array $presentes, string $quien, bool $esCliente,
                                      ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $ev = $this->evento($negocioId, $eventoId);
        if ($esCliente) {
            if (!in_array($ev['estado'], self::ESTADOS_CLIENTE, true)) throw new RuntimeException('El formulario todavía no está disponible para este evento.');
            if (!$this->abiertoParaCliente($ev, $hoy)) {
                $cierre = (new DateTimeImmutable($ev['fecha']))->modify('-' . (int) $ev['cuestionario_dias_cierre'] . ' days');
                throw new RuntimeException('El formulario se cerró el ' . $cierre->format('d/m/Y') . '. Si necesitas cambiar algo, escríbele al local.');
            }
        }
        $preguntas = [];
        foreach ($this->q('SELECT * FROM evento_preguntas WHERE evento_id = ? AND negocio_id = ?', [$eventoId, $negocioId])->fetchAll() as $p) {
            $preguntas[(int) $p['id']] = $p;
        }
        $ids = array_unique(array_merge(array_map('intval', $presentes), array_map('intval', array_keys($respuestas))));
        $cambios = [];
        foreach ($ids as $pid) {
            if (!isset($preguntas[$pid])) continue;   // pregunta de otro evento o borrada: se ignora
            $nuevo = self::normalizar($preguntas[$pid], $respuestas[$pid] ?? null);
            if ($nuevo !== $preguntas[$pid]['respuesta']) $cambios[$pid] = $nuevo;
        }
        if ($cambios) {
            $antes = count(array_filter($preguntas, fn($p) => $p['respuesta'] !== null));
            $propia = !$this->db->inTransaction();
            if ($propia) $this->db->beginTransaction();
            try {
                $up = $this->db->prepare('UPDATE evento_preguntas SET respuesta = ?, respondido_en = now(), respondido_por = ? WHERE id = ? AND evento_id = ?');
                foreach ($cambios as $pid => $valor) $up->execute([$valor, mb_substr($quien, 0, 120), $pid, $eventoId]);
                $this->q('UPDATE evento_cuestionarios SET actualizado_en = now() WHERE evento_id = ?', [$eventoId]);
                if ($esCliente && $antes === 0) {
                    $this->historial($negocioId, $eventoId, 'Cliente', 'El cliente empezó a llenar el formulario del evento');
                } elseif (!$esCliente) {
                    $this->historial($negocioId, $eventoId, $quien, 'Cambió ' . count($cambios) . (count($cambios) === 1 ? ' respuesta' : ' respuestas') . ' del formulario del evento');
                }
                if ($propia) $this->db->commit();
            } catch (\Throwable $e) {
                if ($propia && $this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        }
        return $this->deEvento($negocioId, $eventoId, false, $hoy);
    }

    /**
     * El cliente toca "Listo, enviar al local": revisa las obligatorias, marca la fecha y avisa al equipo (solo la primera vez).
     * Devuelve true si es la primera vez.
     */
    public function terminar(int $negocioId, int $eventoId, ?DateTimeImmutable $hoy = null): bool
    {
        $hoy ??= new DateTimeImmutable('today');
        $ev = $this->evento($negocioId, $eventoId);
        $f = $this->deEvento($negocioId, $eventoId, false, $hoy);
        if (!$f || !$f['total']) throw new RuntimeException('Este evento no tiene formulario.');
        if (!$f['abierto']) throw new RuntimeException('El formulario se cerró el ' . (new DateTimeImmutable($f['cierre']))->format('d/m/Y') . '. Si necesitas cambiar algo, escríbele al local.');
        if ($f['faltan_obligatorias']) {
            $txt = implode(', ', array_map(fn($p) => '"' . $p['texto'] . '"', array_slice($f['faltan_obligatorias'], 0, 3)));
            throw new RuntimeException('Te falta responder: ' . $txt . (count($f['faltan_obligatorias']) > 3 ? ' y ' . (count($f['faltan_obligatorias']) - 3) . ' más' : '') . '.');
        }
        $st = $this->q('UPDATE evento_cuestionarios SET enviado_en = now() WHERE evento_id = ? AND enviado_en IS NULL', [$eventoId]);
        if ($st->rowCount() !== 1) return false;
        $this->historial($negocioId, $eventoId, 'Cliente', 'El cliente terminó el formulario del evento (' . $f['respondidas'] . ' de ' . $f['total'] . ' respuestas)');
        $texto = '📝 ' . self::primerNombre((string) $ev['cliente']) . ' terminó el formulario de ' . $ev['nombre'] . ' (' . $ev['codigo'] . '): respondió '
               . $f['respondidas'] . ' de ' . $f['total'] . ' preguntas. Míralo en el evento → Formulario y extras.';
        $avisos = new Notificaciones($this->db);
        $avisos->alNegocio($negocioId, $texto, $eventoId);
        $extra = array_diff(array_filter([(int) $ev['coordinador_id'], (int) $ev['vendedor_id']]), $avisos->destinos($negocioId, ['dueno', 'vendedor']));
        if ($extra) $avisos->aUsuarios($negocioId, array_values($extra), $texto, $eventoId);
        return true;
    }

    /** El local agrega una pregunta solo para este evento. */
    public function agregarPreguntaEvento(int $negocioId, int $eventoId, array $d, ?array $usuario = null): int
    {
        $this->evento($negocioId, $eventoId);
        $this->copiarPlantilla($negocioId, $eventoId);
        [$seccion, $texto, $ayuda, $tipo, $opciones, $obl] = self::limpiarPregunta($d);
        $n = (int) $this->q('SELECT count(*) FROM evento_preguntas WHERE evento_id = ?', [$eventoId])->fetchColumn();
        if ($n >= self::MAX_PREGUNTAS) throw new RuntimeException('El formulario ya tiene ' . self::MAX_PREGUNTAS . ' preguntas: es el máximo.');
        $id = (int) $this->q('INSERT INTO evento_preguntas (evento_id, negocio_id, orden, seccion, texto, ayuda, tipo, opciones, obligatoria)
                              VALUES (?,?, (SELECT COALESCE(max(orden),0) + 1 FROM evento_preguntas WHERE evento_id = ?), ?,?,?,?,?,?) RETURNING id',
                             [$eventoId, $negocioId, $eventoId, $seccion, $texto, $ayuda, $tipo, $opciones, $obl ? 'true' : 'false'])->fetchColumn();
        $this->historial($negocioId, $eventoId, $usuario['nombre'] ?? 'Sistema', 'Agregó al formulario la pregunta "' . mb_substr($texto, 0, 120) . '"', $usuario);
        return $id;
    }

    public function borrarPreguntaEvento(int $negocioId, int $eventoId, int $preguntaId, ?array $usuario = null): void
    {
        $st = $this->q('DELETE FROM evento_preguntas WHERE id = ? AND evento_id = ? AND negocio_id = ? RETURNING texto', [$preguntaId, $eventoId, $negocioId]);
        $t = $st->fetchColumn();
        if ($t === false) throw new RuntimeException('Esa pregunta ya no existe.');
        $this->historial($negocioId, $eventoId, $usuario['nombre'] ?? 'Sistema', 'Quitó del formulario la pregunta "' . mb_substr((string) $t, 0, 120) . '"', $usuario);
    }

    /** Vuelve a copiar la plantilla del tipo del evento (solo si todavía no hay respuestas, por ejemplo si cambiaron el tipo). */
    public function recargarPlantilla(int $negocioId, int $eventoId, ?array $usuario = null): void
    {
        $ev = $this->evento($negocioId, $eventoId);
        $n = (int) $this->q('SELECT count(*) FROM evento_preguntas WHERE evento_id = ? AND respuesta IS NOT NULL', [$eventoId])->fetchColumn();
        if ($n > 0) throw new RuntimeException('El formulario ya tiene respuestas: no se puede volver a cargar. Agrega o quita preguntas una por una.');
        $pl = $this->plantillaParaTipo($negocioId, (string) $ev['tipo']);
        if (!$pl) throw new RuntimeException('No hay un formulario activo para este tipo de evento. Revísalo en Más → Formularios del evento.');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->q('DELETE FROM evento_preguntas WHERE evento_id = ? AND negocio_id = ?', [$eventoId, $negocioId]);
            $this->q('INSERT INTO evento_cuestionarios (evento_id, negocio_id, plantilla_id, nombre) VALUES (?,?,?,?)
                      ON CONFLICT (evento_id) DO UPDATE SET plantilla_id = EXCLUDED.plantilla_id, nombre = EXCLUDED.nombre, enviado_en = NULL',
                     [$eventoId, $negocioId, $pl['id'], $pl['nombre']]);
            $this->copiarPreguntas($negocioId, $eventoId, $pl['preguntas']);
            $this->historial($negocioId, $eventoId, $usuario['nombre'] ?? 'Sistema', 'Volvió a cargar el formulario "' . $pl['nombre'] . '"', $usuario);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Preguntas respondidas agrupadas por sección (para la hoja del evento y el PDF del formulario). */
    public function respondidas(int $negocioId, int $eventoId): array
    {
        $res = [];
        $filas = $this->q('SELECT * FROM evento_preguntas WHERE evento_id = ? AND negocio_id = ? AND respuesta IS NOT NULL ORDER BY orden, id',
                          [$eventoId, $negocioId])->fetchAll();
        foreach ($filas as $p) $res[$p['seccion'] ?: 'Otros detalles'][] = ['pregunta' => $p['texto'], 'respuesta' => self::texto($p)];
        return $res;
    }

    /**
     * Eventos cuyo formulario conviene recordar hoy por WhatsApp: separados o confirmados, entre el aviso y el cierre,
     * sin terminar (con preguntas sin responder) y sin recordatorio en los últimos $repetir días.
     */
    public function porRecordar(int $negocioId, DateTimeImmutable $hoy, int $repetir = 4): array
    {
        $this->asegurarPlantillas($negocioId);
        $h = $hoy->format('Y-m-d');
        $st = $this->q("SELECT e.id AS ref_id, e.id AS evento_id, e.codigo, e.nombre AS evento, e.fecha, e.tipo, e.token_cliente,
                               c.nombre AS cliente, c.telefono, n.cuestionario_dias_cierre AS cierre, (ec.evento_id IS NOT NULL) AS copiado,
                               (SELECT count(*) FROM evento_preguntas p WHERE p.evento_id = e.id) AS total,
                               (SELECT count(*) FROM evento_preguntas p WHERE p.evento_id = e.id AND p.respuesta IS NOT NULL) AS respondidas,
                               (SELECT count(*) FROM cuestionario_plantillas pl JOIN cuestionario_preguntas q ON q.plantilla_id = pl.id
                                 WHERE pl.negocio_id = e.negocio_id AND pl.activo AND pl.tipo_evento IN (e.tipo, 'otro')) AS en_plantilla
                          FROM eventos e JOIN clientes c ON c.id = e.cliente_id JOIN negocios n ON n.id = e.negocio_id
                     LEFT JOIN evento_cuestionarios ec ON ec.evento_id = e.id
                         WHERE e.negocio_id = :n AND e.estado IN ('separado','confirmado')
                           AND e.fecha BETWEEN :hoy::date + n.cuestionario_dias_cierre AND :hoy::date + n.cuestionario_dias_aviso
                           AND ec.enviado_en IS NULL
                           AND NOT EXISTS (SELECT 1 FROM mensajes_enviados me WHERE me.negocio_id = e.negocio_id AND me.tipo = 'cuestionario'
                                              AND me.ref_id = e.id AND me.enviado_en > :hoy::date - (:rep)::int)
                      ORDER BY e.fecha", ['n' => $negocioId, 'hoy' => $h, 'rep' => max(1, $repetir)]);
        $res = [];
        foreach ($st->fetchAll() as $f) {
            $pendiente = $f['copiado'] ? ((int) $f['total'] > 0 && (int) $f['respondidas'] < (int) $f['total']) : (int) $f['en_plantilla'] > 0;
            if (!$pendiente) continue;
            $f['cierre_fecha'] = (new DateTimeImmutable($f['fecha']))->modify('-' . (int) $f['cierre'] . ' days')->format('Y-m-d');
            $res[] = $f;
        }
        return $res;
    }

    // ------------------------------------------------------------------

    /** Número escrito con coma o punto (1.234,50 · 1234,50 · 1234.50) -> float, o null si no es un número. */
    public static function numeroTexto(string $v): ?float
    {
        $v = str_replace(' ', '', trim($v));
        if ($v === '') return null;
        if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace('.', '', $v);
        elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : null;
    }

    private static function primerNombre(string $nombre): string
    {
        return trim(explode(' ', trim($nombre))[0]);
    }

    private function historial(int $negocioId, int $eventoId, string $quien, string $accion, ?array $usuario = null): void
    {
        $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                 [$negocioId, $eventoId, $usuario['id'] ?? null, mb_substr($quien, 0, 120), mb_substr($accion, 0, 400)]);
    }

    // ------------------------------------------------------------------
    // Preguntas de ejemplo: [sección, pregunta, tipo, opciones (una por línea), obligatoria, ayuda]
    // ------------------------------------------------------------------

    private const MUSICA = "Salsa\nMerengue\nBachata\nCumbia\nReguetón\nPop y rock en español\nMúsica nacional\nElectrónica\nCrossover";

    public static function preguntasEjemplo(string $tipo): array
    {
        $comida = [
            ['Comida y bebida', 'Menú elegido (entrada, plato fuerte y postre)', 'largo', null, false, 'Si todavía no lo eligen, déjalo en blanco: lo vemos en la degustación.'],
            ['Comida y bebida', '¿Hay invitados con alergias o dietas especiales?', 'largo', null, false, 'Vegetarianos, veganos, celíacos, alergia al maní o mariscos… Cuántos y cuáles.'],
        ];
        $ninos = [
            ['Invitados', '¿Cuántos niños asistirán?', 'numero', null, false, 'Menores de 12 años.'],
            ['Invitados', '¿Quieren menú especial para los niños?', 'sino'],
            ['Invitados', '¿Hasta qué fecha nos confirman el número final de invitados?', 'fecha'],
        ];
        $decoracion = [
            ['Decoración', 'Colores de la decoración', 'corto', null, false, 'Por ejemplo: blanco y dorado, verde salvia y rosa palo.'],
            ['Decoración', 'Estilo de la decoración', 'opcion', "Clásico y elegante\nModerno\nRústico o campestre\nBohemio\nTemático"],
        ];
        $proveedores = [
            ['Proveedores', '¿Traen proveedores externos?', 'largo', null, false, 'Fotógrafo, DJ, decoración, torta, mariachis… Nombre, servicio y celular de cada uno.'],
        ];
        $dia = [
            ['El día del evento', 'Persona de contacto el día del evento (nombre y celular)', 'corto', null, true, 'A quién llamamos si hay que decidir algo en el momento.'],
            ['El día del evento', '¿Algo más que debamos saber?', 'largo'],
        ];
        $musicaGeneral = [
            ['Música', 'Estilos de música para la fiesta', 'varias', self::MUSICA],
            ['Música', 'Canciones que NO quieren que suenen', 'largo'],
        ];
        $p = match ($tipo) {
            'boda' => array_merge([
                ['Ceremonia', 'Tipo de ceremonia', 'opcion', "Religiosa\nCivil\nSimbólica\nNo habrá ceremonia en el local"],
                ['Ceremonia', 'Canción de entrada de la novia', 'corto'],
                ['Música', 'Canción del primer baile', 'corto'],
                ['Música', 'Canción del vals', 'corto'],
            ], $musicaGeneral, [
                ['Momentos especiales', 'Hora del vals', 'hora', null, false, 'Aproximada, la ajustamos en el cronograma.'],
                ['Momentos especiales', '¿Habrá brindis? ¿Quién dará unas palabras?', 'corto'],
                ['Momentos especiales', 'Momentos que quieren tener', 'varias', "Lanzamiento del ramo\nLanzamiento de la liga\nVideo o fotos de los novios\nBaile sorpresa\nHora loca\nPirotecnia fría"],
            ], $decoracion, [
                ['Decoración', 'Mesa de los novios', 'opcion', "Mesa de honor con padres y padrinos\nMesa solo para los novios\nSin mesa especial"],
            ], $comida, $ninos, $proveedores, [
                ['El día del evento', '¿A qué hora llegan los novios al local?', 'hora'],
            ], $dia),
            'quince' => array_merge([
                ['La quinceañera', 'Nombre de la quinceañera', 'corto', null, true],
                ['Música', 'Canción de entrada', 'corto'],
                ['Música', 'Canción del vals', 'corto'],
                ['Momentos especiales', 'Hora del vals', 'hora'],
                ['Momentos especiales', 'Momentos que quieren tener', 'varias', "Cambio de zapatos\nÚltima muñeca\nVelas o tributo\nBaile sorpresa\nVideo de fotos\nHora loca"],
                ['Momentos especiales', '¿Cuántas damas y chambelanes habrá?', 'numero'],
                ['Decoración', 'Temática o colores', 'corto'],
            ], $musicaGeneral, $comida, $ninos, $proveedores, $dia),
            'bautizo', 'primera_comunion' => array_merge([
                ['El festejado', 'Nombre del niño o la niña', 'corto', null, true],
                ['El festejado', 'Nombres de los padrinos', 'corto'],
                ['Ceremonia', 'Hora y lugar de la ceremonia religiosa', 'corto', null, false, 'Para coordinar la llegada de los invitados al local.'],
                ['Decoración', 'Colores o temática', 'corto'],
            ], $ninos, [
                ['Invitados', '¿Quieren animación o juegos para los niños?', 'sino'],
            ], $comida, $proveedores, $dia),
            'cumpleanos' => array_merge([
                ['El festejado', '¿De quién es el cumpleaños y cuántos años cumple?', 'corto', null, true],
                ['Decoración', 'Temática o colores', 'corto'],
                ['Momentos especiales', 'Hora para cantar el cumpleaños y partir la torta', 'hora'],
                ['Música', 'Canción especial para el festejado', 'corto'],
            ], $musicaGeneral, $comida, $ninos, $proveedores, $dia),
            'graduacion' => array_merge([
                ['La graduación', 'Institución y promoción', 'corto', null, true],
                ['Momentos especiales', '¿Habrá discursos o entrega de reconocimientos?', 'sino'],
                ['Momentos especiales', 'Orden de los momentos de la noche', 'largo', null, false, 'Entrada, palabras, brindis, baile…'],
            ], $decoracion, $musicaGeneral, $comida, $proveedores, $dia),
            'corporativo' => [
                ['La empresa', 'Empresa y nombre del evento', 'corto', null, true],
                ['La empresa', 'Tipo de evento', 'opcion', "Conferencia o charla\nCapacitación\nLanzamiento de marca\nFiesta de fin de año\nReunión o almuerzo\nOtro"],
                ['Montaje y equipos', 'Montaje de las mesas', 'opcion', "Auditorio\nEscuela\nBanquete (mesas redondas)\nCóctel de pie\nHerradura"],
                ['Montaje y equipos', 'Equipos que necesitan', 'varias', "Proyector y pantalla\nMicrófonos inalámbricos\nPodio\nInternet / wifi\nTransmisión en vivo\nTarima"],
                ['Agenda', 'Agenda del evento (horarios)', 'largo', null, false, 'Registro, charlas, coffee break, almuerzo, cierre…'],
                ['Comida y bebida', '¿A qué hora el coffee break o la comida?', 'corto'],
                ['Comida y bebida', '¿Hay invitados con alergias o dietas especiales?', 'largo'],
                ['Facturación', 'Datos para la factura (razón social y RUC)', 'corto'],
                ['Proveedores', '¿Traen proveedores externos?', 'largo', null, false, 'Audiovisuales, catering, decoración… Nombre, servicio y celular.'],
                ['El día del evento', 'Persona de contacto el día del evento (nombre y celular)', 'corto', null, true],
                ['El día del evento', '¿Algo más que debamos saber?', 'largo'],
            ],
            'aniversario' => array_merge([
                ['Los festejados', '¿Cuántos años celebran?', 'numero'],
                ['Música', 'Canción especial de la pareja', 'corto'],
                ['Momentos especiales', 'Momentos que quieren tener', 'largo', null, false, 'Renovación de votos, video de fotos, baile, brindis…'],
            ], $decoracion, $musicaGeneral, $comida, $ninos, $proveedores, $dia),
            default => array_merge([
                ['El evento', 'Cuéntanos de qué se trata el evento', 'largo'],
                ['El evento', 'Horarios importantes de la noche', 'largo', null, false, 'Entrada, comida, momentos especiales, cierre…'],
            ], $decoracion, $musicaGeneral, $comida, $ninos, $proveedores, $dia),
        };
        return $p;
    }
}

