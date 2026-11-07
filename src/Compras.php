<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Compras a proveedores (órdenes de compra), sus facturas y los pagos que el negocio les hace.
 *
 *   cotizacion (pedido de precio) -> pedida (enviada) -> confirmada (el proveedor aceptó) -> entregada (cumplió)
 *   o cancelada en cualquier momento (si no tiene pagos).
 *
 * Valor a pagar = total de la factura (si ya llegó) o total de la orden.
 * Saldo = valor a pagar − retención − descuentos por incidencias − pagos válidos (los anulados no cuentan).
 * Estado de pago: pendiente / parcial / pagada / vencida (pasó la fecha límite con saldo).
 * Cuentan como costo del evento y como deuda solo las compras pedidas, confirmadas o entregadas.
 */
final class Compras
{
    public const ESTADOS = [
        'cotizacion' => 'Pedido de precio', 'pedida' => 'Pedida', 'confirmada' => 'Confirmada',
        'entregada' => 'Entregada', 'cancelada' => 'Cancelada',
    ];
    public const ESTADOS_AYUDA = [
        'cotizacion' => 'Solo pediste el precio. Todavía no es una compra (no suma a la deuda ni al costo).',
        'pedida'     => 'Le enviaste la orden al proveedor. Falta que la confirme.',
        'confirmada' => 'El proveedor aceptó la orden.',
        'entregada'  => 'El proveedor cumplió: entregó o hizo el servicio.',
        'cancelada'  => 'Se anuló. No suma a la deuda ni al costo.',
    ];
    /** A qué estados se puede pasar desde cada estado. */
    public const PASOS = [
        'cotizacion' => ['pedida', 'cancelada'],
        'pedida'     => ['confirmada', 'cancelada'],
        'confirmada' => ['entregada', 'pedida', 'cancelada'],
        'entregada'  => ['confirmada'],
        'cancelada'  => ['cotizacion'],
    ];
    public const ACTIVAS = ['pedida', 'confirmada', 'entregada'];
    public const METODOS = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'deuna' => 'DeUna', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta'];
    public const ESTADOS_PAGO = ['pendiente' => 'Pendiente', 'parcial' => 'Abonada', 'pagada' => 'Pagada', 'vencida' => 'Vencida', 'sin_valor' => 'Sin valor', 'no_aplica' => '—'];
    public const TRAMOS = ['al_dia' => 'Al día', '1_30' => '1 a 30 días', '31_60' => '31 a 60 días', '61_90' => '61 a 90 días', 'mas_90' => 'Más de 90 días'];

