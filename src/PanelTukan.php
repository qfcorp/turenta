<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Panel del super administrador (Tukán): todos los negocios que usan TuRenta, sus pagos y los números del negocio.
 */
final class PanelTukan
{
    public const ESTADOS = [
        'prueba' => 'En demo', 'prueba_vencida' => 'Demo terminada', 'activo' => 'Al día',
        'por_vencer' => 'Venció (en gracia)', 'vencido' => 'Vencido', 'suspendido' => 'Suspendido', 'cancelado' => 'Cancelado',
    ];
    public const METODOS = ['transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'payphone' => 'Payphone', 'plux' => 'Plux',
                            'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'cortesia' => 'Cortesía (sin costo)'];

    private Planes $planes;

    public function __construct(private PDO $db)
    {
        $this->planes = new Planes($db);
    }

    private const SQL_NEGOCIOS = "
        SELECT n.id, n.nombre, n.slug, n.telefono, n.whatsapp, n.email, n.ciudad, n.plan_codigo, n.estado, n.prueba_hasta, n.activo_hasta,
               n.notas_admin, n.creado_en, n.logo, n.origen, pl.nombre AS plan_nombre,
               n.pausa_tipo, n.pausa_motivo, n.pausa_en, n.eliminado_en, n.eliminado_motivo, n.eliminado_por, n.eliminado_copia,
               u.nombre AS dueno, u.email AS dueno_email, u.telefono AS dueno_telefono, u.id AS dueno_usuario_id, u.ultimo_ingreso AS dueno_ingreso,
               (SELECT count(*) FROM espacios es WHERE es.negocio_id = n.id AND es.activo) AS espacios,
               (SELECT count(*) FROM usuarios us WHERE us.negocio_id = n.id AND us.activo) AS usuarios,
               (SELECT count(*) FROM eventos e WHERE e.negocio_id = n.id AND e.creado_en >= now() - interval '30 days') AS eventos_30,
               (SELECT count(*) FROM eventos e WHERE e.negocio_id = n.id) AS eventos_total,
               (SELECT count(*) FROM eventos e WHERE e.negocio_id = n.id AND e.estado IN ('separado','confirmado','realizado')) AS vendidos,
               GREATEST((SELECT max(us.ultimo_ingreso) FROM usuarios us WHERE us.negocio_id = n.id),
                        (SELECT max(e.actualizado_en) FROM eventos e WHERE e.negocio_id = n.id),
                        (SELECT max(p.creado_en) FROM pagos p WHERE p.negocio_id = n.id)) AS ultima_actividad
          FROM negocios n
          JOIN planes pl ON pl.codigo = n.plan_codigo
     LEFT JOIN LATERAL (SELECT id, nombre, email, telefono, ultimo_ingreso FROM usuarios WHERE negocio_id = n.id AND rol = 'dueno' ORDER BY id LIMIT 1) u ON true";

    /** Pagos que cuentan en las cifras: de negocios que no están en la papelera, o hechos hasta el día en que se mandaron ahí. */
    public const SQL_PAGO_CUENTA = 'NOT EXISTS (SELECT 1 FROM negocios nx WHERE nx.id = suscripciones.negocio_id AND nx.eliminado_en IS NOT NULL
                                                AND suscripciones.pagado_en > nx.eliminado_en::date)';
    /** Pagos de negocios que siguen vivos (para el ingreso mensual de hoy). */
    public const SQL_PAGO_VIVO = 'NOT EXISTS (SELECT 1 FROM negocios nx WHERE nx.id = suscripciones.negocio_id AND nx.eliminado_en IS NOT NULL)';

    /**
     * Lista de negocios con su estado real. $filtro = '' | una clave de ESTADOS. $plan = '' | código.
     * Los que están en la papelera no salen; con $papelera = true salen solo ellos.
     */
    public function negocios(string $filtro = '', string $buscar = '', ?DateTimeImmutable $hoy = null, string $plan = '', bool $papelera = false): array
    {
        $sql = self::SQL_NEGOCIOS . ' WHERE n.eliminado_en IS ' . ($papelera ? 'NOT NULL' : 'NULL');
        $params = [];
        if (($buscar = trim($buscar)) !== '') {
            $sql .= ' AND (n.nombre ILIKE ? OR u.nombre ILIKE ? OR u.email ILIKE ? OR n.telefono ILIKE ? OR n.slug ILIKE ? OR n.ciudad ILIKE ? OR n.ruc ILIKE ?)';
            $params = array_fill(0, 7, '%' . $buscar . '%');
        }
        if ($plan !== '') {
            $sql .= ' AND n.plan_codigo = ?';
            $params[] = $plan;
        }
        $st = $this->db->prepare($sql . ' ORDER BY n.creado_en DESC, n.id DESC');
        $st->execute($params);
        $lista = [];
        foreach ($st->fetchAll() as $n) {
            $n['estado_cuenta'] = $this->planes->estadoCuenta($n, $hoy);
            if ($filtro !== '' && $n['estado_cuenta'] !== $filtro) continue;
            $lista[] = $n;
        }
        return $lista;
    }

