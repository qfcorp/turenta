<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Ficha completa del proveedor (módulo Proveedores y compras, migración 060):
 *  - Datos para pagarle (RUC, banco) y condiciones de crédito (días, cupo, anticipo).
 *  - Documentos con fecha de vencimiento (permisos, póliza, contrato…).
 *  - Bitácora: todo lo que pasó con el proveedor (quién, cuándo, de qué compra o evento).
 *  - Eventos donde trabajó, historial de precios.
 *  - Cumplimiento del día del evento: llegada real, puntualidad, calificación, bloques del cronograma a tiempo.
 *  - Puntaje (scorecard) y ranking.
 *  - Portal del proveedor (enlace secreto, sin contraseña): sus eventos, compras, pagos y "Ya llegué".
 * Todo se filtra por negocio.
 */
final class ProveedoresFicha
{
    public const TIPOS_DOC = [
        'ruc' => 'RUC', 'permiso_funcionamiento' => 'Permiso de funcionamiento', 'permiso_sanitario' => 'Permiso sanitario (ARCSA)',
        'poliza' => 'Póliza de seguro', 'contrato' => 'Contrato marco', 'otro' => 'Otro documento',
    ];
    public const TIPOS_CUENTA = ['ahorros' => 'Ahorros', 'corriente' => 'Corriente'];
    public const CUMPLIMIENTO = [
        'cumplio' => 'Cumplió', 'observaciones' => 'Cumplió con observaciones', 'incompleto' => 'Incompleto', 'no_llego' => 'No llegó',
    ];
    /** Días antes del vencimiento de un documento para avisar. */
    public const DIAS_DOC = 30;
    public const BANCOS = ['Banco Pichincha', 'Banco Guayaquil', 'Produbanco', 'Banco del Pacífico', 'Banco Internacional', 'Banco Bolivariano',
                           'Banco del Austro', 'Banco de Loja', 'Banco Machala', 'Banco ProCredit', 'Banco Solidario', 'Banco General Rumiñahui',
                           'Cooperativa JEP', 'Cooperativa Jardín Azuayo', 'Cooperativa 29 de Octubre', 'Cooperativa Policía Nacional'];

    public function __construct(private PDO $db) {}

    /** Minutos de tolerancia del negocio para "a tiempo" (Proveedores → Cómo se calcula el puntaje). */
    public function tolerancia(int $negocioId): int
    {
        return (new PuntajeProveedores($this->db))->config($negocioId)['tolerancia_min'];
    }

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    // ------------------------------------------------------------------
    // Datos del proveedor
    // ------------------------------------------------------------------

    public function proveedor(int $negocioId, int $id): ?array
    {
        return $this->q('SELECT * FROM proveedores WHERE id = ? AND negocio_id = ?', [$id, $negocioId])->fetch() ?: null;
    }

