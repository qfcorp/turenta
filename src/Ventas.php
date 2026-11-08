<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Ventas: calendario, próximos sábados, seguimiento comercial, clientes, espacios, fechas cerradas
 * y la página pública del negocio (consulta de disponibilidad y formulario de consulta).
 * Se apoya en Eventos (núcleo) para crear eventos, ver choques y disponibilidad.
 */
final class Ventas
{
    /** Tipos de seguimiento que el usuario puede agregar (el tipo "sistema" lo pone el programa). */
    public const TIPOS_SEGUIMIENTO = [
        'nota' => 'Nota', 'llamada' => 'Llamada', 'whatsapp' => 'WhatsApp', 'visita' => 'Visita al local',
        'degustacion' => 'Degustación', 'reunion' => 'Reunión', 'correo' => 'Correo',
    ];
    /** Estados públicos de un espacio en una fecha (lo que ve un visitante de la página). */
    public const ESTADOS_PUBLICOS = ['libre' => 'Libre', 'consultar' => 'Por confirmar', 'ocupado' => 'Ocupada'];
    /** Columnas del tablero de ventas, en orden. */
    public const TABLERO = ['consulta', 'cotizado', 'separado', 'confirmado'];
    public const MAX_CONSULTAS_HORA = 5;
    /** Tipos de evento que se muestran en la página pública. */
    public const TIPOS_PUBLICOS = ['boda', 'quince', 'graduacion', 'bautizo', 'primera_comunion', 'cumpleanos', 'corporativo', 'aniversario'];

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------------
    // Calendario
    // ------------------------------------------------------------------

    /**
     * Semanas (lunes a domingo) que cubren el mes. Cada semana es una lista de 7 fechas 'Y-m-d'.
     * @return list<list<string>>
     */
    public static function semanasDelMes(int $anio, int $mes): array
    {
        $primero = new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
        $ultimo = $primero->modify('last day of this month');
        $dia = $primero->modify('-' . ((int) $primero->format('N') - 1) . ' days');
        $semanas = [];
        while ($dia <= $ultimo) {
            $semana = [];
            for ($i = 0; $i < 7; $i++) {
                $semana[] = $dia->format('Y-m-d');
                $dia = $dia->modify('+1 day');
            }
            $semanas[] = $semana;
        }
        return $semanas;
    }

    /** Mes válido a partir de lo que llega en la dirección (si no, el mes de hoy). @return array{0:int,1:int} */
    public static function mesValido(mixed $anio, mixed $mes, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $a = (int) $anio;
        $m = (int) $mes;
        if ($a < 2000 || $a > 2100 || $m < 1 || $m > 12) return [(int) $hoy->format('Y'), (int) $hoy->format('n')];
        return [$a, $m];
    }