    public function negocio(int $id, ?DateTimeImmutable $hoy = null): ?array
    {
        $st = $this->db->prepare(self::SQL_NEGOCIOS . ' WHERE n.id = ?');
        $st->execute([$id]);
        $n = $st->fetch();
        if (!$n) return null;
        $n['estado_cuenta'] = $this->planes->estadoCuenta($n, $hoy);
        return $n;
    }

    public function pagos(?int $negocioId = null, int $limite = 100): array
    {
        $sql = 'SELECT su.*, n.nombre AS negocio, pl.nombre AS plan_nombre FROM suscripciones su
                  JOIN negocios n ON n.id = su.negocio_id JOIN planes pl ON pl.codigo = su.plan_codigo';
        $params = [];
        if ($negocioId) { $sql .= ' WHERE su.negocio_id = ?'; $params[] = $negocioId; }
        $st = $this->db->prepare($sql . ' ORDER BY su.pagado_en DESC, su.id DESC LIMIT ' . (int) $limite);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function registro(?int $negocioId = null, int $limite = 30): array
    {
        $sql = 'SELECT r.*, a.nombre AS admin, n.nombre AS negocio FROM registro_admin r
             LEFT JOIN superadmins a ON a.id = r.admin_id LEFT JOIN negocios n ON n.id = r.negocio_id';
        $params = [];
        if ($negocioId) { $sql .= ' WHERE r.negocio_id = ?'; $params[] = $negocioId; }
        $st = $this->db->prepare($sql . ' ORDER BY r.creado_en DESC, r.id DESC LIMIT ' . (int) $limite);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Números de TuRenta para la portada del panel. */
    public function resumen(?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $todos = $this->negocios('', '', $hoy);
        $cuenta = array_fill_keys(array_keys(self::ESTADOS), 0);
        $porPlan = [];
        foreach ($todos as $n) {
            $cuenta[$n['estado_cuenta']]++;
            $porPlan[$n['plan_nombre']] = ($porPlan[$n['plan_nombre']] ?? 0) + 1;
        }
        $cobradoMes = $this->cobradoMes($hoy->format('Y-m'));
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) FROM suscripciones WHERE NOT anulada AND " . self::SQL_PAGO_CUENTA . " AND to_char(pagado_en,'YYYY') = ?");
        $st->execute([$hoy->format('Y')]);
        $cobradoAnio = (float) $st->fetchColumn();
        $mensual = $this->ingresoMensual($hoy);

        $en3 = $hoy->modify('+3 days')->format('Y-m-d');
        $en15 = $hoy->modify('+15 days')->format('Y-m-d');
        $hace7 = $hoy->modify('-7 days')->format('Y-m-d');
        return [
            'total' => count($todos), 'cuenta' => $cuenta, 'por_plan' => $porPlan,
            'cobrado_mes' => round($cobradoMes, 2), 'cobrado_anio' => round($cobradoAnio, 2), 'mensual' => $mensual,
            'pruebas_por_terminar' => array_values(array_filter($todos, fn($n) => $n['estado_cuenta'] === 'prueba' && $n['prueba_hasta'] <= $en3)),
            'por_vencer' => array_values(array_filter($todos, fn($n) => in_array($n['estado_cuenta'], ['activo', 'por_vencer'], true)
                && $n['activo_hasta'] && $n['activo_hasta'] <= $en15)),
            'sin_pagar' => array_slice(array_values(array_filter($todos, fn($n) => in_array($n['estado_cuenta'], ['prueba_vencida', 'vencido'], true))), 0, 20),
            'nuevos' => array_slice($todos, 0, 8),
            'nuevos_semana' => count(array_filter($todos, fn($n) => substr((string) $n['creado_en'], 0, 10) >= $hace7)),
            'eventos_30' => array_sum(array_map(fn($n) => (int) $n['eventos_30'], $todos)),
        ];
    }

    /** Lo cobrado en un mes ('YYYY-MM'): pagos de suscripción no anulados. Lo usan el resumen y el Panel Tukán Central. */
    public function cobradoMes(string $mes): float
    {
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) FROM suscripciones WHERE NOT anulada AND " . self::SQL_PAGO_CUENTA . " AND to_char(pagado_en,'YYYY-MM') = ?");
        $st->execute([$mes]);
        return round((float) $st->fetchColumn(), 2);
    }