    // Partes de SQL (alias "c" = compras) que usan otras clases (ganancia, reportes, puntajes)
    public const SQL_BASE = 'COALESCE(c.factura_total, c.total)';
    public const SQL_PAGADO = "(SELECT COALESCE(sum(pp.monto),0) FROM pagos_proveedor pp WHERE pp.compra_id = c.id AND pp.estado = 'valido')";
    public const SQL_DESCUENTOS = '(SELECT COALESCE(sum(ic.descuento),0) FROM incidencias ic WHERE ic.compra_id = c.id)';
    public const SQL_SALDO = "(COALESCE(c.factura_total, c.total) - c.retencion - (SELECT COALESCE(sum(ic.descuento),0) FROM incidencias ic WHERE ic.compra_id = c.id)
                              - (SELECT COALESCE(sum(pp.monto),0) FROM pagos_proveedor pp WHERE pp.compra_id = c.id AND pp.estado = 'valido'))";
    public const SQL_ACTIVAS = "c.estado IN ('pedida','confirmada','entregada')";

    /** Costo de las compras de un evento (para la ganancia real): valor a pagar − descuentos por incidencias. */
    public static function sqlCostoEvento(string $eventoId): string
    {
        return "COALESCE((SELECT sum(COALESCE(c.factura_total, c.total) - (SELECT COALESCE(sum(ic.descuento),0) FROM incidencias ic WHERE ic.compra_id = c.id))
                            FROM compras c WHERE c.evento_id = $eventoId AND c.estado IN ('pedida','confirmada','entregada')), 0)";
    }

    private ProveedoresFicha $ficha;

    public function __construct(private PDO $db)
    {
        $this->ficha = new ProveedoresFicha($db);
    }

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    private function transaccion(callable $f): mixed
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

    public static function codigo(int|string $numero): string
    {
        return 'OC-' . str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }

    private static function fechaValida(string $f, string $que): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $f);
        if (!$d || $d->format('Y-m-d') !== $f) throw new RuntimeException("Revisa la $que.");
        return $f;
    }

    // ------------------------------------------------------------------
    // Consultar
    // ------------------------------------------------------------------

    private const SELECT = "SELECT c.*, p.nombre AS proveedor, p.telefono AS proveedor_telefono, p.contacto AS proveedor_contacto, p.ruc AS proveedor_ruc,
                                   p.credito_dias, p.cupo_credito, p.token AS proveedor_token, p.email AS proveedor_email,
                                   e.codigo AS evento_codigo, e.nombre AS evento, e.fecha AS evento_fecha, e.hora_inicio AS evento_hora, e.estado AS evento_estado,
                                   COALESCE(c.factura_total, c.total) AS base,
                                   (SELECT COALESCE(sum(pp.monto),0) FROM pagos_proveedor pp WHERE pp.compra_id = c.id AND pp.estado = 'valido') AS pagado,
                                   (SELECT COALESCE(sum(ic.descuento),0) FROM incidencias ic WHERE ic.compra_id = c.id) AS descuentos
                              FROM compras c JOIN proveedores p ON p.id = c.proveedor_id LEFT JOIN eventos e ON e.id = c.evento_id";

    /** Agrega a la fila: saldo, estado_pago, dias_vencido, tramo y el código OC. */
    public static function completar(array $c, ?DateTimeImmutable $hoy = null): array
    {
        $h = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        foreach (['base', 'pagado', 'descuentos', 'retencion', 'total', 'subtotal', 'iva', 'anticipo'] as $k) $c[$k] = round((float) ($c[$k] ?? 0), 2);
        $c['codigo'] = self::codigo($c['numero']);
        $c['saldo'] = in_array($c['estado'], self::ACTIVAS, true) ? max(0.0, round($c['base'] - $c['retencion'] - $c['descuentos'] - $c['pagado'], 2)) : 0.0;
        $c['dias_vencido'] = $c['vence'] ? (int) (new DateTimeImmutable($c['vence']))->diff(new DateTimeImmutable($h))->format('%r%a') : null;
        $c['estado_pago'] = self::estadoPago($c, $h);
        $c['tramo'] = self::tramo($c['saldo'] > 0 ? $c['dias_vencido'] : null);
        $c['anticipo_pendiente'] = round(max(0.0, $c['anticipo'] - $c['pagado']), 2);
        return $c;
    }

    public static function estadoPago(array $c, string $hoy): string
    {
        if (!in_array($c['estado'], self::ACTIVAS, true)) return 'no_aplica';
        if ((float) $c['base'] <= 0) return 'sin_valor';
        if ($c['saldo'] <= 0.004) return 'pagada';
        if ($c['vence'] && $c['vence'] < $hoy) return 'vencida';
        return (float) $c['pagado'] > 0 ? 'parcial' : 'pendiente';
    }

    /** Antigüedad del saldo según los días vencido (≤ 0 = al día). */
    public static function tramo(?int $diasVencido): string
    {
        if ($diasVencido === null || $diasVencido <= 0) return 'al_dia';
        return $diasVencido <= 30 ? '1_30' : ($diasVencido <= 60 ? '31_60' : ($diasVencido <= 90 ? '61_90' : 'mas_90'));
    }

    public function compra(int $negocioId, int $id, ?DateTimeImmutable $hoy = null): ?array
    {
        $c = $this->q(self::SELECT . ' WHERE c.id = ? AND c.negocio_id = ?', [$id, $negocioId])->fetch();
        return $c ? self::completar($c, $hoy) : null;
    }

    private function obtener(int $negocioId, int $id): array
    {
        $c = $this->compra($negocioId, $id);
        if (!$c) throw new RuntimeException('No se encontró esa compra.');
        return $c;
    }

    public function items(int $compraId): array
    {
        return $this->q('SELECT * FROM compra_items WHERE compra_id = ? ORDER BY orden, id', [$compraId])->fetchAll();
    }

    /**
     * Compras del negocio. Filtros: estado (o 'activas', 'pendientes' = con saldo), proveedor_id, evento_id, categoria, q (texto), limite.
     */
    public function lista(int $negocioId, array $f = [], ?DateTimeImmutable $hoy = null): array
    {
        $w = ['c.negocio_id = ?'];
        $p = [$negocioId];
        $estado = (string) ($f['estado'] ?? '');
        if (isset(self::ESTADOS[$estado])) { $w[] = 'c.estado = ?'; $p[] = $estado; }
        elseif (in_array($estado, ['activas', 'pendientes'], true)) $w[] = self::SQL_ACTIVAS;
        if (!empty($f['proveedor_id'])) { $w[] = 'c.proveedor_id = ?'; $p[] = (int) $f['proveedor_id']; }
        if (!empty($f['evento_id'])) { $w[] = 'c.evento_id = ?'; $p[] = (int) $f['evento_id']; }
        if (!empty($f['categoria'])) { $w[] = 'c.categoria = ?'; $p[] = (string) $f['categoria']; }
        if (trim((string) ($f['q'] ?? '')) !== '') {
            $w[] = "(p.nombre ILIKE ? OR e.nombre ILIKE ? OR c.factura_numero ILIKE ? OR 'OC-' || lpad(c.numero::text, 4, '0') ILIKE ?)";
            $t = '%' . trim((string) $f['q']) . '%';
            array_push($p, $t, $t, $t, $t);
        }
        $lim = max(1, min(1000, (int) ($f['limite'] ?? 300)));
        $res = array_map(fn($c) => self::completar($c, $hoy),
                         $this->q(self::SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY c.fecha DESC, c.id DESC LIMIT $lim", $p)->fetchAll());
        if ($estado === 'pendientes') $res = array_values(array_filter($res, fn($c) => $c['saldo'] > 0));
        return $res;
    }

    public function deEvento(int $negocioId, int $eventoId, ?DateTimeImmutable $hoy = null): array
    {
        return $this->lista($negocioId, ['evento_id' => $eventoId], $hoy);
    }

    /** Costo de las compras del evento (sin canceladas ni pedidos de precio), sin duplicar los gastos. */
    public function costoEvento(int $negocioId, int $eventoId): float
    {
        return round((float) $this->q('SELECT ' . self::sqlCostoEvento('e.id') . ' FROM eventos e WHERE e.id = ? AND e.negocio_id = ?', [$eventoId, $negocioId])->fetchColumn(), 2);
    }

    // ------------------------------------------------------------------
    // Crear y editar
    // ------------------------------------------------------------------

    /**
     * Ítems limpios: [[descripcion, cantidad, precio_unitario, con_iva], ...]. Las filas vacías se ignoran.
     * @return list<array{descripcion:string, cantidad:float, precio_unitario:float, con_iva:bool}>
     */
    public static function limpiarItems(array $items): array
    {
        $res = [];
        foreach ($items as $i => $it) {
            $desc = trim((string) ($it['descripcion'] ?? ''));
            $cant = trim((string) ($it['cantidad'] ?? ''));
            $precio = trim((string) ($it['precio_unitario'] ?? ''));
            if ($desc === '' && ($precio === '' || Cotizaciones::num($precio) == 0.0)) continue;
            $n = count($res) + 1;
            if (mb_strlen($desc) < 2) throw new RuntimeException("Escribe qué se compra en la línea $n.");
            $c = $cant === '' ? 1.0 : Cotizaciones::num($cant);
            if ($c <= 0 || $c > 99999999) throw new RuntimeException("Revisa la cantidad de «" . mb_substr($desc, 0, 40) . "».");
            $pu = $precio === '' ? 0.0 : Cotizaciones::num($precio);
            if ($pu < 0 || $pu > 99999999) throw new RuntimeException("Revisa el precio de «" . mb_substr($desc, 0, 40) . "».");
            $res[] = ['descripcion' => mb_substr($desc, 0, 300), 'cantidad' => round($c, 2), 'precio_unitario' => round($pu, 4),
                      'con_iva' => !array_key_exists('con_iva', $it) || (bool) $it['con_iva']];
        }
        return $res;
    }

    /** Subtotal, IVA y total de los ítems. */
    public static function totales(array $items, float $ivaPct): array
    {
        $sub = 0.0; $base = 0.0;
        foreach ($items as $it) {
            $l = round($it['cantidad'] * $it['precio_unitario'], 2);
            $sub += $l;
            if ($it['con_iva']) $base += $l;
        }
        $iva = round($base * $ivaPct / 100, 2);
        return ['subtotal' => round($sub, 2), 'base_iva' => round($base, 2), 'iva' => $iva, 'total' => round($sub + $iva, 2)];
    }

    private function proveedorDe(int $negocioId, int $proveedorId): array
    {
        $p = $this->ficha->proveedor($negocioId, $proveedorId);
        if (!$p) throw new RuntimeException('Elige el proveedor.');
        return $p;
    }

    private function eventoDe(int $negocioId, ?int $eventoId): ?array
    {
        if (!$eventoId) return null;
        $e = $this->q('SELECT id, codigo, nombre, fecha, estado FROM eventos WHERE id = ? AND negocio_id = ?', [$eventoId, $negocioId])->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    /**
     * Crea una compra. $d: proveedor_id, evento_id (opcional), categoria, fecha, estado (cotizacion|pedida|confirmada),
     * anticipo, vence (opcional: si no, fecha + días de crédito), retencion, notas. Devuelve el id.
     */
    public function crear(int $negocioId, array $d, array $items, ?array $usuario = null, ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        $prov = $this->proveedorDe($negocioId, (int) ($d['proveedor_id'] ?? 0));
        if (!$prov['activo']) throw new RuntimeException($prov['nombre'] . ' está desactivado. Actívalo en Proveedores para comprarle.');
        $ev = $this->eventoDe($negocioId, (int) ($d['evento_id'] ?? 0) ?: null);
        if ($ev && in_array($ev['estado'], ['perdido', 'cancelado'], true)) throw new RuntimeException('Ese evento está ' . Eventos::ESTADOS[$ev['estado']] . '.');
        $estado = (string) ($d['estado'] ?? 'pedida');
        if (!in_array($estado, ['cotizacion', 'pedida', 'confirmada'], true)) throw new RuntimeException('Elige si es un pedido de precio o una compra.');
        $cat = (string) ($d['categoria'] ?? '') ?: (string) $prov['servicio'];
        if (!isset(Cotizaciones::CATEGORIAS[$cat])) $cat = 'otros';
        $fecha = trim((string) ($d['fecha'] ?? '')) ?: $hoy->format('Y-m-d');
        self::fechaValida($fecha, 'fecha de la compra');
        $items = self::limpiarItems($items);
        if (!$items) throw new RuntimeException('Agrega al menos una línea con lo que compras (por ejemplo: 200 sillas Tiffany a $1,50).');
        $iva = (float) $this->q('SELECT iva_pct FROM negocios WHERE id = ?', [$negocioId])->fetchColumn();
        $t = self::totales($items, $iva);
        $anticipo = trim((string) ($d['anticipo'] ?? '')) === '' ? round($t['total'] * (float) $prov['anticipo_pct'] / 100, 2) : round(Cotizaciones::num($d['anticipo']), 2);
        if ($anticipo < 0 || $anticipo > $t['total']) throw new RuntimeException('El anticipo no puede ser mayor que el total de la compra.');
        $ret = trim((string) ($d['retencion'] ?? '')) === '' ? 0.0 : round(Cotizaciones::num($d['retencion']), 2);
        if ($ret < 0 || $ret > $t['total']) throw new RuntimeException('La retención no puede ser mayor que el total.');
        $venceTxt = trim((string) ($d['vence'] ?? ''));
        $manual = $venceTxt !== '';
        $vence = $manual ? self::fechaValida($venceTxt, 'fecha límite de pago')
                         : (new DateTimeImmutable($fecha))->modify('+' . (int) $prov['credito_dias'] . ' days')->format('Y-m-d');
        $notas = mb_substr(trim((string) ($d['notas'] ?? '')), 0, 2000) ?: null;
        return $this->transaccion(function () use ($negocioId, $prov, $ev, $estado, $cat, $fecha, $items, $iva, $t, $anticipo, $ret, $vence, $manual, $notas, $usuario) {
            $this->q('SELECT id FROM negocios WHERE id = ? FOR UPDATE', [$negocioId]);
            $num = (int) $this->q('SELECT COALESCE(max(numero),0) + 1 FROM compras WHERE negocio_id = ?', [$negocioId])->fetchColumn();
            $id = (int) $this->q("INSERT INTO compras (negocio_id, numero, proveedor_id, evento_id, categoria, fecha, estado, iva_pct, subtotal, iva, total, anticipo,
                                                       vence, vence_manual, retencion, notas, enviada_en, confirmada_en, confirmada_por, creado_por)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id",
                                 [$negocioId, $num, $prov['id'], $ev['id'] ?? null, $cat, $fecha, $estado, $iva, $t['subtotal'], $t['iva'], $t['total'], $anticipo,
                                  $vence, $manual ? 'true' : 'false', $ret, $notas,
                                  $estado !== 'cotizacion' ? date('Y-m-d H:i:s') : null, $estado === 'confirmada' ? date('Y-m-d H:i:s') : null,
                                  $estado === 'confirmada' ? 'negocio' : null, $usuario['id'] ?? null])->fetchColumn();
            $this->insertarItems($id, $items);
            $this->ficha->anotar($negocioId, (int) $prov['id'], 'compra', ($estado === 'cotizacion' ? 'Pidió precio ' : 'Creó la compra ') . self::codigo($num)
                . ' por ' . Pdf::dinero($t['total']) . ($ev ? ' para ' . $ev['nombre'] : ' (compra general)') . ' · ' . self::ESTADOS[$estado], $usuario, 'usuario', $id, $ev ? (int) $ev['id'] : null);
            if ($ev && $estado !== 'cotizacion') $this->vincularEvento($negocioId, $id);
            return $id;
        });
    }

    private function insertarItems(int $compraId, array $items): void
    {
        $this->q('DELETE FROM compra_items WHERE compra_id = ?', [$compraId]);
        $ins = $this->db->prepare('INSERT INTO compra_items (compra_id, descripcion, cantidad, precio_unitario, con_iva, subtotal, orden) VALUES (?,?,?,?,?,?,?)');
        foreach (array_values($items) as $i => $it) {
            $ins->execute([$compraId, $it['descripcion'], $it['cantidad'], $it['precio_unitario'], $it['con_iva'] ? 'true' : 'false', round($it['cantidad'] * $it['precio_unitario'], 2), $i]);
        }
    }

    /** Cambia los ítems (recalcula totales). No deja que el total quede por debajo de lo ya pagado. */
    public function guardarItems(int $negocioId, int $compraId, array $items, ?array $usuario = null): void
    {
        $c = $this->obtener($negocioId, $compraId);
        if ($c['estado'] === 'cancelada') throw new RuntimeException('Esta compra está cancelada. Reábrela para cambiarla.');
        $items = self::limpiarItems($items);
        if (!$items) throw new RuntimeException('La compra necesita al menos una línea.');
        $t = self::totales($items, (float) $c['iva_pct']);
        $base = $c['factura_total'] !== null ? (float) $c['factura_total'] : $t['total'];
        if ($base - $c['retencion'] - $c['descuentos'] - $c['pagado'] < -0.004) {
            throw new RuntimeException('Con ese total, lo ya pagado (' . Pdf::dinero($c['pagado']) . ') quedaría mayor que lo que se debe. Anula un pago primero.');
        }
        $this->transaccion(function () use ($c, $compraId, $items, $t, $negocioId, $usuario) {
            $this->insertarItems($compraId, $items);
            $this->q('UPDATE compras SET subtotal = ?, iva = ?, total = ?, anticipo = LEAST(anticipo, ?), retencion = LEAST(retencion, GREATEST(?, COALESCE(factura_total, 0))), actualizado_en = now() WHERE id = ?',
                     [$t['subtotal'], $t['iva'], $t['total'], $t['total'], $t['total'], $compraId]);
            if (abs($t['total'] - $c['total']) > 0.004) {
                $this->ficha->anotar($negocioId, (int) $c['proveedor_id'], 'compra', 'Cambió el detalle de ' . $c['codigo'] . ': total de ' . Pdf::dinero($c['total']) . ' a ' . Pdf::dinero($t['total']),
                                     $usuario, 'usuario', $compraId, $c['evento_id'] ? (int) $c['evento_id'] : null);
            }
        });
    }

    /** Cabecera: evento_id, categoria, fecha, anticipo, vence, retencion, notas. */
    public function editar(int $negocioId, int $compraId, array $d, ?array $usuario = null): void
    {
        $c = $this->obtener($negocioId, $compraId);
        if ($c['estado'] === 'cancelada') throw new RuntimeException('Esta compra está cancelada. Reábrela para cambiarla.');
        $ev = array_key_exists('evento_id', $d) ? $this->eventoDe($negocioId, (int) $d['evento_id'] ?: null) : ($c['evento_id'] ? $this->eventoDe($negocioId, (int) $c['evento_id']) : null);
        $cat = (string) ($d['categoria'] ?? $c['categoria']);
        if (!isset(Cotizaciones::CATEGORIAS[$cat])) $cat = $c['categoria'];
        $fecha = self::fechaValida(trim((string) ($d['fecha'] ?? '')) ?: $c['fecha'], 'fecha de la compra');
        $anticipo = array_key_exists('anticipo', $d) ? round(Cotizaciones::num($d['anticipo'] === '' ? 0 : $d['anticipo']), 2) : $c['anticipo'];
        if ($anticipo < 0 || $anticipo > $c['total'] + 0.004) throw new RuntimeException('El anticipo no puede ser mayor que el total de la compra.');
        $ret = array_key_exists('retencion', $d) ? round(Cotizaciones::num($d['retencion'] === '' ? 0 : $d['retencion']), 2) : $c['retencion'];
        if ($ret < 0 || $ret > $c['base'] + 0.004) throw new RuntimeException('La retención no puede ser mayor que el valor a pagar.');
        if ($c['base'] - $ret - $c['descuentos'] - $c['pagado'] < -0.004) throw new RuntimeException('Con esa retención, lo pagado quedaría mayor que lo que se debe.');
        $venceTxt = trim((string) ($d['vence'] ?? ''));
        $manual = $venceTxt !== '' && $venceTxt !== $c['vence'] ? true : (bool) $c['vence_manual'];
        if ($venceTxt === '') {
            $base = $c['factura_fecha'] ?: $fecha;
            $vence = (new DateTimeImmutable($base))->modify('+' . (int) $c['credito_dias'] . ' days')->format('Y-m-d');
            $manual = false;
        } else {
            $vence = self::fechaValida($venceTxt, 'fecha límite de pago');
        }
        $notas = array_key_exists('notas', $d) ? (mb_substr(trim((string) $d['notas']), 0, 2000) ?: null) : $c['notas'];
        $this->q('UPDATE compras SET evento_id = ?, categoria = ?, fecha = ?, anticipo = ?, retencion = ?, vence = ?, vence_manual = ?, notas = ?, actualizado_en = now() WHERE id = ?',
                 [$ev['id'] ?? null, $cat, $fecha, $anticipo, $ret, $vence, $manual ? 'true' : 'false', $notas, $compraId]);
        $cambios = [];
        if ((int) ($ev['id'] ?? 0) !== (int) ($c['evento_id'] ?? 0)) $cambios[] = $ev ? 'evento ' . $ev['nombre'] : 'ya no es de un evento';
        if ($vence !== $c['vence']) $cambios[] = 'fecha límite ' . (new DateTimeImmutable($vence))->format('d/m/Y');
        if (abs($ret - $c['retencion']) > 0.004) $cambios[] = 'retención ' . Pdf::dinero($ret);
        if (abs($anticipo - $c['anticipo']) > 0.004) $cambios[] = 'anticipo ' . Pdf::dinero($anticipo);
        if ($cambios) {
            $this->ficha->anotar($negocioId, (int) $c['proveedor_id'], 'condiciones', 'Cambió ' . $c['codigo'] . ': ' . implode(', ', $cambios), $usuario, 'usuario', $compraId, $ev ? (int) $ev['id'] : null);
        }
        if ($ev && in_array($c['estado'], self::ACTIVAS, true)) $this->vincularEvento($negocioId, $compraId);
    }

    /**
     * Cambia el estado. $origen 'negocio' o 'proveedor' (desde su portal). $motivo: obligatorio al cancelar.
     * Devuelve un aviso opcional (por ejemplo, si pasó el cupo de crédito).
     */
    public function cambiarEstado(int $negocioId, int $compraId, string $nuevo, ?array $usuario = null, string $motivo = '', string $origen = 'negocio'): ?string
    {
        $c = $this->obtener($negocioId, $compraId);
        if (!isset(self::ESTADOS[$nuevo])) throw new RuntimeException('Estado no válido.');
        if ($c['estado'] === $nuevo) return null;
        if (!in_array($nuevo, self::PASOS[$c['estado']], true)) {
            throw new RuntimeException('Una compra ' . mb_strtolower(self::ESTADOS[$c['estado']]) . ' no puede pasar a ' . mb_strtolower(self::ESTADOS[$nuevo]) . '.');
        }
        $motivo = mb_substr(trim($motivo), 0, 300);
        if ($nuevo === 'cancelada') {
            if ($c['pagado'] > 0) throw new RuntimeException('Esta compra tiene pagos por ' . Pdf::dinero($c['pagado']) . '. Anúlalos (o registra la devolución) antes de cancelarla.');
            if (mb_strlen($motivo) < 3) throw new RuntimeException('Escribe por qué se cancela.');
        }
        $marca = match ($nuevo) {
            'pedida' => ', enviada_en = COALESCE(enviada_en, now()), confirmada_en = NULL, confirmada_por = NULL',
            'confirmada' => ", confirmada_en = now(), confirmada_por = '" . ($origen === 'proveedor' ? 'proveedor' : 'negocio') . "', entregada_en = NULL",
            'entregada' => ', entregada_en = now()',
            'cancelada' => ', cancelada_en = now()',
            'cotizacion' => ', cancelada_en = NULL, motivo_cancelacion = NULL, elegida = false',
        };
        $this->transaccion(function () use ($c, $compraId, $nuevo, $marca, $motivo, $origen, $negocioId, $usuario) {
            $this->q("UPDATE compras SET estado = ?, actualizado_en = now()$marca" . ($nuevo === 'cancelada' ? ', motivo_cancelacion = ?' : '')
                     . ($origen === 'proveedor' && $motivo !== '' ? ', respuesta_proveedor = ?' : '') . ' WHERE id = ? AND estado = ?',
                     array_merge([$nuevo], $nuevo === 'cancelada' ? [$motivo] : [], $origen === 'proveedor' && $motivo !== '' ? [$motivo] : [], [$compraId, $c['estado']]))
                 ->rowCount() === 1 || throw new RuntimeException('La compra cambió mientras tanto. Vuelve a abrirla.');
            $texto = match ($nuevo) {
                'pedida' => $c['estado'] === 'confirmada' ? 'Volvió ' . $c['codigo'] . ' a pedida' : 'Envió la orden ' . $c['codigo'] . ' al proveedor',
                'confirmada' => $c['estado'] === 'entregada' ? 'Deshizo la entrega de ' . $c['codigo'] : ($origen === 'proveedor' ? 'Confirmó la orden ' : 'Marcó confirmada la orden ') . $c['codigo'],
                'entregada' => 'Marcó entregada ' . $c['codigo'] . ' (cumplió)',
                'cancelada' => ($origen === 'proveedor' ? 'Rechazó la orden ' : 'Canceló ') . $c['codigo'],
                'cotizacion' => 'Reabrió ' . $c['codigo'] . ' como pedido de precio',
            };
            if ($motivo !== '') $texto .= ': ' . $motivo;
            $this->ficha->anotar($negocioId, (int) $c['proveedor_id'], 'compra', $texto, $usuario, $origen === 'proveedor' ? 'proveedor' : 'usuario', $compraId, $c['evento_id'] ? (int) $c['evento_id'] : null);
            if ($c['evento_id'] && in_array($nuevo, self::ACTIVAS, true)) $this->vincularEvento($negocioId, $compraId);
            if ($nuevo === 'cancelada') $this->q('UPDATE evento_proveedores SET compra_id = NULL WHERE compra_id = ?', [$compraId]);
        });
        if (in_array($nuevo, self::ACTIVAS, true) && !in_array($c['estado'], self::ACTIVAS, true)) return $this->avisoCupo($negocioId, (int) $c['proveedor_id']);
        return null;
    }

    /** Si el saldo con el proveedor pasa su cupo de crédito, devuelve el aviso. */
    public function avisoCupo(int $negocioId, int $proveedorId): ?string
    {
        $p = $this->ficha->proveedor($negocioId, $proveedorId);
        if (!$p || $p['cupo_credito'] === null) return null;
        $saldo = $this->saldoProveedor($negocioId, $proveedorId);
        if ($saldo <= (float) $p['cupo_credito'] + 0.004) return null;
        return 'Ojo: con esta compra le debes ' . Pdf::dinero($saldo) . ' a ' . $p['nombre'] . ', más que su cupo de crédito de ' . Pdf::dinero($p['cupo_credito']) . '.';
    }

    /**
     * Se asegura de que el proveedor de la compra esté en los proveedores del evento (día del evento), unido a la compra.
     * Si ya estaba, solo lo une; si la compra está confirmada, lo marca confirmado.
     */
    private function vincularEvento(int $negocioId, int $compraId): void
    {
        $c = $this->q('SELECT c.*, p.nombre, p.servicio, p.telefono FROM compras c JOIN proveedores p ON p.id = c.proveedor_id WHERE c.id = ? AND c.negocio_id = ?',
                      [$compraId, $negocioId])->fetch();
        if (!$c || !$c['evento_id']) return;
        $confirmada = in_array($c['estado'], ['confirmada', 'entregada'], true);
        $ep = $this->q('SELECT id, compra_id FROM evento_proveedores WHERE evento_id = ? AND proveedor_id = ? ORDER BY (compra_id = ?) DESC NULLS LAST, id LIMIT 1',
                       [$c['evento_id'], $c['proveedor_id'], $compraId])->fetch();
        if ($ep) {
            $this->q('UPDATE evento_proveedores SET compra_id = CASE WHEN compra_id IS NULL OR compra_id = ? THEN ? ELSE compra_id END,
                             confirmado = confirmado OR ? WHERE id = ?', [$compraId, $compraId, $confirmada ? 'true' : 'false', $ep['id']]);
            return;
        }
        $this->q('INSERT INTO evento_proveedores (evento_id, proveedor_id, nombre, servicio, telefono, confirmado, compra_id, notas) VALUES (?,?,?,?,?,?,?,?)',
                 [$c['evento_id'], $c['proveedor_id'], mb_substr((string) $c['nombre'], 0, 150), $c['servicio'], $c['telefono'], $confirmada ? 'true' : 'false', $compraId,
                  'Compra ' . self::codigo($c['numero'])]);
    }

    // ------------------------------------------------------------------
    // Comparar precios (pedidos de precio del mismo evento y rubro)
    // ------------------------------------------------------------------

    /** Pedidos de precio que se pueden comparar: mismo evento y misma categoría. */
    public function comparables(int $negocioId, int $eventoId, string $categoria): array
    {
        $res = $this->lista($negocioId, ['evento_id' => $eventoId, 'categoria' => $categoria, 'estado' => 'cotizacion']);
        foreach ($res as &$c) $c['items'] = $this->items((int) $c['id']);
        unset($c);
        usort($res, fn($a, $b) => $a['total'] <=> $b['total']);
        return $res;
    }

    /** Grupos con 2 o más pedidos de precio para comparar: [[evento_id, evento, categoria, cuantos, minimo, maximo], ...] */
    public function gruposComparables(int $negocioId): array
    {
        return $this->q("SELECT c.evento_id, e.codigo, e.nombre AS evento, e.fecha, c.categoria, count(*) AS cuantos, min(c.total) AS minimo, max(c.total) AS maximo
                           FROM compras c JOIN eventos e ON e.id = c.evento_id
                          WHERE c.negocio_id = ? AND c.estado = 'cotizacion' GROUP BY c.evento_id, e.id, c.categoria HAVING count(*) >= 2
                       ORDER BY e.fecha, c.categoria", [$negocioId])->fetchAll();
    }

    /** Elige un pedido de precio: pasa a pedida y los demás del mismo evento y rubro se cancelan. Devuelve cuántos se cancelaron. */
    public function elegirCotizacion(int $negocioId, int $compraId, ?array $usuario = null): int
    {
        $c = $this->obtener($negocioId, $compraId);
        if ($c['estado'] !== 'cotizacion') throw new RuntimeException('Solo se puede elegir un pedido de precio.');
        if (!$c['evento_id']) throw new RuntimeException('Para comparar, el pedido de precio debe ser de un evento.');
        return $this->transaccion(function () use ($c, $compraId, $negocioId, $usuario) {
            $otros = $this->q("SELECT id FROM compras WHERE negocio_id = ? AND evento_id = ? AND categoria = ? AND estado = 'cotizacion' AND id <> ?",
                              [$negocioId, $c['evento_id'], $c['categoria'], $compraId])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($otros as $o) {
                $this->cambiarEstado($negocioId, (int) $o, 'cancelada', $usuario, 'Se eligió ' . $c['codigo'] . ' de ' . $c['proveedor'] . ' al comparar precios');
            }
            $this->q('UPDATE compras SET elegida = true WHERE id = ?', [$compraId]);
            $this->cambiarEstado($negocioId, $compraId, 'pedida', $usuario);
            $this->ficha->anotar($negocioId, (int) $c['proveedor_id'], 'compra', 'Se eligió su precio (' . $c['codigo'] . ', ' . Pdf::dinero($c['total']) . ') al comparar con ' . count($otros)
                . (count($otros) === 1 ? ' proveedor más' : ' proveedores más'), $usuario, 'usuario', $compraId, (int) $c['evento_id']);
            return count($otros);
        });
    }

    // ------------------------------------------------------------------
    // Factura del proveedor
    // ------------------------------------------------------------------

    /** "001-001-000000123" desde 001001000000123, 1-1-123, etc. Vacío = null. */
    public static function numeroFactura(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^\d{15}$/', $v)) return substr($v, 0, 3) . '-' . substr($v, 3, 3) . '-' . substr($v, 6);
        if (preg_match('/^(\d{1,3})\s*-\s*(\d{1,3})\s*-\s*(\d{1,9})$/', $v, $m)) {
            return str_pad($m[1], 3, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 3, '0', STR_PAD_LEFT) . '-' . str_pad($m[3], 9, '0', STR_PAD_LEFT);
        }
        throw new RuntimeException('El número de factura se escribe así: 001-001-000000123.');
    }

    /**
     * Registra los datos de la factura (número, fecha, total) y, si llega, el archivo (PDF o foto).
     * $origen 'negocio' o 'proveedor'. Si no se cambió a mano, la fecha límite pasa a ser fecha de factura + días de crédito.
     */
    public function registrarFactura(int $negocioId, int $compraId, array $d, ?array $archivo = null, ?array $usuario = null, string $origen = 'negocio',
                                     ?string $xmlGuardado = null, ?string $clave = null): void
    {
        $c = $this->obtener($negocioId, $compraId);
        if (!in_array($c['estado'], self::ACTIVAS, true)) throw new RuntimeException('La factura se registra en una compra pedida, confirmada o entregada.');
        $numero = self::numeroFactura($d['numero'] ?? null) ?? $c['factura_numero'];
        $fechaTxt = trim((string) ($d['fecha'] ?? ''));
        $fecha = $fechaTxt !== '' ? self::fechaValida($fechaTxt, 'fecha de la factura') : $c['factura_fecha'];
        $totalTxt = trim((string) ($d['total'] ?? ''));
        $total = $totalTxt !== '' ? round(Cotizaciones::num($totalTxt), 2) : ($c['factura_total'] !== null ? (float) $c['factura_total'] : null);
        if ($total !== null && ($total < 0 || $total > 99999999)) throw new RuntimeException('Revisa el total de la factura.');
        $hayArchivo = Adjuntos::llego($archivo);
        if ($numero === null && !$hayArchivo && $xmlGuardado === null) throw new RuntimeException('Escribe el número de la factura o sube el archivo.');
        if ($numero !== null) {
            $dup = $this->q("SELECT numero FROM compras WHERE negocio_id = ? AND proveedor_id = ? AND factura_numero = ? AND id <> ? AND estado <> 'cancelada'",
                            [$negocioId, $c['proveedor_id'], $numero, $compraId])->fetchColumn();
            if ($dup !== false) throw new RuntimeException('La factura ' . $numero . ' de este proveedor ya está registrada en ' . self::codigo($dup) . '.');
        }
        if ($total !== null && $total - $c['retencion'] - $c['descuentos'] - $c['pagado'] < -0.004) {
            throw new RuntimeException('El total de la factura (' . Pdf::dinero($total) . ') es menor que lo ya pagado. Revisa el valor.');
        }
        $guardado = $hayArchivo ? Adjuntos::guardar($archivo, $xmlGuardado === null ? ['imagen', 'pdf', 'xml'] : ['imagen', 'pdf']) : null;
        if ($guardado && $guardado['tipo'] === 'xml') {
            // Subió el XML del SRI en el campo del archivo: manda lo que dice el XML (número, fecha y total)
            try {
                $this->importarXml($negocioId, $compraId, (string) Adjuntos::leer($guardado['archivo']), $usuario, $origen, $guardado['archivo']);
            } catch (\Throwable $e) {
                Adjuntos::borrar($guardado['archivo']);
                throw $e;
            }
            return;
        }
        $vence = $c['vence'];
        if (!$c['vence_manual'] && $fecha) $vence = (new DateTimeImmutable($fecha))->modify('+' . (int) $c['credito_dias'] . ' days')->format('Y-m-d');
        $this->q('UPDATE compras SET factura_numero = ?, factura_fecha = ?, factura_total = ?, vence = ?, factura_origen = ?,
                         factura_archivo = COALESCE(?, factura_archivo), factura_xml = COALESCE(?, factura_xml), factura_clave = COALESCE(?, factura_clave),
                         retencion = LEAST(retencion, GREATEST(total, COALESCE(?::numeric, 0))), actualizado_en = now() WHERE id = ?',
                 [$numero, $fecha, $total, $vence, $origen === 'proveedor' ? 'proveedor' : 'negocio', $guardado['archivo'] ?? null, $xmlGuardado, $clave, $total, $compraId]);
        if ($guardado && $c['factura_archivo'] && $c['factura_archivo'] !== $guardado['archivo']) Adjuntos::borrar($c['factura_archivo']);
        $this->ficha->anotar($negocioId, (int) $c['proveedor_id'], 'factura', ($origen === 'proveedor' ? 'Subió su factura ' : 'Registró la factura ') . ($numero ?? '(sin número)')
            . ' en ' . $c['codigo'] . ($total !== null ? ' por ' . Pdf::dinero($total) : '') . ($xmlGuardado ? ' (XML del SRI)' : ($guardado ? ' (con archivo)' : '')),
            $usuario, $origen === 'proveedor' ? 'proveedor' : 'usuario', $compraId, $c['evento_id'] ? (int) $c['evento_id'] : null);
    }

    /**
     * Lee el XML de la factura del SRI y lo pone en la compra (número, fecha, total, clave). Revisa que el RUC sea el del proveedor
     * (si el proveedor no tenía RUC, se le pone). Si la compra no tenía ítems con valor, toma los de la factura.
     * Devuelve avisos (por ejemplo, si la factura no está a nombre del negocio o si el total no cuadra con la orden).
     * @return list<string>
     */
    public function importarXml(int $negocioId, int $compraId, string $contenido, ?array $usuario = null, string $origen = 'negocio', ?string $archivoGuardado = null): array
    {
        $c = $this->obtener($negocioId, $compraId);
        $f = FacturaXml::leer($contenido);
        $avisos = [];
        $prov = $this->proveedorDe($negocioId, (int) $c['proveedor_id']);
        $rucProv = (string) ($prov['ruc'] ?? '');
        $coincide = $rucProv === '' || $rucProv === $f['ruc_emisor'] || (strlen($rucProv) === 10 && $rucProv . '001' === $f['ruc_emisor']);
        if (!$coincide) {
            throw new RuntimeException('La factura es del RUC ' . $f['ruc_emisor'] . ' (' . $f['razon_emisor'] . '), pero ' . $prov['nombre'] . ' tiene el RUC ' . $rucProv . '. Revisa que sea la factura correcta.');
        }
        $negocio = $this->q('SELECT ruc, razon_social, nombre FROM negocios WHERE id = ?', [$negocioId])->fetch();
        if (!empty($negocio['ruc']) && $f['ruc_comprador'] !== '' && $f['ruc_comprador'] !== $negocio['ruc']) {
            $avisos[] = 'La factura está a nombre de ' . ($f['razon_comprador'] ?: $f['ruc_comprador']) . ' (' . $f['ruc_comprador'] . '), no del RUC de tu negocio (' . $negocio['ruc'] . ').';
        }
        $this->transaccion(function () use ($c, $compraId, $f, $rucProv, $prov, $negocioId, $usuario, $origen, $contenido, $archivoGuardado, &$avisos) {
            if ($rucProv === '') {
                $otro = $this->q('SELECT nombre FROM proveedores WHERE negocio_id = ? AND ruc = ? AND id <> ?', [$negocioId, $f['ruc_emisor'], $prov['id']])->fetchColumn();
                if ($otro === false) {
                    $this->q('UPDATE proveedores SET ruc = ?, razon_social = COALESCE(razon_social, ?), direccion = COALESCE(direccion, ?) WHERE id = ?',
                             [$f['ruc_emisor'], mb_substr($f['razon_emisor'], 0, 150) ?: null, mb_substr($f['direccion_emisor'], 0, 250) ?: null, $prov['id']]);
                    $this->ficha->anotar($negocioId, (int) $prov['id'], 'condiciones', 'Se tomó el RUC ' . $f['ruc_emisor'] . ' de su factura electrónica', $usuario, $origen === 'proveedor' ? 'proveedor' : 'usuario');
                }
            }
            $conValor = array_filter($this->items($compraId), fn($i) => (float) $i['precio_unitario'] > 0);
            if (!$conValor && $f['items']) {
                $this->insertarItems($compraId, $f['items']);
                $t = self::totales($f['items'], (float) $c['iva_pct']);
                $this->q('UPDATE compras SET subtotal = ?, iva = ?, total = ? WHERE id = ?', [$t['subtotal'], $t['iva'], $t['total'], $compraId]);
            } elseif (abs($f['total'] - (float) $c['total']) > 0.009) {
                $avisos[] = 'El total de la factura (' . Pdf::dinero($f['total']) . ') no es igual al de la orden (' . Pdf::dinero($c['total']) . '). Se usará el de la factura para el saldo.';
            }
            $archivo = $archivoGuardado ?? Adjuntos::guardarTexto($contenido, 'xml');
            $viejoXml = $c['factura_xml'];
            $this->registrarFactura($negocioId, $compraId, ['numero' => $f['numero'], 'fecha' => $f['fecha'], 'total' => (string) $f['total']], null, $usuario, $origen, $archivo, $f['clave']);
            if ($viejoXml && $viejoXml !== $archivo) Adjuntos::borrar($viejoXml);
        });
        return $avisos;
    }

    /**
     * Compra nueva desde una factura XML (cuando no había orden). $f = FacturaXml::leer(). Queda entregada (ya facturó).
     */
    public function crearDesdeFactura(int $negocioId, int $proveedorId, array $f, string $xml, ?int $eventoId = null, ?array $usuario = null, ?DateTimeImmutable $hoy = null): int
    {
        $prov = $this->proveedorDe($negocioId, $proveedorId);
        if (!empty($prov['ruc']) && $prov['ruc'] !== $f['ruc_emisor'] && $prov['ruc'] . '001' !== $f['ruc_emisor']) {
            throw new RuntimeException('La factura es del RUC ' . $f['ruc_emisor'] . ', pero ' . $prov['nombre'] . ' tiene el RUC ' . $prov['ruc'] . '.');
        }
        $items = $f['items'] ?: [['descripcion' => 'Factura ' . $f['numero'], 'cantidad' => 1, 'precio_unitario' => $f['subtotal'], 'con_iva' => $f['iva'] > 0]];
        return $this->transaccion(function () use ($negocioId, $proveedorId, $f, $xml, $eventoId, $usuario, $hoy, $items) {
            $id = $this->crear($negocioId, ['proveedor_id' => $proveedorId, 'evento_id' => $eventoId, 'fecha' => $f['fecha'], 'estado' => 'confirmada', 'anticipo' => '0'],
                               $items, $usuario, $hoy);
            $this->q("UPDATE compras SET estado = 'entregada', entregada_en = now() WHERE id = ?", [$id]);
            $this->importarXml($negocioId, $id, $xml, $usuario);
            return $id;
        });
    }

    // ------------------------------------------------------------------
    // Pagos al proveedor
    // ------------------------------------------------------------------

    public function pagos(int $negocioId, int $compraId): array
    {
        return $this->q('SELECT pp.*, u.nombre AS registrado, ua.nombre AS anulado_nombre FROM pagos_proveedor pp
                     LEFT JOIN usuarios u ON u.id = pp.registrado_por LEFT JOIN usuarios ua ON ua.id = pp.anulado_por
                         WHERE pp.negocio_id = ? AND pp.compra_id = ? ORDER BY pp.fecha DESC, pp.id DESC', [$negocioId, $compraId])->fetchAll();
    }

    public function pago(int $negocioId, int $pagoId): ?array
    {
        return $this->q('SELECT * FROM pagos_proveedor WHERE id = ? AND negocio_id = ?', [$pagoId, $negocioId])->fetch() ?: null;
    }

    /** Pagos hechos a un proveedor (todas sus compras). */
    public function pagosDeProveedor(int $negocioId, int $proveedorId): array
    {
        return $this->q('SELECT pp.*, c.numero, c.evento_id, e.nombre AS evento, u.nombre AS registrado FROM pagos_proveedor pp JOIN compras c ON c.id = pp.compra_id
                     LEFT JOIN eventos e ON e.id = c.evento_id LEFT JOIN usuarios u ON u.id = pp.registrado_por
                         WHERE pp.negocio_id = ? AND pp.proveedor_id = ? ORDER BY pp.fecha DESC, pp.id DESC', [$negocioId, $proveedorId])->fetchAll();
    }

    /**
     * Registra un pago. $d: monto, fecha, metodo, referencia, cheque_fecha (si es cheque posfechado), notas.
     * No deja pagar más que el saldo. Devuelve el id.
     */
    public function registrarPago(int $negocioId, int $compraId, array $d, ?array $comprobante = null, ?array $usuario = null, ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        $monto = round(Cotizaciones::num($d['monto'] ?? 0), 2);
        if ($monto <= 0) throw new RuntimeException('Escribe el valor del pago.');
        $metodo = (string) ($d['metodo'] ?? 'transferencia');
        if (!isset(self::METODOS[$metodo])) throw new RuntimeException('Elige la forma de pago.');
        $fecha = trim((string) ($d['fecha'] ?? '')) ?: $hoy->format('Y-m-d');
        self::fechaValida($fecha, 'fecha del pago');
        if ($fecha > $hoy->format('Y-m-d')) throw new RuntimeException('La fecha del pago no puede ser futura. Si es un cheque posfechado, pon la fecha de cobro en "Cheque para el".');
        $cheque = trim((string) ($d['cheque_fecha'] ?? ''));
        if ($cheque !== '') {
            if ($metodo !== 'cheque') throw new RuntimeException('La fecha de cobro solo va con cheques.');
            self::fechaValida($cheque, 'fecha del cheque');
            if ($cheque < $fecha) throw new RuntimeException('El cheque no puede cobrarse antes de la fecha del pago.');
        }
        $ref = mb_substr(trim((string) ($d['referencia'] ?? '')), 0, 120) ?: null;
        if ($metodo === 'cheque' && $ref === null) throw new RuntimeException('Escribe el número del cheque en la referencia.');
        $notas = mb_substr(trim((string) ($d['notas'] ?? '')), 0, 300) ?: null;
        $archivo = Adjuntos::llego($comprobante) ? Adjuntos::guardar($comprobante, ['imagen', 'pdf'])['archivo'] : null;
        try {
            return $this->transaccion(function () use ($negocioId, $compraId, $monto, $metodo, $fecha, $cheque, $ref, $notas, $archivo, $usuario, $hoy) {
                $this->q('SELECT id FROM compras WHERE id = ? AND negocio_id = ? FOR UPDATE', [$compraId, $negocioId]);
                $c = $this->obtener($negocioId, $compraId);
                if (!in_array($c['estado'], self::ACTIVAS, true)) {
                    throw new RuntimeException($c['estado'] === 'cotizacion' ? 'Es solo un pedido de precio. Pásalo a compra (pedida) para registrar pagos.' : 'Esta compra está cancelada.');
                }
                if ($c['base'] <= 0) throw new RuntimeException('La compra no tiene valor todavía. Agrega lo que compras.');
                if ($monto > $c['saldo'] + 0.004) {
                    throw new RuntimeException($c['saldo'] <= 0 ? 'Esta compra ya está pagada por completo.' : 'No puedes pagar más que el saldo: se debe ' . Pdf::dinero($c['saldo']) . '.');
                }
                $id = (int) $this->q('INSERT INTO pagos_proveedor (negocio_id, compra_id, proveedor_id, fecha, monto, metodo, referencia, cheque_fecha, comprobante, notas, registrado_por)
                                      VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id',
                                     [$negocioId, $compraId, $c['proveedor_id'], $fecha, $monto, $metodo, $ref, $cheque ?: null, $archivo, $notas, $usuario['id'] ?? null])->fetchColumn();
                $saldo = round($c['saldo'] - $monto, 2);
                $this->ficha->anotar($negocioId, (int) $c['proveedor_id'], 'pago', 'Pagó ' . Pdf::dinero($monto) . ' de ' . $c['codigo'] . ' (' . self::METODOS[$metodo]
                    . ($ref ? ' ' . $ref : '') . ($cheque ? ', cheque para el ' . (new DateTimeImmutable($cheque))->format('d/m/Y') : '') . ') · '
                    . ($saldo <= 0 ? 'quedó pagada' : 'saldo ' . Pdf::dinero($saldo)), $usuario, 'usuario', $compraId, $c['evento_id'] ? (int) $c['evento_id'] : null);
                return $id;
            });
        } catch (\Throwable $e) {
            if ($archivo) Adjuntos::borrar($archivo);
            throw $e;
        }
    }

    /** Anula un pago (no se borra: queda con el motivo). */
    public function anularPago(int $negocioId, int $pagoId, string $motivo, ?array $usuario = null): void
    {
        $p = $this->pago($negocioId, $pagoId);
        if (!$p) throw new RuntimeException('No se encontró ese pago.');
        if ($p['estado'] === 'anulado') throw new RuntimeException('Ese pago ya estaba anulado.');
        $motivo = mb_substr(trim($motivo), 0, 300);
        if (mb_strlen($motivo) < 3) throw new RuntimeException('Escribe por qué se anula el pago.');
        $c = $this->obtener($negocioId, (int) $p['compra_id']);
        $this->q("UPDATE pagos_proveedor SET estado = 'anulado', anulado_motivo = ?, anulado_por = ?, anulado_en = now() WHERE id = ? AND estado = 'valido'",
                 [$motivo, $usuario['id'] ?? null, $pagoId]);
        $this->ficha->anotar($negocioId, (int) $p['proveedor_id'], 'pago', 'Anuló el pago de ' . Pdf::dinero($p['monto']) . ' del ' . (new DateTimeImmutable($p['fecha']))->format('d/m/Y')
            . ' (' . $c['codigo'] . '): ' . $motivo, $usuario, 'usuario', (int) $p['compra_id'], $c['evento_id'] ? (int) $c['evento_id'] : null);
    }

    public function rutaComprobante(int $negocioId, int $pagoId): ?string
    {
        $p = $this->pago($negocioId, $pagoId);
        return $p ? Adjuntos::ruta($p['comprobante']) : null;
    }

    // ------------------------------------------------------------------
    // Cuentas por pagar
    // ------------------------------------------------------------------

    public function saldoProveedor(int $negocioId, int $proveedorId): float
    {
        return max(0.0, round((float) $this->q('SELECT COALESCE(sum(GREATEST(' . self::SQL_SALDO . ', 0)), 0) FROM compras c WHERE c.negocio_id = ? AND c.proveedor_id = ? AND ' . self::SQL_ACTIVAS,
                                               [$negocioId, $proveedorId])->fetchColumn(), 2));
    }

    /** Estado de cuenta con un proveedor: compras (no canceladas), totales y si pasa el cupo. */
    public function cuentaProveedor(int $negocioId, int $proveedorId, ?DateTimeImmutable $hoy = null): array
    {
        $compras = array_values(array_filter($this->lista($negocioId, ['proveedor_id' => $proveedorId, 'limite' => 1000], $hoy), fn($c) => $c['estado'] !== 'cancelada'));
        $activas = array_filter($compras, fn($c) => in_array($c['estado'], self::ACTIVAS, true));
        $p = $this->ficha->proveedor($negocioId, $proveedorId);
        $saldo = round(array_sum(array_column($activas, 'saldo')), 2);
        return [
            'compras' => $compras,
            'total' => round(array_sum(array_column($activas, 'base')), 2),
            'pagado' => round(array_sum(array_column($activas, 'pagado')), 2),
            'retenciones' => round(array_sum(array_column($activas, 'retencion')), 2),
            'descuentos' => round(array_sum(array_column($activas, 'descuentos')), 2),
            'saldo' => $saldo,
            'vencido' => round(array_sum(array_map(fn($c) => $c['estado_pago'] === 'vencida' ? $c['saldo'] : 0.0, $activas)), 2),
            'cupo' => $p && $p['cupo_credito'] !== null ? (float) $p['cupo_credito'] : null,
            'sobre_cupo' => $p && $p['cupo_credito'] !== null && $saldo > (float) $p['cupo_credito'] + 0.004,
        ];
    }

    /**
     * Tablero "Por pagar". $eventoId filtra por evento.
     * total, vencido, semana (vence en los próximos 7 días), por proveedor, antigüedad de saldos, próximos pagos,
     * cheques posfechados todavía por cobrar y proveedores sobre su cupo.
     */
    public function porPagar(int $negocioId, ?int $eventoId = null, ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $h = $hoy->format('Y-m-d');
        $semana = $hoy->modify('+7 days')->format('Y-m-d');
        $pend = $this->lista($negocioId, ['estado' => 'pendientes', 'evento_id' => $eventoId, 'limite' => 1000], $hoy);
        usort($pend, fn($a, $b) => [$a['vence'] ?? '9999', $a['id']] <=> [$b['vence'] ?? '9999', $b['id']]);
        $tramos = array_fill_keys(array_keys(self::TRAMOS), 0.0);
        $porProv = [];
        foreach ($pend as $c) {
            $tramos[$c['tramo']] += $c['saldo'];
            $k = (int) $c['proveedor_id'];
            $porProv[$k] ??= ['proveedor_id' => $k, 'proveedor' => $c['proveedor'], 'telefono' => $c['proveedor_telefono'], 'compras' => 0, 'saldo' => 0.0, 'vencido' => 0.0,
                              'cupo' => $c['cupo_credito'] !== null ? (float) $c['cupo_credito'] : null, 'proximo' => null];
            $porProv[$k]['compras']++;
            $porProv[$k]['saldo'] += $c['saldo'];
            if ($c['estado_pago'] === 'vencida') $porProv[$k]['vencido'] += $c['saldo'];
            if ($c['vence'] && ($porProv[$k]['proximo'] === null || $c['vence'] < $porProv[$k]['proximo'])) $porProv[$k]['proximo'] = $c['vence'];
        }
        foreach ($porProv as &$pp) {
            $pp['saldo'] = round($pp['saldo'], 2);
            $pp['vencido'] = round($pp['vencido'], 2);
            // El cupo se mide con TODO lo que se le debe (no solo el evento filtrado)
            $pp['saldo_total'] = $eventoId ? $this->saldoProveedor($negocioId, $pp['proveedor_id']) : $pp['saldo'];
            $pp['sobre_cupo'] = $pp['cupo'] !== null && $pp['saldo_total'] > $pp['cupo'] + 0.004;
        }
        unset($pp);
        usort($porProv, fn($a, $b) => $b['saldo'] <=> $a['saldo']);
        $cheques = $this->q("SELECT pp.*, c.numero, p.nombre AS proveedor, e.nombre AS evento FROM pagos_proveedor pp JOIN compras c ON c.id = pp.compra_id
                               JOIN proveedores p ON p.id = pp.proveedor_id LEFT JOIN eventos e ON e.id = c.evento_id
                              WHERE pp.negocio_id = ? AND pp.estado = 'valido' AND pp.metodo = 'cheque' AND pp.cheque_fecha >= ?::date"
                            . ($eventoId ? ' AND c.evento_id = ' . (int) $eventoId : '') . ' ORDER BY pp.cheque_fecha, pp.id', [$negocioId, $h])->fetchAll();
        // Sin filtro de evento, también los proveedores sobre el cupo que no tienen saldo en la lista (no pasa, pero por si acaso)
        $sobreCupo = array_values(array_filter($porProv, fn($p) => $p['sobre_cupo']));
        return [
            'total' => round(array_sum(array_column($pend, 'saldo')), 2),
            'vencido' => round(array_sum(array_map(fn($c) => $c['estado_pago'] === 'vencida' ? $c['saldo'] : 0.0, $pend)), 2),
            'vencidas' => count(array_filter($pend, fn($c) => $c['estado_pago'] === 'vencida')),
            'semana' => round(array_sum(array_map(fn($c) => $c['vence'] && $c['vence'] >= $h && $c['vence'] <= $semana ? $c['saldo'] : 0.0, $pend)), 2),
            'semana_cuantas' => count(array_filter($pend, fn($c) => $c['vence'] && $c['vence'] >= $h && $c['vence'] <= $semana)),
            'compras' => $pend,
            'por_proveedor' => $porProv,
            'tramos' => array_map(fn($v) => round($v, 2), $tramos),
            'cheques' => $cheques,
            'cheques_total' => round(array_sum(array_map(fn($x) => (float) $x['monto'], $cheques)), 2),
            'sobre_cupo' => $sobreCupo,
        ];
    }

    /** Para el inicio: cuántas compras vencidas y cuántas vencen esta semana, y cuánto. */
    public function resumenInicio(int $negocioId, ?DateTimeImmutable $hoy = null): array
    {
        $pp = $this->porPagar($negocioId, null, $hoy);
        return ['vencidas' => $pp['vencidas'], 'vencido' => $pp['vencido'], 'semana' => $pp['semana_cuantas'], 'semana_valor' => $pp['semana'], 'total' => $pp['total']];
    }

    /** CSV para Excel: lo que se debe a proveedores (encabezados, filas). */
    public function exportarPorPagar(int $negocioId, ?int $eventoId = null, ?DateTimeImmutable $hoy = null): array
    {
        $f = fn(?string $x) => $x ? (new DateTimeImmutable($x))->format('d/m/Y') : '';
        $n = fn($x) => number_format((float) $x, 2, ',', '');
        $pp = $this->porPagar($negocioId, $eventoId, $hoy);
        return [['Orden', 'Proveedor', 'RUC', 'Evento', 'Fecha', 'Estado', 'Factura', 'Valor a pagar', 'Retención', 'Descuentos', 'Pagado', 'Saldo', 'Vence', 'Situación', 'Días vencido', 'Antigüedad'],
                array_map(fn($c) => [$c['codigo'], $c['proveedor'], $c['proveedor_ruc'], $c['evento'] ? $c['evento_codigo'] . ' ' . $c['evento'] : 'Compra general', $f($c['fecha']),
                    self::ESTADOS[$c['estado']], $c['factura_numero'], $n($c['base']), $n($c['retencion']), $n($c['descuentos']), $n($c['pagado']), $n($c['saldo']), $f($c['vence']),
                    self::ESTADOS_PAGO[$c['estado_pago']], max(0, (int) $c['dias_vencido']), self::TRAMOS[$c['tramo']]], $pp['compras'])];
    }

    // ------------------------------------------------------------------
    // Mensajes y verificación
    // ------------------------------------------------------------------

    /** Mensaje de WhatsApp para enviar la orden de compra. $portal = enlace del portal del proveedor (módulo portal_proveedor) o null. */
    public static function mensajeOrden(array $c, array $items, array $negocio, ?string $portal = null): string
    {
        $nombre = trim(explode(' ', trim((string) ($c['proveedor_contacto'] ?: $c['proveedor'])))[0]);
        $esPrecio = $c['estado'] === 'cotizacion';
        $t = "Hola $nombre, te escribe {$negocio['nombre']}. "
           . ($esPrecio ? '¿Nos ayudas con el precio de esto' : 'Te enviamos la orden de compra ' . $c['codigo'])
           . ($c['evento'] ? ' para ' . $c['evento'] . ' (' . DiaEvento::fechaLarga($c['evento_fecha']) . ')' : '') . ($esPrecio ? '?' : '.') . "\n";
        foreach (array_slice($items, 0, 15) as $i) {
            $t .= '• ' . rtrim(rtrim(number_format((float) $i['cantidad'], 2, ',', '.'), '0'), ',') . ' × ' . $i['descripcion']
                . ((float) $i['precio_unitario'] > 0 ? ' a ' . Pdf::dinero($i['precio_unitario']) : '') . "\n";
        }
        if (count($items) > 15) $t .= '… y ' . (count($items) - 15) . " más.\n";
        if (!$esPrecio) {
            $t .= 'Total: ' . Pdf::dinero($c['total']) . ($c['iva'] > 0 ? ' (con IVA)' : '') . '.';
            if ($c['anticipo'] > 0) $t .= ' Anticipo: ' . Pdf::dinero($c['anticipo']) . '.';
            if ($c['vence']) $t .= ' Pago hasta el ' . (new DateTimeImmutable($c['vence']))->format('d/m/Y') . '.';
            $t .= "\n" . ($portal ? 'Confírmala y sube tu factura aquí: ' . $portal : '¿Nos confirmas, por favor?');
        } elseif ($portal) {
            $t .= "\nTus pedidos y pagos con nosotros: " . $portal;
        }
        return $t . "\n¡Gracias!";
    }

    /** Verificación pública del PDF (código del QR). */
    public function verificar(string $codigo): ?array
    {
        $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
        if (strlen($codigo) !== 16) return null;
        $c = $this->q('SELECT c.numero, c.fecha, c.estado, c.total, c.negocio_id, c.creado_en, p.nombre AS proveedor, e.nombre AS evento, e.fecha AS evento_fecha
                         FROM compras c JOIN proveedores p ON p.id = c.proveedor_id LEFT JOIN eventos e ON e.id = c.evento_id WHERE c.codigo_verificacion = ?', [$codigo])->fetch();
        if (!$c) return null;
        $c['codigo'] = self::codigo($c['numero']);
        return $c;
    }

    // ------------------------------------------------------------------
    // Portal del proveedor
    // ------------------------------------------------------------------

    /** Órdenes que ve el proveedor en su portal (pedidas, confirmadas, entregadas y las canceladas del último mes). */
    public function delPortal(array $proveedor, ?DateTimeImmutable $hoy = null): array
    {
        $res = [];
        foreach ($this->lista((int) $proveedor['negocio_id'], ['proveedor_id' => (int) $proveedor['id'], 'limite' => 200], $hoy) as $c) {
            if ($c['estado'] === 'cotizacion') continue;
            if ($c['estado'] === 'cancelada' && $c['cancelada_en'] < ($hoy ?? new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d')) continue;
            $c['items'] = $this->items((int) $c['id']);
            $res[] = $c;
        }
        return $res;
    }

    private function compraDelProveedor(array $p, int $compraId): array
    {
        $c = $this->compra((int) $p['negocio_id'], $compraId);
        if (!$c || (int) $c['proveedor_id'] !== (int) $p['id'] || $c['estado'] === 'cotizacion') throw new RuntimeException('No se encontró esa orden en tu lista.');
        return $c;
    }

    /** El proveedor confirma (true) o rechaza (false, con comentario) una orden pedida. Avisa al negocio. */
    public function responderDesdePortal(array $p, int $compraId, bool $confirma, string $comentario = ''): array
    {
        $c = $this->compraDelProveedor($p, $compraId);
        if ($c['estado'] !== 'pedida') throw new RuntimeException('Esta orden ya está ' . mb_strtolower(self::ESTADOS[$c['estado']]) . '.');
        $comentario = trim($comentario);
        if (!$confirma && mb_strlen($comentario) < 3) throw new RuntimeException('Cuéntanos por qué no puedes (por ejemplo: no tengo disponibilidad esa fecha).');
        $nid = (int) $p['negocio_id'];
        $this->cambiarEstado($nid, $compraId, $confirma ? 'confirmada' : 'cancelada', null, $confirma ? $comentario : 'Rechazada por el proveedor: ' . $comentario, 'proveedor');
        if (!$confirma) $this->q('UPDATE compras SET respuesta_proveedor = ? WHERE id = ?', [mb_substr($comentario, 0, 300), $compraId]);
        (new Notificaciones($this->db))->alNegocio($nid, ($confirma ? '✅ ' . $p['nombre'] . ' confirmó la orden ' : '❌ ' . $p['nombre'] . ' rechazó la orden ') . $c['codigo']
            . ($c['evento'] ? ' (' . $c['evento'] . ')' : '') . ($comentario !== '' ? ': ' . mb_substr($comentario, 0, 150) : '.'), $c['evento_id'] ? (int) $c['evento_id'] : null);
        return $c;
    }

    /** El proveedor sube su factura (PDF, foto o XML del SRI) desde el portal. */
    public function facturaDesdePortal(array $p, int $compraId, array $archivo, string $numero = ''): array
    {
        $c = $this->compraDelProveedor($p, $compraId);
        if (!in_array($c['estado'], self::ACTIVAS, true)) throw new RuntimeException('Esta orden está cancelada.');
        if (!Adjuntos::llego($archivo)) throw new RuntimeException('Elige el archivo de la factura (PDF, foto o XML).');
        $this->registrarFactura((int) $p['negocio_id'], $compraId, ['numero' => $numero], $archivo, null, 'proveedor');
        (new Notificaciones($this->db))->alNegocio((int) $p['negocio_id'], '🧾 ' . $p['nombre'] . ' subió su factura de la orden ' . $c['codigo'] . '. Revísala en Compras.',
                                                   $c['evento_id'] ? (int) $c['evento_id'] : null);
        return $c;
    }
}
