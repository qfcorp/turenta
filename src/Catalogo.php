<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Catálogo del negocio: productos y servicios que se cotizan (por categoría) y paquetes armados con ellos.
 *  - Precio = lo que paga el cliente; costo = lo que le cuesta al negocio (solo lo ve el dueño).
 *  - Subir precios en bloque por % (todo el catálogo o una categoría), también los precios especiales de los paquetes.
 *  - Paquetes: productos con cantidad y precio especial, mínimo de invitados y tipo de evento.
 *    Los productos "por persona" se multiplican por los invitados (igual que Cotizaciones::aplicarPaquete).
 */
final class Catalogo
{
    public function __construct(private PDO $db) {}

    /** Margen en % sobre el precio: (precio − costo) / precio. Null si no hay precio. */
    public static function margen(float $precio, float $costo): ?float
    {
        if ($precio <= 0) return null;
        return round(($precio - $costo) * 100 / $precio, 1);
    }

    // ------------------------------------------------------------------
    // Productos
    // ------------------------------------------------------------------

    /** Lista de productos. $f: categoria, buscar, inactivos (bool). */
    public function productos(int $negocioId, array $f = []): array
    {
        $sql = 'SELECT p.*, (SELECT count(*) FROM paquete_items pi JOIN paquetes pq ON pq.id = pi.paquete_id
                                WHERE pi.producto_id = p.id AND pq.activo) AS en_paquetes
                  FROM productos p WHERE p.negocio_id = ?';
        $par = [$negocioId];
        if (empty($f['inactivos'])) $sql .= ' AND p.activo';
        $cat = (string) ($f['categoria'] ?? '');
        if ($cat !== '' && isset(Cotizaciones::CATEGORIAS[$cat])) {
            $sql .= ' AND p.categoria = ?';
            $par[] = $cat;
        }
        $q = trim((string) ($f['buscar'] ?? ''));
        if ($q !== '') {
            $sql .= " AND (p.nombre ILIKE ? OR COALESCE(p.descripcion,'') ILIKE ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $par[] = $like;
            $par[] = $like;
        }
        $st = $this->db->prepare($sql);
        $st->execute($par);
        $filas = $st->fetchAll();
        // Orden: por categoría (en el orden de la lista), luego orden y nombre
        $pos = array_flip(array_keys(Cotizaciones::CATEGORIAS));
        usort($filas, fn($a, $b) => [$pos[$a['categoria']] ?? 99, (int) $a['orden'], $a['nombre']] <=> [$pos[$b['categoria']] ?? 99, (int) $b['orden'], $b['nombre']]);
        return $filas;
    }

    public function producto(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM productos WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        return $st->fetch() ?: null;
    }

    /**
     * Crea o cambia un producto. $d: nombre, descripcion, categoria, unidad, precio, costo, con_iva, orden.
     * Si 'costo' no viene (vendedor), se mantiene el que tenía.
     */
    public function guardarProducto(int $negocioId, ?int $id, array $d): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre del producto o servicio.');
        if (mb_strlen($nombre) > 150) throw new RuntimeException('El nombre es muy largo (máximo 150 letras).');
        $cat = (string) ($d['categoria'] ?? 'otros');
        if (!isset(Cotizaciones::CATEGORIAS[$cat])) throw new RuntimeException('Elige la categoría.');
        $uni = (string) ($d['unidad'] ?? 'evento');
        if (!isset(Cotizaciones::UNIDADES[$uni])) throw new RuntimeException('Elige cómo se cobra (por persona, por evento…).');
        $precio = self::num($d['precio'] ?? null);
        if ($precio === null) throw new RuntimeException('Escribe el precio.');
        if ($precio < 0) throw new RuntimeException('El precio no puede ser negativo.');
        if ($precio > 99999999) throw new RuntimeException('El precio es demasiado alto.');
        $anterior = $id ? $this->producto($negocioId, $id) : null;
        if ($id && !$anterior) throw new RuntimeException('No se encontró el producto.');
        $costo = array_key_exists('costo', $d) ? (self::num($d['costo']) ?? 0.0) : (float) ($anterior['costo'] ?? 0);
        if ($costo < 0) throw new RuntimeException('El costo no puede ser negativo.');
        $desc = mb_substr(trim((string) ($d['descripcion'] ?? '')), 0, 600) ?: null;
        $conIva = !empty($d['con_iva']);
        $orden = isset($d['orden']) && $d['orden'] !== '' ? (int) $d['orden'] : (int) ($anterior['orden'] ?? 0);
        if ($id) {
            $this->db->prepare('UPDATE productos SET nombre = ?, descripcion = ?, categoria = ?, unidad = ?, precio = ?, costo = ?, con_iva = ?, orden = ?
                                 WHERE id = ? AND negocio_id = ?')
                     ->execute([$nombre, $desc, $cat, $uni, round($precio, 2), round($costo, 2), $conIva ? 'true' : 'false', $orden, $id, $negocioId]);
            return $id;
        }
        if (!isset($d['orden']) || $d['orden'] === '') {
            $st = $this->db->prepare('SELECT COALESCE(max(orden), -1) + 1 FROM productos WHERE negocio_id = ? AND categoria = ?');
            $st->execute([$negocioId, $cat]);
            $orden = (int) $st->fetchColumn();
        }
        $st = $this->db->prepare('INSERT INTO productos (negocio_id, categoria, nombre, descripcion, unidad, precio, costo, con_iva, orden)
                                  VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$negocioId, $cat, $nombre, $desc, $uni, round($precio, 2), round($costo, 2), $conIva ? 'true' : 'false', $orden]);
        return (int) $st->fetchColumn();
    }

    /** Activa o desactiva (no se borra: las cotizaciones viejas lo siguen mostrando). */
    public function activarProducto(int $negocioId, int $id, bool $activo): void
    {
        $st = $this->db->prepare('UPDATE productos SET activo = ? WHERE id = ? AND negocio_id = ?');
        $st->execute([$activo ? 'true' : 'false', $id, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el producto.');
    }

    /**
     * Sube (o baja, con % negativo) los precios en bloque. $categoria null = todo el catálogo activo.
     * También ajusta los precios especiales de esos productos dentro de los paquetes (si $paquetes).
     * Devuelve cuántos productos cambiaron.
     */
    public function subirPrecios(int $negocioId, float $pct, ?string $categoria = null, bool $paquetes = true): int
    {
        if ($pct == 0.0) throw new RuntimeException('Escribe el porcentaje (por ejemplo 5 para subir 5 %).');
        if ($pct < -50 || $pct > 100) throw new RuntimeException('El porcentaje debe estar entre −50 % y 100 %.');
        if ($categoria !== null && $categoria !== '' && !isset(Cotizaciones::CATEGORIAS[$categoria])) {
            throw new RuntimeException('Elige una categoría válida.');
        }
        $categoria = $categoria ?: null;
        $factor = 1 + $pct / 100;
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $filtro = 'negocio_id = ? AND activo' . ($categoria ? ' AND categoria = ?' : '');
            $par = $categoria ? [$negocioId, $categoria] : [$negocioId];
            $st = $this->db->prepare("UPDATE productos SET precio = round(precio * ?, 2) WHERE $filtro AND precio > 0");
            $st->execute([$factor, ...$par]);
            $n = $st->rowCount();
            if ($paquetes) {
                $this->db->prepare("UPDATE paquete_items SET precio = round(precio * ?, 2)
                                     WHERE precio IS NOT NULL AND precio > 0
                                       AND paquete_id IN (SELECT id FROM paquetes WHERE negocio_id = ?)
                                       AND producto_id IN (SELECT id FROM productos WHERE $filtro)")
                         ->execute([$factor, $negocioId, ...$par]);
            }
            if ($propia) $this->db->commit();
            return $n;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Productos activos para el buscador del editor de cotizaciones. $conCosto solo para el dueño. */
    public function paraBuscador(int $negocioId, bool $conCosto): array
    {
        $res = [];
        foreach ($this->productos($negocioId) as $p) {
            $fila = ['id' => (int) $p['id'], 'categoria' => $p['categoria'], 'nombre' => $p['nombre'],
                     'descripcion' => (string) $p['descripcion'], 'unidad' => $p['unidad'], 'precio' => (float) $p['precio'],
                     'con_iva' => (bool) $p['con_iva']];
            if ($conCosto) $fila['costo'] = (float) $p['costo'];
            $res[] = $fila;
        }
        return $res;
    }

    // ------------------------------------------------------------------
    // Paquetes
    // ------------------------------------------------------------------

    public function paquetes(int $negocioId, bool $inactivos = true): array
    {
        $st = $this->db->prepare('SELECT pq.*, (SELECT count(*) FROM paquete_items WHERE paquete_id = pq.id) AS productos
                                    FROM paquetes pq WHERE pq.negocio_id = ?' . ($inactivos ? '' : ' AND pq.activo') . '
                                   ORDER BY pq.activo DESC, pq.nombre');
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function paquete(int $negocioId, int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM paquetes WHERE id = ? AND negocio_id = ?');
        $st->execute([$id, $negocioId]);
        $p = $st->fetch();
        if (!$p) return null;
        $p['items'] = $this->itemsPaquete($id);
        return $p;
    }

    public function itemsPaquete(int $paqueteId): array
    {
        $st = $this->db->prepare('SELECT pi.id, pi.producto_id, pi.cantidad, pi.precio AS precio_paquete,
                                         p.nombre, p.descripcion, p.categoria, p.unidad, p.precio AS precio_normal, p.costo, p.con_iva, p.activo
                                    FROM paquete_items pi JOIN productos p ON p.id = pi.producto_id
                                   WHERE pi.paquete_id = ? ORDER BY p.orden, p.id');
        $st->execute([$paqueteId]);
        return $st->fetchAll();
    }

    /**
     * Crea o cambia un paquete. $d: nombre, descripcion, tipo_evento, minimo_invitados.
     * $items: [producto_id, cantidad, precio (vacío = precio del producto)].
     */
    public function guardarPaquete(int $negocioId, ?int $id, array $d, array $items): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 3) throw new RuntimeException('Escribe el nombre del paquete (por ejemplo "Paquete Boda Oro").');
        if (mb_strlen($nombre) > 120) throw new RuntimeException('El nombre es muy largo (máximo 120 letras).');
        $tipo = trim((string) ($d['tipo_evento'] ?? ''));
        if ($tipo !== '' && !isset(Eventos::TIPOS[$tipo])) throw new RuntimeException('Elige un tipo de evento válido.');
        $minimo = (int) ($d['minimo_invitados'] ?? 0);
        if ($minimo < 0 || $minimo > 5000) throw new RuntimeException('Revisa el mínimo de invitados.');
        $limpios = [];
        $vistos = [];
        foreach ($items as $i) {
            $pid = (int) ($i['producto_id'] ?? 0);
            if ($pid <= 0) continue;
            $prod = $this->producto($negocioId, $pid);
            if (!$prod) throw new RuntimeException('Uno de los productos ya no existe. Recarga la página.');
            if (isset($vistos[$pid])) throw new RuntimeException('"' . $prod['nombre'] . '" está dos veces en el paquete. Déjalo una sola vez.');
            $vistos[$pid] = true;
            $cant = self::num($i['cantidad'] ?? null) ?? 1.0;
            if ($cant <= 0) throw new RuntimeException('La cantidad de "' . $prod['nombre'] . '" debe ser mayor que 0.');
            $precio = self::num($i['precio'] ?? null);
            if ($precio !== null && $precio < 0) throw new RuntimeException('El precio de "' . $prod['nombre'] . '" no puede ser negativo.');
            $limpios[] = [$pid, round($cant, 2), $precio === null ? null : round($precio, 2)];
        }
        if (!$limpios) throw new RuntimeException('Agrega al menos un producto al paquete.');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $desc = mb_substr(trim((string) ($d['descripcion'] ?? '')), 0, 800) ?: null;
            if ($id) {
                $st = $this->db->prepare('UPDATE paquetes SET nombre = ?, descripcion = ?, tipo_evento = ?, minimo_invitados = ? WHERE id = ? AND negocio_id = ?');
                $st->execute([$nombre, $desc, $tipo ?: null, $minimo, $id, $negocioId]);
                if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el paquete.');
                $this->db->prepare('DELETE FROM paquete_items WHERE paquete_id = ?')->execute([$id]);
            } else {
                $st = $this->db->prepare('INSERT INTO paquetes (negocio_id, nombre, descripcion, tipo_evento, minimo_invitados) VALUES (?,?,?,?,?) RETURNING id');
                $st->execute([$negocioId, $nombre, $desc, $tipo ?: null, $minimo]);
                $id = (int) $st->fetchColumn();
            }
            $ins = $this->db->prepare('INSERT INTO paquete_items (paquete_id, producto_id, cantidad, precio) VALUES (?,?,?,?)');
            foreach ($limpios as [$pid, $cant, $precio]) $ins->execute([$id, $pid, $cant, $precio]);
            if ($propia) $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function activarPaquete(int $negocioId, int $id, bool $activo): void
    {
        $st = $this->db->prepare('UPDATE paquetes SET activo = ? WHERE id = ? AND negocio_id = ?');
        $st->execute([$activo ? 'true' : 'false', $id, $negocioId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No se encontró el paquete.');
    }

    /** Copia un paquete con todos sus productos ("Copia de …"). Devuelve el id nuevo. */
    public function duplicarPaquete(int $negocioId, int $id): int
    {
        $p = $this->paquete($negocioId, $id);
        if (!$p) throw new RuntimeException('No se encontró el paquete.');
        $nombre = mb_substr('Copia de ' . $p['nombre'], 0, 120);
        $items = array_map(fn($i) => ['producto_id' => $i['producto_id'], 'cantidad' => $i['cantidad'], 'precio' => $i['precio_paquete']], $p['items']);
        if (!$items) {
            $st = $this->db->prepare('INSERT INTO paquetes (negocio_id, nombre, descripcion, tipo_evento, minimo_invitados) VALUES (?,?,?,?,?) RETURNING id');
            $st->execute([$negocioId, $nombre, $p['descripcion'], $p['tipo_evento'], $p['minimo_invitados']]);
            return (int) $st->fetchColumn();
        }
        return $this->guardarPaquete($negocioId, null, ['nombre' => $nombre, 'descripcion' => $p['descripcion'],
            'tipo_evento' => $p['tipo_evento'], 'minimo_invitados' => $p['minimo_invitados']], $items);
    }

    /**
     * ¿Cuánto sale el paquete para N invitados? Mismo cálculo que la cotización (IVA por línea, % del negocio).
     * Si N es menor que el mínimo del paquete, se calcula con el mínimo (y se avisa).
     */
    public function simular(int $negocioId, int $paqueteId, int $invitados): array
    {
        $p = $this->paquete($negocioId, $paqueteId);
        if (!$p) throw new RuntimeException('No se encontró el paquete.');
        if ($invitados < 1) throw new RuntimeException('Escribe cuántos invitados.');
        $st = $this->db->prepare('SELECT iva_pct FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $ivaPct = (float) $st->fetchColumn();
        $calc = max($invitados, (int) $p['minimo_invitados']);
        $lineas = [];
        $conIva = $sinIva = $costo = 0.0;
        foreach ($p['items'] as $i) {
            $cant = round((float) $i['cantidad'] * ($i['unidad'] === 'persona' ? $calc : 1), 2);
            $precio = $i['precio_paquete'] !== null ? (float) $i['precio_paquete'] : (float) $i['precio_normal'];
            $total = round($cant * $precio, 2);
            if ($i['con_iva']) $conIva += $total; else $sinIva += $total;
            $costo += $cant * (float) $i['costo'];
            $lineas[] = ['nombre' => $i['nombre'], 'categoria' => $i['categoria'], 'unidad' => $i['unidad'], 'cantidad' => $cant,
                         'precio' => $precio, 'total' => $total, 'con_iva' => (bool) $i['con_iva']];
        }
        $t = Cotizaciones::calcularTotales($conIva, $sinIva, 0, $ivaPct);
        $costo = round($costo, 2);
        return $t + [
            'paquete' => $p, 'invitados' => $invitados, 'invitados_calculo' => $calc, 'bajo_minimo' => $calc > $invitados,
            'lineas' => $lineas, 'iva_pct' => $ivaPct, 'costo' => $costo, 'ganancia' => round($t['subtotal'] - $costo, 2),
            'por_persona' => round($t['total'] / $calc, 2),
        ];
    }

    /** "1.234,50" / "1234.50" / "" -> float|null */
    private static function num(mixed $v): ?float
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (float) $v;
        $v = str_replace(' ', '', trim((string) $v));
        if ($v === '') return null;
        if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
        if (!is_numeric($v)) throw new RuntimeException('Revisa los números: "' . $v . '" no es un valor válido.');
        return (float) $v;
    }
}
