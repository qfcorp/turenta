<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Extras que el cliente pide desde su portal (migración 070): hora extra, barra libre, fotocabina, mesa de dulces...
 *  - El local marca en su catálogo qué productos se pueden pedir desde el portal (productos.en_portal), con texto y foto.
 *  - El cliente elige cantidades y envía el pedido: queda "pendiente" con los precios de ese momento y se avisa al local.
 *  - Un vendedor o el dueño lo aprueba o lo rechaza (con nota). El cliente ve la respuesta en su portal.
 *
 * Cómo entra al valor del evento al APROBAR (todo en una transacción, el pedido se bloquea: no se aprueba dos veces):
 *  1) Se crea una versión nueva de la cotización ACEPTADA: copia sus líneas, su descuento y su IVA, y suma las líneas del pedido.
 *     Queda "aceptada"; la anterior (y cualquier borrador o enviada) queda "reemplazada". Así la ganancia, los reportes
 *     y la hoja del evento ven los extras, igual que si se hubieran cotizado.
 *  2) eventos.total y costo_estimado pasan a los de la versión nueva.
 *  3) NO se rehace el plan de pagos (se respetan las cuotas y lo ya pagado): se agrega UNA cuota nueva al final,
 *     "Extras: …", por la diferencia, que vence con el saldo (X días antes del evento, o hoy si esa fecha ya pasó).
 *     Lo pagado se vuelve a repartir (Pagos::reaplicar), así un saldo a favor cubre los extras. Si el evento no tenía
 *     plan, se arma el plan automático completo. Las cuotas siguen sumando el total del evento.
 *  4) Si el evento ya tiene contrato FIRMADO, se prepara un anexo para que el cliente lo firme (Anexos, migración 090).
 *     Si no, los extras entran en el contrato cuando se genere (los toma de la cotización aceptada).
 * Un pedido aprobado NO se puede deshacer: si el cliente se arrepiente, se hace una cotización nueva sin los extras.
 */
final class Extras
{
    public const ESTADOS = ['pendiente' => 'Esperando respuesta', 'aprobado' => 'Aprobado', 'rechazado' => 'No aprobado', 'cancelado' => 'Cancelado'];
    /** Estados del evento en los que se pueden pedir y aprobar extras. */
    public const ESTADOS_EVENTO = ['cotizado', 'separado', 'confirmado'];
    public const MAX_PENDIENTES = 3;
    public const MAX_CANTIDAD = 2000;

    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    private function carpeta(): string
    {
        return Fotos::carpeta('extras');
    }

    private static function plata(float $v): string
    {
        return '$' . number_format($v, 2, ',', '.');
    }

    // ------------------------------------------------------------------
    // Productos que se ofrecen en el portal
    // ------------------------------------------------------------------

