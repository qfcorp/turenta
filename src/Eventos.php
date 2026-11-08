<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Eventos: el centro de TuRenta.
 * Estados: consulta -> cotizado -> separado -> confirmado -> realizado (o perdido / cancelado).
 * Una fecha queda ASEGURADA (bloquea el espacio) desde "separado". Nadie más puede separar ese espacio
 * a la misma hora: lo impide la base de datos con una restricción de exclusión (no solo el programa).
 * Una fecha "cotizada" queda RETENIDA unos días: se avisa, pero no se bloquea.
 */
final class Eventos
{
    public const TIPOS = [
        'boda' => 'Boda', 'quince' => 'Quince años', 'graduacion' => 'Graduación', 'bautizo' => 'Bautizo',
        'primera_comunion' => 'Primera comunión', 'cumpleanos' => 'Cumpleaños', 'corporativo' => 'Evento corporativo',
        'aniversario' => 'Aniversario', 'otro' => 'Otro',
    ];
    public const ESTADOS = [
        'consulta' => 'Consulta', 'cotizado' => 'Cotizado', 'separado' => 'Separado',
        'confirmado' => 'Confirmado', 'realizado' => 'Realizado', 'perdido' => 'Perdido', 'cancelado' => 'Cancelado',
    ];
    /** Explicación corta de cada estado (se muestra en pantalla). */
    public const ESTADOS_AYUDA = [
        'consulta'   => 'El cliente preguntó. La fecha no está guardada.',
        'cotizado'   => 'Ya tiene cotización. La fecha queda retenida unos días, sin bloquearla.',
        'separado'   => 'Pagó la reserva: la fecha y el espacio quedan bloqueados.',
        'confirmado' => 'Contrato firmado. El evento va.',
        'realizado'  => 'El evento ya pasó.',
        'perdido'    => 'El cliente no contrató.',
        'cancelado'  => 'Se canceló después de separar.',
    ];
    public const BLOQUEAN = ['separado', 'confirmado', 'realizado'];
    public const ACTIVOS = ['consulta', 'cotizado', 'separado', 'confirmado'];
    /** A qué estados se puede pasar desde cada estado. */
    public const PASOS = [
        'consulta'   => ['cotizado', 'separado', 'perdido'],
        'cotizado'   => ['consulta', 'separado', 'perdido'],
        'separado'   => ['confirmado', 'realizado', 'cotizado', 'cancelado'],
        'confirmado' => ['realizado', 'separado', 'cancelado'],
        'realizado'  => ['confirmado'],
        'perdido'    => ['consulta', 'cotizado'],
        'cancelado'  => ['consulta', 'separado'],
    ];
    public const ORIGENES = [
        'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'google' => 'Google',
        'referido' => 'Recomendación', 'pagina' => 'Página web', 'feria' => 'Feria de bodas',
        'planner' => 'Wedding planner', 'cliente_anterior' => 'Cliente anterior', 'otro' => 'Otro',
    ];
    public const MONTAJES = [
        'redondas' => 'Mesas redondas', 'imperial' => 'Mesa imperial', 'coctel' => 'Cóctel (de pie)',
        'teatro' => 'Auditorio / teatro', 'escuela' => 'Escuela', 'herradura' => 'Herradura', 'mixto' => 'Mixto',
    ];
    public const TIPOS_ACTIVIDAD = [
        'nota' => 'Nota', 'llamada' => 'Llamada', 'whatsapp' => 'WhatsApp', 'visita' => 'Visita al local',
        'degustacion' => 'Degustación', 'reunion' => 'Reunión', 'correo' => 'Correo', 'sistema' => 'Sistema',
    ];

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------------
    // Clientes
    // ------------------------------------------------------------------

