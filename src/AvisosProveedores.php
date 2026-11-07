<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;

/**
 * Tareas automáticas de proveedores (las llama Automaticas::correr() cada hora, desde las 8:00).
 * Cada aviso sale una sola vez: se marca en la base con un UPDATE ... RETURNING que solo gana una vez.
 *  1) Pagos a proveedores que vencen en 3 días o ya vencieron: un aviso al día por compra (en una sola lista).
 *  2) Documentos de proveedores que vencen en 30 días o menos (una vez por fecha de vencimiento).
 *  3) Proveedores de un evento que no han confirmado y faltan 3 días o menos (una vez por fecha del evento).
 */
final class AvisosProveedores
{
    public const HORA = 8;
    public const DIAS_PAGO = 3;
    public const DIAS_CONFIRMAR = 3;

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

    /** Campana corta + Telegram con el texto largo. */
    private function avisar(int $negocioId, array $roles, string $corto, ?string $largo = null, ?int $eventoId = null, array $extra = []): void
    {
        $ids = $this->avisos->destinos($negocioId, $roles);
        if (!$ids) $ids = $this->avisos->destinos($negocioId, ['dueno']);
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', array_filter($extra)))));
        if (!$ids) return;
        $this->avisos->aUsuarios($negocioId, $ids, $corto, $eventoId, false);
        (new Telegram($this->db))->textoAUsuarios($ids, $largo ?? $corto);
    }

    /** 1) Pagos a proveedores que vencen pronto o ya vencieron. Devuelve cuántas compras entraron en el aviso. */
    public function avisarPagos(int $negocioId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA) return 0;
        $hoy = $ahora->format('Y-m-d');
        $st = $this->q("UPDATE compras c SET aviso_pago_dia = ?::date
                         WHERE c.negocio_id = ? AND " . Compras::SQL_ACTIVAS . " AND c.vence IS NOT NULL AND c.vence <= ?::date + " . self::DIAS_PAGO . "
                           AND c.aviso_pago_dia IS DISTINCT FROM ?::date AND " . Compras::SQL_SALDO . " > 0.004
                     RETURNING c.id", [$hoy, $negocioId, $hoy, $hoy]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return 0;
        $compras = new Compras($this->db);
        $lista = [];
        foreach ($ids as $id) if ($c = $compras->compra($negocioId, $id, $ahora->setTime(0, 0))) $lista[] = $c;
        usort($lista, fn($a, $b) => $a['vence'] <=> $b['vence']);
        $vencidas = count(array_filter($lista, fn($c) => $c['estado_pago'] === 'vencida'));
        $total = array_sum(array_column($lista, 'saldo'));
        $corto = '🧾 Pagos a proveedores: ' . count($lista) . (count($lista) === 1 ? ' compra' : ' compras') . ' por ' . Pdf::dinero($total)
               . ($vencidas ? " ($vencidas " . ($vencidas === 1 ? 'vencida' : 'vencidas') . ')' : '')
               . ' vencen hasta el ' . $ahora->modify('+' . self::DIAS_PAGO . ' days')->format('d/m') . '. Míralas en "Por pagar".';
        $largo = $corto . "\n";
        foreach (array_slice($lista, 0, 25) as $c) {
            $cuando = $c['vence'] < $hoy ? 'venció el ' . (new DateTimeImmutable($c['vence']))->format('d/m')
                    : ($c['vence'] === $hoy ? 'vence hoy' : 'vence el ' . (new DateTimeImmutable($c['vence']))->format('d/m'));
            $largo .= "\n• {$c['proveedor']} ({$c['codigo']}" . ($c['evento'] ? ', ' . $c['evento'] : '') . '): ' . Pdf::dinero($c['saldo']) . " — $cuando";
        }
        if (count($lista) > 25) $largo .= "\n… y " . (count($lista) - 25) . ' más.';
        $this->avisar($negocioId, ['dueno', 'vendedor'], $corto, $largo);
        return count($lista);
    }