    /** Productos activos que el cliente puede pedir, con el precio final (IVA incluido). */
    public function ofrecidos(int $negocioId): array
    {
        $iva = (float) $this->q('SELECT iva_pct FROM negocios WHERE id = ?', [$negocioId])->fetchColumn();
        $filas = $this->q('SELECT id, categoria, nombre, descripcion, portal_texto, portal_foto, unidad, precio, con_iva, orden
                             FROM productos WHERE negocio_id = ? AND activo AND en_portal', [$negocioId])->fetchAll();
        $pos = array_flip(array_keys(Cotizaciones::CATEGORIAS));
        usort($filas, fn($a, $b) => [$pos[$a['categoria']] ?? 99, (int) $a['orden'], $a['nombre']] <=> [$pos[$b['categoria']] ?? 99, (int) $b['orden'], $b['nombre']]);
        foreach ($filas as &$f) {
            $f['precio'] = (float) $f['precio'];
            $f['precio_final'] = round($f['precio'] * ($f['con_iva'] ? 1 + $iva / 100 : 1), 2);
            $f['texto'] = trim((string) ($f['portal_texto'] ?: $f['descripcion']));
        }
        return $filas;
    }

    /** Productos del catálogo (activos) para configurar cuáles salen en el portal. */
    public function catalogo(int $negocioId): array
    {
        $filas = $this->q('SELECT id, categoria, nombre, descripcion, unidad, precio, con_iva, en_portal, portal_texto, portal_foto, orden
                             FROM productos WHERE negocio_id = ? AND activo', [$negocioId])->fetchAll();
        $pos = array_flip(array_keys(Cotizaciones::CATEGORIAS));
        usort($filas, fn($a, $b) => [!$a['en_portal'], $pos[$a['categoria']] ?? 99, (int) $a['orden'], $a['nombre']]
                                   <=> [!$b['en_portal'], $pos[$b['categoria']] ?? 99, (int) $b['orden'], $b['nombre']]);
        return $filas;
    }

    /** Marca (o quita) un producto para el portal, con un texto para el cliente y una foto opcional. */
    public function configurarProducto(int $negocioId, int $productoId, bool $enPortal, string $texto = '', ?array $foto = null, bool $quitarFoto = false): void
    {
        $p = $this->q('SELECT id, portal_foto, activo FROM productos WHERE id = ? AND negocio_id = ?', [$productoId, $negocioId])->fetch();
        if (!$p) throw new RuntimeException('No se encontró el producto.');
        if ($enPortal && !$p['activo']) throw new RuntimeException('Ese producto está desactivado en el catálogo. Actívalo primero.');
        $nueva = null;
        if ($foto && ($foto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $nueva = Fotos::guardarImagen($foto, $this->carpeta(), 1200);
        }
        $fotoFinal = $nueva ?? ($quitarFoto ? null : $p['portal_foto']);
        $this->q('UPDATE productos SET en_portal = ?, portal_texto = ?, portal_foto = ? WHERE id = ? AND negocio_id = ?',
                 [$enPortal ? 'true' : 'false', mb_substr(trim($texto), 0, 300) ?: null, $fotoFinal, $productoId, $negocioId]);
        if (($nueva || $quitarFoto) && $p['portal_foto'] && $p['portal_foto'] !== $fotoFinal) Fotos::borrar('extras', $p['portal_foto']);
    }

    /** Foto de un extra. $soloPortal: solo si se ofrece en el portal (para el cliente). */
    public function rutaFoto(int $negocioId, int $productoId, bool $soloPortal = true): ?string
    {
        $f = $this->q('SELECT portal_foto FROM productos WHERE id = ? AND negocio_id = ?' . ($soloPortal ? ' AND en_portal AND activo' : ''),
                      [$productoId, $negocioId])->fetchColumn();
        return $f ? Fotos::ruta('extras', (string) $f) : null;
    }

    // ------------------------------------------------------------------
    // El cliente pide
    // ------------------------------------------------------------------

    /** ¿El cliente puede pedir extras para este evento? Devuelve null si sí, o el motivo si no. */
    public function motivoNoDisponible(int $negocioId, int $eventoId, ?DateTimeImmutable $hoy = null): ?string
    {
        $hoy ??= new DateTimeImmutable('today');
        $e = $this->q('SELECT e.estado, e.fecha, n.estado AS cuenta FROM eventos e JOIN negocios n ON n.id = e.negocio_id WHERE e.id = ? AND e.negocio_id = ?',
                      [$eventoId, $negocioId])->fetch();
        if (!$e) return 'No se encontró el evento.';
        if (in_array($e['cuenta'], ['suspendido', 'cancelado'], true)) return 'Por ahora el local no recibe pedidos en línea. Escríbele por WhatsApp.';
        if (!in_array($e['estado'], self::ESTADOS_EVENTO, true)) return 'Los extras se pueden pedir mientras el evento está activo y todavía no se realiza.';
        if ($e['fecha'] < $hoy->format('Y-m-d')) return 'El evento ya pasó.';
        $acept = $this->q("SELECT 1 FROM cotizaciones WHERE evento_id = ? AND estado = 'aceptada' LIMIT 1", [$eventoId])->fetchColumn();
        if (!$acept) return 'Podrás pedir extras cuando aceptes tu cotización.';
        return null;
    }

    /** Subtotal, IVA y total de unas líneas [cantidad, precio_unitario, con_iva]. */
    public static function totales(array $lineas, float $ivaPct): array
    {
        $sub = $base = 0.0;
        foreach ($lineas as $l) {
            $t = round((float) $l['cantidad'] * (float) $l['precio_unitario'], 2);
            $sub += $t;
            if (!empty($l['con_iva'])) $base += $t;
        }
        $iva = round($base * $ivaPct / 100, 2);
        return ['subtotal' => round($sub, 2), 'iva' => $iva, 'total' => round($sub + $iva, 2)];
    }

    /**
     * El cliente envía un pedido desde su portal. $cantidades = [producto_id => cantidad] (las vacías o 0 se ignoran).
     * Devuelve el id del pedido.
     */
    public function pedir(int $negocioId, int $eventoId, array $cantidades, string $nota = '', string $ip = '', ?DateTimeImmutable $hoy = null): int
    {
        $motivo = $this->motivoNoDisponible($negocioId, $eventoId, $hoy);
        if ($motivo) throw new RuntimeException($motivo);
        $pend = (int) $this->q("SELECT count(*) FROM extras_pedidos WHERE evento_id = ? AND negocio_id = ? AND estado = 'pendiente'", [$eventoId, $negocioId])->fetchColumn();
        if ($pend >= self::MAX_PENDIENTES) {
            throw new RuntimeException('Ya tienes ' . $pend . ' pedidos esperando respuesta. Espera a que el local los revise o cancela uno.');
        }
        $ofrecidos = [];
        foreach ($this->ofrecidos($negocioId) as $p) $ofrecidos[(int) $p['id']] = $p;
        $costos = [];
        foreach ($this->q('SELECT id, costo FROM productos WHERE negocio_id = ?', [$negocioId])->fetchAll() as $c) $costos[(int) $c['id']] = (float) $c['costo'];
        $lineas = [];
        foreach ($cantidades as $pid => $cant) {
            $cant = Cuestionarios::numeroTexto(is_array($cant) ? '' : (string) $cant) ?? 0.0;
            if ($cant <= 0) continue;
            $p = $ofrecidos[(int) $pid] ?? null;
            if (!$p) throw new RuntimeException('Uno de los extras ya no está disponible. Recarga la página.');
            if ($cant > self::MAX_CANTIDAD) throw new RuntimeException('Revisa la cantidad de "' . $p['nombre'] . '".');
            $cant = round($cant, 2);
            if ($p['unidad'] !== 'persona' && $p['unidad'] !== 'hora' && floor($cant) != $cant) {
                throw new RuntimeException('La cantidad de "' . $p['nombre'] . '" debe ser un número entero.');
            }
            $lineas[] = ['producto_id' => (int) $p['id'], 'categoria' => $p['categoria'], 'descripcion' => $p['nombre'], 'unidad' => $p['unidad'],
                         'cantidad' => $cant, 'precio_unitario' => $p['precio'], 'costo_unitario' => $costos[(int) $p['id']] ?? 0.0,
                         'con_iva' => (bool) $p['con_iva']];
        }
        if (!$lineas) throw new RuntimeException('Elige al menos un extra y la cantidad que quieres.');
        $iva = (float) $this->q('SELECT iva_pct FROM negocios WHERE id = ?', [$negocioId])->fetchColumn();
        $t = self::totales($lineas, $iva);
        $nota = mb_substr(trim($nota), 0, 400);
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $id = (int) $this->q('INSERT INTO extras_pedidos (negocio_id, evento_id, nota_cliente, subtotal, iva, total, ip) VALUES (?,?,?,?,?,?,?) RETURNING id',
                                 [$negocioId, $eventoId, $nota ?: null, $t['subtotal'], $t['iva'], $t['total'], mb_substr($ip, 0, 45) ?: null])->fetchColumn();
            $ins = $this->db->prepare('INSERT INTO extras_pedido_items (pedido_id, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total)
                                       VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach ($lineas as $l) {
                $ins->execute([$id, $l['producto_id'], $l['categoria'], mb_substr($l['descripcion'], 0, 300), $l['unidad'], $l['cantidad'],
                               round($l['precio_unitario'], 2), round($l['costo_unitario'], 2), $l['con_iva'] ? 'true' : 'false',
                               round($l['cantidad'] * $l['precio_unitario'], 2)]);
            }
            $resumen = self::resumenLineas($lineas);
            $this->historial($negocioId, $eventoId, null, 'Cliente', 'Pidió extras desde su portal: ' . $resumen . ' (aprox. ' . self::plata($t['total']) . ')');
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $ev = $this->q('SELECT e.codigo, e.nombre, e.vendedor_id, c.nombre AS cliente FROM eventos e JOIN clientes c ON c.id = e.cliente_id WHERE e.id = ?', [$eventoId])->fetch();
        $texto = '🛍️ ' . trim(explode(' ', trim((string) $ev['cliente']))[0]) . ' pidió extras desde su portal para ' . $ev['nombre'] . ' (' . $ev['codigo'] . '): '
               . $resumen . ' — aprox. ' . self::plata($t['total']) . '. Apruébalos o recházalos en el evento → Formulario y extras.';
        $avisos = new Notificaciones($this->db);
        $avisos->alNegocio($negocioId, $texto, $eventoId);
        if ($ev['vendedor_id'] && !in_array((int) $ev['vendedor_id'], $avisos->destinos($negocioId, ['dueno', 'vendedor']), true)) {
            $avisos->aUsuarios($negocioId, [(int) $ev['vendedor_id']], $texto, $eventoId);
        }
        return $id;
    }

    /** "Hora adicional ×2, Fotocabina ×1" */
    public static function resumenLineas(array $lineas): string
    {
        return implode(', ', array_map(fn($l) => $l['descripcion'] . ' ×' . rtrim(rtrim(number_format((float) $l['cantidad'], 2, ',', ''), '0'), ','), $lineas));
    }

    /** El cliente cancela un pedido que todavía no se responde. */
    public function cancelar(int $negocioId, int $eventoId, int $pedidoId): void
    {
        $st = $this->q("UPDATE extras_pedidos SET estado = 'cancelado', respondido_en = now()
                         WHERE id = ? AND evento_id = ? AND negocio_id = ? AND estado = 'pendiente'", [$pedidoId, $eventoId, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Ese pedido ya fue respondido por el local o no existe.');
        $this->historial($negocioId, $eventoId, null, 'Cliente', 'Canceló su pedido de extras N.º ' . $pedidoId);
    }

    // ------------------------------------------------------------------
    // El local responde
    // ------------------------------------------------------------------

    private function pedidoParaResponder(int $negocioId, int $pedidoId): array
    {
        $p = $this->q('SELECT * FROM extras_pedidos WHERE id = ? AND negocio_id = ? FOR UPDATE', [$pedidoId, $negocioId])->fetch();
        if (!$p) throw new RuntimeException('No se encontró ese pedido.');
        if ($p['estado'] !== 'pendiente') {
            throw new RuntimeException(match ($p['estado']) {
                'aprobado' => 'Este pedido ya fue aprobado.', 'rechazado' => 'Este pedido ya fue rechazado.',
                default => 'El cliente canceló este pedido.',
            });
        }
        return $p;
    }

    /**
     * Aprueba el pedido: versión nueva de la cotización aceptada con los extras, nuevo valor del evento y cuota "Extras".
     * @return array{cotizacion_id:int, numero:int, version:int, monto:float, total:float, cuota_id:?int}
     */
    public function aprobar(int $negocioId, int $pedidoId, array $usuario, string $nota = '', ?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $p = $this->pedidoParaResponder($negocioId, $pedidoId);
            $eventoId = (int) $p['evento_id'];
            $ev = $this->q('SELECT e.*, n.validez_cotizacion, n.dias_saldo_antes FROM eventos e JOIN negocios n ON n.id = e.negocio_id
                             WHERE e.id = ? AND e.negocio_id = ? FOR UPDATE OF e', [$eventoId, $negocioId])->fetch();
            if (!$ev) throw new RuntimeException('No se encontró el evento.');
            if (!in_array($ev['estado'], self::ESTADOS_EVENTO, true)) {
                throw new RuntimeException('El evento está ' . mb_strtolower(Eventos::ESTADOS[$ev['estado']]) . ': ya no se pueden aprobar extras. Recházalo con una nota.');
            }
            $acept = $this->q("SELECT * FROM cotizaciones WHERE evento_id = ? AND negocio_id = ? AND estado = 'aceptada' ORDER BY version DESC LIMIT 1 FOR UPDATE",
                              [$eventoId, $negocioId])->fetch();
            if (!$acept) throw new RuntimeException('El evento no tiene una cotización aceptada. Agrega estos extras en una cotización nueva y que el cliente la acepte.');
            $items = $this->items($pedidoId);
            if (!$items) throw new RuntimeException('El pedido no tiene extras.');

            // 1) Versión nueva de la cotización aceptada + las líneas del pedido
            $this->q('SELECT id FROM negocios WHERE id = ? FOR UPDATE', [$negocioId]);
            $numero = (int) $this->q('SELECT COALESCE(max(numero),0) + 1 FROM cotizaciones WHERE negocio_id = ?', [$negocioId])->fetchColumn();
            $version = (int) $this->q('SELECT COALESCE(max(version),0) + 1 FROM cotizaciones WHERE evento_id = ?', [$eventoId])->fetchColumn();
            $nota = mb_substr(trim($nota), 0, 400);
            $notas = trim(((string) $acept['notas']) . ' Incluye los extras que el cliente pidió desde su portal (pedido N.º ' . $pedidoId . ').');
            $nueva = (int) $this->q('INSERT INTO cotizaciones (negocio_id, evento_id, numero, version, fecha, valida_hasta, invitados, descuento, iva_pct, notas, condiciones, creado_por)
                                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',
                                    [$negocioId, $eventoId, $numero, $version, $hoy->format('Y-m-d'),
                                     $hoy->modify('+' . (int) $ev['validez_cotizacion'] . ' days')->format('Y-m-d'),
                                     (int) $acept['invitados'], $acept['descuento'], $acept['iva_pct'], $notas, $acept['condiciones'], $usuario['id'] ?? null])->fetchColumn();
            $this->q('INSERT INTO cotizacion_items (cotizacion_id, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total, orden)
                      SELECT ?, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total, orden
                        FROM cotizacion_items WHERE cotizacion_id = ?', [$nueva, $acept['id']]);
            $this->q('INSERT INTO cotizacion_items (cotizacion_id, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total, orden)
                      SELECT ?, producto_id, categoria, descripcion, unidad, cantidad, precio_unitario, costo_unitario, con_iva, total,
                             (SELECT COALESCE(max(orden),0) FROM cotizacion_items WHERE cotizacion_id = ?) + row_number() OVER (ORDER BY id)
                        FROM extras_pedido_items WHERE pedido_id = ?', [$nueva, $nueva, $pedidoId]);
            $tot = (new Cotizaciones($this->db))->recalcular($nueva);
            $this->q("UPDATE cotizaciones SET estado = 'reemplazada' WHERE evento_id = ? AND id <> ? AND estado IN ('borrador','enviada','aceptada')", [$eventoId, $nueva]);
            $cliente = (string) $this->q('SELECT nombre FROM clientes WHERE id = ?', [$ev['cliente_id']])->fetchColumn();
            $this->q("UPDATE cotizaciones SET estado = 'aceptada', aceptada_en = now(), aceptada_por = ? WHERE id = ?",
                     [mb_substr($cliente . ' (extras del portal, aprobó ' . ($usuario['nombre'] ?? 'el local') . ')', 0, 150), $nueva]);

            // 2) Nuevo valor del evento
            $monto = round($tot['total'] - (float) $acept['total'], 2);
            $this->q('UPDATE eventos SET total = ?, costo_estimado = ?, actualizado_en = now() WHERE id = ?', [$tot['total'], $tot['costo'], $eventoId]);

            // 3) Plan de pagos: una cuota nueva por los extras (o el plan completo si no había)
            $pagos = new Pagos($this->db);
            $cuotaId = null;
            $hayPlan = (int) $this->q('SELECT count(*) FROM cuotas WHERE evento_id = ?', [$eventoId])->fetchColumn() > 0;
            if (!$hayPlan) {
                $pagos->generarPlan($negocioId, $eventoId, null, null, $hoy);
            } elseif ($monto > 0) {
                $vence = (new DateTimeImmutable($ev['fecha']))->modify('-' . (int) $ev['dias_saldo_antes'] . ' days');
                if ($vence < $hoy) $vence = $hoy;
                $concepto = mb_substr('Extras: ' . implode(', ', array_map(fn($i) => $i['descripcion'], $items)), 0, 80);
                $cuotaId = (int) $this->q('INSERT INTO cuotas (evento_id, numero, concepto, vence, monto)
                                           VALUES (?, (SELECT COALESCE(max(numero),0) + 1 FROM cuotas WHERE evento_id = ?), ?,?,?) RETURNING id',
                                          [$eventoId, $eventoId, $concepto, $vence->format('Y-m-d'), $monto])->fetchColumn();
                $pagos->reaplicar($eventoId);
            }

            $this->q("UPDATE extras_pedidos SET estado = 'aprobado', nota_negocio = ?, cotizacion_id = ?, cuota_id = ?, monto_agregado = ?,
                                                respondido_en = now(), respondido_por = ? WHERE id = ?",
                     [$nota ?: null, $nueva, $cuotaId, $monto, $usuario['id'] ?? null, $pedidoId]);
            $this->historial($negocioId, $eventoId, $usuario, $usuario['nombre'] ?? 'Sistema',
                'Aprobó los extras del portal (' . self::resumenLineas($items) . '): el evento sube ' . self::plata($monto) . ' a ' . self::plata($tot['total'])
                . '. Cotización N.º ' . $numero . ' (versión ' . $version . ')' . ($cuotaId ? ' y cuota nueva de extras' : ''));
            // 4) Si el evento ya tiene contrato firmado: anexo para que el cliente lo firme (migración 090)
            $anexoId = (new Anexos($this->db))->crearParaPedido($negocioId, $pedidoId, $usuario, $hoy);
            $sinFirmar = $anexoId ? null : $this->q("SELECT numero FROM contratos WHERE negocio_id = ? AND evento_id = ? AND estado IN ('borrador','enviado')
                                                      ORDER BY numero DESC LIMIT 1", [$negocioId, $eventoId])->fetchColumn();
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['cotizacion_id' => $nueva, 'numero' => $numero, 'version' => $version, 'monto' => $monto, 'total' => (float) $tot['total'], 'cuota_id' => $cuotaId,
                'anexo_id' => $anexoId, 'contrato_sin_firmar' => $sinFirmar === false || $sinFirmar === null ? null : (int) $sinFirmar];
    }

    public function rechazar(int $negocioId, int $pedidoId, array $usuario, string $nota): void
    {
        $nota = trim($nota);
        if (mb_strlen($nota) < 3) throw new RuntimeException('Escribe una nota para el cliente: por qué no se puede (por ejemplo "Ese día la fotocabina ya está reservada").');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $p = $this->pedidoParaResponder($negocioId, $pedidoId);
            $this->q("UPDATE extras_pedidos SET estado = 'rechazado', nota_negocio = ?, respondido_en = now(), respondido_por = ? WHERE id = ?",
                     [mb_substr($nota, 0, 400), $usuario['id'] ?? null, $pedidoId]);
            $this->historial($negocioId, (int) $p['evento_id'], $usuario, $usuario['nombre'] ?? 'Sistema',
                'No aprobó los extras del portal (' . self::resumenLineas($this->items($pedidoId)) . '): ' . mb_substr($nota, 0, 150));
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Listas
    // ------------------------------------------------------------------

    public function items(int $pedidoId): array
    {
        return $this->q('SELECT * FROM extras_pedido_items WHERE pedido_id = ? ORDER BY id', [$pedidoId])->fetchAll();
    }

    private function conItems(array $pedidos): array
    {
        foreach ($pedidos as &$p) $p['items'] = $this->items((int) $p['id']);
        return $pedidos;
    }

    private const SELECT = 'SELECT x.*, e.codigo, e.nombre AS evento, e.fecha AS evento_fecha, e.token_cliente, c.nombre AS cliente, c.telefono,
                                   u.nombre AS respondio, co.numero AS cotizacion_numero, co.version AS cotizacion_version, cu.concepto AS cuota_concepto
                              FROM extras_pedidos x JOIN eventos e ON e.id = x.evento_id JOIN clientes c ON c.id = e.cliente_id
                         LEFT JOIN usuarios u ON u.id = x.respondido_por LEFT JOIN cotizaciones co ON co.id = x.cotizacion_id
                         LEFT JOIN cuotas cu ON cu.id = x.cuota_id';

    public function pedido(int $negocioId, int $pedidoId): ?array
    {
        $p = $this->q(self::SELECT . ' WHERE x.id = ? AND x.negocio_id = ?', [$pedidoId, $negocioId])->fetch();
        return $p ? $this->conItems([$p])[0] : null;
    }

    public function deEvento(int $negocioId, int $eventoId): array
    {
        return $this->conItems($this->q(self::SELECT . ' WHERE x.negocio_id = ? AND x.evento_id = ? ORDER BY x.creado_en DESC, x.id DESC',
                                        [$negocioId, $eventoId])->fetchAll());
    }

    public function pendientes(int $negocioId): array
    {
        return $this->conItems($this->q(self::SELECT . " WHERE x.negocio_id = ? AND x.estado = 'pendiente' ORDER BY x.creado_en, x.id", [$negocioId])->fetchAll());
    }

    public function recientes(int $negocioId, int $limite = 30): array
    {
        return $this->conItems($this->q(self::SELECT . " WHERE x.negocio_id = ? AND x.estado <> 'pendiente' ORDER BY x.respondido_en DESC NULLS LAST, x.id DESC LIMIT " . (int) $limite,
                                        [$negocioId])->fetchAll());
    }

    public function cuantosPendientes(int $negocioId, ?int $eventoId = null): int
    {
        return (int) $this->q("SELECT count(*) FROM extras_pedidos WHERE negocio_id = ? AND estado = 'pendiente'" . ($eventoId ? ' AND evento_id = ' . (int) $eventoId : ''),
                              [$negocioId])->fetchColumn();
    }

    /** Mensaje de WhatsApp para avisarle al cliente la respuesta. */
    public static function textoRespuesta(array $p, string $negocio, string $portal): string
    {
        $nombre = trim(explode(' ', trim((string) $p['cliente']))[0]);
        $lista = self::resumenLineas($p['items']);
        if ($p['estado'] === 'aprobado') {
            return "Hola $nombre, te saludamos de $negocio. ¡Aprobamos los extras que pediste ($lista)! Ya están sumados a tu plan de pagos"
                 . ((float) $p['monto_agregado'] > 0 ? ' (' . self::plata((float) $p['monto_agregado']) . ')' : '') . '. Míralo en tu portal: ' . $portal
                 . ($p['nota_negocio'] ? ' Nota: ' . $p['nota_negocio'] : '');
        }
        return "Hola $nombre, te saludamos de $negocio. Revisamos tu pedido de extras ($lista) y esta vez no lo podemos aprobar"
             . ($p['nota_negocio'] ? ': ' . $p['nota_negocio'] : '.') . ' Si quieres, lo conversamos por aquí. Tu portal: ' . $portal;
    }

    private function historial(int $negocioId, int $eventoId, ?array $usuario, string $quien, string $accion): void
    {
        $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                 [$negocioId, $eventoId, $usuario['id'] ?? null, mb_substr($quien, 0, 120), mb_substr($accion, 0, 400)]);
    }
}
