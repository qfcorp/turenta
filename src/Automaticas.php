<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Tareas automáticas de TuRenta (las corre bin/tareas.php cada hora). Cada aviso sale UNA sola vez,
 * aunque el cron corra muchas veces: se marca en la base con un UPDATE que solo gana una vez.
 *  1) Cotizaciones enviadas que pasaron su validez → vencidas
 *  2) Fechas retenidas (cotizado sin pago) que vencieron → "la fecha se liberó"
 *  3) Cuotas vencidas o que vencen en 3 días → un aviso diario al negocio con la lista
 *  4) Revisar precio: eventos vendidos con mucha anticipación a los que ya les faltan X meses
 *  5) Eventos en 7 días → aviso con las tareas pendientes
 *  6) Eventos de ayer o antes → pasan solos a "realizado"; aviso de devolver la garantía
 *  7) Reporte del mes al dueño, el día 1 desde las 7:00
 *  8) 8:00: a cada vendedor/dueño con Telegram, su lista de seguimientos del día
 *  9) Proveedores (AvisosProveedores): pagos que vencen o vencieron, documentos por vencer, proveedores sin confirmar a 3 días
 * Anexo B: cada tarea de un módulo solo corre si el negocio lo tiene encendido (8 = Telegram, 9 = Proveedores y compras).
 * Lo que sale por Telegram, además, lo filtra Telegram::textoAUsuarios.
 */
final class Automaticas
{
    public const HORA_REPORTE = 7;
    public const HORA_AVISOS = 8;      // cuotas, semana, precio y seguimientos: desde esta hora
    public const DIAS_CUOTAS = 3;
    public const DIAS_SEMANA = 7;
    public const SISTEMA = ['id' => null, 'nombre' => 'Sistema'];

    private Notificaciones $avisos;

    public function __construct(private PDO $db)
    {
        $this->avisos = new Notificaciones($db);
    }

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    private static function fecha(string $f): string
    {
        $d = new DateTimeImmutable($f);
        $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
        return $dias[(int) $d->format('w')] . ' ' . $d->format('d/m/Y');
    }

    private static function dinero(float|string $v): string
    {
        return '$' . number_format((float) $v, 2, ',', '.');
    }

    /**
     * Aviso corto en la campana y, por Telegram, el texto largo (si lo hay).
     * Devuelve a cuántos usuarios llegó.
     */
    private function avisar(int $negocioId, array $roles, string $corto, ?string $largo = null, ?int $eventoId = null, array $extra = []): int
    {
        $ids = $this->avisos->destinos($negocioId, $roles);
        if (!$ids) $ids = $this->avisos->destinos($negocioId, ['dueno']);
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', array_filter($extra)))));
        if (!$ids) return 0;
        $this->avisos->aUsuarios($negocioId, $ids, $corto, $eventoId, false);
        (new Telegram($this->db))->textoAUsuarios($ids, $largo ?? $corto);
        return count($ids);
    }

    // ------------------------------------------------------------------
    // 2) Fechas retenidas que vencieron
    // ------------------------------------------------------------------