    /** Cédula (10 dígitos) o RUC (13). Vacío = null. */
    public static function identificacion(?string $v): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $v);
        if ($d === '') return null;
        if (strlen($d) !== 10 && strlen($d) !== 13) throw new RuntimeException('El RUC tiene 13 dígitos y la cédula 10. Revisa el número.');
        return $d;
    }

    /**
     * Revisa los datos ampliados (antes de guardar). Devuelve los valores limpios.
     * Campos: ruc, razon_social, direccion, ciudad, banco, tipo_cuenta, numero_cuenta, titular_cuenta,
     *         credito_dias, cupo_credito, anticipo_pct, preferido
     */
    public static function validarDatos(array $d): array
    {
        $ruc = self::identificacion($d['ruc'] ?? '');
        $tipo = trim((string) ($d['tipo_cuenta'] ?? ''));
        if ($tipo !== '' && !isset(self::TIPOS_CUENTA[$tipo])) throw new RuntimeException('Elige si la cuenta es de ahorros o corriente.');
        $cuenta = preg_replace('/[^0-9A-Za-z-]/', '', (string) ($d['numero_cuenta'] ?? ''));
        if ($cuenta !== '' && strlen($cuenta) < 5) throw new RuntimeException('Revisa el número de cuenta del banco.');
        $dias = trim((string) ($d['credito_dias'] ?? '0'));
        if (!preg_match('/^\d{1,3}$/', $dias === '' ? '0' : $dias) || (int) $dias > 365) throw new RuntimeException('Los días de crédito van de 0 (de contado) a 365.');
        $cupo = $d['cupo_credito'] ?? null;
        $cupo = $cupo === null || trim((string) $cupo) === '' ? null : Cotizaciones::num($cupo);
        if ($cupo !== null && ($cupo < 0 || $cupo > 9999999)) throw new RuntimeException('Revisa el cupo de crédito.');
        $ant = trim((string) ($d['anticipo_pct'] ?? '')) === '' ? 0.0 : Cotizaciones::num($d['anticipo_pct']);
        if ($ant < 0 || $ant > 100) throw new RuntimeException('El anticipo va de 0 a 100 %.');
        $t = fn(string $k, int $max) => mb_substr(trim((string) ($d[$k] ?? '')), 0, $max) ?: null;
        return [
            'ruc' => $ruc, 'razon_social' => $t('razon_social', 150), 'direccion' => $t('direccion', 250), 'ciudad' => $t('ciudad', 80),
            'banco' => $t('banco', 80), 'tipo_cuenta' => $tipo ?: null, 'numero_cuenta' => $cuenta ?: null, 'titular_cuenta' => $t('titular_cuenta', 150),
            'credito_dias' => (int) $dias, 'cupo_credito' => $cupo === null ? null : round($cupo, 2), 'anticipo_pct' => round($ant, 2),
            'preferido' => !empty($d['preferido']),
        ];
    }

    /** Guarda RUC, banco y condiciones. Anota en la bitácora lo que cambió de las condiciones. */
    public function guardarDatos(int $negocioId, int $proveedorId, array $d, ?array $usuario = null): void
    {
        $p = $this->proveedor($negocioId, $proveedorId);
        if (!$p) throw new RuntimeException('No se encontró el proveedor.');
        $v = self::validarDatos($d);
        if ($v['ruc'] !== null) {
            $otro = $this->q('SELECT nombre FROM proveedores WHERE negocio_id = ? AND ruc = ? AND id <> ?', [$negocioId, $v['ruc'], $proveedorId])->fetchColumn();
            if ($otro !== false) throw new RuntimeException('Ese RUC ya es de otro proveedor de tu lista: ' . $otro . '.');
        }
        $this->q('UPDATE proveedores SET ruc = ?, razon_social = ?, direccion = ?, ciudad = ?, banco = ?, tipo_cuenta = ?, numero_cuenta = ?, titular_cuenta = ?,
                         credito_dias = ?, cupo_credito = ?, anticipo_pct = ?, preferido = ? WHERE id = ? AND negocio_id = ?',
                 [$v['ruc'], $v['razon_social'], $v['direccion'], $v['ciudad'], $v['banco'], $v['tipo_cuenta'], $v['numero_cuenta'], $v['titular_cuenta'],
                  $v['credito_dias'], $v['cupo_credito'], $v['anticipo_pct'], $v['preferido'] ? 'true' : 'false', $proveedorId, $negocioId]);
        $cambios = [];
        if ((int) $p['credito_dias'] !== $v['credito_dias']) $cambios[] = 'crédito de ' . self::textoCredito((int) $p['credito_dias']) . ' a ' . self::textoCredito($v['credito_dias']);
        $cupoAntes = $p['cupo_credito'] === null ? null : round((float) $p['cupo_credito'], 2);
        if ($cupoAntes !== $v['cupo_credito']) $cambios[] = 'cupo ' . ($v['cupo_credito'] === null ? 'sin límite' : Pdf::dinero($v['cupo_credito']));
        if (abs((float) $p['anticipo_pct'] - $v['anticipo_pct']) > 0.001) $cambios[] = 'anticipo ' . rtrim(rtrim(number_format($v['anticipo_pct'], 2, ',', ''), '0'), ',') . ' %';
        if (($p['numero_cuenta'] ?? null) !== $v['numero_cuenta'] || ($p['banco'] ?? null) !== $v['banco']) $cambios[] = 'datos del banco';
        if (($p['ruc'] ?? null) !== $v['ruc']) $cambios[] = 'RUC ' . ($v['ruc'] ?? '(sin RUC)');
        if ((bool) $p['preferido'] !== $v['preferido']) $cambios[] = $v['preferido'] ? 'marcado como preferido' : 'ya no es preferido';
        if ($cambios) $this->anotar($negocioId, $proveedorId, 'condiciones', 'Cambió ' . implode(', ', $cambios), $usuario);
    }

    public static function textoCredito(int $dias): string
    {
        return $dias <= 0 ? 'contado' : $dias . ($dias === 1 ? ' día' : ' días');
    }

    public function porRuc(int $negocioId, string $ruc): ?array
    {
        $ruc = preg_replace('/\D/', '', $ruc);
        if ($ruc === '') return null;
        // El RUC de una persona natural es su cédula + 001
        return $this->q('SELECT * FROM proveedores WHERE negocio_id = ? AND (ruc = ? OR (length(ruc) = 10 AND ruc || \'001\' = ?)) ORDER BY activo DESC, id LIMIT 1',
                        [$negocioId, $ruc, $ruc])->fetch() ?: null;
    }

    /** Crea el proveedor con los datos de una factura electrónica (FacturaXml::leer). */
    public function crearDesdeFactura(int $negocioId, array $f, string $servicio = 'otros', ?string $telefono = null, ?array $usuario = null): int
    {
        if ($this->porRuc($negocioId, (string) $f['ruc_emisor'])) throw new RuntimeException('Ya tienes un proveedor con el RUC ' . $f['ruc_emisor'] . '.');
        $nombre = trim((string) ($f['nombre_comercial'] ?: $f['razon_emisor']));
        $id = (new DiaEvento($this->db))->guardarProveedor($negocioId, null, ['nombre' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE), 'servicio' => $servicio, 'telefono' => (string) $telefono]);
        $this->q('UPDATE proveedores SET ruc = ?, razon_social = ?, direccion = ? WHERE id = ?',
                 [self::identificacion((string) $f['ruc_emisor']), mb_substr((string) $f['razon_emisor'], 0, 150) ?: null, mb_substr((string) $f['direccion_emisor'], 0, 250) ?: null, $id]);
        $this->anotar($negocioId, $id, 'condiciones', 'Se creó el proveedor con los datos de la factura ' . $f['numero'], $usuario);
        return $id;
    }

    // ------------------------------------------------------------------
    // Bitácora
    // ------------------------------------------------------------------

    /**
     * Anota algo en la bitácora del proveedor. Si hay evento, también en el historial del evento.
     * $origen: 'usuario' (con $usuario), 'proveedor' (desde su portal) o 'sistema'.
     */
    public function anotar(int $negocioId, int $proveedorId, string $tipo, string $accion, ?array $usuario = null, string $origen = 'usuario',
                           ?int $compraId = null, ?int $eventoId = null): void
    {
        if (!in_array($origen, ['usuario', 'proveedor', 'sistema'], true)) $origen = 'sistema';
        if ($origen === 'usuario' && empty($usuario['id'])) $origen = 'sistema';
        $nombreProv = '';
        if ($origen === 'proveedor' || $eventoId) {
            $nombreProv = (string) $this->q('SELECT nombre FROM proveedores WHERE id = ?', [$proveedorId])->fetchColumn();
        }
        $quien = match ($origen) { 'usuario' => (string) ($usuario['nombre'] ?? 'Usuario'), 'proveedor' => 'Proveedor', default => 'Sistema' };
        $this->q('INSERT INTO proveedor_movimientos (negocio_id, proveedor_id, compra_id, evento_id, tipo, accion, origen, usuario_id, quien) VALUES (?,?,?,?,?,?,?,?,?)',
                 [$negocioId, $proveedorId, $compraId, $eventoId, $tipo, mb_substr($accion, 0, 400), $origen, $origen === 'usuario' ? $usuario['id'] : null, $quien]);
        if ($eventoId) {
            $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                     [$negocioId, $eventoId, $origen === 'usuario' ? $usuario['id'] : null, $origen === 'proveedor' ? 'Proveedor: ' . $nombreProv : $quien,
                      mb_substr('Proveedor ' . $nombreProv . ': ' . $accion, 0, 400)]);
        }
    }

    public function bitacora(int $negocioId, int $proveedorId, int $limite = 300): array
    {
        return $this->q('SELECT m.*, e.codigo AS evento_codigo, e.nombre AS evento, c.numero AS compra_numero
                           FROM proveedor_movimientos m LEFT JOIN eventos e ON e.id = m.evento_id LEFT JOIN compras c ON c.id = m.compra_id
                          WHERE m.negocio_id = ? AND m.proveedor_id = ? ORDER BY m.creado_en DESC, m.id DESC LIMIT ' . max(1, $limite),
                        [$negocioId, $proveedorId])->fetchAll();
    }

    // ------------------------------------------------------------------
    // Documentos con vencimiento
    // ------------------------------------------------------------------

    /** 'vencido', 'por_vencer' (en 30 días o menos), 'vigente' o 'sin_fecha'. */
    public static function estadoDocumento(?string $vence, ?DateTimeImmutable $hoy = null): string
    {
        if (!$vence) return 'sin_fecha';
        $h = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        if ($vence < $h) return 'vencido';
        if ($vence <= (new DateTimeImmutable($h))->modify('+' . self::DIAS_DOC . ' days')->format('Y-m-d')) return 'por_vencer';
        return 'vigente';
    }

    public function documentos(int $negocioId, int $proveedorId, ?DateTimeImmutable $hoy = null): array
    {
        $res = [];
        foreach ($this->q('SELECT d.*, u.nombre AS subido FROM proveedor_documentos d LEFT JOIN usuarios u ON u.id = d.subido_por
                            WHERE d.negocio_id = ? AND d.proveedor_id = ? ORDER BY d.vence NULLS LAST, d.id DESC', [$negocioId, $proveedorId])->fetchAll() as $d) {
            $d['estado'] = self::estadoDocumento($d['vence'], $hoy);
            $res[] = $d;
        }
        return $res;
    }

    public function documento(int $negocioId, int $id): ?array
    {
        return $this->q('SELECT * FROM proveedor_documentos WHERE id = ? AND negocio_id = ?', [$id, $negocioId])->fetch() ?: null;
    }

    /** $d: tipo, nombre, vence (AAAA-MM-DD, opcional). $archivo = $_FILES[...] (foto o PDF, opcional). */
    public function agregarDocumento(int $negocioId, int $proveedorId, array $d, ?array $archivo = null, ?array $usuario = null): int
    {
        if (!$this->proveedor($negocioId, $proveedorId)) throw new RuntimeException('No se encontró el proveedor.');
        $tipo = (string) ($d['tipo'] ?? 'otro');
        if (!isset(self::TIPOS_DOC[$tipo])) throw new RuntimeException('Elige qué documento es.');
        $vence = trim((string) ($d['vence'] ?? ''));
        if ($vence !== '' && !DateTimeImmutable::createFromFormat('!Y-m-d', $vence)) throw new RuntimeException('Revisa la fecha de vencimiento.');
        $nombre = mb_substr(trim((string) ($d['nombre'] ?? '')), 0, 150) ?: null;
        $tiene = Adjuntos::llego($archivo);
        if (!$tiene && $vence === '' && $nombre === null) throw new RuntimeException('Sube la foto o el PDF del documento, o al menos anota su fecha de vencimiento.');
        $guardado = $tiene ? Adjuntos::guardar($archivo, ['imagen', 'pdf'])['archivo'] : null;
        $id = (int) $this->q('INSERT INTO proveedor_documentos (negocio_id, proveedor_id, tipo, nombre, archivo, vence, subido_por) VALUES (?,?,?,?,?,?,?) RETURNING id',
                             [$negocioId, $proveedorId, $tipo, $nombre, $guardado, $vence ?: null, $usuario['id'] ?? null])->fetchColumn();
        $this->anotar($negocioId, $proveedorId, 'documento', 'Subió ' . self::TIPOS_DOC[$tipo] . ($nombre ? ' (' . $nombre . ')' : '')
            . ($vence ? ', vence el ' . (new DateTimeImmutable($vence))->format('d/m/Y') : ''), $usuario);
        return $id;
    }

    public function borrarDocumento(int $negocioId, int $documentoId, ?array $usuario = null): void
    {
        $d = $this->documento($negocioId, $documentoId);
        if (!$d) throw new RuntimeException('Ese documento ya no existe.');
        $this->q('DELETE FROM proveedor_documentos WHERE id = ?', [$documentoId]);
        Adjuntos::borrar($d['archivo']);
        $this->anotar($negocioId, (int) $d['proveedor_id'], 'documento', 'Quitó el documento ' . self::TIPOS_DOC[$d['tipo']] . ($d['nombre'] ? ' (' . $d['nombre'] . ')' : ''), $usuario);
    }

    /** [proveedor_id => ['vencidos' => n, 'por_vencer' => n]] */
    public function alertasDocumentos(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $h = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $res = [];
        foreach ($this->q('SELECT proveedor_id, count(*) FILTER (WHERE vence < ?::date) AS vencidos,
                                  count(*) FILTER (WHERE vence >= ?::date AND vence <= ?::date + ' . self::DIAS_DOC . ') AS por_vencer
                             FROM proveedor_documentos WHERE negocio_id = ? AND vence IS NOT NULL GROUP BY proveedor_id', [$h, $h, $h, $negocioId])->fetchAll() as $r) {
            if ((int) $r['vencidos'] || (int) $r['por_vencer']) $res[(int) $r['proveedor_id']] = ['vencidos' => (int) $r['vencidos'], 'por_vencer' => (int) $r['por_vencer']];
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Eventos del proveedor y cumplimiento
    // ------------------------------------------------------------------

    /**
     * Momento pactado de llegada. Las llegadas son el mismo día del evento; solo si el evento es de noche
     * y la hora pactada es de madrugada (antes de las 6:00), es del día siguiente.
     */
    public static function momentoLlegada(string $fecha, string $horaInicio, string $horaLlegada): DateTimeImmutable
    {
        $d = new DateTimeImmutable($fecha . ' ' . substr($horaLlegada, 0, 5));
        if (substr($horaLlegada, 0, 5) < '06:00' && substr($horaInicio, 0, 5) >= '12:00') $d = $d->modify('+1 day');
        return $d;
    }

    /**
     * Puntualidad de un proveedor del evento (fila con fecha, hora_inicio, hora_llegada, llegada_real, cumplimiento).
     * $tolerancia = minutos del negocio (tolerancia()); null = la recomendada.
     * @return ?array{estado:string, min:?int, texto:string}  null si no hay cómo medirla
     */
    public static function puntualidad(array $ep, ?int $tolerancia = null): ?array
    {
        $tolerancia ??= PuntajeProveedores::RECOMENDADO['tolerancia_min'];
        if (($ep['cumplimiento'] ?? null) === 'no_llego') return ['estado' => 'no_llego', 'min' => null, 'texto' => 'No llegó'];
        if (empty($ep['llegada_real']) || empty($ep['hora_llegada'])) return null;
        $plan = self::momentoLlegada((string) $ep['fecha'], (string) $ep['hora_inicio'], (string) $ep['hora_llegada']);
        $min = (int) round(((new DateTimeImmutable((string) $ep['llegada_real']))->getTimestamp() - $plan->getTimestamp()) / 60);
        if ($min <= $tolerancia) {
            return ['estado' => 'a_tiempo', 'min' => $min, 'texto' => $min < -1 ? 'A tiempo (' . self::duracion(-$min) . ' antes)' : 'A tiempo'];
        }
        return ['estado' => 'tarde', 'min' => $min, 'texto' => 'Tarde ' . self::duracion($min)];
    }

    /** 25 -> "25 min", 125 -> "2 h 5 min", 120 -> "2 h" */
    public static function duracion(int $min): string
    {
        $h = intdiv($min, 60);
        return $h ? trim($h . ' h ' . ($min % 60 ? ($min % 60) . ' min' : '')) : $min . ' min';
    }

    private function eventoProveedor(int $negocioId, int $epId): array
    {
        $r = $this->q('SELECT ep.*, e.fecha, e.hora_inicio, e.hora_fin, e.estado AS evento_estado, e.nombre AS evento, e.codigo, e.negocio_id, e.coordinador_id
                         FROM evento_proveedores ep JOIN eventos e ON e.id = ep.evento_id WHERE ep.id = ? AND e.negocio_id = ?', [$epId, $negocioId])->fetch();
        if (!$r) throw new RuntimeException('No se encontró ese proveedor del evento.');
        return $r;
    }

    /**
     * Marca la hora real de llegada. $hora = 'HH:MM' (la escribe el coordinador) o null = ahora.
     * $origen 'coordinador' o 'proveedor'. Devuelve la puntualidad.
     */
    public function marcarLlegada(int $negocioId, int $epId, ?string $hora = null, ?array $usuario = null, string $origen = 'coordinador',
                                  ?DateTimeImmutable $ahora = null): ?array
    {
        $ep = $this->eventoProveedor($negocioId, $epId);
        $ahora ??= new DateTimeImmutable();
        if ($hora !== null && trim($hora) !== '') {
            $hora = DiaEvento::hora($hora);
            $llegada = self::momentoLlegada($ep['fecha'], $ep['hora_inicio'], $hora);
        } else {
            $llegada = $ahora;
        }
        $this->q('UPDATE evento_proveedores SET llegada_real = ?, llegada_origen = ?, cumplimiento = CASE WHEN cumplimiento = \'no_llego\' THEN NULL ELSE cumplimiento END WHERE id = ?',
                 [$llegada->format('Y-m-d H:i:s'), $origen === 'proveedor' ? 'proveedor' : 'coordinador', $epId]);
        $ep['llegada_real'] = $llegada->format('Y-m-d H:i:s');
        if (($ep['cumplimiento'] ?? null) === 'no_llego') $ep['cumplimiento'] = null;
        $p = self::puntualidad($ep, $this->tolerancia($negocioId));
        if ($ep['proveedor_id']) {
            $this->anotar($negocioId, (int) $ep['proveedor_id'], 'llegada', ($origen === 'proveedor' ? 'Avisó que llegó' : 'Llegó') . ' a las ' . $llegada->format('H:i')
                . ($p ? ' · ' . mb_strtolower($p['texto']) : '') . ' (' . $ep['evento'] . ')', $usuario, $origen === 'proveedor' ? 'proveedor' : 'usuario', $ep['compra_id'] ? (int) $ep['compra_id'] : null, (int) $ep['evento_id']);
        } else {
            $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                     [$negocioId, $ep['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', mb_substr($ep['nombre'] . ' llegó a las ' . $llegada->format('H:i') . ($p ? ' (' . mb_strtolower($p['texto']) . ')' : ''), 0, 400)]);
        }
        return $p;
    }

    public function quitarLlegada(int $negocioId, int $epId, ?array $usuario = null): void
    {
        $ep = $this->eventoProveedor($negocioId, $epId);
        $this->q('UPDATE evento_proveedores SET llegada_real = NULL, llegada_origen = NULL WHERE id = ?', [$epId]);
        if ($ep['proveedor_id'] && $ep['llegada_real']) {
            $this->anotar($negocioId, (int) $ep['proveedor_id'], 'llegada', 'Se borró la hora de llegada (' . $ep['evento'] . ')', $usuario, 'usuario', null, (int) $ep['evento_id']);
        }
    }

    /** Después del evento: cumplimiento, calificación 1-5 y comentario. */
    public function evaluar(int $negocioId, int $epId, array $d, ?array $usuario = null): void
    {
        $ep = $this->eventoProveedor($negocioId, $epId);
        $cum = trim((string) ($d['cumplimiento'] ?? ''));
        if ($cum !== '' && !isset(self::CUMPLIMIENTO[$cum])) throw new RuntimeException('Elige si cumplió.');
        $cal = trim((string) ($d['calificacion'] ?? ''));
        if ($cal !== '' && (!ctype_digit($cal) || (int) $cal < 1 || (int) $cal > 5)) throw new RuntimeException('La calificación va de 1 a 5 estrellas.');
        if ($cum === '' && $cal === '') throw new RuntimeException('Elige si cumplió o ponle una calificación.');
        $com = mb_substr(trim((string) ($d['comentario'] ?? '')), 0, 500) ?: null;
        if ($cum === 'no_llego' && $ep['llegada_real']) throw new RuntimeException('Tiene hora de llegada anotada. Bórrala primero si en verdad no llegó.');
        $this->q('UPDATE evento_proveedores SET cumplimiento = ?, calificacion = ?, comentario = ?, calificado_por = ?, calificado_en = now() WHERE id = ?',
                 [$cum ?: null, $cal === '' ? null : (int) $cal, $com, $usuario['id'] ?? null, $epId]);
        if ($ep['proveedor_id']) {
            $this->anotar($negocioId, (int) $ep['proveedor_id'], 'calificacion', 'Evaluación de ' . $ep['evento'] . ': '
                . implode(' · ', array_filter([$cum ? self::CUMPLIMIENTO[$cum] : null, $cal !== '' ? str_repeat('★', (int) $cal) . ' (' . $cal . '/5)' : null, $com])),
                $usuario, 'usuario', $ep['compra_id'] ? (int) $ep['compra_id'] : null, (int) $ep['evento_id']);
        }
    }

    /**
     * Bloques del cronograma de los que el proveedor es responsable, con atraso en minutos (modo en vivo).
     * $tolerancia = la del negocio (si no se pasa, se busca la del negocio del evento).
     * @return list<array{actividad:string, hora:string, atraso:?int, a_tiempo:?bool}>
     */
    public function bloquesDe(int $eventoProveedorId, ?int $tolerancia = null): array
    {
        $res = [];
        $filas = $this->q('SELECT c.actividad, c.hora, c.inicio_real, e.fecha, e.hora_inicio, e.negocio_id FROM cronograma c JOIN eventos e ON e.id = c.evento_id
                            WHERE c.evento_proveedor_id = ? ORDER BY c.orden, c.id', [$eventoProveedorId])->fetchAll();
        if ($filas && $tolerancia === null) $tolerancia = $this->tolerancia((int) $filas[0]['negocio_id']);
        foreach ($filas as $b) {
            $atraso = null;
            if ($b['inicio_real']) {
                $plan = DiaEvento::momento($b['fecha'], $b['hora_inicio'], $b['hora']);
                $atraso = (int) round(((new DateTimeImmutable($b['inicio_real']))->getTimestamp() - $plan->getTimestamp()) / 60);
            }
            $res[] = ['actividad' => $b['actividad'], 'hora' => substr((string) $b['hora'], 0, 5), 'atraso' => $atraso,
                      'a_tiempo' => $atraso === null ? null : $atraso <= $tolerancia];
        }
        return $res;
    }

    /** Eventos del proveedor: próximos y pasados, con su cumplimiento. */
    public function eventosDe(int $negocioId, int $proveedorId, ?DateTimeImmutable $hoy = null): array
    {
        $h = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $filas = $this->q("SELECT ep.*, e.codigo, e.nombre AS evento, e.fecha, e.hora_inicio, e.hora_fin, e.estado AS evento_estado,
                                  co.numero AS compra_numero, co.estado AS compra_estado
                             FROM evento_proveedores ep JOIN eventos e ON e.id = ep.evento_id LEFT JOIN compras co ON co.id = ep.compra_id
                            WHERE e.negocio_id = ? AND ep.proveedor_id = ? AND e.estado NOT IN ('perdido','cancelado')
                         ORDER BY e.fecha DESC, ep.hora_llegada", [$negocioId, $proveedorId])->fetchAll();
        $res = ['proximos' => [], 'pasados' => []];
        $tol = $this->tolerancia($negocioId);
        foreach ($filas as $f) {
            $f['puntualidad'] = self::puntualidad($f, $tol);
            $f['bloques'] = $this->bloquesDe((int) $f['id'], $tol);
            if ($f['fecha'] >= $h) array_unshift($res['proximos'], $f);
            else $res['pasados'][] = $f;
        }
        return $res;
    }

    /** Ítems comprados con fecha y precio, y cuánto cambió el precio contra la compra anterior del mismo ítem. */
    public function historialPrecios(int $negocioId, int $proveedorId): array
    {
        $filas = $this->q("SELECT i.descripcion, i.cantidad, i.precio_unitario, i.con_iva, c.id AS compra_id, c.numero, c.fecha, c.estado,
                                  e.codigo AS evento_codigo, e.nombre AS evento
                             FROM compra_items i JOIN compras c ON c.id = i.compra_id LEFT JOIN eventos e ON e.id = c.evento_id
                            WHERE c.negocio_id = ? AND c.proveedor_id = ? AND c.estado <> 'cancelada'
                         ORDER BY c.fecha, c.id, i.orden", [$negocioId, $proveedorId])->fetchAll();
        $ultimo = [];
        foreach ($filas as &$f) {
            $k = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $f['descripcion'])));
            $f['anterior'] = $ultimo[$k] ?? null;
            $f['cambio_pct'] = $f['anterior'] && $f['anterior'] > 0 ? round(((float) $f['precio_unitario'] - $f['anterior']) * 100 / $f['anterior'], 1) : null;
            $ultimo[$k] = (float) $f['precio_unitario'];
        }
        unset($f);
        return array_reverse($filas);
    }

    // ------------------------------------------------------------------
    // Puntaje (scorecard) y ranking
    // ------------------------------------------------------------------

    /**
     * Puntaje de cada proveedor del negocio: [proveedor_id => tarjeta].
     * La fórmula y sus reglas (pesos, tolerancia, incidencias, niveles, periodo, mínimo de eventos) son las del negocio:
     * PuntajeProveedores::config() / ::calcular(). $cfg permite probar otra configuración sin guardarla (vista previa).
     */
    public function puntajes(int $negocioId, ?DateTimeImmutable $hoy = null, ?array $cfg = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $cfg ??= (new PuntajeProveedores($this->db))->config($negocioId);
        $tol = (int) $cfg['tolerancia_min'];
        $h = $hoy->format('Y-m-d');
        $anio = $hoy->format('Y');
        // Periodo evaluado: desde hace N meses (0 = todo el historial)
        $desde = $cfg['periodo_meses'] > 0 ? $hoy->modify('-' . (int) $cfg['periodo_meses'] . ' months')->format('Y-m-d') : '1900-01-01';
        $res = [];
        foreach ($this->q('SELECT id, nombre, servicio, activo, preferido FROM proveedores WHERE negocio_id = ?', [$negocioId])->fetchAll() as $p) {
            $res[(int) $p['id']] = ['proveedor_id' => (int) $p['id'], 'nombre' => $p['nombre'], 'servicio' => $p['servicio'], 'activo' => (bool) $p['activo'],
                                    'preferido' => (bool) $p['preferido'], 'eventos' => 0, 'proximos' => 0, 'medidas' => 0, 'a_tiempo' => 0, 'puntualidad' => null,
                                    'evaluados' => 0, 'cumplidos' => 0, 'cumplimiento' => null, 'calificacion' => null, 'calificaciones' => 0,
                                    'incidencias' => 0, 'graves' => 0, 'medias' => 0, 'leves' => 0, 'bloques' => 0, 'bloques_a_tiempo' => 0,
                                    'comprado_anio' => 0.0, 'saldo' => 0.0, 'puntaje' => null, 'nivel' => null, 'pocos_datos' => false,
                                    'detalle' => [], 'tope_aplicado' => false, 'minimo_eventos' => (int) $cfg['minimo_eventos']];
        }
        if (!$res) return [];
        $eps = $this->q("SELECT ep.id, ep.proveedor_id, ep.hora_llegada, ep.llegada_real, ep.cumplimiento, ep.calificacion, e.fecha, e.hora_inicio
                           FROM evento_proveedores ep JOIN eventos e ON e.id = ep.evento_id
                          WHERE e.negocio_id = ? AND ep.proveedor_id IS NOT NULL AND e.estado NOT IN ('perdido','cancelado') AND e.fecha >= ?::date",
                        [$negocioId, $desde])->fetchAll();
        $sumaCal = [];
        foreach ($eps as $ep) {
            $pid = (int) $ep['proveedor_id'];
            if (!isset($res[$pid])) continue;
            $r = &$res[$pid];
            if ($ep['fecha'] < $h) $r['eventos']++; else $r['proximos']++;
            $pu = self::puntualidad($ep, $tol);
            if ($pu) { $r['medidas']++; if ($pu['estado'] === 'a_tiempo') $r['a_tiempo']++; }
            if ($ep['cumplimiento']) { $r['evaluados']++; if (in_array($ep['cumplimiento'], ['cumplio', 'observaciones'], true)) $r['cumplidos'] += $ep['cumplimiento'] === 'cumplio' ? 1 : 0.5; }
            if ($ep['calificacion'] !== null) { $r['calificaciones']++; $sumaCal[$pid] = ($sumaCal[$pid] ?? 0) + (int) $ep['calificacion']; }
            unset($r);
        }
        foreach ($this->q("SELECT ep.proveedor_id, c.hora, c.inicio_real, e.fecha, e.hora_inicio FROM cronograma c
                             JOIN evento_proveedores ep ON ep.id = c.evento_proveedor_id JOIN eventos e ON e.id = c.evento_id
                            WHERE e.negocio_id = ? AND c.inicio_real IS NOT NULL AND ep.proveedor_id IS NOT NULL AND e.fecha >= ?::date", [$negocioId, $desde])->fetchAll() as $b) {
            $pid = (int) $b['proveedor_id'];
            if (!isset($res[$pid])) continue;
            $plan = DiaEvento::momento($b['fecha'], $b['hora_inicio'], $b['hora']);
            $atraso = (int) round(((new DateTimeImmutable($b['inicio_real']))->getTimestamp() - $plan->getTimestamp()) / 60);
            if (abs($atraso) > 720) continue;                    // modo en vivo usado de prueba otro día
            $res[$pid]['bloques']++;
            if ($atraso <= $tol) $res[$pid]['bloques_a_tiempo']++;
        }
        // Incidencias del periodo: por la fecha de su evento (o la del reporte si no tiene evento)
        foreach ($this->q("SELECT i.proveedor_id, count(*) AS n, count(*) FILTER (WHERE i.gravedad = 'grave') AS graves, count(*) FILTER (WHERE i.gravedad = 'media') AS medias
                             FROM incidencias i LEFT JOIN eventos e ON e.id = i.evento_id
                            WHERE i.negocio_id = ? AND i.proveedor_id IS NOT NULL AND COALESCE(e.fecha, i.creado_en::date) >= ?::date
                         GROUP BY i.proveedor_id", [$negocioId, $desde])->fetchAll() as $i) {
            $pid = (int) $i['proveedor_id'];
            if (!isset($res[$pid])) continue;
            $res[$pid]['incidencias'] = (int) $i['n'];
            $res[$pid]['graves'] = (int) $i['graves'];
            $res[$pid]['medias'] = (int) $i['medias'];
            $res[$pid]['leves'] = (int) $i['n'] - (int) $i['graves'] - (int) $i['medias'];
        }
        $montos = $this->q("SELECT c.proveedor_id,
                                   COALESCE(sum(" . Compras::SQL_BASE . ") FILTER (WHERE extract(year FROM c.fecha) = ?::int), 0) AS anio,
                                   COALESCE(sum(" . Compras::SQL_SALDO . "), 0) AS saldo
                              FROM compras c WHERE c.negocio_id = ? AND " . Compras::SQL_ACTIVAS . " GROUP BY c.proveedor_id", [$anio, $negocioId])->fetchAll();
        foreach ($montos as $m) {
            $pid = (int) $m['proveedor_id'];
            if (!isset($res[$pid])) continue;
            $res[$pid]['comprado_anio'] = round((float) $m['anio'], 2);
            $res[$pid]['saldo'] = max(0.0, round((float) $m['saldo'], 2));
        }
        foreach ($res as $pid => &$r) {
            $medidas = $r['medidas'] + $r['bloques'];
            $r['puntualidad'] = $medidas ? (int) round(($r['a_tiempo'] + $r['bloques_a_tiempo']) * 100 / $medidas) : null;
            $r['cumplimiento'] = $r['evaluados'] ? (int) round($r['cumplidos'] * 100 / $r['evaluados']) : null;
            $r['calificacion'] = $r['calificaciones'] ? round($sumaCal[$pid] / $r['calificaciones'], 1) : null;
            $r = array_merge($r, PuntajeProveedores::calcular($r, $cfg));
        }
        unset($r);
        return $res;
    }

    public function puntaje(int $negocioId, int $proveedorId, ?DateTimeImmutable $hoy = null, ?array $cfg = null): ?array
    {
        return $this->puntajes($negocioId, $hoy, $cfg)[$proveedorId] ?? null;
    }

    /** Ranking: de mayor a menor puntaje (los que no tienen datos al final). */
    public function ranking(int $negocioId, ?DateTimeImmutable $hoy = null, bool $soloActivos = true, ?array $cfg = null): array
    {
        $lista = array_values(array_filter($this->puntajes($negocioId, $hoy, $cfg), fn($r) => !$soloActivos || $r['activo']));
        usort($lista, fn($a, $b) => [$b['puntaje'] !== null, $b['puntaje'] ?? 0, $b['calificacion'] ?? 0, $b['eventos'], $a['nombre']]
                                <=> [$a['puntaje'] !== null, $a['puntaje'] ?? 0, $a['calificacion'] ?? 0, $a['eventos'], $b['nombre']]);
        return $lista;
    }

    public const NIVELES = PuntajeProveedores::NIVELES;

    /** Lo que se muestra cuando no hay puntaje: "Sin datos suficientes" (no llega al mínimo de eventos) o "Sin datos". */
    public static function textoSinPuntaje(?array $r): string
    {
        return !empty($r['pocos_datos']) ? 'Sin datos suficientes' : 'Sin datos';
    }

    /** "★ 4,5 · 92 pts" para mostrar junto al nombre (vacío si no tiene datos). */
    public static function textoPuntaje(?array $r): string
    {
        if (!$r) return '';
        $partes = [];
        if ($r['calificacion'] !== null) $partes[] = '★ ' . number_format((float) $r['calificacion'], 1, ',', '');
        if ($r['puntaje'] !== null) $partes[] = $r['puntaje'] . ' pts';
        return implode(' · ', $partes);
    }

    // ------------------------------------------------------------------
    // Portal del proveedor (enlace secreto)
    // ------------------------------------------------------------------

    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
        return $this->q('SELECT * FROM proveedores WHERE token = ?', [$token])->fetch() ?: null;
    }

    /** Cambia el enlace del portal (el anterior deja de servir). */
    public function nuevoToken(int $negocioId, int $proveedorId, ?array $usuario = null): string
    {
        $t = bin2hex(random_bytes(24));
        $st = $this->q('UPDATE proveedores SET token = ? WHERE id = ? AND negocio_id = ?', [$t, $proveedorId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el proveedor.');
        $this->anotar($negocioId, $proveedorId, 'portal', 'Cambió el enlace del portal del proveedor (el anterior ya no sirve)', $usuario);
        return $t;
    }

    /** Ventana para "Ya llegué": el día del evento (y hasta las 6:00 del día siguiente si el evento pasa la medianoche). */
    public static function puedeMarcarLlegada(array $ep, ?DateTimeImmutable $ahora = null): bool
    {
        $ahora ??= new DateTimeImmutable();
        if (!empty($ep['llegada_real']) || !in_array($ep['evento_estado'] ?? $ep['estado'] ?? '', ['separado', 'confirmado', 'realizado'], true)) return false;
        $ini = new DateTimeImmutable($ep['fecha'] . ' 00:00');
        return $ahora >= $ini && $ahora < $ini->modify('+1 day +6 hours');
    }

    /** Eventos del proveedor para su portal: desde ayer, con cronograma asignado y coordinador. */
    public function eventosPortal(array $proveedor, ?DateTimeImmutable $hoy = null): array
    {
        $desde = ($hoy ?? new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
        $filas = $this->q("SELECT ep.*, e.codigo, e.nombre AS evento, e.fecha, e.hora_inicio, e.hora_fin, e.estado AS evento_estado, e.invitados,
                                  co.nombre AS coordinador, co.telefono AS coordinador_telefono
                             FROM evento_proveedores ep JOIN eventos e ON e.id = ep.evento_id LEFT JOIN usuarios co ON co.id = e.coordinador_id
                            WHERE ep.proveedor_id = ? AND e.negocio_id = ? AND e.fecha >= ?::date AND e.estado IN ('separado','confirmado','realizado')
                         ORDER BY e.fecha, ep.hora_llegada NULLS LAST", [$proveedor['id'], $proveedor['negocio_id'], $desde])->fetchAll();
        $tol = $this->tolerancia((int) $proveedor['negocio_id']);
        foreach ($filas as &$f) {
            $f['bloques'] = $this->q('SELECT hora, duracion_min, actividad, notas FROM cronograma WHERE evento_proveedor_id = ? ORDER BY orden, id', [$f['id']])->fetchAll();
            $f['puntualidad'] = self::puntualidad($f, $tol);
        }
        unset($f);
        return $filas;
    }

    private function epDelProveedor(array $p, int $epId): array
    {
        $r = $this->q('SELECT ep.*, e.fecha, e.hora_inicio, e.hora_fin, e.estado AS evento_estado, e.nombre AS evento, e.negocio_id, e.coordinador_id
                         FROM evento_proveedores ep JOIN eventos e ON e.id = ep.evento_id WHERE ep.id = ? AND ep.proveedor_id = ? AND e.negocio_id = ?',
                      [$epId, $p['id'], $p['negocio_id']])->fetch();
        if (!$r) throw new RuntimeException('No se encontró ese evento en tu lista.');
        return $r;
    }

    /** Desde el portal: confirma que va al evento. */
    public function portalConfirmarEvento(array $p, int $epId, ?DateTimeImmutable $hoy = null): array
    {
        $ep = $this->epDelProveedor($p, $epId);
        if ($ep['fecha'] < ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d')) throw new RuntimeException('Ese evento ya pasó.');
        if (!$ep['confirmado']) {
            $this->q('UPDATE evento_proveedores SET confirmado = true WHERE id = ?', [$epId]);
            $this->anotar((int) $p['negocio_id'], (int) $p['id'], 'portal', 'Confirmó que va a ' . $ep['evento'] . ' (' . (new DateTimeImmutable($ep['fecha']))->format('d/m/Y') . ')',
                          null, 'proveedor', $ep['compra_id'] ? (int) $ep['compra_id'] : null, (int) $ep['evento_id']);
        }
        return $ep;
    }

    /** Desde el portal: "Ya llegué". Avisa al coordinador del evento. */
    public function portalLlegue(array $p, int $epId, ?DateTimeImmutable $ahora = null): ?array
    {
        $ahora ??= new DateTimeImmutable();
        $ep = $this->epDelProveedor($p, $epId);
        if (!empty($ep['llegada_real'])) throw new RuntimeException('Ya registraste tu llegada a las ' . substr((string) $ep['llegada_real'], 11, 5) . '.');
        if (!self::puedeMarcarLlegada($ep, $ahora)) throw new RuntimeException('"Ya llegué" se marca el mismo día del evento.');
        $pu = $this->marcarLlegada((int) $p['negocio_id'], $epId, null, null, 'proveedor', $ahora);
        $avisos = new Notificaciones($this->db);
        $ids = $ep['coordinador_id'] ? [(int) $ep['coordinador_id']] : $avisos->destinos((int) $p['negocio_id'], ['dueno', 'coordinador']);
        $avisos->aUsuarios((int) $p['negocio_id'], $ids, '🚚 ' . $p['nombre'] . ' llegó a ' . $ep['evento'] . ' a las ' . $ahora->format('H:i')
            . ($pu ? ' (' . mb_strtolower($pu['texto']) . ')' : '') . '.', (int) $ep['evento_id']);
        return $pu;
    }
}
