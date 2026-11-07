<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Cotizaciones con versiones. Se arman con productos sueltos o con un paquete:
 * los productos "por persona" se multiplican por los invitados.
 * El IVA se calcula por línea (cada producto dice si paga IVA), con el % del negocio.
 * El descuento se reparte en proporción entre lo que paga IVA y lo que no.
 * Al aceptarse, el valor pasa al evento y se arma el plan de pagos.
 */
final class Cotizaciones
{
    public const CATEGORIAS = [
        'espacio' => 'Espacio', 'menu' => 'Menú y comida', 'bebidas' => 'Bebidas', 'decoracion' => 'Decoración',
        'musica' => 'Música y sonido', 'foto_video' => 'Foto y video', 'mobiliario' => 'Mobiliario',
        'personal' => 'Personal', 'transmision' => 'Transmisión en vivo', 'otros' => 'Otros',
    ];
    public const UNIDADES = ['persona' => 'por persona', 'evento' => 'por evento', 'hora' => 'por hora', 'unidad' => 'por unidad'];
    public const ESTADOS = ['borrador' => 'Borrador', 'enviada' => 'Enviada', 'aceptada' => 'Aceptada', 'rechazada' => 'Rechazada',
                            'vencida' => 'Vencida', 'reemplazada' => 'Reemplazada'];

    public function __construct(private PDO $db) {}

    private function negocio(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT iva_pct, validez_cotizacion, condiciones FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $n = $st->fetch();
        if (!$n) throw new RuntimeException('Negocio no encontrado.');
        return $n;
    }

    private function evento(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT * FROM eventos WHERE id = ? AND negocio_id = ?');
        $st->execute([$eventoId, $negocioId]);
        $e = $st->fetch();
        if (!$e) throw new RuntimeException('No se encontró el evento.');
        return $e;
    }

