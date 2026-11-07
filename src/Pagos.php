<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Plan de pagos (reserva + cuotas + saldo) y pagos del evento.
 *  - Los pagos confirmados se aplican a las cuotas en orden (primero la reserva).
 *  - El cliente puede subir la foto de su transferencia o DeUna desde su portal (queda "por revisar"),
 *    o pagar con tarjeta por Payphone (se confirma solo).
 *  - Cuando lo pagado cubre la reserva, el evento pasa solo a "separado" (si el espacio sigue libre).
 *  - La garantía por daños se lleva aparte: se recibe, se devuelve o se retiene por daños.
 */
final class Pagos
{
    public const METODOS = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'tarjeta' => 'Tarjeta',
                            'payphone' => 'Payphone', 'cheque' => 'Cheque', 'otro' => 'Otro'];
    public const TIPOS = ['evento' => 'Pago del evento', 'garantia' => 'Garantía recibida',
                          'devolucion_garantia' => 'Garantía devuelta', 'cargo_danos' => 'Garantía retenida por daños'];
    public const ESTADOS = ['confirmado' => 'Confirmado', 'por_revisar' => 'Por revisar', 'rechazado' => 'Rechazado',
                            'pendiente' => 'Pendiente', 'anulado' => 'Anulado'];

    /** Mensaje de la última revisión de separación (ej. "Reserva pagada: la fecha quedó separada."). */
    public ?string $ultimoMensaje = null;

    public function __construct(private PDO $db, private ?string $carpeta = null) {}

    public static function apiPayphone(): string
    {
        return rtrim((string) (getenv('PAYPHONE_API_BASE') ?: 'https://pay.payphonetodoesposible.com'), '/');
    }

    private function carpeta(): string
    {
        return $this->carpeta ?? Fotos::carpeta('comprobantes');
    }

    private function evento(int $negocioId, int $eventoId, bool $bloquear = false): array
    {
        $st = $this->db->prepare('SELECT e.*, n.reserva_pct, n.cuotas_defecto, n.dias_saldo_antes, n.nombre AS negocio
                                    FROM eventos e JOIN negocios n ON n.id = e.negocio_id
                                   WHERE e.id = ? AND e.negocio_id = ?' . ($bloquear ? ' FOR UPDATE OF e' : ''));
        $st->execute([$eventoId, $negocioId]);
        $e = $st->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    // ------------------------------------------------------------------
    // Plan de cuotas
    // ------------------------------------------------------------------

    /**
     * Arma el plan: Reserva (hoy) + cuotas repartidas + Saldo (X días antes del evento).
     * $numCuotas = total de pagos (reserva y saldo incluidos). Reemplaza el plan anterior y vuelve a aplicar lo pagado.
     */
    public function generarPlan(int $negocioId, int $eventoId, ?int $numCuotas = null, ?float $reservaPct = null, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $ev = $this->evento($negocioId, $eventoId);
        $total = round((float) $ev['total'], 2);
        if ($total <= 0) throw new RuntimeException('El evento todavía no tiene valor. Acepta una cotización primero.');
        $n = max(1, min(24, $numCuotas ?? (int) $ev['cuotas_defecto']));
        $pct = $reservaPct ?? (float) $ev['reserva_pct'];
        if ($pct <= 0 || $pct > 100) throw new RuntimeException('La reserva debe ser entre 1 % y 100 %.');
        $fechaEvento = new DateTimeImmutable($ev['fecha']);
        $saldoVence = $fechaEvento->modify('-' . (int) $ev['dias_saldo_antes'] . ' days');
        if ($saldoVence < $hoy) $saldoVence = $hoy;
        $filas = [];
        if ($n === 1) {
            $filas[] = ['concepto' => 'Pago total', 'vence' => $hoy, 'monto' => $total];
        } else {
            $reserva = round($total * $pct / 100, 2);
            if ($reserva >= $total) $reserva = round($total / 2, 2);
            $resto = round($total - $reserva, 2);
            $filas[] = ['concepto' => 'Reserva', 'vence' => $hoy, 'monto' => $reserva];
            $partes = $n - 1;
            $cada = floor($resto / $partes * 100) / 100;
            $dias = max(0, (int) $hoy->diff($saldoVence)->days);
            for ($i = 1; $i <= $partes; $i++) {
                $esUltima = $i === $partes;
                $vence = $esUltima ? $saldoVence : $hoy->modify('+' . (int) round($dias * $i / $partes) . ' days');
                $monto = $esUltima ? round($resto - $cada * ($partes - 1), 2) : $cada;
                $filas[] = ['concepto' => $esUltima ? 'Saldo' : 'Cuota ' . ($i + 1), 'vence' => $vence, 'monto' => $monto];
            }
        }
        $this->reemplazarPlan($eventoId, $filas);
        return $this->cuotas($eventoId);
    }

    /** Plan escrito a mano: filas [concepto, vence, monto]. Debe sumar el total del evento. */
    public function guardarPlan(int $negocioId, int $eventoId, array $filas): void
    {
        $ev = $this->evento($negocioId, $eventoId);
        $limpias = [];
        $suma = 0.0;
        foreach ($filas as $f) {
            $monto = round(Cotizaciones::num($f['monto'] ?? 0), 2);
            $concepto = trim((string) ($f['concepto'] ?? ''));
            if ($monto <= 0 && $concepto === '') continue;
            if ($monto <= 0) throw new RuntimeException("El valor de \"$concepto\" debe ser mayor que 0.");
            $vence = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($f['vence'] ?? ''));
            if (!$vence) throw new RuntimeException("Revisa la fecha de \"$concepto\".");
            $limpias[] = ['concepto' => $concepto ?: 'Cuota', 'vence' => $vence, 'monto' => $monto];
            $suma += $monto;
        }
        if (!$limpias) throw new RuntimeException('Escribe al menos una cuota.');
        if (abs(round($suma, 2) - round((float) $ev['total'], 2)) > 0.009) {
            throw new RuntimeException('Las cuotas suman ' . number_format($suma, 2, ',', '.') . ' y el evento vale ' . number_format((float) $ev['total'], 2, ',', '.') . '. Deben ser iguales.');
        }
        usort($limpias, fn($a, $b) => $a['vence'] <=> $b['vence']);
        $this->reemplazarPlan($eventoId, $limpias);
    }

    private function reemplazarPlan(int $eventoId, array $filas): void
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM cuotas WHERE evento_id = ?')->execute([$eventoId]);
            $ins = $this->db->prepare('INSERT INTO cuotas (evento_id, numero, concepto, vence, monto) VALUES (?,?,?,?,?)');
            foreach (array_values($filas) as $i => $f) {
                $ins->execute([$eventoId, $i + 1, mb_substr($f['concepto'], 0, 80), $f['vence']->format('Y-m-d'), $f['monto']]);
            }
            $this->reaplicar($eventoId);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Reparte lo pagado (confirmado) entre las cuotas, en orden. */
    public function reaplicar(int $eventoId): void
    {
        $st = $this->db->prepare("SELECT COALESCE(sum(monto),0) FROM pagos WHERE evento_id = ? AND tipo = 'evento' AND estado = 'confirmado'");
        $st->execute([$eventoId]);
        $disponible = round((float) $st->fetchColumn(), 2);
        $st = $this->db->prepare('SELECT id, monto FROM cuotas WHERE evento_id = ? ORDER BY numero');
        $st->execute([$eventoId]);
        $up = $this->db->prepare('UPDATE cuotas SET pagado = ? WHERE id = ?');
        foreach ($st->fetchAll() as $c) {
            $aplica = min($disponible, (float) $c['monto']);
            $up->execute([round($aplica, 2), $c['id']]);
            $disponible = round($disponible - $aplica, 2);
        }
    }

    public function cuotas(int $eventoId, ?DateTimeImmutable $hoy = null): array
    {
        $hoyTxt = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $st = $this->db->prepare('SELECT *, round(monto - pagado, 2) AS saldo FROM cuotas WHERE evento_id = ? ORDER BY numero');
        $st->execute([$eventoId]);
        $res = [];
        foreach ($st->fetchAll() as $c) {
            $c['estado'] = (float) $c['saldo'] <= 0 ? 'pagada' : ($c['vence'] < $hoyTxt ? 'vencida' : ((float) $c['pagado'] > 0 ? 'parcial' : 'pendiente'));
            $res[] = $c;
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Pagos
    // ------------------------------------------------------------------

    /**
     * El negocio registra un pago ya recibido (queda confirmado y con número de recibo).
     * $d: monto, metodo, fecha (Y-m-d o Y-m-d H:i), referencia, tipo, notas. Devuelve el id del pago.
     */
    public function registrar(int $negocioId, int $eventoId, array $d, ?array $usuario = null, ?array $archivo = null): int
    {
        $this->evento($negocioId, $eventoId);
        $monto = round(Cotizaciones::num($d['monto'] ?? 0), 2);
        if ($monto <= 0) throw new RuntimeException('Escribe el valor del pago.');
        $metodo = (string) ($d['metodo'] ?? 'transferencia');
        if (!isset(self::METODOS[$metodo])) throw new RuntimeException('Elige la forma de pago.');
        $tipo = (string) ($d['tipo'] ?? 'evento');
        if (!isset(self::TIPOS[$tipo])) throw new RuntimeException('Tipo de pago no válido.');
        $fecha = trim((string) ($d['fecha'] ?? ''));
        $fecha = $fecha === '' ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $fecha)) ?: time());
        if ($tipo === 'devolucion_garantia' || $tipo === 'cargo_danos') {
            $g = $this->garantia($eventoId);
            if ($monto > $g['saldo'] + 0.009) throw new RuntimeException('Solo hay ' . number_format($g['saldo'], 2, ',', '.') . ' dólares de garantía para devolver o retener.');
        }
        $comprobante = $archivo && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? Fotos::guardarImagen($archivo, $this->carpeta(), 1800) : null;
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $recibo = in_array($tipo, ['evento', 'garantia'], true) ? $this->siguienteRecibo($negocioId) : null;
            $st = $this->db->prepare("INSERT INTO pagos (negocio_id, evento_id, tipo, fecha, monto, metodo, referencia, comprobante, estado, origen, numero_recibo, notas, registrado_por)
                                      VALUES (?,?,?,?,?,?,?,?, 'confirmado', 'negocio', ?,?,?) RETURNING id");
            $st->execute([$negocioId, $eventoId, $tipo, $fecha, $monto, $metodo, mb_substr(trim((string) ($d['referencia'] ?? '')), 0, 120) ?: null,
                          $comprobante, $recibo, mb_substr(trim((string) ($d['notas'] ?? '')), 0, 300) ?: null, $usuario['id'] ?? null]);
            $id = (int) $st->fetchColumn();
            $this->reaplicar($eventoId);
            $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                     ->execute([$negocioId, $eventoId, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema',
                                self::TIPOS[$tipo] . ': ' . number_format($monto, 2, ',', '.') . ' dólares (' . self::METODOS[$metodo] . ')']);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            if ($comprobante) Fotos::borrar('comprobantes', $comprobante);
            throw $e;
        }
        $this->ultimoMensaje = $tipo === 'evento' ? $this->revisarSeparacion($negocioId, $eventoId, $usuario) : null;
        return $id;
    }

    private function siguienteRecibo(int $negocioId): int
    {
        $this->db->prepare('SELECT id FROM negocios WHERE id = ? FOR UPDATE')->execute([$negocioId]);
        $st = $this->db->prepare('SELECT COALESCE(max(numero_recibo),0) + 1 FROM pagos WHERE negocio_id = ?');
        $st->execute([$negocioId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Si lo pagado ya cubre la reserva y el evento está en consulta o cotizado, se separa la fecha.
     * Si el contrato ya está firmado, queda confirmado. Devuelve un mensaje para mostrar (o null).
     */
    public function revisarSeparacion(int $negocioId, int $eventoId, ?array $usuario = null): ?string
    {
        $ev = $this->evento($negocioId, $eventoId);
        $cuotas = $this->cuotas($eventoId);
        if (!$cuotas) return null;
        $eventos = new Eventos($this->db);
        if (in_array($ev['estado'], ['consulta', 'cotizado'], true) && (float) $cuotas[0]['saldo'] <= 0) {
            try {
                $eventos->cambiarEstado($negocioId, $eventoId, 'separado', [], $usuario ?? ['id' => null, 'nombre' => 'Sistema']);
            } catch (RuntimeException $e) {
                (new Notificaciones($this->db))->alNegocio($negocioId, 'Se pagó la reserva de ' . $ev['codigo'] . ' pero NO se pudo separar la fecha: ' . $e->getMessage(), $eventoId);
                return 'Se registró el pago, pero no se pudo separar la fecha: ' . $e->getMessage();
            }
            $st = $this->db->prepare("SELECT 1 FROM contratos WHERE evento_id = ? AND estado = 'firmado' LIMIT 1");
            $st->execute([$eventoId]);
            if ($st->fetchColumn()) {
                $eventos->cambiarEstado($negocioId, $eventoId, 'confirmado', [], ['id' => null, 'nombre' => 'Sistema']);
                return 'Reserva pagada y contrato firmado: el evento quedó confirmado.';
            }
            return 'Reserva pagada: la fecha quedó separada.';
        }
        return null;
    }

    /** El cliente sube desde su portal la foto de su transferencia o DeUna. Queda por revisar. */
    public function subirComprobante(int $eventoId, array $archivo, float $monto, string $metodo, string $referencia = ''): int
    {
        $st = $this->db->prepare('SELECT negocio_id, codigo, estado, cliente_id FROM eventos WHERE id = ?');
        $st->execute([$eventoId]);
        $ev = $st->fetch();
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        if (in_array($ev['estado'], ['perdido', 'cancelado'], true)) throw new RuntimeException('Este evento ya no está activo.');
        $monto = round($monto, 2);
        if ($monto <= 0) throw new RuntimeException('Escribe cuánto pagaste.');
        $saldo = $this->resumen($eventoId)['saldo'];
        if ($monto > $saldo + 0.009 && $saldo > 0) throw new RuntimeException('El valor es mayor que lo que falta pagar (' . number_format($saldo, 2, ',', '.') . ' dólares).');
        if (!in_array($metodo, ['transferencia', 'deuna'], true)) $metodo = 'transferencia';
        $nombre = Fotos::guardarImagen($archivo, $this->carpeta(), 1800);
        $st = $this->db->prepare("INSERT INTO pagos (negocio_id, evento_id, tipo, monto, metodo, referencia, comprobante, estado, origen)
                                  VALUES (?,?, 'evento', ?,?,?,?, 'por_revisar', 'cliente') RETURNING id");
        $st->execute([$ev['negocio_id'], $eventoId, $monto, $metodo, mb_substr(trim($referencia), 0, 120) ?: null, $nombre]);
        $id = (int) $st->fetchColumn();
        $this->db->prepare("INSERT INTO registro_cambios (negocio_id, evento_id, quien, accion) VALUES (?,?, 'Cliente', ?)")
                 ->execute([$ev['negocio_id'], $eventoId, 'Subió un comprobante de ' . number_format($monto, 2, ',', '.') . ' dólares (' . self::METODOS[$metodo] . ')']);
        (new Notificaciones($this->db))->alNegocio((int) $ev['negocio_id'],
            "El cliente de {$ev['codigo']} subió un comprobante de pago por " . number_format($monto, 2, ',', '.') . ' dólares. Revísalo y confírmalo.', $eventoId);
        return $id;
    }

    /** El negocio confirma un comprobante revisado (le pone número de recibo). */
    public function confirmar(int $negocioId, int $pagoId, ?array $usuario = null, ?string $referencia = null): ?string
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT * FROM pagos WHERE id = ? AND negocio_id = ? FOR UPDATE");
            $st->execute([$pagoId, $negocioId]);
            $p = $st->fetch();
            if (!$p) throw new RuntimeException('No se encontró el pago.');
            if ($p['estado'] === 'confirmado') {
                if ($propia) $this->db->commit();
                return null;
            }
            if (!in_array($p['estado'], ['por_revisar', 'pendiente', 'rechazado'], true)) throw new RuntimeException('Este pago no se puede confirmar.');
            $recibo = $this->siguienteRecibo($negocioId);
            $this->db->prepare("UPDATE pagos SET estado = 'confirmado', numero_recibo = ?, referencia = COALESCE(?, referencia), registrado_por = COALESCE(registrado_por, ?) WHERE id = ?")
                     ->execute([$recibo, $referencia, $usuario['id'] ?? null, $pagoId]);
            $this->reaplicar((int) $p['evento_id']);
            $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                     ->execute([$negocioId, $p['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema',
                                'Confirmó el pago de ' . number_format((float) $p['monto'], 2, ',', '.') . ' dólares (' . self::METODOS[$p['metodo']] . ')']);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $this->revisarSeparacion($negocioId, (int) $p['evento_id'], $usuario);
    }

    public function rechazar(int $negocioId, int $pagoId, string $motivo = '', ?array $usuario = null): void
    {
        $st = $this->db->prepare("UPDATE pagos SET estado = 'rechazado', notas = ? WHERE id = ? AND negocio_id = ? AND estado = 'por_revisar' RETURNING evento_id, monto");
        $st->execute([mb_substr(trim($motivo), 0, 300) ?: null, $pagoId, $negocioId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('No hay un comprobante por revisar con ese número.');
        $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                 ->execute([$negocioId, $r['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema',
                            'Rechazó un comprobante de ' . number_format((float) $r['monto'], 2, ',', '.') . ' dólares' . ($motivo ? " ($motivo)" : '')]);
    }

    /** Anula un pago registrado por error (se recalculan las cuotas). */
    public function anular(int $negocioId, int $pagoId, string $motivo, ?array $usuario = null): void
    {
        if (mb_strlen(trim($motivo)) < 3) throw new RuntimeException('Escribe por qué se anula el pago.');
        $st = $this->db->prepare("UPDATE pagos SET estado = 'anulado', notas = trim(COALESCE(notas,'') || ' [Anulado: ' || ? || ']')
                                   WHERE id = ? AND negocio_id = ? AND estado = 'confirmado' RETURNING evento_id, monto");
        $st->execute([mb_substr(trim($motivo), 0, 200), $pagoId, $negocioId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Ese pago no existe o ya no está confirmado.');
        $this->reaplicar((int) $r['evento_id']);
        $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                 ->execute([$negocioId, $r['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema',
                            'Anuló un pago de ' . number_format((float) $r['monto'], 2, ',', '.') . " dólares ($motivo)"]);
    }

    public function pagos(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT p.*, u.nombre AS registrado FROM pagos p LEFT JOIN usuarios u ON u.id = p.registrado_por
                                   WHERE p.negocio_id = ? AND p.evento_id = ? ORDER BY p.fecha DESC, p.id DESC');
        $st->execute([$negocioId, $eventoId]);
        return $st->fetchAll();
    }

    public function pago(int $negocioId, int $pagoId): ?array
    {
        $st = $this->db->prepare('SELECT p.*, e.codigo, e.nombre AS evento, e.fecha AS evento_fecha, c.nombre AS cliente, c.identificacion AS cliente_identificacion,
                                         u.nombre AS registrado
                                    FROM pagos p JOIN eventos e ON e.id = p.evento_id JOIN clientes c ON c.id = e.cliente_id
                               LEFT JOIN usuarios u ON u.id = p.registrado_por
                                   WHERE p.id = ? AND p.negocio_id = ?');
        $st->execute([$pagoId, $negocioId]);
        return $st->fetch() ?: null;
    }

    /** Ruta de la foto del comprobante (solo para el personal del negocio). */
    public function rutaComprobante(int $negocioId, int $pagoId): ?string
    {
        $st = $this->db->prepare('SELECT comprobante FROM pagos WHERE id = ? AND negocio_id = ?');
        $st->execute([$pagoId, $negocioId]);
        return Fotos::ruta('comprobantes', (string) $st->fetchColumn());
    }

    /** Total, pagado, saldo, lo vencido y la próxima cuota. */
    public function resumen(int $eventoId, ?DateTimeImmutable $hoy = null): array
    {
        $st = $this->db->prepare("SELECT e.total, e.garantia,
                                         COALESCE((SELECT sum(monto) FROM pagos WHERE evento_id = e.id AND tipo = 'evento' AND estado = 'confirmado'),0) AS pagado,
                                         COALESCE((SELECT sum(monto) FROM pagos WHERE evento_id = e.id AND tipo = 'evento' AND estado = 'por_revisar'),0) AS por_revisar
                                    FROM eventos e WHERE e.id = ?");
        $st->execute([$eventoId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('No se encontró el evento.');
        $cuotas = $this->cuotas($eventoId, $hoy);
        $vencido = 0.0;
        $proxima = null;
        foreach ($cuotas as $c) {
            if ($c['estado'] === 'vencida') $vencido += (float) $c['saldo'];
            if ($proxima === null && (float) $c['saldo'] > 0) $proxima = $c;
        }
        $total = round((float) $r['total'], 2);
        $pagado = round((float) $r['pagado'], 2);
        return [
            'total' => $total, 'pagado' => $pagado, 'saldo' => round(max(0, $total - $pagado), 2),
            'a_favor' => round(max(0, $pagado - $total), 2),
            'por_revisar' => round((float) $r['por_revisar'], 2), 'vencido' => round($vencido, 2),
            'proxima' => $proxima, 'cuotas' => $cuotas, 'pct' => $total > 0 ? min(100, (int) floor($pagado * 100 / $total)) : 0,
            'garantia' => $this->garantia($eventoId),
        ];
    }

    /** Garantía por daños: pedida, recibida, devuelta, retenida y lo que queda en manos del negocio. */
    public function garantia(int $eventoId): array
    {
        $st = $this->db->prepare("SELECT e.garantia,
                                         COALESCE(sum(p.monto) FILTER (WHERE p.tipo = 'garantia'),0) AS recibida,
                                         COALESCE(sum(p.monto) FILTER (WHERE p.tipo = 'devolucion_garantia'),0) AS devuelta,
                                         COALESCE(sum(p.monto) FILTER (WHERE p.tipo = 'cargo_danos'),0) AS retenida
                                    FROM eventos e LEFT JOIN pagos p ON p.evento_id = e.id AND p.estado = 'confirmado'
                                   WHERE e.id = ? GROUP BY e.id");
        $st->execute([$eventoId]);
        $g = $st->fetch() ?: ['garantia' => 0, 'recibida' => 0, 'devuelta' => 0, 'retenida' => 0];
        $recibida = round((float) $g['recibida'], 2);
        return ['pedida' => round((float) $g['garantia'], 2), 'recibida' => $recibida, 'devuelta' => round((float) $g['devuelta'], 2),
                'retenida' => round((float) $g['retenida'], 2),
                'saldo' => round($recibida - (float) $g['devuelta'] - (float) $g['retenida'], 2)];
    }

    /** Cuotas con saldo que vencen hasta dentro de X días (las vencidas incluidas), de eventos activos. */
    public function porCobrar(int $negocioId, int $dias = 7, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare("SELECT cu.*, round(cu.monto - cu.pagado, 2) AS saldo, e.id AS evento_id, e.codigo, e.nombre AS evento, e.fecha AS evento_fecha,
                                         e.token_cliente, e.estado AS evento_estado, c.nombre AS cliente, c.telefono
                                    FROM cuotas cu JOIN eventos e ON e.id = cu.evento_id JOIN clientes c ON c.id = e.cliente_id
                                   WHERE e.negocio_id = ? AND cu.pagado < cu.monto AND cu.vence <= ?::date
                                     AND e.estado IN ('cotizado','separado','confirmado','realizado')
                                ORDER BY cu.vence, e.fecha");
        $st->execute([$negocioId, $hoy->modify("+$dias days")->format('Y-m-d')]);
        $res = [];
        foreach ($st->fetchAll() as $c) {
            $c['vencida'] = $c['vence'] < $hoy->format('Y-m-d');
            $c['dias'] = (int) $hoy->diff(new DateTimeImmutable($c['vence']))->format('%r%a');
            $res[] = $c;
        }
        return $res;
    }

    /** Comprobantes que subieron los clientes y esperan revisión. */
    public function porRevisar(int $negocioId): array
    {
        $st = $this->db->prepare("SELECT p.*, e.codigo, e.nombre AS evento, c.nombre AS cliente FROM pagos p
                                    JOIN eventos e ON e.id = p.evento_id JOIN clientes c ON c.id = e.cliente_id
                                   WHERE p.negocio_id = ? AND p.estado = 'por_revisar' ORDER BY p.creado_en");
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    /** Mensaje de recordatorio de cuota para WhatsApp. */
    public static function textoRecordatorio(string $cliente, string $negocio, string $concepto, float $saldo, string $vence, string $enlacePortal): string
    {
        $nombre = trim(explode(' ', trim($cliente))[0]);
        $f = new DateTimeImmutable($vence);
        $cuando = $f < new DateTimeImmutable('today') ? 'venció el ' . $f->format('d/m') : 'vence el ' . $f->format('d/m');
        return "Hola $nombre, te saludamos de $negocio. Te recordamos que tu pago \"$concepto\" por $" . number_format($saldo, 2, ',', '.')
             . " $cuando. " . ($enlacePortal !== '' ? "Puedes ver tu plan de pagos y subir tu comprobante aquí: $enlacePortal" : 'Si ya pagaste, envíanos el comprobante por aquí.') . ' ¡Gracias!';
    }

    // ------------------------------------------------------------------
    // Payphone (tarjeta) para el cliente desde su portal
    // ------------------------------------------------------------------

    public function payphoneListo(int $negocioId): bool
    {
        $st = $this->db->prepare('SELECT payphone_token, payphone_store_id FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $n = $st->fetch();
        return $n && trim((string) $n['payphone_token']) !== '' && trim((string) $n['payphone_store_id']) !== ''
            && (new Modulos($this->db))->activo($negocioId, 'cobros_linea');   // Anexo B: módulo "Cobros en línea"
    }

    /** Prepara la cajita de Payphone para pagar $monto. Crea un pago pendiente con un código único. */
    public function prepararPayphone(int $eventoId, float $monto): array
    {
        $st = $this->db->prepare('SELECT e.negocio_id, e.codigo, n.payphone_token, n.payphone_store_id, n.nombre FROM eventos e JOIN negocios n ON n.id = e.negocio_id WHERE e.id = ?');
        $st->execute([$eventoId]);
        $ev = $st->fetch();
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        if (!$this->payphoneListo((int) $ev['negocio_id'])) throw new RuntimeException('Este negocio no tiene activado el pago con tarjeta.');
        $monto = round($monto, 2);
        $saldo = $this->resumen($eventoId)['saldo'];
        if ($monto <= 0 || $monto > $saldo + 0.009) throw new RuntimeException('El valor a pagar debe ser entre 0,01 y ' . number_format($saldo, 2, ',', '.') . ' dólares.');
        $st = $this->db->prepare("INSERT INTO pagos (negocio_id, evento_id, tipo, monto, metodo, estado, origen)
                                  VALUES (?,?, 'evento', ?, 'payphone', 'pendiente', 'payphone') RETURNING id");
        $st->execute([$ev['negocio_id'], $eventoId, $monto]);
        $pagoId = (int) $st->fetchColumn();
        $tx = 'TE' . $pagoId . '-' . bin2hex(random_bytes(4));
        $this->db->prepare('UPDATE pagos SET referencia = ? WHERE id = ?')->execute([$tx, $pagoId]);
        $centavos = (int) round($monto * 100);
        return [
            'token' => $ev['payphone_token'], 'storeId' => $ev['payphone_store_id'], 'clientTransactionId' => $tx,
            'amount' => $centavos, 'amountWithoutTax' => $centavos, 'currency' => 'USD',
            'reference' => mb_substr('Pago ' . $ev['codigo'] . ' ' . $ev['nombre'], 0, 100),
        ];
    }

    /**
     * Payphone devuelve al cliente con ?id=...&clientTransactionId=...: se pregunta a Payphone si se aprobó.
     * Devuelve [estado, token_cliente del evento]. estado: aprobado | cancelado | no_aprobado
     * @param callable|null $http para pruebas: fn(string $url, array $cuerpo, string $token): ?array
     */
    public function confirmarPayphone(int $id, string $clientTxId, ?callable $http = null): array
    {
        if (!preg_match('/^TE(\d+)-[a-f0-9]{8}$/', $clientTxId, $m)) throw new RuntimeException('Pago no reconocido.');
        $st = $this->db->prepare('SELECT p.*, e.token_cliente, n.payphone_token FROM pagos p JOIN eventos e ON e.id = p.evento_id
                                    JOIN negocios n ON n.id = p.negocio_id WHERE p.id = ?');
        $st->execute([(int) $m[1]]);
        $p = $st->fetch();
        if (!$p || $p['referencia'] !== $clientTxId) throw new RuntimeException('Pago no reconocido.');
        if ($p['estado'] === 'confirmado') return ['aprobado', $p['token_cliente']];
        $http ??= [self::class, 'llamarPayphone'];
        $r = $http(self::apiPayphone() . '/api/button/V2/Confirm', ['id' => $id, 'clientTxId' => $clientTxId], (string) $p['payphone_token']);
        $estado = (string) ($r['transactionStatus'] ?? '');
        if ($estado === 'Approved' && (int) ($r['amount'] ?? -1) === (int) round((float) $p['monto'] * 100)
            && ($r['clientTransactionId'] ?? $clientTxId) === $clientTxId) {
            $this->db->prepare('UPDATE pagos SET notas = ? WHERE id = ?')->execute(['Payphone ' . ($r['transactionId'] ?? $id), $p['id']]);
            $this->confirmar((int) $p['negocio_id'], (int) $p['id'], ['id' => null, 'nombre' => 'Payphone']);
            (new Notificaciones($this->db))->alNegocio((int) $p['negocio_id'],
                'Pago con tarjeta aprobado por Payphone: ' . number_format((float) $p['monto'], 2, ',', '.') . ' dólares.', (int) $p['evento_id']);
            return ['aprobado', $p['token_cliente']];
        }
        if ($estado === 'Canceled') {
            $this->db->prepare("UPDATE pagos SET estado = 'anulado', notas = 'Cancelado en Payphone' WHERE id = ? AND estado = 'pendiente'")->execute([$p['id']]);
            return ['cancelado', $p['token_cliente']];
        }
        return ['no_aprobado', $p['token_cliente']];
    }

    public static function llamarPayphone(string $url, array $cuerpo, string $token): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($cuerpo),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        ]);
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $codigo >= 400) {
            error_log("TuRenta Payphone: confirmación falló (HTTP $codigo)");
            return null;
        }
        return json_decode((string) $r, true);
    }
}
