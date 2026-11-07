<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Incidencias: problemas de un evento (o del negocio), con o sin proveedor.
 * Área, gravedad, quién reportó, responsable, estado (abierta → en proceso → resuelta), resolución,
 * costo del problema y, si fue culpa de un proveedor, un descuento que baja el saldo de su compra.
 * Las graves avisan al dueño (campana y Telegram).
 */
final class Incidencias
{
    public const AREAS = [
        'proveedor' => 'Proveedor', 'cliente' => 'Cliente', 'invitados' => 'Invitados', 'personal' => 'Personal',
        'mobiliario' => 'Mobiliario', 'instalaciones' => 'Instalaciones', 'seguridad' => 'Seguridad', 'otro' => 'Otro',
    ];
    public const GRAVEDADES = ['leve' => 'Leve', 'media' => 'Media', 'grave' => 'Grave'];
    public const ESTADOS = ['abierta' => 'Abierta', 'en_proceso' => 'En proceso', 'resuelta' => 'Resuelta'];

    public function __construct(private PDO $db) {}

    private function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st;
    }

    private const SELECT = 'SELECT i.*, e.codigo AS evento_codigo, e.nombre AS evento, e.fecha AS evento_fecha, p.nombre AS proveedor,
                                   c.numero AS compra_numero, u.nombre AS reportado
                              FROM incidencias i LEFT JOIN eventos e ON e.id = i.evento_id LEFT JOIN proveedores p ON p.id = i.proveedor_id
                         LEFT JOIN compras c ON c.id = i.compra_id LEFT JOIN usuarios u ON u.id = i.reportado_por';

    public function incidencia(int $negocioId, int $id): ?array
    {
        return $this->q(self::SELECT . ' WHERE i.id = ? AND i.negocio_id = ?', [$id, $negocioId])->fetch() ?: null;
    }

    /** Filtros: estado ('abiertas' = abierta o en proceso, o un estado), evento_id, proveedor_id, gravedad. */
    public function lista(int $negocioId, array $f = [], int $limite = 300): array
    {
        $w = ['i.negocio_id = ?'];
        $p = [$negocioId];
        $estado = (string) ($f['estado'] ?? '');
        if ($estado === 'abiertas') $w[] = "i.estado IN ('abierta','en_proceso')";
        elseif (isset(self::ESTADOS[$estado])) { $w[] = 'i.estado = ?'; $p[] = $estado; }
        if (!empty($f['evento_id'])) { $w[] = 'i.evento_id = ?'; $p[] = (int) $f['evento_id']; }
        if (!empty($f['proveedor_id'])) { $w[] = 'i.proveedor_id = ?'; $p[] = (int) $f['proveedor_id']; }
        if (isset(self::GRAVEDADES[(string) ($f['gravedad'] ?? '')])) { $w[] = 'i.gravedad = ?'; $p[] = $f['gravedad']; }
        return $this->q(self::SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY (i.estado = 'resuelta'), array_position(ARRAY['grave','media','leve']::varchar[], i.gravedad),
                        i.creado_en DESC LIMIT " . max(1, $limite), $p)->fetchAll();
    }

    public function deEvento(int $negocioId, int $eventoId): array
    {
        return $this->lista($negocioId, ['evento_id' => $eventoId]);
    }

    public function abiertas(int $negocioId): int
    {
        return (int) $this->q("SELECT count(*) FROM incidencias WHERE negocio_id = ? AND estado IN ('abierta','en_proceso')", [$negocioId])->fetchColumn();
    }

    /** Revisa evento, proveedor y compra (todos del negocio y que cuadren entre sí). */
    private function relaciones(int $negocioId, array $d): array
    {
        $ev = (int) ($d['evento_id'] ?? 0) ?: null;
        if ($ev && !$this->q('SELECT 1 FROM eventos WHERE id = ? AND negocio_id = ?', [$ev, $negocioId])->fetchColumn()) throw new RuntimeException('No se encontró el evento.');
        $prov = (int) ($d['proveedor_id'] ?? 0) ?: null;
        if ($prov && !$this->q('SELECT 1 FROM proveedores WHERE id = ? AND negocio_id = ?', [$prov, $negocioId])->fetchColumn()) throw new RuntimeException('No se encontró el proveedor.');
        $compra = (int) ($d['compra_id'] ?? 0) ?: null;
        if ($compra) {
            $c = $this->q('SELECT proveedor_id, evento_id FROM compras WHERE id = ? AND negocio_id = ?', [$compra, $negocioId])->fetch();
            if (!$c) throw new RuntimeException('No se encontró la compra.');
            if ($prov && (int) $c['proveedor_id'] !== $prov) throw new RuntimeException('Esa compra es de otro proveedor.');
            $prov ??= (int) $c['proveedor_id'];
        }
        return [$ev, $prov, $compra];
    }

    /** Lo que se puede descontar de una compra (su saldo, sin contar el descuento de esta misma incidencia). */
    private function descuentoMaximo(int $negocioId, int $compraId, ?int $excluir = null): float
    {
        $c = (new Compras($this->db))->compra($negocioId, $compraId);
        if (!$c) throw new RuntimeException('No se encontró la compra.');
        if (!in_array($c['estado'], Compras::ACTIVAS, true)) throw new RuntimeException('Solo se descuenta de una compra pedida, confirmada o entregada.');
        $propio = $excluir ? (float) $this->q('SELECT descuento FROM incidencias WHERE id = ?', [$excluir])->fetchColumn() : 0.0;
        return round($c['base'] - $c['retencion'] - $c['descuentos'] + $propio - $c['pagado'], 2);
    }

    private static function monto(mixed $v, string $que): float
    {
        $m = trim((string) $v) === '' ? 0.0 : round(Cotizaciones::num($v), 2);
        if ($m < 0 || $m > 9999999) throw new RuntimeException("Revisa el $que.");
        return $m;
    }

    /**
     * Reporta una incidencia. $d: evento_id, proveedor_id, compra_id, area, gravedad, descripcion, responsable, costo, descuento.
     * $foto = $_FILES[...] opcional. Devuelve el id.
     */
    public function crear(int $negocioId, array $d, ?array $foto = null, ?array $usuario = null): int
    {
        [$ev, $prov, $compra] = $this->relaciones($negocioId, $d);
        $area = (string) ($d['area'] ?? '') ?: ($prov ? 'proveedor' : 'otro');
        if (!isset(self::AREAS[$area])) throw new RuntimeException('Elige el área del problema.');
        $grav = (string) ($d['gravedad'] ?? 'leve');
        if (!isset(self::GRAVEDADES[$grav])) throw new RuntimeException('Elige qué tan grave es.');
        $desc = trim((string) ($d['descripcion'] ?? ''));
        if (mb_strlen($desc) < 5) throw new RuntimeException('Cuenta qué pasó (por ejemplo: "El DJ llegó sin el micrófono inalámbrico").');
        $costo = self::monto($d['costo'] ?? '', 'costo del problema');
        $descuento = self::monto($d['descuento'] ?? '', 'descuento al proveedor');
        if ($descuento > 0) {
            if (!$compra) throw new RuntimeException('Para descontarle al proveedor, elige de qué compra se descuenta.');
            $max = $this->descuentoMaximo($negocioId, $compra);
            if ($descuento > $max + 0.004) throw new RuntimeException('El descuento no puede ser mayor que el saldo de la compra (' . Pdf::dinero(max(0, $max)) . ').');
        }
        $resp = mb_substr(trim((string) ($d['responsable'] ?? '')), 0, 120) ?: null;
        $archivo = Adjuntos::llego($foto) ? Fotos::guardarImagen($foto, Adjuntos::carpeta(), 1600) : null;
        $id = (int) $this->q('INSERT INTO incidencias (negocio_id, evento_id, proveedor_id, compra_id, area, gravedad, descripcion, foto, reportado_por, reportado_nombre,
                                                       responsable, costo, descuento) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',
                             [$negocioId, $ev, $prov, $compra, $area, $grav, mb_substr($desc, 0, 3000), $archivo, $usuario['id'] ?? null,
                              mb_substr((string) ($usuario['nombre'] ?? 'Sistema'), 0, 120), $resp, $costo, $descuento])->fetchColumn();
        $texto = 'Incidencia ' . mb_strtolower(self::GRAVEDADES[$grav]) . ' (' . self::AREAS[$area] . '): ' . mb_substr($desc, 0, 200)
               . ($descuento > 0 ? ' · descuento ' . Pdf::dinero($descuento) : '');
        if ($prov) {
            (new ProveedoresFicha($this->db))->anotar($negocioId, $prov, 'incidencia', $texto, $usuario, 'usuario', $compra, $ev);
        } elseif ($ev) {
            $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                     [$negocioId, $ev, $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', mb_substr($texto, 0, 400)]);
        }
        if ($grav === 'grave') {
            $nombreEv = $ev ? (string) $this->q('SELECT nombre FROM eventos WHERE id = ?', [$ev])->fetchColumn() : '';
            $nombreProv = $prov ? (string) $this->q('SELECT nombre FROM proveedores WHERE id = ?', [$prov])->fetchColumn() : '';
            (new Notificaciones($this->db))->alNegocio($negocioId, '🚨 Incidencia grave' . ($nombreEv ? ' en ' . $nombreEv : '') . ($nombreProv ? ' con ' . $nombreProv : '')
                . ': ' . mb_substr($desc, 0, 180) . ' — reportó ' . ($usuario['nombre'] ?? 'alguien del equipo') . '.', $ev, ['dueno']);
        }
        return $id;
    }

    /** Actualiza: estado, resolucion, responsable, gravedad y (si $conDinero) costo, descuento y compra_id. */
    public function actualizar(int $negocioId, int $id, array $d, ?array $usuario = null, bool $conDinero = true): void
    {
        $i = $this->incidencia($negocioId, $id);
        if (!$i) throw new RuntimeException('No se encontró esa incidencia.');
        $estado = (string) ($d['estado'] ?? $i['estado']);
        if (!isset(self::ESTADOS[$estado])) throw new RuntimeException('Elige el estado.');
        $grav = (string) ($d['gravedad'] ?? $i['gravedad']);
        if (!isset(self::GRAVEDADES[$grav])) throw new RuntimeException('Elige qué tan grave es.');
        $resol = array_key_exists('resolucion', $d) ? (mb_substr(trim((string) $d['resolucion']), 0, 3000) ?: null) : $i['resolucion'];
        if ($estado === 'resuelta' && $resol === null) throw new RuntimeException('Escribe cómo se resolvió.');
        $resp = array_key_exists('responsable', $d) ? (mb_substr(trim((string) $d['responsable']), 0, 120) ?: null) : $i['responsable'];
        $costo = (float) $i['costo'];
        $descuento = (float) $i['descuento'];
        $compra = $i['compra_id'] ? (int) $i['compra_id'] : null;
        if ($conDinero) {
            if (array_key_exists('costo', $d)) $costo = self::monto($d['costo'], 'costo del problema');
            if (array_key_exists('compra_id', $d)) {
                [, , $compra] = $this->relaciones($negocioId, ['compra_id' => $d['compra_id'], 'proveedor_id' => $i['proveedor_id']]);
            }
            if (array_key_exists('descuento', $d)) $descuento = self::monto($d['descuento'], 'descuento al proveedor');
            if ($descuento > 0) {
                if (!$compra) throw new RuntimeException('Para descontarle al proveedor, elige de qué compra se descuenta.');
                $max = $this->descuentoMaximo($negocioId, $compra, $compra === (int) $i['compra_id'] ? $id : null);
                if ($descuento > $max + 0.004) throw new RuntimeException('El descuento no puede ser mayor que el saldo de la compra (' . Pdf::dinero(max(0, $max)) . ').');
            }
        }
        $this->q('UPDATE incidencias SET estado = ?, gravedad = ?, resolucion = ?, responsable = ?, costo = ?, descuento = ?, compra_id = ?,
                         resuelta_en = CASE WHEN ? = \'resuelta\' THEN COALESCE(resuelta_en, now()) ELSE NULL END, actualizado_en = now() WHERE id = ?',
                 [$estado, $grav, $resol, $resp, $costo, $descuento, $compra, $estado, $id]);
        $cambios = [];
        if ($estado !== $i['estado']) $cambios[] = 'pasó a ' . mb_strtolower(self::ESTADOS[$estado]);
        if ($grav !== $i['gravedad']) $cambios[] = 'gravedad ' . mb_strtolower(self::GRAVEDADES[$grav]);
        if (abs($descuento - (float) $i['descuento']) > 0.004) $cambios[] = 'descuento ' . Pdf::dinero($descuento);
        if (abs($costo - (float) $i['costo']) > 0.004) $cambios[] = 'costo ' . Pdf::dinero($costo);
        if ($resol !== $i['resolucion'] && $resol) $cambios[] = 'resolución: ' . mb_substr($resol, 0, 150);
        if (!$cambios) return;
        $texto = 'Incidencia «' . mb_substr($i['descripcion'], 0, 60) . '»: ' . implode(', ', $cambios);
        if ($i['proveedor_id']) {
            (new ProveedoresFicha($this->db))->anotar($negocioId, (int) $i['proveedor_id'], 'incidencia', $texto, $usuario, 'usuario', $compra, $i['evento_id'] ? (int) $i['evento_id'] : null);
        } elseif ($i['evento_id']) {
            $this->q('INSERT INTO registro_cambios (negocio_id, evento_id, usuario_id, quien, accion) VALUES (?,?,?,?,?)',
                     [$negocioId, $i['evento_id'], $usuario['id'] ?? null, $usuario['nombre'] ?? 'Sistema', mb_substr($texto, 0, 400)]);
        }
        if ($grav === 'grave' && $i['gravedad'] !== 'grave') {
            (new Notificaciones($this->db))->alNegocio($negocioId, '🚨 Incidencia grave' . ($i['evento'] ? ' en ' . $i['evento'] : '') . ': ' . mb_substr($i['descripcion'], 0, 180),
                                                       $i['evento_id'] ? (int) $i['evento_id'] : null, ['dueno']);
        }
    }

    public function rutaFoto(int $negocioId, int $id): ?string
    {
        $i = $this->incidencia($negocioId, $id);
        return $i ? Adjuntos::ruta($i['foto']) : null;
    }
}