    /**
     * Ingreso mensual recurrente (Anexo B): lo que paga al mes cada negocio con un pago vigente (no de cortesía),
     * según su plan y sus módulos (plan + extra − quitados, o su precio acordado).
     */
    public function ingresoMensual(DateTimeImmutable $hoy): float
    {
        $st = $this->db->prepare('SELECT DISTINCT negocio_id FROM suscripciones WHERE NOT anulada AND monto > 0 AND ' . self::SQL_PAGO_VIVO . ' AND inicio <= ? AND fin >= ?');
        $st->execute([$hoy->format('Y-m-d'), $hoy->format('Y-m-d')]);
        $mods = new Modulos($this->db);
        $total = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $nid) $total += $mods->mensual((int) $nid);
        return round($total, 2);
    }

    // ------------------------------------------------------------------
    // Acciones sobre un negocio
    // ------------------------------------------------------------------

    public function cambiarPlan(int $negocioId, string $plan): void
    {
        $st = $this->db->prepare('UPDATE negocios SET plan_codigo = ? WHERE id = ? AND EXISTS (SELECT 1 FROM planes WHERE codigo = ?)');
        $st->execute([$plan, $negocioId, $plan]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Plan no válido.');
    }

    public function cambiarEstado(int $negocioId, string $estado): void
    {
        if (!in_array($estado, ['prueba', 'activo', 'suspendido', 'cancelado'], true)) throw new RuntimeException('Estado no válido.');
        // Al salir de "suspendido" se borra el motivo de la pausa (Papelera::pausar lo pone)
        $st = $this->db->prepare("UPDATE negocios SET estado = ?,
                                         pausa_tipo = CASE WHEN ? = 'suspendido' THEN pausa_tipo END,
                                         pausa_motivo = CASE WHEN ? = 'suspendido' THEN pausa_motivo END,
                                         pausa_en = CASE WHEN ? = 'suspendido' THEN COALESCE(pausa_en, now()) END
                                   WHERE id = ?");
        $st->execute([$estado, $estado, $estado, $estado, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Negocio no encontrado.');
    }

    /** Alarga la demo N días desde hoy (o desde su fin si aún no termina). */
    public function extenderPrueba(int $negocioId, int $dias, ?DateTimeImmutable $hoy = null): string
    {
        if ($dias < 1 || $dias > 90) throw new RuntimeException('Elige de 1 a 90 días.');
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $st = $this->db->prepare("UPDATE negocios SET estado = 'prueba',
                                         prueba_hasta = GREATEST(COALESCE(prueba_hasta, ?::date), ?::date) + ?::int
                                   WHERE id = ? RETURNING prueba_hasta");
        $st->execute([$hoy, $hoy, $dias, $negocioId]);
        $r = $st->fetchColumn();
        if ($r === false) throw new RuntimeException('Negocio no encontrado.');
        return (string) $r;
    }

    public function guardarNotas(int $negocioId, string $notas): void
    {
        $this->db->prepare('UPDATE negocios SET notas_admin = ? WHERE id = ?')->execute([mb_substr(trim($notas), 0, 4000) ?: null, $negocioId]);
    }

    /** Nueva contraseña temporal para el dueño. Se muestra UNA vez al administrador. */
    public function nuevaClaveDueno(int $negocioId): array
    {
        $st = $this->db->prepare("SELECT id, email, nombre FROM usuarios WHERE negocio_id = ? AND rol = 'dueno' ORDER BY id LIMIT 1");
        $st->execute([$negocioId]);
        $u = $st->fetch();
        if (!$u) throw new RuntimeException('Este negocio no tiene dueño con acceso.');
        $clave = Usuarios::claveTemporal();
        $this->db->prepare('UPDATE usuarios SET password_hash = ?, activo = true, clave_temporal = true WHERE id = ?')
                 ->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
        return ['email' => $u['email'], 'nombre' => $u['nombre'], 'clave' => $clave];
    }

    /** Registra un pago de suscripción (valida todo lo que llega del formulario). 'fecha' (opcional): día en que pagó. */
    public function registrarPago(int $negocioId, array $d): array
    {
        $plan = (string) ($d['plan'] ?? '');
        $periodo = (string) ($d['periodo'] ?? '');
        $inicio = (string) ($d['inicio'] ?? '');
        $fi = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        if (!$fi || $fi->format('Y-m-d') !== $inicio) throw new RuntimeException('Elige la fecha de inicio.');
        $metodo = (string) ($d['metodo'] ?? '');
        if (!isset(self::METODOS[$metodo])) throw new RuntimeException('Elige cómo pagó.');
        $montoTxt = trim((string) ($d['monto'] ?? ''));
        if ($montoTxt !== '' && !is_numeric(str_replace(',', '.', $montoTxt))) throw new RuntimeException('Escribe el monto con números, por ejemplo 39,00.');
        $monto = $montoTxt === '' ? ($metodo === 'cortesia' ? 0.0 : null) : (float) str_replace(',', '.', $montoTxt);
        $dominio = strtolower(trim((string) ($d['dominio'] ?? ''))) ?: null;
        if ($dominio !== null && !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $dominio)) throw new RuntimeException('El dominio no es válido (ej. quintalosarrayanes.com).');
        $pagadoEn = null;
        if (($fecha = (string) ($d['fecha'] ?? '')) !== '') {
            $pagadoEn = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$pagadoEn || $pagadoEn->format('Y-m-d') !== $fecha) throw new RuntimeException('La fecha del pago no es válida.');
        }
        if (!$this->negocio($negocioId)) throw new RuntimeException('Negocio no encontrado.');
        $id = $this->planes->suscribir($negocioId, $plan, $periodo, $fi, $metodo, $dominio, $monto, $pagadoEn);
        $c = $this->planes->cotizarNegocio($negocioId, $plan, $periodo);
        return ['id' => $id, 'monto' => $monto ?? $c['monto'], 'plan' => $plan, 'periodo' => $periodo, 'metodo' => $metodo];
    }
}