    /**
     * Nueva cotización (o nueva versión) para un evento. Si se da un paquete, se carga con sus productos.
     * Si no se da nada y el evento ya tenía cotización, se copia la última para corregirla.
     */
    public function crear(int $negocioId, int $eventoId, ?int $paqueteId = null, ?int $usuarioId = null, ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        $n = $this->negocio($negocioId);
        $ev = $this->evento($negocioId, $eventoId);
        if (in_array($ev['estado'], ['perdido', 'cancelado', 'realizado'], true)) {
            throw new RuntimeException('Este evento está ' . Eventos::ESTADOS[$ev['estado']] . '. Cámbialo de estado para cotizar.');
        }
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM negocios WHERE id = ? FOR UPDATE')->execute([$negocioId]);
            $st = $this->db->prepare('SELECT COALESCE(max(numero),0) + 1 FROM cotizaciones WHERE negocio_id = ?');
            $st->execute([$negocioId]);
            $numero = (int) $st->fetchColumn();
            $st = $this->db->prepare('SELECT id, version FROM cotizaciones WHERE evento_id = ? ORDER BY version DESC LIMIT 1');
            $st->execute([$eventoId]);
            $anterior = $st->fetch();
            $version = $anterior ? (int) $anterior['version'] + 1 : 1;
            $st = $this->db->prepare('INSERT INTO cotizaciones (negocio_id, evento_id, numero, version, fecha, valida_hasta, invitados, iva_pct, condiciones, creado_por)
                                      VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id');
            $st->execute([$negocioId, $eventoId, $numero, $version, $hoy->format('Y-m-d'),
                          $hoy->modify('+' . (int) $n['validez_cotizacion'] . ' days')->format('Y-m-d'),
                          (int) $ev['invitados'], $n['iva_pct'], $n['condiciones'], $usuarioId]);
            $id = (int) $st->fetchColumn();
            $this->db->prepare("UPDATE cotizaciones SET estado = 'reemplazada' WHERE evento_id = ? AND id <> ? AND estado IN ('borrador','enviada')")
                     ->execute([$eventoId, $id]);
            if ($paqueteId) {
                $this->aplicarPaquete($negocioId, $id, $paqueteId);
            } elseif ($anterior) {
                $this->db->prepare('INSERT INTO cotizacion_items (cotizacion_id, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total, orden)
                                    SELECT ?, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total, orden
                                      FROM cotizacion_items WHERE cotizacion_id = ?')->execute([$id, $anterior['id']]);
                $st = $this->db->prepare('SELECT descuento, notas FROM cotizaciones WHERE id = ?');
                $st->execute([$anterior['id']]);
                $a = $st->fetch();
                $this->db->prepare('UPDATE cotizaciones SET descuento = ?, notas = ? WHERE id = ?')->execute([$a['descuento'], $a['notas'], $id]);
            } else {
                // Primera cotización sin paquete: arranca con el alquiler de los espacios del evento
                $esp = $this->db->prepare('SELECT es.nombre, es.precio_base FROM evento_espacios ee JOIN espacios es ON es.id = ee.espacio_id WHERE ee.evento_id = ? ORDER BY es.orden');
                $esp->execute([$eventoId]);
                foreach ($esp->fetchAll() as $i => $e) {
                    $this->agregarLinea($id, ['categoria' => 'espacio', 'descripcion' => 'Alquiler de ' . $e['nombre'], 'unidad' => 'evento',
                                              'cantidad' => 1, 'precio_unitario' => (float) $e['precio_base'], 'con_iva' => true, 'orden' => $i]);
                }
            }
            $this->recalcular($id);
            if ($propia) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Carga los productos de un paquete (los "por persona" x invitados de la cotización). */
    public function aplicarPaquete(int $negocioId, int $cotizacionId, int $paqueteId): void
    {
        $cot = $this->cotizacionEditable($negocioId, $cotizacionId);
        $st = $this->db->prepare('SELECT * FROM paquetes WHERE id = ? AND negocio_id = ?');
        $st->execute([$paqueteId, $negocioId]);
        $paq = $st->fetch();
        if (!$paq) throw new RuntimeException('No se encontró el paquete.');
        $st = $this->db->prepare('SELECT p.*, pi.cantidad, pi.precio AS precio_paquete FROM paquete_items pi JOIN productos p ON p.id = pi.producto_id
                                   WHERE pi.paquete_id = ? ORDER BY p.orden, p.id');
        $st->execute([$paqueteId]);
        $st2 = $this->db->prepare('SELECT COALESCE(max(orden),0) FROM cotizacion_items WHERE cotizacion_id = ?');
        $st2->execute([$cotizacionId]);
        $orden = (int) $st2->fetchColumn();
        $invitados = max((int) $cot['invitados'], (int) $paq['minimo_invitados']);
        foreach ($st->fetchAll() as $p) {
            $cant = (float) $p['cantidad'] * ($p['unidad'] === 'persona' ? $invitados : 1);
            $this->agregarLinea($cotizacionId, [
                'producto_id' => (int) $p['id'], 'categoria' => $p['categoria'],
                'descripcion' => $p['nombre'] . ($p['descripcion'] ? ' — ' . $p['descripcion'] : ''),
                'unidad' => $p['unidad'], 'cantidad' => $cant,
                'precio_unitario' => $p['precio_paquete'] !== null ? (float) $p['precio_paquete'] : (float) $p['precio'],
                'costo_unitario' => (float) $p['costo'], 'con_iva' => (bool) $p['con_iva'], 'orden' => ++$orden,
            ]);
        }
        $nota = 'Incluye ' . $paq['nombre'] . '.' . ($paq['descripcion'] ? ' ' . $paq['descripcion'] : '');
        $this->db->prepare("UPDATE cotizaciones SET notas = trim(COALESCE(notas,'') || ' ' || ?) WHERE id = ?")->execute([$nota, $cotizacionId]);
        $this->recalcular($cotizacionId);
    }

    /** Agrega un producto del catálogo (si es por persona, cantidad = invitados). */
    public function agregarProducto(int $negocioId, int $cotizacionId, int $productoId, ?float $cantidad = null): int
    {
        $cot = $this->cotizacionEditable($negocioId, $cotizacionId);
        $st = $this->db->prepare('SELECT * FROM productos WHERE id = ? AND negocio_id = ?');
        $st->execute([$productoId, $negocioId]);
        $p = $st->fetch();
        if (!$p) throw new RuntimeException('No se encontró el producto.');
        $cantidad ??= $p['unidad'] === 'persona' ? (float) $cot['invitados'] : 1.0;
        $id = $this->agregarLinea($cotizacionId, [
            'producto_id' => $productoId, 'categoria' => $p['categoria'],
            'descripcion' => $p['nombre'] . ($p['descripcion'] ? ' — ' . $p['descripcion'] : ''),
            'unidad' => $p['unidad'], 'cantidad' => $cantidad, 'precio_unitario' => (float) $p['precio'],
            'costo_unitario' => (float) $p['costo'], 'con_iva' => (bool) $p['con_iva'], 'orden' => 900,
        ]);
        $this->recalcular($cotizacionId);
        return $id;
    }

    private function agregarLinea(int $cotizacionId, array $l): int
    {
        $cant = round((float) ($l['cantidad'] ?? 1), 2);
        $precio = round((float) ($l['precio_unitario'] ?? 0), 2);
        $cat = (string) ($l['categoria'] ?? 'otros');
        $uni = (string) ($l['unidad'] ?? 'evento');
        $st = $this->db->prepare('INSERT INTO cotizacion_items (cotizacion_id, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total, orden)
                                  VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$cotizacionId, $l['producto_id'] ?? null, isset(self::CATEGORIAS[$cat]) ? $cat : 'otros',
                      mb_substr(trim((string) $l['descripcion']), 0, 300), isset(self::UNIDADES[$uni]) ? $uni : 'evento',
                      $cant, $precio, round((float) ($l['costo_unitario'] ?? 0), 2), !empty($l['con_iva']) ? 'true' : 'false',
                      round($cant * $precio, 2), (int) ($l['orden'] ?? 0)]);
        return (int) $st->fetchColumn();
    }

    /**
     * Guarda todas las líneas de golpe (desde el editor). Cada línea: id (opcional), producto_id, categoria, descripcion,
     * unidad, cantidad, precio_unitario, costo_unitario, con_iva. Las líneas sin descripción se borran.
     * También guarda invitados, descuento, notas, condiciones y valida_hasta.
     */
    public function guardar(int $negocioId, int $cotizacionId, array $lineas, array $cabecera = []): void
    {
        $cot = $this->cotizacionEditable($negocioId, $cotizacionId);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM cotizacion_items WHERE cotizacion_id = ?')->execute([$cotizacionId]);
            $orden = 0;
            foreach ($lineas as $l) {
                if (trim((string) ($l['descripcion'] ?? '')) === '') continue;
                $cant = self::num($l['cantidad'] ?? 0);
                $precio = self::num($l['precio_unitario'] ?? 0);
                if ($cant <= 0) throw new RuntimeException('La cantidad de "' . trim($l['descripcion']) . '" debe ser mayor que 0.');
                if ($precio < 0) throw new RuntimeException('El precio de "' . trim($l['descripcion']) . '" no puede ser negativo.');
                $l['cantidad'] = $cant;
                $l['precio_unitario'] = $precio;
                $l['costo_unitario'] = self::num($l['costo_unitario'] ?? 0);
                $l['producto_id'] = !empty($l['producto_id']) ? (int) $l['producto_id'] : null;
                $l['orden'] = $orden++;
                $this->agregarLinea($cotizacionId, $l);
            }
            $inv = isset($cabecera['invitados']) ? (int) $cabecera['invitados'] : (int) $cot['invitados'];
            if ($inv < 1) throw new RuntimeException('Escribe cuántos invitados.');
            $desc = isset($cabecera['descuento']) ? round(self::num($cabecera['descuento']), 2) : (float) $cot['descuento'];
            if ($desc < 0) throw new RuntimeException('El descuento no puede ser negativo.');
            $valida = (string) ($cabecera['valida_hasta'] ?? $cot['valida_hasta']);
            if (!DateTimeImmutable::createFromFormat('!Y-m-d', $valida)) throw new RuntimeException('Revisa la fecha de validez.');
            $this->db->prepare('UPDATE cotizaciones SET invitados = ?, descuento = ?, valida_hasta = ?,
                                       notas = ?, condiciones = ? WHERE id = ?')
                     ->execute([$inv, $desc, $valida,
                                array_key_exists('notas', $cabecera) ? (trim((string) $cabecera['notas']) ?: null) : $cot['notas'],
                                array_key_exists('condiciones', $cabecera) ? (trim((string) $cabecera['condiciones']) ?: null) : $cot['condiciones'],
                                $cotizacionId]);
            $this->recalcular($cotizacionId);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Número escrito a la ecuatoriana: 1.234,50 / 1234,50 / 1234.50 / 12.000 */
    public static function num(mixed $v): float
    {
        $v = str_replace(' ', '', trim((string) $v));
        if ($v === '') return 0.0;
        if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace('.', '', $v);
        elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
        return (float) str_replace(',', '.', $v);
    }

    /** Cambia los invitados y recalcula todas las líneas "por persona". */
    public function cambiarInvitados(int $negocioId, int $cotizacionId, int $invitados): void
    {
        $this->cotizacionEditable($negocioId, $cotizacionId);
        if ($invitados < 1) throw new RuntimeException('Escribe cuántos invitados.');
        $this->db->prepare("UPDATE cotizacion_items SET cantidad = ?, total = round(? * precio_unitario, 2) WHERE cotizacion_id = ? AND unidad = 'persona'")
                 ->execute([$invitados, $invitados, $cotizacionId]);
        $this->db->prepare('UPDATE cotizaciones SET invitados = ? WHERE id = ?')->execute([$invitados, $cotizacionId]);
        $this->recalcular($cotizacionId);
    }

    /** Recalcula subtotal, descuento, IVA y total. Devuelve los totales. */
    public function recalcular(int $cotizacionId): array
    {
        $st = $this->db->prepare('SELECT c.descuento, c.iva_pct,
                                         COALESCE(sum(i.total) FILTER (WHERE i.con_iva), 0) AS con_iva,
                                         COALESCE(sum(i.total) FILTER (WHERE NOT i.con_iva), 0) AS sin_iva,
                                         COALESCE(sum(i.cantidad * i.costo_unitario), 0) AS costo
                                    FROM cotizaciones c LEFT JOIN cotizacion_items i ON i.cotizacion_id = c.id
                                   WHERE c.id = ? GROUP BY c.id');
        $st->execute([$cotizacionId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('No se encontró la cotización.');
        $t = self::calcularTotales((float) $r['con_iva'], (float) $r['sin_iva'], (float) $r['descuento'], (float) $r['iva_pct']);
        $this->db->prepare('UPDATE cotizaciones SET subtotal = ?, descuento = ?, base_iva = ?, base_cero = ?, iva = ?, total = ?, costo = ? WHERE id = ?')
                 ->execute([$t['subtotal'], $t['descuento'], $t['base_iva'], $t['base_cero'], $t['iva'], $t['total'], round((float) $r['costo'], 2), $cotizacionId]);
        return $t + ['costo' => round((float) $r['costo'], 2)];
    }

    /** Cálculo puro (también se usa en las pruebas). */
    public static function calcularTotales(float $conIva, float $sinIva, float $descuento, float $ivaPct): array
    {
        $subtotal = round($conIva + $sinIva, 2);
        $descuento = min(round($descuento, 2), $subtotal);
        $descIva = $subtotal > 0 ? round($descuento * $conIva / $subtotal, 2) : 0.0;
        $descCero = round($descuento - $descIva, 2);
        $baseIva = round($conIva - $descIva, 2);
        $baseCero = round($sinIva - $descCero, 2);
        $iva = round($baseIva * $ivaPct / 100, 2);
        return ['subtotal' => $subtotal, 'descuento' => $descuento, 'base_iva' => $baseIva, 'base_cero' => $baseCero,
                'iva' => $iva, 'total' => round($baseIva + $baseCero + $iva, 2)];
    }

    public function obtener(int $negocioId, int $cotizacionId): ?array
    {
        $st = $this->db->prepare('SELECT c.*, e.codigo AS evento_codigo, e.nombre AS evento, e.fecha AS evento_fecha, e.estado AS evento_estado,
                                         e.hora_inicio, e.hora_fin, e.tipo, e.token_cliente, cl.nombre AS cliente, cl.telefono AS cliente_telefono,
                                         cl.email AS cliente_email, cl.identificacion AS cliente_identificacion
                                    FROM cotizaciones c JOIN eventos e ON e.id = c.evento_id JOIN clientes cl ON cl.id = e.cliente_id
                                   WHERE c.id = ? AND c.negocio_id = ?');
        $st->execute([$cotizacionId, $negocioId]);
        $c = $st->fetch();
        if (!$c) return null;
        $c['items'] = $this->items($cotizacionId);
        $c['ganancia'] = round((float) $c['subtotal'] - (float) $c['descuento'] - (float) $c['costo'], 2);
        $c['por_persona'] = (int) $c['invitados'] > 0 ? round((float) $c['total'] / (int) $c['invitados'], 2) : 0.0;
        return $c;
    }

    /** Para el cliente: busca por el enlace secreto de la cotización. */
    public function porToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $st = $this->db->prepare('SELECT negocio_id, id FROM cotizaciones WHERE token = ?');
        $st->execute([$token]);
        $r = $st->fetch();
        return $r ? $this->obtener((int) $r['negocio_id'], (int) $r['id']) : null;
    }

    public function items(int $cotizacionId): array
    {
        $st = $this->db->prepare('SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden, id');
        $st->execute([$cotizacionId]);
        return $st->fetchAll();
    }

    public function deEvento(int $negocioId, int $eventoId): array
    {
        $st = $this->db->prepare('SELECT * FROM cotizaciones WHERE negocio_id = ? AND evento_id = ? ORDER BY version DESC');
        $st->execute([$negocioId, $eventoId]);
        return $st->fetchAll();
    }

    private function cotizacionEditable(int $negocioId, int $cotizacionId): array
    {
        $st = $this->db->prepare('SELECT * FROM cotizaciones WHERE id = ? AND negocio_id = ?');
        $st->execute([$cotizacionId, $negocioId]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('No se encontró la cotización.');
        if (!in_array($c['estado'], ['borrador', 'enviada'], true)) {
            throw new RuntimeException('Esta cotización ya está ' . strtolower(self::ESTADOS[$c['estado']]) . '. Crea una nueva versión para cambiarla.');
        }
        return $c;
    }

    /** Marca como enviada. Si el evento estaba en consulta, pasa a cotizado (y la fecha queda retenida). */
    public function enviar(int $negocioId, int $cotizacionId, ?array $usuario = null): void
    {
        $c = $this->cotizacionEditable($negocioId, $cotizacionId);
        if (!$this->items($cotizacionId)) throw new RuntimeException('La cotización no tiene nada. Agrega productos o un paquete.');
        $this->db->prepare("UPDATE cotizaciones SET estado = 'enviada' WHERE id = ?")->execute([$cotizacionId]);
        $st = $this->db->prepare('SELECT estado FROM eventos WHERE id = ?');
        $st->execute([$c['evento_id']]);
        if ($st->fetchColumn() === 'consulta') {
            (new Eventos($this->db))->cambiarEstado($negocioId, (int) $c['evento_id'], 'cotizado', [], $usuario);
        }
    }

    /**
     * El cliente (desde su enlace) o el negocio acepta la cotización.
     * El valor pasa al evento, las demás versiones quedan reemplazadas y se arma el plan de pagos.
     */
    public function aceptar(int $negocioId, int $cotizacionId, string $nombre, string $ip = '', ?array $usuario = null, ?DateTimeImmutable $hoy = null): void
    {
        $hoy ??= new DateTimeImmutable('today');
        $nombre = trim($nombre);
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Escribe el nombre de quien acepta.');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT * FROM cotizaciones WHERE id = ? AND negocio_id = ? FOR UPDATE');
            $st->execute([$cotizacionId, $negocioId]);
            $c = $st->fetch();
            if (!$c) throw new RuntimeException('No se encontró la cotización.');
            if ($c['estado'] === 'aceptada') {
                if ($propia) $this->db->commit();
                return;
            }
            if (!in_array($c['estado'], ['borrador', 'enviada'], true)) throw new RuntimeException('Esta cotización ya no se puede aceptar.');
            if ($c['valida_hasta'] < $hoy->format('Y-m-d') && $usuario === null) {
                throw new RuntimeException('Esta cotización venció el ' . (new DateTimeImmutable($c['valida_hasta']))->format('d/m/Y') . '. Pide una actualizada.');
            }
            $this->db->prepare("UPDATE cotizaciones SET estado = 'reemplazada' WHERE evento_id = ? AND id <> ? AND estado IN ('borrador','enviada','aceptada')")
                     ->execute([$c['evento_id'], $cotizacionId]);
            $this->db->prepare("UPDATE cotizaciones SET estado = 'aceptada', aceptada_en = now(), aceptada_por = ?, aceptada_ip = ? WHERE id = ?")
                     ->execute([mb_substr($nombre, 0, 150), mb_substr($ip, 0, 45) ?: null, $cotizacionId]);
            $this->db->prepare('UPDATE eventos SET total = ?, costo_estimado = ?, invitados = ?, actualizado_en = now() WHERE id = ?')
                     ->execute([$c['total'], $c['costo'], $c['invitados'], $c['evento_id']]);
            $ev = $this->evento($negocioId, (int) $c['evento_id']);
            if ($ev['estado'] === 'consulta') {
                (new Eventos($this->db))->cambiarEstado($negocioId, (int) $c['evento_id'], 'cotizado', [], $usuario, $hoy);
            }
            (new Pagos($this->db))->generarPlan($negocioId, (int) $c['evento_id'], null, null, $hoy);
            $this->db->prepare('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)')
                     ->execute([$negocioId, $c['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? $nombre,
                                'Aceptó la cotización N.º ' . $c['numero'] . ' (versión ' . $c['version'] . ') por ' . number_format((float) $c['total'], 2, ',', '.') . ' dólares']);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function rechazar(int $negocioId, int $cotizacionId, string $motivo = ''): void
    {
        $st = $this->db->prepare("UPDATE cotizaciones SET estado = 'rechazada', notas = trim(COALESCE(notas,'') || ' ' || ?)
                                   WHERE id = ? AND negocio_id = ? AND estado IN ('borrador','enviada')");
        $st->execute([$motivo !== '' ? '[Rechazada: ' . mb_substr($motivo, 0, 200) . ']' : '', $cotizacionId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Esta cotización no se puede rechazar.');
    }

    /** Marca vencidas las enviadas cuya validez pasó (tarea automática). Devuelve cuántas. */
    public function vencerAntiguas(?DateTimeImmutable $hoy = null): int
    {
        $st = $this->db->prepare("UPDATE cotizaciones SET estado = 'vencida' WHERE estado = 'enviada' AND valida_hasta < ?::date
                                     AND negocio_id NOT IN (SELECT id FROM negocios WHERE eliminado_en IS NOT NULL)");   // la papelera no se toca
        $st->execute([($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d')]);
        return $st->rowCount();
    }

    /** Cotización aceptada del evento (o null). */
    public function aceptadaDe(int $eventoId): ?array
    {
        $st = $this->db->prepare("SELECT negocio_id, id FROM cotizaciones WHERE evento_id = ? AND estado = 'aceptada' ORDER BY version DESC LIMIT 1");
        $st->execute([$eventoId]);
        $r = $st->fetch();
        return $r ? $this->obtener((int) $r['negocio_id'], (int) $r['id']) : null;
    }
}