    /** Crea o actualiza un cliente. Si ya existe uno con el mismo celular o cédula, lo reutiliza. */
    public function guardarCliente(int $negocioId, array $d, ?int $id = null): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $tel = trim((string) ($d['telefono'] ?? ''));
        $ident = preg_replace('/\D+/', '', (string) ($d['identificacion'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Escribe el nombre del cliente.');
        if ($ident !== '' && !in_array(strlen($ident), [10, 13], true)) throw new RuntimeException('La cédula debe tener 10 dígitos o el RUC 13.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo del cliente no es válido.');
        $origen = (string) ($d['origen'] ?? '');
        if ($origen !== '' && !isset(self::ORIGENES[$origen])) $origen = 'otro';
        if ($id === null) {
            $id = $this->buscarClienteRepetido($negocioId, $tel, $ident);
        }
        $valores = [mb_substr($nombre, 0, 150), $ident ?: null, $tel ?: null, $email ?: null,
                    mb_substr(trim((string) ($d['direccion'] ?? '')), 0, 250) ?: null, $origen ?: null];
        if ($id) {
            $st = $this->db->prepare('UPDATE clientes SET nombre = ?, identificacion = COALESCE(?, identificacion), telefono = COALESCE(?, telefono),
                                             email = COALESCE(?, email), direccion = COALESCE(?, direccion), origen = COALESCE(origen, ?)
                                       WHERE id = ? AND negocio_id = ?');
            $st->execute([...$valores, $id, $negocioId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el cliente.');
            if (array_key_exists('notas', $d)) {
                $this->db->prepare('UPDATE clientes SET notas = ? WHERE id = ?')->execute([trim((string) $d['notas']) ?: null, $id]);
            }
            return $id;
        }
        $st = $this->db->prepare('INSERT INTO clientes (negocio_id, nombre, identificacion, telefono, email, direccion, origen, notas)
                                  VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$negocioId, ...$valores, trim((string) ($d['notas'] ?? '')) ?: null]);
        return (int) $st->fetchColumn();
    }

    private function buscarClienteRepetido(int $negocioId, string $tel, string $ident): ?int
    {
        if ($ident !== '') {
            $st = $this->db->prepare('SELECT id FROM clientes WHERE negocio_id = ? AND identificacion = ? LIMIT 1');
            $st->execute([$negocioId, $ident]);
            if ($id = $st->fetchColumn()) return (int) $id;
        }
        $n = WhatsApp::normalizarTelefono($tel);
        if ($n !== null) {
            $st = $this->db->prepare("SELECT id, telefono FROM clientes WHERE negocio_id = ? AND telefono IS NOT NULL");
            $st->execute([$negocioId]);
            foreach ($st->fetchAll() as $c) {
                if (WhatsApp::normalizarTelefono($c['telefono']) === $n) return (int) $c['id'];
            }
        }
        return null;
    }

    public function cliente(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM clientes WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        return $st->fetch() ?: null;
    }

    public function clientes(int $negocioId, string $buscar = '', int $limite = 300): array
    {
        $sql = 'SELECT c.*, (SELECT count(*) FROM eventos e WHERE e.cliente_id = c.id) AS eventos,
                       (SELECT max(e.fecha) FROM eventos e WHERE e.cliente_id = c.id) AS ultimo_evento
                  FROM clientes c WHERE c.negocio_id = ?';
        $p = [$negocioId];
        if (($buscar = trim($buscar)) !== '') {
            $sql .= ' AND (c.nombre ILIKE ? OR c.telefono ILIKE ? OR c.identificacion ILIKE ? OR c.email ILIKE ?)';
            array_push($p, ...array_fill(0, 4, "%$buscar%"));
        }
        $st = $this->db->prepare($sql . ' ORDER BY c.creado_en DESC LIMIT ' . (int) $limite);
        $st->execute($p);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------
    // Horarios y disponibilidad
    // ------------------------------------------------------------------

    /**
     * Desde y hasta que se ocupa el espacio: X horas antes (montaje) y X horas después (desmontaje).
     * Si la hora de fin es menor o igual que la de inicio, el evento termina al día siguiente.
     * @return array{0:string,1:string} 'Y-m-d H:i:s'
     */
    public function periodo(int $negocioId, string $fecha, string $horaInicio, string $horaFin, int $viajeMin = 0, ?int $espacioId = null): array
    {
        [$montaje, $desmontaje] = $this->minutosMontaje($negocioId, $espacioId);
        $viajeMin = max(0, $viajeMin);
        $ini = new DateTimeImmutable("$fecha " . substr($horaInicio, 0, 5));
        $fin = new DateTimeImmutable("$fecha " . substr($horaFin, 0, 5));
        if ($fin <= $ini) $fin = $fin->modify('+1 day');
        // (201) El equipo sale de la base: viaje de ida + montaje antes; desmontaje + viaje de vuelta después
        $ini = $ini->modify('-' . ($montaje + $viajeMin) . ' minutes');
        $fin = $fin->modify('+' . ($desmontaje + $viajeMin) . ' minutes');
        return [$ini->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')];
    }

    /**
     * Minutos de montaje y desmontaje (201): los del equipo si los tiene (un camión grande tarda más que uno chico);
     * si no, las horas de montaje y desmontaje del negocio (Reglas).
     * @return array{0:int,1:int}
     */
    public function minutosMontaje(int $negocioId, ?int $espacioId = null): array
    {
        $st = $this->db->prepare('SELECT n.horas_montaje, n.horas_desmontaje, es.montaje_min, es.desmontaje_min
                                    FROM negocios n LEFT JOIN espacios es ON es.id = ?::int AND es.negocio_id = n.id WHERE n.id = ?');
        $st->execute([$espacioId, $negocioId]);
        $r = $st->fetch() ?: ['horas_montaje' => 0, 'horas_desmontaje' => 0, 'montaje_min' => null, 'desmontaje_min' => null];
        $montaje = $r['montaje_min'] !== null ? (int) $r['montaje_min'] : (int) round((float) $r['horas_montaje'] * 60);
        $desmontaje = $r['desmontaje_min'] !== null ? (int) $r['desmontaje_min'] : (int) round((float) $r['horas_desmontaje'] * 60);
        return [$montaje, $desmontaje];
    }

    /**
     * Eventos que chocan con ese horario en esos espacios.
     * Cada choque trae 'nivel': 'bloqueado' (separado/confirmado/realizado), 'retenido' (cotizado vigente) o 'consulta'.
     */
    public function choques(int $negocioId, string $fecha, string $horaInicio, string $horaFin, array $espacioIds, ?int $excluirEvento = null, ?DateTimeImmutable $hoy = null, int $viajeMin = 0): array
    {
        $espacioIds = array_values(array_filter(array_map('intval', $espacioIds)));
        if (!$espacioIds) return [];
        $hoyTxt = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $marcas = implode(',', array_fill(0, count($espacioIds), '?'));
        // (201) Cada equipo tiene su propio montaje y desmontaje: el horario ocupado se arma equipo por equipo
        $choques = [];
        foreach ($espacioIds as $espacioId) {
            [$desde, $hasta] = $this->periodo($negocioId, $fecha, $horaInicio, $horaFin, $viajeMin, $espacioId);
            array_push($choques, ...$this->choquesEnEquipo($negocioId, $espacioId, $desde, $hasta, $excluirEvento, $hoyTxt));
        }
        usort($choques, fn($a, $b) => (($b['nivel'] === 'bloqueado') <=> ($a['nivel'] === 'bloqueado')) ?: strcmp((string) $a['fecha'], (string) $b['fecha']));
        return $this->conDiasCerrados($choques, $negocioId, $fecha, $espacioIds, $marcas);
    }

    /** Eventos que ocupan ese equipo en ese horario (ya con montaje, desmontaje y viaje). */
    private function choquesEnEquipo(int $negocioId, int $espacioId, string $desde, string $hasta, ?int $excluirEvento, string $hoyTxt): array
    {
        $espacioIds = [$espacioId];
        $marcas = '?';
        $st = $this->db->prepare(
            "SELECT e.id, e.codigo, e.nombre, e.estado, e.fecha, e.hora_inicio, e.hora_fin, e.retenida_hasta,
                    es.id AS espacio_id, es.nombre AS espacio, c.nombre AS cliente,
                    CASE WHEN ee.bloquea THEN 'bloqueado'
                         WHEN e.estado = 'cotizado' AND e.retenida_hasta >= ?::date THEN 'retenido'
                         ELSE 'consulta' END AS nivel
               FROM evento_espacios ee
               JOIN eventos e ON e.id = ee.evento_id
               JOIN espacios es ON es.id = ee.espacio_id
               JOIN clientes c ON c.id = e.cliente_id
              WHERE ee.negocio_id = ? AND ee.espacio_id IN ($marcas)
                AND ee.periodo && tsrange(?::timestamp, ?::timestamp)
                AND e.estado IN ('consulta','cotizado','separado','confirmado','realizado')
                AND (?::int IS NULL OR e.id <> ?::int)
           ORDER BY ee.bloquea DESC, e.fecha"
        );
        $st->execute([$hoyTxt, $negocioId, ...$espacioIds, $desde, $hasta, $excluirEvento, $excluirEvento]);
        return $st->fetchAll();
    }

    /** Agrega los días cerrados (de todo el negocio o de esos equipos) a la lista de choques. */
    private function conDiasCerrados(array $choques, int $negocioId, string $fecha, array $espacioIds, string $marcas): array
    {
        $st = $this->db->prepare("SELECT fc.fecha, fc.motivo, fc.espacio_id, es.nombre AS espacio FROM fechas_cerradas fc
                                   LEFT JOIN espacios es ON es.id = fc.espacio_id
                                   WHERE fc.negocio_id = ? AND fc.fecha = ?::date AND (fc.espacio_id IS NULL OR fc.espacio_id IN ($marcas))");
        $st->execute([$negocioId, $fecha, ...$espacioIds]);
        foreach ($st->fetchAll() as $c) {
            $choques[] = ['id' => null, 'codigo' => '', 'nombre' => 'Día cerrado' . ($c['motivo'] ? ': ' . $c['motivo'] : ''),
                          'estado' => 'cerrado', 'nivel' => 'bloqueado', 'espacio' => $c['espacio'] ?? 'Todo el local',
                          'espacio_id' => $c['espacio_id'], 'cliente' => '', 'fecha' => $c['fecha'], 'hora_inicio' => null, 'hora_fin' => null, 'retenida_hasta' => null];
        }
        return $choques;
    }

    /**
     * Disponibilidad de todos los espacios en una fecha (para el calendario y la página pública).
     * @return list<array{espacio_id:int, espacio:string, capacidad_banquete:?int, estado:string, eventos:array}>
     *   estado: libre | consulta | retenido | ocupado | cerrado
     */
    public function disponibilidad(int $negocioId, string $fecha, ?DateTimeImmutable $hoy = null): array
    {
        $hoyTxt = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $esp = $this->db->prepare('SELECT id, nombre, capacidad_banquete, capacidad_coctel, color FROM espacios WHERE negocio_id = ? AND activo ORDER BY orden, id');
        $esp->execute([$negocioId]);
        $st = $this->db->prepare(
            "SELECT ee.espacio_id, ee.bloquea, e.id, e.codigo, e.nombre, e.estado, e.hora_inicio, e.hora_fin, e.retenida_hasta
               FROM evento_espacios ee JOIN eventos e ON e.id = ee.evento_id
              WHERE ee.negocio_id = ? AND e.fecha = ?::date AND e.estado IN ('consulta','cotizado','separado','confirmado','realizado')
           ORDER BY e.hora_inicio"
        );
        $st->execute([$negocioId, $fecha]);
        $porEspacio = [];
        foreach ($st->fetchAll() as $r) $porEspacio[(int) $r['espacio_id']][] = $r;
        $cer = $this->db->prepare('SELECT espacio_id FROM fechas_cerradas WHERE negocio_id = ? AND fecha = ?::date');
        $cer->execute([$negocioId, $fecha]);
        $cerrados = array_map(fn($v) => $v === null ? 0 : (int) $v, $cer->fetchAll(PDO::FETCH_COLUMN));
        $res = [];
        foreach ($esp->fetchAll() as $e) {
            $evs = $porEspacio[(int) $e['id']] ?? [];
            $estado = 'libre';
            if (in_array(0, $cerrados, true) || in_array((int) $e['id'], $cerrados, true)) {
                $estado = 'cerrado';
            } else {
                foreach ($evs as $ev) {
                    if ($ev['bloquea']) { $estado = 'ocupado'; break; }
                    if ($ev['estado'] === 'cotizado' && $ev['retenida_hasta'] >= $hoyTxt) $estado = 'retenido';
                    elseif ($estado === 'libre') $estado = 'consulta';
                }
            }
            $res[] = ['espacio_id' => (int) $e['id'], 'espacio' => $e['nombre'], 'color' => $e['color'],
                      'capacidad_banquete' => $e['capacidad_banquete'], 'capacidad_coctel' => $e['capacidad_coctel'],
                      'estado' => $estado, 'eventos' => $evs];
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Crear y modificar eventos
    // ------------------------------------------------------------------

    private function validar(int $negocioId, array $d): array
    {
        $tipo = (string) ($d['tipo'] ?? 'boda');
        if (!isset(self::TIPOS[$tipo])) throw new RuntimeException('Elige el tipo de evento.');
        $fecha = (string) ($d['fecha'] ?? '');
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (!$f || $f->format('Y-m-d') !== $fecha) throw new RuntimeException('Elige la fecha del evento.');
        $hi = substr((string) ($d['hora_inicio'] ?? '18:00'), 0, 5);
        $hf = substr((string) ($d['hora_fin'] ?? '02:00'), 0, 5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hi) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hf)) {
            throw new RuntimeException('Revisa la hora de inicio y de fin.');
        }
        if ($hi === $hf) throw new RuntimeException('La hora de fin no puede ser igual a la de inicio.');
        $inv = (int) ($d['invitados'] ?? 0);
        if ($inv < 1 || $inv > 20000) throw new RuntimeException('Escribe cuántos invitados (aproximado).');
        $espacios = array_values(array_unique(array_filter(array_map('intval', (array) ($d['espacios'] ?? [])))));
        if (!$espacios) throw new RuntimeException('Elige al menos un espacio.');
        $marcas = implode(',', array_fill(0, count($espacios), '?'));
        $st = $this->db->prepare("SELECT count(*) FROM espacios WHERE negocio_id = ? AND activo AND id IN ($marcas)");
        $st->execute([$negocioId, ...$espacios]);
        if ((int) $st->fetchColumn() !== count($espacios)) throw new RuntimeException('Uno de los espacios no existe.');
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $origen = (string) ($d['origen'] ?? '');
        $montaje = (string) ($d['montaje'] ?? '');
        $pres = isset($d['presupuesto_cliente']) && $d['presupuesto_cliente'] !== '' ? (float) $d['presupuesto_cliente'] : null;
        return [
            'tipo' => $tipo, 'fecha' => $fecha, 'hora_inicio' => $hi, 'hora_fin' => $hf, 'invitados' => $inv,
            'espacios' => $espacios, 'nombre' => mb_substr($nombre, 0, 150),
            'origen' => isset(self::ORIGENES[$origen]) ? $origen : null,
            'montaje' => isset(self::MONTAJES[$montaje]) ? $montaje : null,
            'presupuesto_cliente' => $pres !== null && $pres >= 0 ? round($pres, 2) : null,
            'notas' => trim((string) ($d['notas'] ?? '')) ?: null,
            'vendedor_id' => !empty($d['vendedor_id']) ? (int) $d['vendedor_id'] : null,
        ];
    }

    /** Siguiente código del negocio: EV-2026-0001 (año de creación). */
    private function nuevoCodigo(int $negocioId, ?DateTimeImmutable $hoy = null): string
    {
        $anio = ($hoy ?? new DateTimeImmutable('today'))->format('Y');
        $this->db->prepare('SELECT id FROM negocios WHERE id = ? FOR UPDATE')->execute([$negocioId]);
        $st = $this->db->prepare("SELECT max(substring(codigo from 9)::int) FROM eventos WHERE negocio_id = ? AND codigo LIKE ?");
        $st->execute([$negocioId, "EV-$anio-%"]);
        return sprintf('EV-%s-%04d', $anio, ((int) $st->fetchColumn()) + 1);
    }

    /**
     * Crea un evento en estado "consulta". $d: cliente_id (o datos de cliente nuevo en $d['cliente']), tipo, fecha,
     * hora_inicio, hora_fin, invitados, espacios[], nombre, origen, montaje, presupuesto_cliente, notas, vendedor_id.
     */
    public function crear(int $negocioId, array $d, ?int $usuarioId = null, ?DateTimeImmutable $hoy = null): int
    {
        $v = $this->validar($negocioId, $d);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $clienteId = !empty($d['cliente_id']) ? (int) $d['cliente_id'] : $this->guardarCliente($negocioId, (array) ($d['cliente'] ?? []) + ['origen' => $v['origen']]);
            $cli = $this->cliente($negocioId, $clienteId);
            if (!$cli) throw new RuntimeException('No se encontró el cliente.');
            if ($v['nombre'] === '') $v['nombre'] = self::TIPOS[$v['tipo']] . ' de ' . $cli['nombre'];
            $codigo = $this->nuevoCodigo($negocioId, $hoy);
            $st = $this->db->prepare(
                "INSERT INTO eventos (negocio_id, codigo, cliente_id, tipo, nombre, fecha, hora_inicio, hora_fin, invitados, estado,
                                      vendedor_id, origen, montaje, presupuesto_cliente, notas, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,?, 'consulta', ?,?,?,?,?,?) RETURNING id"
            );
            $st->execute([$negocioId, $codigo, $clienteId, $v['tipo'], $v['nombre'], $v['fecha'], $v['hora_inicio'], $v['hora_fin'],
                          $v['invitados'], $v['vendedor_id'] ?? $usuarioId, $v['origen'] ?? $cli['origen'], $v['montaje'],
                          $v['presupuesto_cliente'], $v['notas'], $usuarioId]);
            $id = (int) $st->fetchColumn();
            if (isset($d['viaje_min'])) {   // (201) reserva en línea: minutos de viaje desde la base, antes de ocupar el equipo
                $this->db->prepare('UPDATE eventos SET viaje_min = ? WHERE id = ?')->execute([max(0, min(1440, (int) $d['viaje_min'])), $id]);
            }
            $this->guardarEspacios($negocioId, $id, $v['fecha'], $v['hora_inicio'], $v['hora_fin'], $v['espacios'], false);
            $this->crearTareasDesdePlantilla($negocioId, $id, $v['tipo'], $v['fecha']);
            $st = $this->db->prepare('SELECT garantia_defecto FROM negocios WHERE id = ?');
            $st->execute([$negocioId]);
            $this->db->prepare('UPDATE eventos SET garantia = ? WHERE id = ?')->execute([(float) $st->fetchColumn(), $id]);
            if ($propia) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Cambia los datos generales. Si cambia la fecha, la hora o los espacios de un evento separado, revisa que siga libre. */
    public function actualizar(int $negocioId, int $id, array $d): void
    {
        $ev = $this->obtener($negocioId, $id);
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        $v = $this->validar($negocioId, $d + ['espacios' => array_column($ev['espacios'], 'id')]);
        if ($v['nombre'] === '') $v['nombre'] = $ev['nombre'];
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE eventos SET tipo = ?, nombre = ?, fecha = ?, hora_inicio = ?, hora_fin = ?, invitados = ?,
                                       origen = COALESCE(?, origen), montaje = ?, presupuesto_cliente = ?, notas = ?,
                                       vendedor_id = COALESCE(?, vendedor_id), actualizado_en = now()
                                 WHERE id = ? AND negocio_id = ?')
                     ->execute([$v['tipo'], $v['nombre'], $v['fecha'], $v['hora_inicio'], $v['hora_fin'], $v['invitados'],
                                $v['origen'], $v['montaje'], $v['presupuesto_cliente'], $v['notas'], $v['vendedor_id'], $id, $negocioId]);
            if (array_key_exists('coordinador_id', $d)) {
                $this->db->prepare('UPDATE eventos SET coordinador_id = ? WHERE id = ?')->execute([!empty($d['coordinador_id']) ? (int) $d['coordinador_id'] : null, $id]);
            }
            foreach (['menu_elegido', 'detalles_cliente'] as $campo) {
                if (array_key_exists($campo, $d)) {
                    $this->db->prepare("UPDATE eventos SET $campo = ? WHERE id = ?")->execute([trim((string) $d[$campo]) ?: null, $id]);
                }
            }
            $this->guardarEspacios($negocioId, $id, $v['fecha'], $v['hora_inicio'], $v['hora_fin'], $v['espacios'], in_array($ev['estado'], self::BLOQUEAN, true));
            if ($ev['fecha'] !== $v['fecha']) {
                // Las tareas se mueven con la fecha
                $dias = (int) (new DateTimeImmutable($ev['fecha']))->diff(new DateTimeImmutable($v['fecha']))->format('%r%a');
                $this->db->prepare('UPDATE evento_tareas SET vence = vence + ?::int WHERE evento_id = ? AND NOT hecha AND vence IS NOT NULL')->execute([$dias, $id]);
            }
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Guarda los espacios del evento con su horario. Traduce el choque de la base de datos a un mensaje claro. */
    private function guardarEspacios(int $negocioId, int $eventoId, string $fecha, string $hi, string $hf, array $espacios, bool $bloquea): void
    {
        $viaje = $this->viajeDe($eventoId);
        if ($bloquea) $this->exigirLibre($negocioId, $fecha, $hi, $hf, $espacios, $eventoId);
        $marcas = implode(',', array_fill(0, count($espacios), '?'));
        $this->db->prepare("DELETE FROM evento_espacios WHERE evento_id = ? AND espacio_id NOT IN ($marcas)")->execute([$eventoId, ...$espacios]);
        $st = $this->db->prepare('INSERT INTO evento_espacios (negocio_id, evento_id, espacio_id, periodo, bloquea)
                                  VALUES (?,?,?, tsrange(?::timestamp, ?::timestamp), ?)
                                  ON CONFLICT (evento_id, espacio_id) DO UPDATE SET periodo = EXCLUDED.periodo, bloquea = EXCLUDED.bloquea');
        $this->conTraduccionDeChoque(function () use ($st, $negocioId, $eventoId, $espacios, $fecha, $hi, $hf, $viaje, $bloquea) {
            foreach ($espacios as $esp) {
                [$desde, $hasta] = $this->periodo($negocioId, $fecha, $hi, $hf, $viaje, (int) $esp);
                $st->execute([$negocioId, $eventoId, $esp, $desde, $hasta, $bloquea ? 'true' : 'false']);
            }
        });
    }

    /** Minutos de viaje (una ida) guardados en el evento (201). 0 si no tiene. */
    public function viajeDe(int $eventoId): int
    {
        $st = $this->db->prepare('SELECT viaje_min FROM eventos WHERE id = ?');
        $st->execute([$eventoId]);
        return max(0, (int) $st->fetchColumn());
    }

    /** Lanza un error con el evento que ya tiene separada la fecha. */
    private function exigirLibre(int $negocioId, string $fecha, string $hi, string $hf, array $espacios, int $eventoId): void
    {
        foreach ($this->choques($negocioId, $fecha, $hi, $hf, $espacios, $eventoId, null, $this->viajeDe($eventoId)) as $c) {
            if ($c['nivel'] === 'bloqueado') {
                throw new RuntimeException($c['estado'] === 'cerrado'
                    ? "Esa fecha está cerrada en {$c['espacio']} ({$c['nombre']})."
                    : "{$c['espacio']} ya está separado ese día por {$c['codigo']} ({$c['nombre']}, " . substr((string) $c['hora_inicio'], 0, 5) . ' a ' . substr((string) $c['hora_fin'], 0, 5) . '). Elige otra fecha, otra hora u otro espacio.');
            }
        }
    }

    /** Si dos personas separan a la vez, la base de datos frena a la segunda: se muestra un mensaje claro. */
    private function conTraduccionDeChoque(callable $f): void
    {
        try {
            $f();
        } catch (PDOException $e) {
            if ($e->getCode() === '23P01') {
                throw new RuntimeException('Otra persona acaba de separar ese espacio en ese horario. Revisa el calendario.');
            }
            throw $e;
        }
    }

    public function obtener(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT e.*, c.nombre AS cliente, c.telefono AS cliente_telefono, c.email AS cliente_email,
                    c.identificacion AS cliente_identificacion, c.direccion AS cliente_direccion,
                    v.nombre AS vendedor, co.nombre AS coordinador
               FROM eventos e JOIN clientes c ON c.id = e.cliente_id
          LEFT JOIN usuarios v ON v.id = e.vendedor_id
          LEFT JOIN usuarios co ON co.id = e.coordinador_id
              WHERE e.id = ? AND e.negocio_id = ?'
        );
        $st->execute([$id, $negocioId]);
        $e = $st->fetch();
        if (!$e) return null;
        $e['espacios'] = $this->espacios($id);
        $e['espacios_texto'] = implode(', ', array_column($e['espacios'], 'nombre'));
        return $e;
    }

    /** Busca un evento por uno de sus enlaces secretos (cliente, puerta o en vivo). */
    public function porToken(string $columna, string $token): ?array
    {
        if (!in_array($columna, ['token_cliente', 'token_puerta', 'token_vivo'], true)) return null;
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
        $st = $this->db->prepare("SELECT negocio_id, id FROM eventos WHERE $columna = ?");
        $st->execute([$token]);
        $r = $st->fetch();
        return $r ? $this->obtener((int) $r['negocio_id'], (int) $r['id']) : null;
    }

    public function espacios(int $eventoId): array
    {
        $st = $this->db->prepare('SELECT es.id, es.nombre, es.color, ee.bloquea, lower(ee.periodo) AS desde, upper(ee.periodo) AS hasta
                                    FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id
                                   WHERE ee.evento_id = ? ORDER BY es.orden, es.id');
        $st->execute([$eventoId]);
        return $st->fetchAll();
    }

    /**
     * Cambia el estado. Al separar, bloquea los espacios (falla si otro evento ya los tiene).
     * $extra['motivo'] es obligatorio para perdido y cancelado.
     */
    public function cambiarEstado(int $negocioId, int $id, string $nuevo, array $extra = [], ?array $usuario = null, ?DateTimeImmutable $hoy = null): void
    {
        $hoy ??= new DateTimeImmutable('today');
        if (!isset(self::ESTADOS[$nuevo])) throw new RuntimeException('Estado no válido.');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT * FROM eventos WHERE id = ? AND negocio_id = ? FOR UPDATE');
            $st->execute([$id, $negocioId]);
            $ev = $st->fetch();
            if (!$ev) throw new RuntimeException('No se encontró el evento.');
            $actual = $ev['estado'];
            if ($actual === $nuevo) {
                if ($propia) $this->db->commit();
                return;
            }
            if (!in_array($nuevo, self::PASOS[$actual], true)) {
                throw new RuntimeException('No se puede pasar de "' . self::ESTADOS[$actual] . '" a "' . self::ESTADOS[$nuevo] . '".');
            }
            $motivo = trim((string) ($extra['motivo'] ?? ''));
            if (in_array($nuevo, ['perdido', 'cancelado'], true) && mb_strlen($motivo) < 3) {
                throw new RuntimeException('Escribe el motivo (sirve para saber por qué se pierden eventos).');
            }
            if ($nuevo === 'realizado' && $ev['fecha'] > $hoy->format('Y-m-d')) {
                throw new RuntimeException('Solo se puede marcar como realizado el día del evento o después.');
            }
            $bloquea = in_array($nuevo, self::BLOQUEAN, true);
            $espacios = array_map('intval', array_column($this->espacios($id), 'id'));
            if ($bloquea && !in_array($actual, self::BLOQUEAN, true)) {
                if (!$espacios) throw new RuntimeException('El evento no tiene espacio asignado.');
                $this->exigirLibre($negocioId, $ev['fecha'], $ev['hora_inicio'], $ev['hora_fin'], $espacios, $id);
            }
            $this->conTraduccionDeChoque(function () use ($id, $bloquea) {
                $this->db->prepare('UPDATE evento_espacios SET bloquea = ? WHERE evento_id = ?')->execute([$bloquea ? 'true' : 'false', $id]);
            });
            $retenida = null;
            if ($nuevo === 'cotizado') {
                $st = $this->db->prepare('SELECT dias_retencion FROM negocios WHERE id = ?');
                $st->execute([$negocioId]);
                $retenida = $hoy->modify('+' . (int) $st->fetchColumn() . ' days')->format('Y-m-d');
            }
            $this->db->prepare('UPDATE eventos SET estado = ?, retenida_hasta = ?, actualizado_en = now(),
                                       motivo_perdida = CASE WHEN ? IN (\'perdido\',\'cancelado\') THEN ? ELSE motivo_perdida END,
                                       confirmado_en = CASE WHEN ? = \'confirmado\' AND confirmado_en IS NULL THEN now() ELSE confirmado_en END
                                 WHERE id = ?')
                     ->execute([$nuevo, $retenida, $nuevo, $motivo ?: null, $nuevo, $id]);
            $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                     ->execute([$negocioId, $id, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema',
                                'Cambió el estado de ' . self::ESTADOS[$actual] . ' a ' . self::ESTADOS[$nuevo] . ($motivo ? " ($motivo)" : '')]);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Vuelve a retener la fecha de un evento cotizado (otros X días). */
    public function extenderRetencion(int $negocioId, int $id, int $dias, ?DateTimeImmutable $hoy = null): string
    {
        if ($dias < 1 || $dias > 60) throw new RuntimeException('Elige de 1 a 60 días.');
        $hasta = ($hoy ?? new DateTimeImmutable('today'))->modify("+$dias days")->format('Y-m-d');
        $st = $this->db->prepare("UPDATE eventos SET retenida_hasta = ? WHERE id = ? AND negocio_id = ? AND estado = 'cotizado'");
        $st->execute([$hasta, $id, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Solo se puede retener la fecha de un evento cotizado.');
        return $hasta;
    }

    /** Copia un evento (mismo cliente o cliente que se repite cada año) a otra fecha, como consulta. */
    public function duplicar(int $negocioId, int $id, string $nuevaFecha, ?int $usuarioId = null): int
    {
        $ev = $this->obtener($negocioId, $id);
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        return $this->crear($negocioId, [
            'cliente_id' => $ev['cliente_id'], 'tipo' => $ev['tipo'], 'nombre' => $ev['nombre'], 'fecha' => $nuevaFecha,
            'hora_inicio' => $ev['hora_inicio'], 'hora_fin' => $ev['hora_fin'], 'invitados' => $ev['invitados'],
            'espacios' => array_column($ev['espacios'], 'id'), 'origen' => 'cliente_anterior', 'montaje' => $ev['montaje'],
        ], $usuarioId);
    }

    /**
     * Lista de eventos con filtros: estado ('' todos, 'activos'), desde, hasta, buscar, tipo, vendedor_id.
     */
    public function lista(int $negocioId, array $f = [], int $limite = 500): array
    {
        $sql = 'SELECT e.id, e.codigo, e.nombre, e.tipo, e.fecha, e.hora_inicio, e.hora_fin, e.invitados, e.estado, e.total,
                       e.retenida_hasta, e.origen, e.creado_en, c.nombre AS cliente, c.telefono AS cliente_telefono,
                       v.nombre AS vendedor,
                       (SELECT string_agg(es.nombre, \', \' ORDER BY es.orden) FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = e.id) AS espacios,
                       (SELECT COALESCE(sum(p.monto),0) FROM pagos p WHERE p.evento_id = e.id AND p.tipo = \'evento\' AND p.estado = \'confirmado\') AS pagado
                  FROM eventos e JOIN clientes c ON c.id = e.cliente_id LEFT JOIN usuarios v ON v.id = e.vendedor_id
                 WHERE e.negocio_id = ?';
        $p = [$negocioId];
        $estado = (string) ($f['estado'] ?? '');
        if ($estado === 'activos') { $sql .= " AND e.estado IN ('consulta','cotizado','separado','confirmado')"; }
        elseif ($estado === 'vendidos') { $sql .= " AND e.estado IN ('separado','confirmado','realizado')"; }
        elseif (isset(self::ESTADOS[$estado])) { $sql .= ' AND e.estado = ?'; $p[] = $estado; }
        if (!empty($f['desde'])) { $sql .= ' AND e.fecha >= ?::date'; $p[] = $f['desde']; }
        if (!empty($f['hasta'])) { $sql .= ' AND e.fecha <= ?::date'; $p[] = $f['hasta']; }
        if (!empty($f['tipo']) && isset(self::TIPOS[$f['tipo']])) { $sql .= ' AND e.tipo = ?'; $p[] = $f['tipo']; }
        if (!empty($f['vendedor_id'])) { $sql .= ' AND e.vendedor_id = ?'; $p[] = (int) $f['vendedor_id']; }
        if (!empty($f['cliente_id'])) { $sql .= ' AND e.cliente_id = ?'; $p[] = (int) $f['cliente_id']; }
        if (($b = trim((string) ($f['buscar'] ?? ''))) !== '') {
            $sql .= ' AND (e.nombre ILIKE ? OR e.codigo ILIKE ? OR c.nombre ILIKE ? OR c.telefono ILIKE ?)';
            array_push($p, ...array_fill(0, 4, "%$b%"));
        }
        $orden = ($f['orden'] ?? '') === 'recientes' ? 'e.creado_en DESC' : 'e.fecha, e.hora_inicio';
        $st = $this->db->prepare("$sql ORDER BY $orden LIMIT " . (int) $limite);
        $st->execute($p);
        return $st->fetchAll();
    }

    /** Eventos de un mes con sus espacios (para el calendario). Incluye 7 días antes y después para completar semanas. */
    public function calendarioMes(int $negocioId, int $anio, int $mes): array
    {
        $ini = (new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->modify('-7 days')->format('Y-m-d');
        $fin = (new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))->modify('last day of this month')->modify('+7 days')->format('Y-m-d');
        $st = $this->db->prepare(
            "SELECT e.id, e.codigo, e.nombre, e.tipo, e.fecha, e.hora_inicio, e.hora_fin, e.estado, e.invitados, e.retenida_hasta,
                    c.nombre AS cliente,
                    (SELECT string_agg(es.nombre, ', ' ORDER BY es.orden) FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = e.id) AS espacios,
                    (SELECT min(es.color) FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = e.id) AS color
               FROM eventos e JOIN clientes c ON c.id = e.cliente_id
              WHERE e.negocio_id = ? AND e.fecha BETWEEN ?::date AND ?::date AND e.estado NOT IN ('perdido','cancelado')
           ORDER BY e.fecha, e.hora_inicio"
        );
        $st->execute([$negocioId, $ini, $fin]);
        $porDia = [];
        foreach ($st->fetchAll() as $e) $porDia[$e['fecha']][] = $e;
        $cer = $this->db->prepare('SELECT fc.fecha, fc.motivo, es.nombre AS espacio FROM fechas_cerradas fc LEFT JOIN espacios es ON es.id = fc.espacio_id
                                    WHERE fc.negocio_id = ? AND fc.fecha BETWEEN ?::date AND ?::date');
        $cer->execute([$negocioId, $ini, $fin]);
        $cerrados = [];
        foreach ($cer->fetchAll() as $c) $cerrados[$c['fecha']][] = $c;
        return ['eventos' => $porDia, 'cerrados' => $cerrados];
    }

    /** Cuántos eventos hay en cada estado y cuánto valen (embudo de ventas). */
    public function embudo(int $negocioId, ?string $desde = null, ?string $hasta = null): array
    {
        $st = $this->db->prepare("SELECT estado, count(*) AS cantidad, COALESCE(sum(total),0) AS valor FROM eventos
                                   WHERE negocio_id = ? AND (?::date IS NULL OR fecha >= ?::date) AND (?::date IS NULL OR fecha <= ?::date)
                                   GROUP BY estado");
        $st->execute([$negocioId, $desde, $desde, $hasta, $hasta]);
        $res = [];
        foreach (array_keys(self::ESTADOS) as $e) $res[$e] = ['cantidad' => 0, 'valor' => 0.0];
        foreach ($st->fetchAll() as $r) $res[$r['estado']] = ['cantidad' => (int) $r['cantidad'], 'valor' => (float) $r['valor']];
        return $res;
    }

    // ------------------------------------------------------------------
    // Seguimiento (actividades) y tareas del evento
    // ------------------------------------------------------------------

    public function agregarActividad(int $negocioId, ?int $eventoId, ?int $clienteId, string $tipo, string $texto, ?string $programada = null, ?int $usuarioId = null): int
    {
        if (!isset(self::TIPOS_ACTIVIDAD[$tipo])) $tipo = 'nota';
        $texto = trim($texto);
        if (mb_strlen($texto) < 2) throw new RuntimeException('Escribe la nota.');
        if ($programada !== null && $programada !== '') {
            $p = strtotime(str_replace('T', ' ', $programada));
            if ($p === false) throw new RuntimeException('Revisa la fecha y hora agendada.');
            $programada = date('Y-m-d H:i:s', $p);
        } else {
            $programada = null;
        }
        if ($eventoId && !$clienteId) {
            $st = $this->db->prepare('SELECT cliente_id FROM eventos WHERE id = ? AND negocio_id = ?');
            $st->execute([$eventoId, $negocioId]);
            $clienteId = (int) $st->fetchColumn() ?: null;
            if (!$clienteId) throw new RuntimeException('No se encontró el evento.');
        }
        $st = $this->db->prepare('INSERT INTO actividades (negocio_id, evento_id, cliente_id, tipo, texto, programada_para, hecha, usuario_id)
                                  VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$negocioId, $eventoId, $clienteId, $tipo, mb_substr($texto, 0, 2000), $programada,
                      $programada === null ? 'true' : 'false', $usuarioId]);
        return (int) $st->fetchColumn();
    }

    public function marcarActividad(int $negocioId, int $actividadId, bool $hecha = true): void
    {
        $st = $this->db->prepare('UPDATE actividades SET hecha = ? WHERE id = ? AND negocio_id = ?');
        $st->execute([$hecha ? 'true' : 'false', $actividadId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró la actividad.');
    }

    public function actividades(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT a.*, u.nombre AS usuario FROM actividades a LEFT JOIN usuarios u ON u.id = a.usuario_id
                                   WHERE a.negocio_id = ? AND a.evento_id = ? ORDER BY COALESCE(a.programada_para, a.creado_en) DESC');
        $st->execute([$negocioId, $eventoId]);
        return $st->fetchAll();
    }

    /** Llamadas, visitas y degustaciones pendientes hasta una fecha (las vencidas incluidas). */
    public function pendientes(int $negocioId, ?string $hasta = null, ?int $usuarioId = null): array
    {
        $hasta ??= date('Y-m-d 23:59:59');
        $st = $this->db->prepare('SELECT a.*, e.codigo, e.nombre AS evento, e.fecha AS evento_fecha, e.estado AS evento_estado,
                                         c.nombre AS cliente, c.telefono, u.nombre AS usuario
                                    FROM actividades a LEFT JOIN eventos e ON e.id = a.evento_id
                                    LEFT JOIN clientes c ON c.id = a.cliente_id LEFT JOIN usuarios u ON u.id = a.usuario_id
                                   WHERE a.negocio_id = ? AND NOT a.hecha AND a.programada_para <= ?::timestamp
                                     AND (?::int IS NULL OR a.usuario_id = ?::int)
                                ORDER BY a.programada_para');
        $st->execute([$negocioId, $hasta, $usuarioId, $usuarioId]);
        return $st->fetchAll();
    }

    /** Crea las tareas del evento según la plantilla del negocio (ej. "Degustación" 60 días antes). */
    public function crearTareasDesdePlantilla(int $negocioId, int $eventoId, string $tipo, string $fecha): int
    {
        $st = $this->db->prepare('SELECT titulo, dias_antes, orden FROM plantilla_tareas
                                   WHERE negocio_id = ? AND (tipo_evento IS NULL OR tipo_evento = ?) ORDER BY dias_antes DESC, orden');
        $st->execute([$negocioId, $tipo]);
        $ins = $this->db->prepare('INSERT INTO evento_tareas (evento_id, titulo, vence, orden)
                                    VALUES (?,?, CASE WHEN ?::int < 0 THEN ?::date - ?::int ELSE LEAST(?::date, GREATEST(CURRENT_DATE, ?::date - ?::int)) END, ?)');
        $n = 0;
        foreach ($st->fetchAll() as $t) {
            $d = (int) $t['dias_antes'];
            $ins->execute([$eventoId, $t['titulo'], $d, $fecha, $d, $fecha, $fecha, $d, $n++]);
        }
        return $n;
    }

    public function tareas(int $eventoId): array
    {
        $st = $this->db->prepare('SELECT t.*, u.nombre AS responsable FROM evento_tareas t LEFT JOIN usuarios u ON u.id = t.responsable_id
                                   WHERE t.evento_id = ? ORDER BY t.hecha, t.vence NULLS LAST, t.orden');
        $st->execute([$eventoId]);
        return $st->fetchAll();
    }

    public function agregarTarea(int $negocioId, int $eventoId, string $titulo, ?string $vence = null): int
    {
        if (!$this->obtener($negocioId, $eventoId)) throw new RuntimeException('No se encontró el evento.');
        if (mb_strlen(trim($titulo)) < 2) throw new RuntimeException('Escribe la tarea.');
        $st = $this->db->prepare('INSERT INTO evento_tareas (evento_id, titulo, vence, orden) VALUES (?,?,?,999) RETURNING id');
        $st->execute([$eventoId, mb_substr(trim($titulo), 0, 150), $vence ?: null]);
        return (int) $st->fetchColumn();
    }

    public function marcarTarea(int $negocioId, int $tareaId, bool $hecha): void
    {
        $st = $this->db->prepare('UPDATE evento_tareas t SET hecha = ?, hecha_en = CASE WHEN ? THEN now() END
                                    FROM eventos e WHERE e.id = t.evento_id AND e.negocio_id = ? AND t.id = ?');
        $st->execute([$hecha ? 'true' : 'false', $hecha ? 'true' : 'false', $negocioId, $tareaId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró la tarea.');
    }

    /** Tareas de todos los eventos que vencen hasta una fecha (para el inicio). */
    public function tareasPorVencer(int $negocioId, string $hasta): array
    {
        $st = $this->db->prepare("SELECT t.*, e.codigo, e.nombre AS evento, e.fecha AS evento_fecha FROM evento_tareas t
                                    JOIN eventos e ON e.id = t.evento_id
                                   WHERE e.negocio_id = ? AND NOT t.hecha AND t.vence <= ?::date AND e.estado IN ('separado','confirmado')
                                ORDER BY t.vence");
        $st->execute([$negocioId, $hasta]);
        return $st->fetchAll();
    }

    public function historial(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT * FROM registro_cambios WHERE negocio_id = ? AND evento_id = ? ORDER BY creado_en DESC, id DESC LIMIT 200');
        $st->execute([$negocioId, $eventoId]);
        return $st->fetchAll();
    }

    /** Sugerencia de meseros según invitados (configurable en el negocio). */
    public function meserosSugeridos(int $negocioId, int $invitados): int
    {
        $st = $this->db->prepare('SELECT invitados_por_mesero FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $por = max(1, (int) $st->fetchColumn());
        return (int) ceil($invitados / $por);
    }
}