    public function liberarRetenidas(int $negocioId, DateTimeImmutable $hoy): int
    {
        $st = $this->q("UPDATE eventos e SET retencion_avisada = e.retenida_hasta
                          FROM clientes c
                         WHERE c.id = e.cliente_id AND e.negocio_id = ? AND e.estado = 'cotizado'
                           AND e.retenida_hasta < ?::date AND e.retencion_avisada IS DISTINCT FROM e.retenida_hasta
                     RETURNING e.id, e.codigo, e.nombre, e.fecha, e.vendedor_id, c.nombre AS cliente",
                       [$negocioId, $hoy->format('Y-m-d')]);
        $n = 0;
        foreach ($st->fetchAll() as $e) {
            $this->avisar($negocioId, ['dueno'], 'La fecha que estaba retenida para ' . $e['nombre'] . ' (' . self::fecha($e['fecha'])
                . ') se liberó: pasaron los días de retención sin que paguen la reserva. Llama a ' . $e['cliente']
                . ' y, si sigue interesado, vuelve a retenerla desde el evento.', null, (int) $e['id'], [$e['vendedor_id']]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // 3) Cuotas por cobrar: un aviso diario con la lista
    // ------------------------------------------------------------------

    public function avisarCuotas(int $negocioId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA_AVISOS) return 0;
        $hoy = $ahora->setTime(0, 0);
        $cuotas = (new Pagos($this->db))->porCobrar($negocioId, self::DIAS_CUOTAS, $hoy);
        if (!$cuotas) return 0;
        $st = $this->q('UPDATE negocios SET aviso_cuotas_dia = ? WHERE id = ? AND aviso_cuotas_dia IS DISTINCT FROM ?',
                       [$hoy->format('Y-m-d'), $negocioId, $hoy->format('Y-m-d')]);
        if ($st->rowCount() !== 1) return 0;   // ya se avisó hoy
        $vencidas = count(array_filter($cuotas, fn($c) => $c['vencida']));
        $total = array_sum(array_map(fn($c) => (float) $c['saldo'], $cuotas));
        $corto = '💰 Cobros: ' . count($cuotas) . (count($cuotas) === 1 ? ' cuota' : ' cuotas') . ' por ' . self::dinero($total)
               . ($vencidas ? " ($vencidas " . ($vencidas === 1 ? 'vencida' : 'vencidas') . ')' : '')
               . ' vencen hasta el ' . $hoy->modify('+' . self::DIAS_CUOTAS . ' days')->format('d/m')
               . '. Mándales el recordatorio desde "Mensajes por WhatsApp".';
        $largo = $corto . "\n";
        foreach (array_slice($cuotas, 0, 25) as $c) {
            $cuando = $c['vencida'] ? 'venció el ' . (new DateTimeImmutable($c['vence']))->format('d/m')
                    : ($c['dias'] === 0 ? 'vence hoy' : 'vence el ' . (new DateTimeImmutable($c['vence']))->format('d/m'));
            $largo .= "\n• {$c['evento']} ({$c['cliente']}): {$c['concepto']} " . self::dinero($c['saldo']) . " — $cuando";
        }
        if (count($cuotas) > 25) $largo .= "\n… y " . (count($cuotas) - 25) . ' más.';
        $this->avisar($negocioId, ['dueno', 'vendedor'], $corto, $largo);
        return 1;
    }

    // ------------------------------------------------------------------
    // 4) Revisar precio de eventos vendidos con mucha anticipación
    // ------------------------------------------------------------------

    public function avisarRevisarPrecio(int $negocioId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA_AVISOS) return 0;
        $hoy = $ahora->format('Y-m-d');
        $st = $this->q("UPDATE eventos e SET aviso_precio_enviado = true, aviso_precio_fecha = e.fecha
                          FROM negocios n
                         WHERE n.id = e.negocio_id AND e.negocio_id = ? AND e.estado IN ('separado','confirmado')
                           AND e.fecha > ?::date
                           AND e.fecha <= ?::date + make_interval(months => n.meses_revisar_precio)
                           AND e.creado_en::date < e.fecha - make_interval(months => n.meses_revisar_precio)
                           AND e.aviso_precio_fecha IS DISTINCT FROM e.fecha
                     RETURNING e.id, e.nombre, e.fecha, e.vendedor_id, n.meses_revisar_precio",
                       [$negocioId, $hoy, $hoy]);
        $n = 0;
        foreach ($st->fetchAll() as $e) {
            $this->avisar($negocioId, ['dueno'], '🔎 Revisa los precios de ' . $e['nombre'] . ' (' . self::fecha($e['fecha']) . '): se vendió hace tiempo y faltan '
                . (int) $e['meses_revisar_precio'] . ' meses o menos. Confirma con tus proveedores que los costos sigan iguales (comida, bebidas, decoración).',
                null, (int) $e['id'], [$e['vendedor_id']]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // 5) Eventos en 7 días: aviso con las tareas pendientes
    // ------------------------------------------------------------------

    public function avisarSemana(int $negocioId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA_AVISOS) return 0;
        $hoy = $ahora->format('Y-m-d');
        $st = $this->q("UPDATE eventos e SET aviso_semana_enviado = true, aviso_semana_fecha = e.fecha
                         WHERE e.negocio_id = ? AND e.estado IN ('separado','confirmado')
                           AND e.fecha BETWEEN ?::date AND ?::date + ?::int AND e.aviso_semana_fecha IS DISTINCT FROM e.fecha
                     RETURNING e.id, e.nombre, e.fecha, e.hora_inicio, e.invitados, e.vendedor_id, e.coordinador_id",
                       [$negocioId, $hoy, $hoy, self::DIAS_SEMANA]);
        $eventos = new Eventos($this->db);
        $n = 0;
        foreach ($st->fetchAll() as $e) {
            $pend = array_values(array_filter($eventos->tareas((int) $e['id']), fn($t) => !$t['hecha']));
            $dias = (int) (new DateTimeImmutable($hoy))->diff(new DateTimeImmutable($e['fecha']))->days;
            $cuando = $dias === 0 ? 'hoy' : ($dias === 1 ? 'mañana' : "en $dias días");
            $corto = '📅 ' . $e['nombre'] . ' es ' . $cuando . ' (' . self::fecha($e['fecha']) . ', ' . substr((string) $e['hora_inicio'], 0, 5) . ', '
                   . (int) $e['invitados'] . ' invitados). '
                   . ($pend ? count($pend) . (count($pend) === 1 ? ' tarea pendiente: ' : ' tareas pendientes: ')
                              . implode(', ', array_map(fn($t) => $t['titulo'], array_slice($pend, 0, 4))) . (count($pend) > 4 ? '…' : '') . '.'
                            : 'Todas las tareas están hechas.');
            $largo = $corto;
            if (count($pend) > 4) {
                $largo = '📅 ' . $e['nombre'] . ' es ' . $cuando . ' (' . self::fecha($e['fecha']) . ").\nTareas pendientes:";
                foreach ($pend as $t) $largo .= "\n• " . $t['titulo'] . ($t['vence'] ? ' (' . (new DateTimeImmutable($t['vence']))->format('d/m') . ')' : '');
            }
            $this->avisar($negocioId, ['dueno', 'coordinador'], $corto, $largo, (int) $e['id'], [$e['vendedor_id'], $e['coordinador_id']]);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // 6) Eventos que ya pasaron → realizado; garantía por devolver
    // ------------------------------------------------------------------

    public function marcarRealizados(int $negocioId, DateTimeImmutable $ahora): array
    {
        $hoy = $ahora->setTime(0, 0);
        $lista = $this->q("SELECT id, codigo, nombre, fecha, hora_inicio, hora_fin, estado, vendedor_id, coordinador_id FROM eventos
                            WHERE negocio_id = ? AND estado IN ('separado','confirmado') AND fecha < ?::date ORDER BY fecha",
                          [$negocioId, $hoy->format('Y-m-d')])->fetchAll();
        $eventos = new Eventos($this->db);
        $pagos = new Pagos($this->db);
        $res = ['realizados' => 0, 'garantias' => 0];
        foreach ($lista as $e) {
            // Si el evento pasa la medianoche, se espera a que termine (más una hora)
            $ini = new DateTimeImmutable($e['fecha'] . ' ' . substr((string) $e['hora_inicio'], 0, 5));
            $fin = new DateTimeImmutable($e['fecha'] . ' ' . substr((string) $e['hora_fin'], 0, 5));
            if ($fin <= $ini) $fin = $fin->modify('+1 day');
            if ($fin->modify('+1 hour') > $ahora) continue;
            try {
                $this->pasarARealizado($eventos, $negocioId, $e, $hoy);
            } catch (RuntimeException $ex) {
                error_log("TuRenta tareas: no se pudo marcar realizado el evento {$e['id']}: " . $ex->getMessage());
                continue;
            }
            $res['realizados']++;
            $g = $pagos->garantia((int) $e['id']);
            if ($g['saldo'] > 0) {
                $this->avisar($negocioId, ['dueno'], '↩️ Devuelve la garantía de ' . $e['nombre'] . ': el local tiene ' . self::dinero($g['saldo'])
                    . ' del cliente. Revisa si hubo daños y registra la devolución en la pestaña Pagos del evento.',
                    null, (int) $e['id'], [$e['vendedor_id'], $e['coordinador_id']]);
                $res['garantias']++;
            }
        }
        return $res;
    }

    /**
     * Pasa a realizado con Eventos::cambiarEstado. "Separado" no puede ir directo a "realizado" en el núcleo,
     * así que en ese caso se hace aquí mismo, igual que el núcleo (estado + historial), sin tocar confirmado_en.
     */
    private function pasarARealizado(Eventos $eventos, int $negocioId, array $e, DateTimeImmutable $hoy): void
    {
        if (in_array('realizado', Eventos::PASOS[$e['estado']] ?? [], true)) {
            $eventos->cambiarEstado($negocioId, (int) $e['id'], 'realizado', [], self::SISTEMA, $hoy);
            return;
        }
        $st = $this->q("UPDATE eventos SET estado = 'realizado', retenida_hasta = NULL, actualizado_en = now()
                         WHERE id = ? AND negocio_id = ? AND estado = ?", [$e['id'], $negocioId, $e['estado']]);
        if ($st->rowCount() !== 1) throw new RuntimeException('El evento cambió mientras tanto.');
        $this->q('UPDATE evento_espacios SET bloquea = true WHERE evento_id = ?', [$e['id']]);
        $this->q("INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,NULL,'Sistema',?)",
                 [$negocioId, $e['id'], 'Cambió el estado de ' . Eventos::ESTADOS[$e['estado']] . ' a Realizado (el evento ya pasó)']);
    }

    // ------------------------------------------------------------------
    // 7) Reporte del mes al dueño (día 1, desde las 7:00)
    // ------------------------------------------------------------------

    public function enviarReporteMes(int $negocioId, DateTimeImmutable $ahora): bool
    {
        if ((int) $ahora->format('j') !== 1 || (int) $ahora->format('G') < self::HORA_REPORTE) return false;
        $mes = $ahora->modify('first day of last month')->format('Y-m');
        $st = $this->q('UPDATE negocios SET reporte_enviado_mes = ? WHERE id = ? AND reporte_enviado_mes IS DISTINCT FROM ? RETURNING nombre',
                       [$mes, $negocioId, $mes]);
        $nombre = $st->fetchColumn();
        if ($nombre === false) return false;
        $r = (new Reportes($this->db))->resumenMes($negocioId, $mes, $ahora->setTime(0, 0));
        $corto = '📊 Tu reporte de ' . Reportes::nombreMes($mes) . ': ' . $r['vendidos'] . ' eventos vendidos por ' . self::dinero($r['vendido'])
               . ', cobrado ' . self::dinero($r['cobrado']) . '. Míralo completo en Reportes.';
        $this->avisar($negocioId, ['dueno'], $corto, Reportes::textoResumenMes($r, (string) $nombre));
        return true;
    }

    // ------------------------------------------------------------------
    // 8) Seguimientos del día por Telegram (8:00)
    // ------------------------------------------------------------------

    public function enviarSeguimientos(int $negocioId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA_AVISOS || !Telegram::configurado()) return 0;
        $hoy = $ahora->format('Y-m-d');
        $st = $this->q("UPDATE usuarios SET seguimientos_dia = ?::date
                         WHERE negocio_id = ? AND activo AND rol IN ('dueno','admin','vendedor') AND telegram_chat_id IS NOT NULL
                           AND seguimientos_dia IS DISTINCT FROM ?::date
                     RETURNING id, nombre, rol", [$hoy, $negocioId, $hoy]);
        $usuarios = $st->fetchAll();
        if (!$usuarios) return 0;
        $todos = (new Eventos($this->db))->pendientes($negocioId, $hoy . ' 23:59:59');
        $tg = new Telegram($this->db);
        $n = 0;
        foreach ($usuarios as $u) {
            $mios = array_values(array_filter($todos, fn($a) => (int) $a['usuario_id'] === (int) $u['id']
                || (in_array($u['rol'], ['dueno', 'admin'], true) && $a['usuario_id'] === null)));
            if (!$mios) continue;
            $t = '☀️ Buenos días, ' . explode(' ', trim($u['nombre']))[0] . '. Tus seguimientos de hoy (' . count($mios) . '):';
            foreach (array_slice($mios, 0, 20) as $a) {
                $hora = (new DateTimeImmutable($a['programada_para']));
                $cuando = $hora->format('Y-m-d') < $hoy ? 'atrasado del ' . $hora->format('d/m') : $hora->format('H:i');
                $t .= "\n• $cuando · " . (Eventos::TIPOS_ACTIVIDAD[$a['tipo']] ?? 'Nota') . ': ' . mb_substr($a['texto'], 0, 80)
                    . ($a['cliente'] ? ' — ' . $a['cliente'] . ($a['telefono'] ? ' (' . $a['telefono'] . ')' : '') : '');
            }
            if (count($mios) > 20) $t .= "\n… y " . (count($mios) - 20) . ' más en TuRenta → Seguimiento.';
            $tg->textoAUsuarios([(int) $u['id']], $t);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Todo junto (lo llama bin/tareas.php)
    // ------------------------------------------------------------------

    public function correr(DateTimeImmutable $ahora): array
    {
        $hoy = $ahora->setTime(0, 0);
        $total = ['negocios' => 0, 'cotizaciones_vencidas' => 0, 'retenciones' => 0, 'cuotas' => 0, 'precios' => 0,
                  'semana' => 0, 'realizados' => 0, 'garantias' => 0, 'reportes' => 0, 'seguimientos' => 0, 'errores' => 0];
        $total += ['prov_pagos' => 0, 'prov_documentos' => 0, 'prov_confirmar' => 0];   // proveedores (060)
        $total['cotizaciones_vencidas'] = (new Cotizaciones($this->db))->vencerAntiguas($hoy);
        // Papelera (Anexo A): borra de verdad los negocios con más de 30 días en la papelera y las copias de más de 1 año
        $total += ['purgados' => 0, 'copias_borradas' => 0];
        try {
            $p = (new Papelera($this->db))->purgar($ahora);
            $total['purgados'] = $p['negocios'];
            $total['copias_borradas'] = $p['copias'];
        } catch (\Throwable $e) {
            $total['errores']++;
            error_log('TuRenta tareas papelera: ' . $e->getMessage());
        }
        $planes = new Planes($this->db);
        $modulos = new Modulos($this->db);
        // Ni los pausados (suspendidos) ni los que están en la papelera reciben tareas ni avisos
        $negocios = $this->db->query("SELECT id, estado, prueba_hasta, activo_hasta FROM negocios WHERE estado IN ('prueba','activo') AND eliminado_en IS NULL ORDER BY id")->fetchAll();
        foreach ($negocios as $n) {
            if (!$planes->alDia($n, $hoy)) continue;
            $id = (int) $n['id'];
            $total['negocios']++;
            try {
                $total['retenciones'] += $this->liberarRetenidas($id, $hoy);
                $r = $this->marcarRealizados($id, $ahora);
                $total['realizados'] += $r['realizados'];
                $total['garantias'] += $r['garantias'];
                $total['cuotas'] += $this->avisarCuotas($id, $ahora);
                $total['precios'] += $this->avisarRevisarPrecio($id, $ahora);
                $total['semana'] += $this->avisarSemana($id, $ahora);
                $total['reportes'] += $this->enviarReporteMes($id, $ahora) ? 1 : 0;
                $activos = $modulos->activos($id);
                if (in_array('telegram', $activos, true)) $total['seguimientos'] += $this->enviarSeguimientos($id, $ahora);
                if (in_array('proveedores', $activos, true)) {   // pagos, documentos y confirmaciones de proveedores
                    foreach ((new AvisosProveedores($this->db))->correr($id, $ahora) as $k => $v) $total[$k] += $v;
                }
            } catch (\Throwable $e) {
                $total['errores']++;
                error_log("TuRenta tareas negocio $id: " . $e->getMessage());
            }
        }
        return $total;
    }

    public static function resumen(DateTimeImmutable $ahora, array $r): string
    {
        return $ahora->format('Y-m-d H:i') . " · negocios {$r['negocios']} · cotizaciones vencidas {$r['cotizaciones_vencidas']}"
             . " · fechas liberadas {$r['retenciones']} · avisos de cobro {$r['cuotas']} · revisar precio {$r['precios']}"
             . " · eventos en 7 dias {$r['semana']} · realizados {$r['realizados']} (garantias {$r['garantias']})"
             . " · reportes {$r['reportes']} · seguimientos {$r['seguimientos']}" . AvisosProveedores::resumen($r)
             . (($r['purgados'] ?? 0) || ($r['copias_borradas'] ?? 0) ? " · papelera: borrados {$r['purgados']}, copias viejas {$r['copias_borradas']}" : '')
             . ($r['errores'] ? " · ERRORES {$r['errores']}" : '');
    }
}