    /** 2) Documentos que vencen en 30 días o menos (o ya vencieron y no se avisó). Devuelve cuántos documentos. */
    public function avisarDocumentos(int $negocioId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA) return 0;
        $hoy = $ahora->format('Y-m-d');
        $docs = $this->q("UPDATE proveedor_documentos d SET aviso_vence = d.vence
                            FROM proveedores p
                           WHERE p.id = d.proveedor_id AND d.negocio_id = ? AND p.activo AND d.vence IS NOT NULL
                             AND d.vence <= ?::date + " . ProveedoresFicha::DIAS_DOC . " AND d.aviso_vence IS DISTINCT FROM d.vence
                       RETURNING d.id, d.tipo, d.nombre, d.vence, p.nombre AS proveedor", [$negocioId, $hoy])->fetchAll();
        if (!$docs) return 0;
        usort($docs, fn($a, $b) => $a['vence'] <=> $b['vence']);
        $vencidos = count(array_filter($docs, fn($d) => $d['vence'] < $hoy));
        $corto = '📄 Documentos de proveedores: ' . count($docs) . (count($docs) === 1 ? ' vence' : ' vencen') . ' pronto'
               . ($vencidos ? " ($vencidos ya " . ($vencidos === 1 ? 'vencido' : 'vencidos') . ')' : '') . '. Pídeles el documento nuevo (permiso, póliza…).';
        $largo = $corto . "\n";
        foreach (array_slice($docs, 0, 25) as $d) {
            $largo .= "\n• {$d['proveedor']}: " . ProveedoresFicha::TIPOS_DOC[$d['tipo']] . ($d['nombre'] ? " ({$d['nombre']})" : '')
                    . ' — ' . ($d['vence'] < $hoy ? 'venció' : 'vence') . ' el ' . (new DateTimeImmutable($d['vence']))->format('d/m/Y');
        }
        $this->avisar($negocioId, ['dueno', 'coordinador'], $corto, $largo);
        return count($docs);
    }

    /** 3) Proveedores sin confirmar a 3 días del evento (o menos). Un aviso por evento. Devuelve cuántos proveedores. */
    public function avisarSinConfirmar(int $negocioId, DateTimeImmutable $ahora): int
    {
        if ((int) $ahora->format('G') < self::HORA) return 0;
        $hoy = $ahora->format('Y-m-d');
        $filas = $this->q("UPDATE evento_proveedores ep SET aviso_confirmar = e.fecha
                             FROM eventos e
                            WHERE e.id = ep.evento_id AND e.negocio_id = ? AND e.estado IN ('separado','confirmado')
                              AND e.fecha BETWEEN ?::date AND ?::date + " . self::DIAS_CONFIRMAR . "
                              AND NOT ep.confirmado AND ep.aviso_confirmar IS DISTINCT FROM e.fecha
                        RETURNING ep.id, ep.nombre, ep.telefono, ep.hora_llegada, e.id AS evento_id, e.nombre AS evento, e.fecha, e.coordinador_id, e.vendedor_id",
                          [$negocioId, $hoy, $hoy])->fetchAll();
        $porEvento = [];
        foreach ($filas as $f) $porEvento[(int) $f['evento_id']][] = $f;
        foreach ($porEvento as $eventoId => $provs) {
            $e = $provs[0];
            $dias = (int) (new DateTimeImmutable($hoy))->diff(new DateTimeImmutable($e['fecha']))->days;
            $cuando = $dias === 0 ? 'hoy' : ($dias === 1 ? 'mañana' : "en $dias días");
            $corto = '⏰ ' . $e['evento'] . ' es ' . $cuando . ' y ' . (count($provs) === 1 ? 'un proveedor no ha confirmado: ' : count($provs) . ' proveedores no han confirmado: ')
                   . implode(', ', array_map(fn($p) => $p['nombre'], array_slice($provs, 0, 5))) . (count($provs) > 5 ? '…' : '') . '. Escríbeles desde el día del evento.';
            $this->avisar($negocioId, ['dueno', 'coordinador'], $corto, null, $eventoId, [$e['coordinador_id'], $e['vendedor_id']]);
        }
        return count($filas);
    }

    /** Todo junto para un negocio. */
    public function correr(int $negocioId, DateTimeImmutable $ahora): array
    {
        return [
            'prov_pagos' => $this->avisarPagos($negocioId, $ahora),
            'prov_documentos' => $this->avisarDocumentos($negocioId, $ahora),
            'prov_confirmar' => $this->avisarSinConfirmar($negocioId, $ahora),
        ];
    }

    public static function resumen(array $r): string
    {
        return ' · pagos a proveedores ' . (int) ($r['prov_pagos'] ?? 0) . ' · documentos por vencer ' . (int) ($r['prov_documentos'] ?? 0)
             . ' · proveedores sin confirmar ' . (int) ($r['prov_confirmar'] ?? 0);
    }
}