    /** ¿Es una fecha real con formato Y-m-d? */
    public static function fechaValida(?string $f): bool
    {
        if ($f === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) return false;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f);
        return $d !== false && $d->format('Y-m-d') === $f;
    }

    /**
     * Eventos (no perdidos ni cancelados) y días cerrados de un mes, agrupados por día.
     * Con $espacioId solo se ven los eventos de ese espacio y los cierres que lo afectan.
     */
    public function calendario(int $negocioId, int $anio, int $mes, ?int $espacioId = null): array
    {
        $semanas = self::semanasDelMes($anio, $mes);
        $desde = $semanas[0][0];
        $hasta = end($semanas)[6];
        $st = $this->db->prepare(
            "SELECT e.id, e.codigo, e.nombre, e.tipo, e.fecha, e.hora_inicio, e.hora_fin, e.estado, e.invitados, e.retenida_hasta,
                    c.nombre AS cliente,
                    (SELECT string_agg(es.nombre, ', ' ORDER BY es.orden, es.id) FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = e.id) AS espacios
               FROM eventos e JOIN clientes c ON c.id = e.cliente_id
              WHERE e.negocio_id = ? AND e.fecha BETWEEN ?::date AND ?::date AND e.estado NOT IN ('perdido','cancelado')
                AND (?::int IS NULL OR EXISTS (SELECT 1 FROM evento_espacios ee WHERE ee.evento_id = e.id AND ee.espacio_id = ?::int))
           ORDER BY e.fecha, e.hora_inicio, e.id"
        );
        $st->execute([$negocioId, $desde, $hasta, $espacioId, $espacioId]);
        $eventos = [];
        foreach ($st->fetchAll() as $e) $eventos[$e['fecha']][] = $e;
        $st = $this->db->prepare(
            'SELECT fc.id, fc.fecha, fc.motivo, fc.espacio_id, es.nombre AS espacio
               FROM fechas_cerradas fc LEFT JOIN espacios es ON es.id = fc.espacio_id
              WHERE fc.negocio_id = ? AND fc.fecha BETWEEN ?::date AND ?::date
                AND (?::int IS NULL OR fc.espacio_id IS NULL OR fc.espacio_id = ?::int)
           ORDER BY fc.fecha'
        );
        $st->execute([$negocioId, $desde, $hasta, $espacioId, $espacioId]);
        $cerrados = [];
        foreach ($st->fetchAll() as $c) $cerrados[$c['fecha']][] = $c;
        return ['semanas' => $semanas, 'eventos' => $eventos, 'cerrados' => $cerrados];
    }

    /**
     * Los próximos sábados (desde hoy) con el estado de cada espacio: libre, consulta, retenido, ocupado o cerrado.
     * @return list<array{fecha:string, espacios:array, libres:int}>
     */
    public function proximosSabados(int $negocioId, int $cuantos = 12, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $sab = (int) $hoy->format('N') === 6 ? $hoy : $hoy->modify('next saturday');
        $eventos = new Eventos($this->db);
        $res = [];
        for ($i = 0; $i < max(1, min(52, $cuantos)); $i++) {
            $fecha = $sab->modify("+$i weeks")->format('Y-m-d');
            $esp = $eventos->disponibilidad($negocioId, $fecha, $hoy);
            $res[] = ['fecha' => $fecha, 'espacios' => $esp,
                      'libres' => count(array_filter($esp, fn($x) => $x['estado'] === 'libre')),
                      // libre o solo con consultas (sin cotización vigente ni pago): todavía se puede vender
                      'vendibles' => count(array_filter($esp, fn($x) => in_array($x['estado'], ['libre', 'consulta'], true)))];
        }
        return $res;
    }

    /**
     * Lo que ve un visitante en la página pública: por espacio, libre / consultar / ocupado.
     * Retenido y consulta = "consultar"; días cerrados = "ocupado". No muestra datos de otros clientes.
     * @return list<array{espacio:string, capacidad:?int, estado:string}>
     */
    public function disponibilidadPublica(int $negocioId, string $fecha, ?DateTimeImmutable $hoy = null): array
    {
        $res = [];
        foreach ((new Eventos($this->db))->disponibilidad($negocioId, $fecha, $hoy) as $d) {
            $estado = match ($d['estado']) {
                'libre' => 'libre',
                'consulta', 'retenido' => 'consultar',
                default => 'ocupado',
            };
            $res[] = ['espacio' => $d['espacio'],
                      'capacidad' => $d['capacidad_banquete'] !== null ? (int) $d['capacidad_banquete'] : null,
                      'estado' => $estado];
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Página pública y consultas desde la web
    // ------------------------------------------------------------------

    /** Negocio por su dirección pública (con los datos que se pueden mostrar y su estado de cuenta). */
    public function negocioPorSlug(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug)) return null;
        $st = $this->db->prepare('SELECT id, nombre, slug, telefono, whatsapp, email, direccion, ciudad, mapa_url, instagram, facebook,
                                         descripcion, logo, color, plan_codigo, estado, prueba_hasta, activo_hasta
                                    FROM negocios WHERE slug = ?');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    }

    public function espaciosPublicos(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT id, nombre, descripcion, capacidad_banquete, capacidad_coctel, color, foto
                                    FROM espacios WHERE negocio_id = ? AND activo ORDER BY orden, id');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    /** Cuántas consultas llegaron desde esa IP a ese negocio en la última hora. */
    public function consultasRecientes(int $negocioId, string $ip): int
    {
        $st = $this->db->prepare("SELECT count(*) FROM consultas_web WHERE negocio_id = ? AND ip = ? AND creado_en > now() - interval '1 hour'");
        $st->execute([$negocioId, $ip]);
        return (int) $st->fetchColumn();
    }

    /**
     * Consulta desde la página pública: crea (o reutiliza) el cliente, el evento en "consulta" con origen "página web",
     * una actividad pendiente para responder y un aviso al equipo. Devuelve el id del evento.
     * $d: nombre, telefono, email, tipo, fecha, invitados, espacio_id (opcional), mensaje, sitio_web (campo trampa).
     */
    public function crearConsultaWeb(int $negocioId, array $d, string $ip = '', ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        if (trim((string) ($d['sitio_web'] ?? '')) !== '') throw new RuntimeException('No se pudo enviar la consulta.');
        if ($this->consultasRecientes($negocioId, $ip) >= self::MAX_CONSULTAS_HORA) {
            throw new RuntimeException('Ya enviaste varias consultas. Si necesitas algo más, escríbenos por WhatsApp.');
        }
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $tel = trim((string) ($d['telefono'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Escribe tu nombre.');
        if (WhatsApp::normalizarTelefono($tel) === null) throw new RuntimeException('Escribe tu celular (ej. 0991234567) para poder responderte.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Revisa tu correo.');
        $tipo = (string) ($d['tipo'] ?? '');
        if (!isset(Eventos::TIPOS[$tipo])) throw new RuntimeException('Elige qué vas a celebrar.');
        $fecha = (string) ($d['fecha'] ?? '');
        if (!self::fechaValida($fecha)) throw new RuntimeException('Elige la fecha de tu evento.');
        if ($fecha < $hoy->format('Y-m-d')) throw new RuntimeException('La fecha ya pasó. Elige una fecha desde hoy.');
        if ($fecha > $hoy->modify('+3 years')->format('Y-m-d')) throw new RuntimeException('Elige una fecha dentro de los próximos 3 años.');
        $invitados = (int) ($d['invitados'] ?? 0);
        if ($invitados < 1 || $invitados > 20000) throw new RuntimeException('Escribe cuántos invitados tendrás (aproximado).');
        $mensaje = mb_substr(trim((string) ($d['mensaje'] ?? '')), 0, 1000);

        $espacios = $this->espaciosPublicos($negocioId);
        if (!$espacios) throw new RuntimeException('Este local todavía no tiene espacios publicados. Escríbele por WhatsApp.');
        $preferido = null;
        $espacioId = (int) ($d['espacio_id'] ?? 0);
        foreach ($espacios as $es) {
            if ((int) $es['id'] === $espacioId) $preferido = $es;
        }
        $usado = $preferido ?? $espacios[0];

        $notas = 'Consulta desde la página web.' . "\n" . 'Espacio preferido: ' . ($preferido ? $preferido['nombre'] : 'sin preferencia');
        if ($mensaje !== '') $notas .= "\nMensaje: " . $mensaje;

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            // Si el celular ya es de un cliente, se usa ese cliente sin cambiar sus datos (un visitante no puede editar fichas)
            $clienteId = $this->clientePorTelefono($negocioId, $tel);
            if ($clienteId === null) {
                $st = $this->db->prepare("INSERT INTO clientes (negocio_id, nombre, telefono, email, origen) VALUES (?,?,?,?, 'pagina') RETURNING id");
                $st->execute([$negocioId, mb_substr($nombre, 0, 150), mb_substr($tel, 0, 20), $email ?: null]);
                $clienteId = (int) $st->fetchColumn();
            } else {
                $notas .= "\nNombre que escribió: $nombre" . ($email !== '' ? " · $email" : '');
            }
            $eventos = new Eventos($this->db);
            $evId = $eventos->crear($negocioId, [
                'cliente_id' => $clienteId, 'tipo' => $tipo, 'fecha' => $fecha, 'hora_inicio' => '18:00', 'hora_fin' => '02:00',
                'invitados' => $invitados, 'espacios' => [(int) $usado['id']], 'origen' => 'pagina', 'notas' => $notas,
                'nombre' => Eventos::TIPOS[$tipo] . ' de ' . $nombre,
            ], null, $hoy);
            $this->db->prepare("UPDATE eventos SET origen = 'pagina' WHERE id = ?")->execute([$evId]);
            $eventos->agregarActividad($negocioId, $evId, $clienteId, 'whatsapp',
                'Responder la consulta que llegó por la página web' . ($mensaje !== '' ? ': «' . $mensaje . '»' : '.'), date('Y-m-d H:i:s'), null);
            $this->db->prepare("INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?, 'Cliente', ?)")
                     ->execute([$negocioId, $evId, 'Envió una consulta desde la página web']);
            $this->db->prepare('INSERT INTO consultas_web (negocio_id, evento_id, ip) VALUES (?,?,?)')->execute([$negocioId, $evId, mb_substr($ip, 0, 45)]);
            (new Notificaciones($this->db))->alNegocio($negocioId,
                "Nueva consulta desde tu página: $nombre — " . Eventos::TIPOS[$tipo] . ' para el ' . self::fechaTexto($fecha)
                . ", $invitados invitados. Respóndele pronto por WhatsApp.", $evId);
            if ($propia) $this->db->commit();
            return $evId;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function clientePorTelefono(int $negocioId, string $tel): ?int
    {
        $n = WhatsApp::normalizarTelefono($tel);
        if ($n === null) return null;
        $st = $this->db->prepare('SELECT id, telefono FROM clientes WHERE negocio_id = ? AND telefono IS NOT NULL ORDER BY id');
        $st->execute([$negocioId]);
        foreach ($st->fetchAll() as $c) {
            if (WhatsApp::normalizarTelefono($c['telefono']) === $n) return (int) $c['id'];
        }
        return null;
    }

    /** "sáb 14 nov 2026" (sin depender de las funciones de la página). */
    public static function fechaTexto(string $fecha): string
    {
        $d = new DateTimeImmutable($fecha);
        $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
        $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' ' . $meses[(int) $d->format('n')] . ' ' . $d->format('Y');
    }

    /** Enlace seguro (solo http/https) o null. */
    public static function enlaceSeguro(?string $url): ?string
    {
        $url = trim((string) $url);
        return preg_match('#^https?://[^\s<>"]+$#i', $url) ? $url : null;
    }

    /** Instagram: "@cuenta", "cuenta" o la dirección completa -> dirección completa. */
    public static function enlaceInstagram(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if ($u = self::enlaceSeguro($v)) return $u;
        $cuenta = ltrim($v, '@');
        return preg_match('/^[A-Za-z0-9._]{1,30}$/', $cuenta) ? 'https://instagram.com/' . $cuenta : null;
    }

    public static function enlaceFacebook(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if ($u = self::enlaceSeguro($v)) return $u;
        $cuenta = ltrim($v, '@');
        return preg_match('/^[A-Za-z0-9.\-]{1,80}$/', $cuenta) ? 'https://facebook.com/' . $cuenta : null;
    }

    // ------------------------------------------------------------------
    // Seguimiento comercial
    // ------------------------------------------------------------------

    /** Actividades pendientes hasta dentro de 7 días, separadas en vencidas, hoy y próximos días. */
    public function pendientesAgrupados(int $negocioId, ?int $usuarioId = null, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $hoyTxt = $hoy->format('Y-m-d');
        $lista = (new Eventos($this->db))->pendientes($negocioId, $hoy->modify('+7 days')->format('Y-m-d') . ' 23:59:59', $usuarioId);
        $g = ['vencidas' => [], 'hoy' => [], 'proximas' => []];
        foreach ($lista as $a) {
            $dia = substr((string) $a['programada_para'], 0, 10);
            $g[$dia < $hoyTxt ? 'vencidas' : ($dia === $hoyTxt ? 'hoy' : 'proximas')][] = $a;
        }
        return $g;
    }

    /** Cambia la fecha y hora de una actividad pendiente. */
    public function reagendar(int $negocioId, int $actividadId, string $cuando): void
    {
        $t = strtotime(str_replace('T', ' ', trim($cuando)));
        if ($t === false || trim($cuando) === '') throw new RuntimeException('Elige la nueva fecha y hora.');
        $st = $this->db->prepare('UPDATE actividades SET programada_para = ?, hecha = false WHERE id = ? AND negocio_id = ?');
        $st->execute([date('Y-m-d H:i:s', $t), $actividadId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró la actividad.');
    }

    /**
     * Eventos en consulta (fecha futura) sin contacto en los últimos X días:
     * ni una nota, llamada o mensaje hecho, ni algo agendado a futuro.
     */
    public function consultasSinAtender(int $negocioId, int $dias = 3, ?DateTimeImmutable $hoy = null): array
    {
        $hoyTxt = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $st = $this->db->prepare(
            "SELECT * FROM (
                SELECT e.id, e.codigo, e.nombre, e.tipo, e.fecha, e.invitados, e.origen, e.creado_en,
                       c.id AS cliente_id, c.nombre AS cliente, c.telefono,
                       (SELECT max(a.creado_en) FROM actividades a WHERE a.evento_id = e.id AND a.tipo <> 'sistema' AND a.hecha) AS ultimo_contacto
                  FROM eventos e JOIN clientes c ON c.id = e.cliente_id
                 WHERE e.negocio_id = ? AND e.estado = 'consulta' AND e.fecha >= ?::date
                   AND NOT EXISTS (SELECT 1 FROM actividades a WHERE a.evento_id = e.id AND NOT a.hecha AND a.programada_para >= now())
             ) x
             WHERE COALESCE(x.ultimo_contacto, x.creado_en) < now() - make_interval(days => ?)
             ORDER BY COALESCE(x.ultimo_contacto, x.creado_en)"
        );
        $st->execute([$negocioId, $hoyTxt, max(0, $dias)]);
        return $st->fetchAll();
    }

    /** Cotizaciones enviadas que el cliente todavía no acepta ni rechaza. */
    public function cotizacionesSinRespuesta(int $negocioId): array
    {
        $st = $this->db->prepare(
            "SELECT q.id, q.numero, q.version, q.fecha, q.valida_hasta, q.total, e.id AS evento_id, e.codigo, e.nombre, e.fecha AS evento_fecha,
                    c.nombre AS cliente, c.telefono
               FROM cotizaciones q JOIN eventos e ON e.id = q.evento_id JOIN clientes c ON c.id = e.cliente_id
              WHERE q.negocio_id = ? AND q.estado = 'enviada' AND e.estado IN ('consulta','cotizado')
           ORDER BY q.fecha, q.id"
        );
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    /** Fechas retenidas (eventos cotizados) que vencen en los próximos X días o vencieron hace poco (7 días). */
    public function retencionesPorVencer(int $negocioId, int $dias = 3, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare(
            "SELECT e.id, e.codigo, e.nombre, e.fecha, e.retenida_hasta, c.nombre AS cliente, c.telefono,
                    (SELECT string_agg(es.nombre, ', ' ORDER BY es.orden) FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = e.id) AS espacios
               FROM eventos e JOIN clientes c ON c.id = e.cliente_id
              WHERE e.negocio_id = ? AND e.estado = 'cotizado' AND e.retenida_hasta BETWEEN ?::date AND ?::date
           ORDER BY e.retenida_hasta, e.fecha"
        );
        $st->execute([$negocioId, $hoy->modify('-7 days')->format('Y-m-d'), $hoy->modify("+$dias days")->format('Y-m-d')]);
        return $st->fetchAll();
    }

    /** Visitas al local, degustaciones y reuniones agendadas de hoy a 7 días (hechas o no). */
    public function agendaVisitas(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare(
            "SELECT a.*, e.codigo, e.nombre AS evento, c.nombre AS cliente, c.telefono, u.nombre AS usuario
               FROM actividades a LEFT JOIN eventos e ON e.id = a.evento_id LEFT JOIN clientes c ON c.id = a.cliente_id
               LEFT JOIN usuarios u ON u.id = a.usuario_id
              WHERE a.negocio_id = ? AND a.tipo IN ('visita','degustacion','reunion')
                AND a.programada_para BETWEEN ?::timestamp AND ?::timestamp
           ORDER BY a.programada_para"
        );
        $st->execute([$negocioId, $hoy->format('Y-m-d 00:00:00'), $hoy->modify('+7 days')->format('Y-m-d 23:59:59')]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------
    // Clientes
    // ------------------------------------------------------------------

    /** Edita la ficha del cliente (permite borrar datos, a diferencia de guardarCliente). */
    public function editarCliente(int $negocioId, int $id, array $d): void
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $tel = trim((string) ($d['telefono'] ?? ''));
        $ident = preg_replace('/\D+/', '', (string) ($d['identificacion'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Escribe el nombre del cliente.');
        if ($ident !== '' && !in_array(strlen($ident), [10, 13], true)) throw new RuntimeException('La cédula debe tener 10 dígitos o el RUC 13.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo del cliente no es válido.');
        if ($tel !== '' && WhatsApp::normalizarTelefono($tel) === null) throw new RuntimeException('Revisa el celular (ej. 0991234567).');
        $origen = (string) ($d['origen'] ?? '');
        if ($origen !== '' && !isset(Eventos::ORIGENES[$origen])) $origen = 'otro';
        $st = $this->db->prepare('UPDATE clientes SET nombre = ?, telefono = ?, identificacion = ?, email = ?, direccion = ?, origen = ?, notas = ?
                                   WHERE id = ? AND negocio_id = ?');
        $st->execute([mb_substr($nombre, 0, 150), mb_substr($tel, 0, 20) ?: null, $ident ?: null, $email ?: null,
                      mb_substr(trim((string) ($d['direccion'] ?? '')), 0, 250) ?: null, $origen ?: null,
                      trim((string) ($d['notas'] ?? '')) ?: null, $id, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el cliente.');
    }

    /** Cuántos eventos tiene, cuánto contrató (separados, confirmados y realizados) y cuánto pagó. */
    public function resumenCliente(int $negocioId, int $clienteId): array
    {
        $st = $this->db->prepare(
            "SELECT count(*) AS eventos,
                    count(*) FILTER (WHERE estado IN ('separado','confirmado','realizado')) AS contratados,
                    COALESCE(sum(total) FILTER (WHERE estado IN ('separado','confirmado','realizado')),0) AS contratado,
                    COALESCE(sum((SELECT COALESCE(sum(p.monto),0) FROM pagos p WHERE p.evento_id = e.id AND p.tipo = 'evento' AND p.estado = 'confirmado')),0) AS pagado
               FROM eventos e WHERE e.negocio_id = ? AND e.cliente_id = ?"
        );
        $st->execute([$negocioId, $clienteId]);
        $r = $st->fetch();
        $contratado = round((float) $r['contratado'], 2);
        $pagado = round((float) $r['pagado'], 2);
        return ['eventos' => (int) $r['eventos'], 'contratados' => (int) $r['contratados'], 'contratado' => $contratado,
                'pagado' => $pagado, 'saldo' => round(max(0, $contratado - $pagado), 2)];
    }

    public function actividadesCliente(int $negocioId, int $clienteId): array
    {
        $st = $this->db->prepare('SELECT a.*, e.codigo, e.nombre AS evento, u.nombre AS usuario
                                    FROM actividades a LEFT JOIN eventos e ON e.id = a.evento_id LEFT JOIN usuarios u ON u.id = a.usuario_id
                                   WHERE a.negocio_id = ? AND a.cliente_id = ?
                                ORDER BY COALESCE(a.programada_para, a.creado_en) DESC, a.id DESC LIMIT 200');
        $st->execute([$negocioId, $clienteId]);
        return $st->fetchAll();
    }

    /** Cambia el cliente de un evento (al editar). */
    public function cambiarCliente(int $negocioId, int $eventoId, int $clienteId): void
    {
        $st = $this->db->prepare('UPDATE eventos e SET cliente_id = c.id, actualizado_en = now() FROM clientes c
                                   WHERE e.id = ? AND e.negocio_id = ? AND c.id = ? AND c.negocio_id = e.negocio_id');
        $st->execute([$eventoId, $negocioId, $clienteId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el cliente.');
    }

    // ------------------------------------------------------------------
    // Eventos: formulario, equipo, detalles, valores y tablero
    // ------------------------------------------------------------------

    /** Número escrito con coma o punto ("1.500,50", "1500.5") -> float, o null si está vacío. */
    public static function numeroTexto(mixed $v): ?float
    {
        $v = str_replace([' ', '$'], '', trim((string) $v));
        if ($v === '') return null;
        if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace('.', '', $v);   // 1.234,50
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);       // 12.000 (miles, como se escribe en Ecuador)
        $v = str_replace(',', '.', $v);
        if (!is_numeric($v)) throw new RuntimeException('Revisa el valor: escribe solo el número (ej. 5000).');
        return (float) $v;
    }

    /**
     * Lee el formulario de evento (nuevo o editar) y lo deja listo para Eventos::crear / actualizar.
     * Cliente: $p['modo_cliente'] = 'nuevo' (datos en cliente_*) o 'existente' (cliente_id).
     */
    public static function leerFormularioEvento(array $p): array
    {
        $t = fn(string $k) => trim((string) ($p[$k] ?? ''));
        $pres = self::numeroTexto($p['presupuesto_cliente'] ?? '');
        $d = [
            'tipo' => $t('tipo'), 'nombre' => $t('nombre'), 'fecha' => $t('fecha'),
            'hora_inicio' => $t('hora_inicio') ?: '18:00', 'hora_fin' => $t('hora_fin') ?: '02:00',
            'invitados' => (int) $t('invitados'),
            'espacios' => array_values(array_filter(array_map('intval', (array) ($p['espacios'] ?? [])))),
            'montaje' => $t('montaje'), 'presupuesto_cliente' => $pres === null ? '' : $pres,
            'notas' => $t('notas'), 'origen' => $t('origen'),
            'vendedor_id' => (int) $t('vendedor_id') ?: null,
        ];
        if (($p['modo_cliente'] ?? 'nuevo') === 'existente') {
            $d['cliente_id'] = (int) $t('cliente_id');
            if ($d['cliente_id'] <= 0) throw new RuntimeException('Elige el cliente de la lista, o marca "Cliente nuevo".');
        } else {
            $d['cliente'] = ['nombre' => $t('cliente_nombre'), 'telefono' => $t('cliente_telefono'),
                             'identificacion' => $t('cliente_identificacion'), 'email' => $t('cliente_email'), 'origen' => $t('origen')];
            if (mb_strlen($d['cliente']['nombre']) < 3) throw new RuntimeException('Escribe el nombre del cliente.');
            if ($d['cliente']['telefono'] !== '' && WhatsApp::normalizarTelefono($d['cliente']['telefono']) === null) {
                throw new RuntimeException('Revisa el celular del cliente (ej. 0991234567).');
            }
        }
        return $d;
    }

    /** El vendedor elegido debe ser del negocio (dueño o vendedor activo). */
    public function vendedorValido(int $negocioId, ?int $usuarioId): ?int
    {
        if (!$usuarioId) return null;
        foreach ($this->usuariosVenta($negocioId) as $v) {
            if ((int) $v['id'] === $usuarioId) return $usuarioId;
        }
        throw new RuntimeException('Elige un vendedor de tu equipo.');
    }

    /** Choques que impiden o complican la fecha: los "bloqueado" (separado o día cerrado) y los demás. */
    public static function separarChoques(array $choques): array
    {
        $b = array_values(array_filter($choques, fn($c) => $c['nivel'] === 'bloqueado'));
        $o = array_values(array_filter($choques, fn($c) => $c['nivel'] !== 'bloqueado'));
        return ['bloqueados' => $b, 'otros' => $o];
    }

    /** Explica un choque en una línea clara. */
    public static function textoChoque(array $c): string
    {
        if (($c['estado'] ?? '') === 'cerrado') return $c['espacio'] . ': ' . $c['nombre'] . '.';
        $hora = $c['hora_inicio'] ? ' (' . substr((string) $c['hora_inicio'], 0, 5) . ' a ' . substr((string) $c['hora_fin'], 0, 5) . ')' : '';
        return match ($c['nivel']) {
            'bloqueado' => "{$c['espacio']} ya está separado por {$c['codigo']} · {$c['nombre']}$hora.",
            'retenido'  => "{$c['espacio']} está retenido por {$c['codigo']} · {$c['nombre']}$hora hasta el "
                           . self::fechaTexto((string) $c['retenida_hasta']) . ' (tiene cotización, sin pago).',
            default     => "{$c['espacio']}: también preguntó {$c['codigo']} · {$c['nombre']}$hora (solo consulta).",
        };
    }

    /** Dueño, administradores y vendedores activos (para elegir quién vende). */
    public function usuariosVenta(int $negocioId): array
    {
        $st = $this->db->prepare("SELECT id, nombre, rol FROM usuarios WHERE negocio_id = ? AND activo AND rol IN ('dueno','admin','vendedor') ORDER BY rol = 'dueno' DESC, rol = 'admin' DESC, nombre");
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    /** Usuarios que pueden coordinar un evento (todos menos la cuenta de solo consulta). */
    public function usuariosEquipo(int $negocioId): array
    {
        $st = $this->db->prepare("SELECT id, nombre, rol FROM usuarios WHERE negocio_id = ? AND activo AND rol <> 'consulta' ORDER BY rol = 'coordinador' DESC, nombre");
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function asignarCoordinador(int $negocioId, int $eventoId, ?int $usuarioId): ?string
    {
        $nombre = null;
        if ($usuarioId) {
            $st = $this->db->prepare("SELECT nombre FROM usuarios WHERE id = ? AND negocio_id = ? AND activo AND rol <> 'consulta'");
            $st->execute([$usuarioId, $negocioId]);
            $nombre = $st->fetchColumn();
            if ($nombre === false) throw new RuntimeException('Elige un usuario de tu equipo.');
        }
        $st = $this->db->prepare('UPDATE eventos SET coordinador_id = ?, actualizado_en = now() WHERE id = ? AND negocio_id = ?');
        $st->execute([$usuarioId ?: null, $eventoId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el evento.');
        return $nombre ?: null;
    }

    /** Menú elegido y detalles que pidió el cliente (colores, música, alergias). */
    public function guardarDetalles(int $negocioId, int $eventoId, string $menu, string $detalles): void
    {
        $st = $this->db->prepare('UPDATE eventos SET menu_elegido = ?, detalles_cliente = ?, actualizado_en = now() WHERE id = ? AND negocio_id = ?');
        $st->execute([mb_substr(trim($menu), 0, 5000) ?: null, mb_substr(trim($detalles), 0, 5000) ?: null, $eventoId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el evento.');
    }

    /** Capacidad de los espacios elegidos (de pie si el montaje es cóctel). null si no tienen capacidad escrita. */
    public function capacidad(int $negocioId, array $espacioIds, ?string $montaje = null): ?int
    {
        $ids = array_values(array_filter(array_map('intval', $espacioIds)));
        if (!$ids) return null;
        $col = $montaje === 'coctel' ? 'COALESCE(capacidad_coctel, capacidad_banquete)' : 'capacidad_banquete';
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT sum($col) FROM espacios WHERE negocio_id = ? AND id IN ($marcas)");
        $st->execute([$negocioId, ...$ids]);
        $v = $st->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    /**
     * Valor de cada evento para el tablero: el total vendido; si aún no hay, la última cotización vigente; si no, el presupuesto del cliente.
     * @return array<int, array{valor:float, fuente:?string}>
     */
    public function valoresEstimados(int $negocioId, array $eventoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $eventoIds))));
        if (!$ids) return [];
        $st = $this->db->prepare(
            "SELECT e.id, e.total, e.presupuesto_cliente,
                    (SELECT q.total FROM cotizaciones q WHERE q.evento_id = e.id AND q.estado IN ('borrador','enviada','aceptada')
                      ORDER BY q.version DESC, q.id DESC LIMIT 1) AS cotizacion
               FROM eventos e WHERE e.negocio_id = ? AND e.id = ANY (?::int[])"
        );
        $st->execute([$negocioId, '{' . implode(',', $ids) . '}']);
        $res = [];
        foreach ($st->fetchAll() as $r) {
            if ((float) $r['total'] > 0) $res[(int) $r['id']] = ['valor' => (float) $r['total'], 'fuente' => 'total'];
            elseif ($r['cotizacion'] !== null && (float) $r['cotizacion'] > 0) $res[(int) $r['id']] = ['valor' => (float) $r['cotizacion'], 'fuente' => 'cotizacion'];
            elseif ($r['presupuesto_cliente'] !== null && (float) $r['presupuesto_cliente'] > 0) $res[(int) $r['id']] = ['valor' => (float) $r['presupuesto_cliente'], 'fuente' => 'presupuesto'];
            else $res[(int) $r['id']] = ['valor' => 0.0, 'fuente' => null];
        }
        return $res;
    }

    /**
     * Tablero de ventas: eventos activos por estado (consulta → cotizado → separado → confirmado), con su valor.
     * $f: los mismos filtros de Eventos::lista (menos estado).
     */
    public function tablero(int $negocioId, array $f = []): array
    {
        unset($f['estado']);
        $lista = (new Eventos($this->db))->lista($negocioId, ['estado' => 'activos'] + $f);
        $valores = $this->valoresEstimados($negocioId, array_column($lista, 'id'));
        $res = [];
        foreach (self::TABLERO as $e) $res[$e] = ['eventos' => [], 'cantidad' => 0, 'valor' => 0.0];
        foreach ($lista as $ev) {
            $v = $valores[(int) $ev['id']] ?? ['valor' => 0.0, 'fuente' => null];
            $ev['valor'] = $v['valor'];
            $ev['fuente_valor'] = $v['fuente'];
            $res[$ev['estado']]['eventos'][] = $ev;
            $res[$ev['estado']]['cantidad']++;
            $res[$ev['estado']]['valor'] += $v['valor'];
        }
        return $res;
    }

    /** Totales de una lista de eventos (de Eventos::lista): cuántos, total vendido, pagado y saldo. */
    public static function totales(array $eventos): array
    {
        $total = 0.0;
        $pagado = 0.0;
        foreach ($eventos as $e) {
            $total += (float) $e['total'];
            $pagado += (float) $e['pagado'];
        }
        return ['cantidad' => count($eventos), 'total' => round($total, 2), 'pagado' => round($pagado, 2),
                'saldo' => round(max(0, $total - $pagado), 2)];
    }

    // ------------------------------------------------------------------
    // Espacios y fechas cerradas
    // ------------------------------------------------------------------

    public function espacios(int $negocioId, bool $incluirInactivos = true): array
    {
        $st = $this->db->prepare(
            "SELECT es.*, (SELECT count(*) FROM evento_espacios ee JOIN eventos e ON e.id = ee.evento_id
                            WHERE ee.espacio_id = es.id AND e.fecha >= CURRENT_DATE AND e.estado IN ('consulta','cotizado','separado','confirmado')) AS eventos_futuros
               FROM espacios es WHERE es.negocio_id = ? " . ($incluirInactivos ? '' : 'AND es.activo ') . 'ORDER BY es.activo DESC, es.orden, es.id'
        );
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function espacio(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM espacios WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        return $st->fetch() ?: null;
    }

    /** Máximo de espacios activos del plan (null = sin límite). */
    public function maxEspacios(int $negocioId): ?int
    {
        $st = $this->db->prepare('SELECT p.max_espacios FROM negocios n JOIN planes p ON p.codigo = n.plan_codigo WHERE n.id = ?');
        $st->execute([$negocioId]);
        $v = $st->fetchColumn();
        return $v === null || $v === false ? null : (int) $v;
    }

    private function mensajeLimite(int $negocioId): string
    {
        $max = $this->maxEspacios($negocioId);
        return "Tu plan permite hasta $max " . ($max === 1 ? 'espacio activo' : 'espacios activos')
             . '. Desactiva uno o cambia de plan en Mi plan (el plan Completo no tiene límite de espacios).';
    }

    /** Crea o edita un espacio. $d: nombre, descripcion, capacidad_banquete, capacidad_coctel, precio_base, color, orden. */
    public function guardarEspacio(int $negocioId, array $d, ?int $id = null): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre del espacio (ej. Salón principal, Jardín).');
        $num = static function (mixed $v, string $error, int $max): ?int {
            $v = trim((string) $v);
            if ($v === '') return null;
            if (!ctype_digit($v) || (int) $v > $max) throw new RuntimeException($error);
            return (int) $v;
        };
        $banquete = $num($d['capacidad_banquete'] ?? '', 'Revisa la capacidad sentados (solo números).', 20000);
        $coctel = $num($d['capacidad_coctel'] ?? '', 'Revisa la capacidad de pie (solo números).', 20000);
        $orden = $num($d['orden'] ?? '', 'El orden debe ser un número.', 999) ?? 0;
        // (201) Montaje y desmontaje propios del equipo; vacío = las horas de Reglas
        $montajeMin = $num($d['montaje_min'] ?? '', 'Revisa los minutos de montaje (solo números, hasta 1440).', 1440);
        $desmontajeMin = $num($d['desmontaje_min'] ?? '', 'Revisa los minutos de desmontaje (solo números, hasta 1440).', 1440);
        try {
            $precio = round(self::numeroTexto($d['precio_base'] ?? '') ?? 0.0, 2);
        } catch (RuntimeException) {
            throw new RuntimeException('Revisa el precio base (solo números).');
        }
        if ($precio < 0 || $precio > 1000000) throw new RuntimeException('Revisa el precio base.');
        $color = (string) ($d['color'] ?? '#6B2D3C');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) $color = '#6B2D3C';
        $desc = mb_substr(trim((string) ($d['descripcion'] ?? '')), 0, 500) ?: null;

        $st = $this->db->prepare('SELECT 1 FROM espacios WHERE negocio_id = ? AND lower(nombre) = lower(?) AND (?::int IS NULL OR id <> ?::int)');
        $st->execute([$negocioId, $nombre, $id, $id]);
        if ($st->fetchColumn()) throw new RuntimeException("Ya tienes un espacio llamado \"$nombre\".");

        if ($id === null) {
            if (!(new Planes($this->db))->puedeAgregarEspacio($negocioId)) throw new RuntimeException($this->mensajeLimite($negocioId));
            $st = $this->db->prepare('INSERT INTO espacios (negocio_id, nombre, descripcion, capacidad_banquete, capacidad_coctel, precio_base, color, orden)
                                      VALUES (?,?,?,?,?,?,?,?) RETURNING id');
            $st->execute([$negocioId, mb_substr($nombre, 0, 80), $desc, $banquete, $coctel, $precio, $color, $orden]);
            $nuevo = (int) $st->fetchColumn();
            $this->db->prepare('UPDATE espacios SET montaje_min = ?, desmontaje_min = ? WHERE id = ?')->execute([$montajeMin, $desmontajeMin, $nuevo]);
            return $nuevo;
        }
        $st = $this->db->prepare('SELECT montaje_min, desmontaje_min FROM espacios WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        $antes = $st->fetch();
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('UPDATE espacios SET nombre = ?, descripcion = ?, capacidad_banquete = ?, capacidad_coctel = ?, precio_base = ?, color = ?, orden = ?,
                                             montaje_min = ?, desmontaje_min = ?
                                       WHERE id = ? AND negocio_id = ?');
            $st->execute([mb_substr($nombre, 0, 80), $desc, $banquete, $coctel, $precio, $color, $orden, $montajeMin, $desmontajeMin, $id, $negocioId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el espacio.');
            $cambio = $antes && ((string) $antes['montaje_min'] !== (string) $montajeMin || (string) $antes['desmontaje_min'] !== (string) $desmontajeMin);
            if ($cambio) $this->recalcularPeriodos($negocioId, $id);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    /**
     * (201) Cambió el montaje o desmontaje de un equipo: los eventos de hoy en adelante con ese equipo vuelven a calcular
     * cuánto lo ocupan. Si con los minutos nuevos dos eventos separados se cruzan, no se guarda y se dice cuál.
     */
    private function recalcularPeriodos(int $negocioId, int $espacioId): void
    {
        $eventos = new Eventos($this->db);
        $st = $this->db->prepare("SELECT e.id, e.codigo, e.fecha, e.hora_inicio, e.hora_fin, e.viaje_min FROM evento_espacios ee JOIN eventos e ON e.id = ee.evento_id
                                   WHERE ee.negocio_id = ? AND ee.espacio_id = ? AND e.fecha >= CURRENT_DATE - 1
                                     AND e.estado NOT IN ('perdido','cancelado') ORDER BY e.fecha, e.hora_inicio");
        $st->execute([$negocioId, $espacioId]);
        $up = $this->db->prepare('UPDATE evento_espacios SET periodo = tsrange(?::timestamp, ?::timestamp) WHERE evento_id = ? AND espacio_id = ?');
        foreach ($st->fetchAll() as $ev) {
            [$desde, $hasta] = $eventos->periodo($negocioId, $ev['fecha'], (string) $ev['hora_inicio'], (string) $ev['hora_fin'], (int) $ev['viaje_min'], $espacioId);
            $this->db->exec('SAVEPOINT recalcular');
            try {
                $up->execute([$desde, $hasta, $ev['id'], $espacioId]);
                $this->db->exec('RELEASE SAVEPOINT recalcular');
            } catch (\PDOException $e) {
                $this->db->exec('ROLLBACK TO SAVEPOINT recalcular');
                if ($e->getCode() === '23P01') {
                    throw new RuntimeException("Con esos minutos de montaje y desmontaje, el evento {$ev['codigo']} del " . self::fechaTexto($ev['fecha'])
                        . ' se cruzaría con otro evento ya separado en este equipo. Baja los minutos o mueve uno de los dos eventos.');
                }
                throw $e;
            }
        }
    }

    /** Pone la foto (nombre de archivo ya guardado con Fotos::guardarImagen). Devuelve la foto anterior para borrarla. */
    public function ponerFotoEspacio(int $negocioId, int $id, ?string $archivo): ?string
    {
        $esp = $this->espacio($negocioId, $id);
        if (!$esp) throw new RuntimeException('No se encontró el espacio.');
        if ($archivo !== null && !Fotos::nombreValido($archivo)) throw new RuntimeException('La foto no es válida.');
        $this->db->prepare('UPDATE espacios SET foto = ? WHERE id = ? AND negocio_id = ?')->execute([$archivo, $id, $negocioId]);
        return $esp['foto'] ?: null;
    }

    /** Activa o desactiva un espacio. No se desactiva si tiene eventos separados o confirmados a futuro. */
    public function activarEspacio(int $negocioId, int $id, bool $activo): void
    {
        $esp = $this->espacio($negocioId, $id);
        if (!$esp) throw new RuntimeException('No se encontró el espacio.');
        if ((bool) $esp['activo'] === $activo) return;
        if ($activo) {
            if (!(new Planes($this->db))->puedeAgregarEspacio($negocioId)) throw new RuntimeException($this->mensajeLimite($negocioId));
        } else {
            $st = $this->db->prepare("SELECT count(*) FROM evento_espacios ee JOIN eventos e ON e.id = ee.evento_id
                                       WHERE ee.espacio_id = ? AND e.fecha >= CURRENT_DATE AND e.estado IN ('separado','confirmado')");
            $st->execute([$id]);
            $n = (int) $st->fetchColumn();
            if ($n > 0) {
                throw new RuntimeException("{$esp['nombre']} tiene $n " . ($n === 1 ? 'evento separado' : 'eventos separados')
                    . ' a futuro. Cámbialos de espacio antes de desactivarlo.');
            }
            $st = $this->db->prepare('SELECT count(*) FROM espacios WHERE negocio_id = ? AND activo');
            $st->execute([$negocioId]);
            if ((int) $st->fetchColumn() <= 1) throw new RuntimeException('Debes tener al menos un espacio activo.');
        }
        $this->db->prepare('UPDATE espacios SET activo = ? WHERE id = ? AND negocio_id = ?')->execute([$activo ? 'true' : 'false', $id, $negocioId]);
    }

    /** Días cerrados desde una fecha (por defecto hoy). */
    public function fechasCerradas(int $negocioId, ?string $desde = null): array
    {
        $st = $this->db->prepare('SELECT fc.*, es.nombre AS espacio FROM fechas_cerradas fc LEFT JOIN espacios es ON es.id = fc.espacio_id
                                   WHERE fc.negocio_id = ? AND fc.fecha >= ?::date ORDER BY fc.fecha, es.orden NULLS FIRST');
        $st->execute([$negocioId, $desde ?? date('Y-m-d')]);
        return $st->fetchAll();
    }

    /**
     * Cierra un día (todo el local si $espacioId es null). Devuelve los eventos activos de ese día que quedan afectados,
     * para avisar (no se borran).
     */
    public function agregarFechaCerrada(int $negocioId, string $fecha, ?int $espacioId, string $motivo = '', ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        if (!self::fechaValida($fecha)) throw new RuntimeException('Elige la fecha que vas a cerrar.');
        if ($fecha < $hoy->format('Y-m-d')) throw new RuntimeException('Esa fecha ya pasó.');
        if ($espacioId) {
            $esp = $this->espacio($negocioId, $espacioId);
            if (!$esp) throw new RuntimeException('No se encontró el espacio.');
        }
        $st = $this->db->prepare('SELECT 1 FROM fechas_cerradas WHERE negocio_id = ? AND fecha = ?::date AND espacio_id IS NOT DISTINCT FROM ?::int');
        $st->execute([$negocioId, $fecha, $espacioId ?: null]);
        if ($st->fetchColumn()) throw new RuntimeException('Esa fecha ya estaba cerrada.');
        $this->db->prepare('INSERT INTO fechas_cerradas (negocio_id, espacio_id, fecha, motivo) VALUES (?,?,?,?)')
                 ->execute([$negocioId, $espacioId ?: null, $fecha, mb_substr(trim($motivo), 0, 150) ?: null]);
        $st = $this->db->prepare(
            "SELECT DISTINCT e.id, e.codigo, e.nombre, e.estado FROM eventos e JOIN evento_espacios ee ON ee.evento_id = e.id
              WHERE e.negocio_id = ? AND e.fecha = ?::date AND e.estado IN ('consulta','cotizado','separado','confirmado')
                AND (?::int IS NULL OR ee.espacio_id = ?::int) ORDER BY e.codigo"
        );
        $st->execute([$negocioId, $fecha, $espacioId ?: null, $espacioId ?: null]);
        return $st->fetchAll();
    }

    public function borrarFechaCerrada(int $negocioId, int $id): void
    {
        $st = $this->db->prepare('DELETE FROM fechas_cerradas WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró esa fecha cerrada.');
    }
}
