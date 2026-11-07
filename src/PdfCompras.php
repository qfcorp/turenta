<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * PDF de proveedores y compras:
 *   orden()        Orden de compra (o pedido de precio) con los datos del proveedor, ítems, condiciones y QR para verificarla.
 *   estadoCuenta() Estado de cuenta con un proveedor: compras, pagos y saldo.
 * Devuelven el objeto TCPDF; la página lo envía con Pdf::enviar().
 */
final class PdfCompras
{
    public function __construct(private PDO $db) {}

    private function negocio(int $negocioId): array
    {
        $st = $this->db->prepare('SELECT * FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $n = $st->fetch();
        if (!$n) throw new RuntimeException('No se encontró el negocio.');
        return $n;
    }

    private static function hex(array $n): string
    {
        [$r, $g, $b] = Pdf::rgb((string) ($n['color'] ?? '#6B2D3C'));
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    private static function f(?string $fecha): string
    {
        return $fecha ? (new DateTimeImmutable($fecha))->format('d/m/Y') : '—';
    }

    private static function cant(float|string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    }

    /** @return \TCPDF */
    public function orden(int $negocioId, int $compraId, string $enlaceVerificar): object
    {
        $compras = new Compras($this->db);
        $c = $compras->compra($negocioId, $compraId);
        if (!$c) throw new RuntimeException('No se encontró esa compra.');
        $items = $compras->items($compraId);
        $n = $this->negocio($negocioId);
        $p = (new ProveedoresFicha($this->db))->proveedor($negocioId, (int) $c['proveedor_id']);
        $color = self::hex($n);
        $h = [Pdf::class, 'h'];
        $esPrecio = $c['estado'] === 'cotizacion';
        $pdf = Pdf::nuevo($n, ($esPrecio ? 'Pedido de precio ' : 'Orden de compra ') . $c['codigo']);
        $pdf->write2DBarcode($enlaceVerificar, 'QRCODE,M', 173, 34, 22, 22, ['fgcolor' => [31, 26, 28], 'bgcolor' => [255, 255, 255]], 'N');
        $pdf->SetFont('dejavusans', '', 6);
        $pdf->SetXY(163, 56.5);
        $pdf->Cell(42, 3, 'Verificar: ' . $c['codigo_verificacion'], 0, 0, 'C');
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->SetXY(15, 36);

        $estado = Compras::ESTADOS[$c['estado']];
        $html = '<table cellpadding="3" cellspacing="0" width="84%" style="font-size:9pt">'
            . '<tr><td width="50%"><span style="color:#665A5E;font-size:7.5pt">PROVEEDOR</span><br><b>' . $h($p['razon_social'] ?: $p['nombre']) . '</b>'
            . ($p['razon_social'] && $p['razon_social'] !== $p['nombre'] ? '<br>' . $h($p['nombre']) : '')
            . ($p['ruc'] ? '<br>' . (strlen($p['ruc']) === 13 ? 'RUC ' : 'C.I. ') . $h($p['ruc']) : '')
            . ($p['direccion'] ? '<br>' . $h(trim($p['direccion'] . ($p['ciudad'] ? ', ' . $p['ciudad'] : ''))) : '')
            . ($p['contacto'] || $p['telefono'] ? '<br>' . $h(implode(' · ', array_filter([$p['contacto'], $p['telefono'], $p['email']]))) : '') . '</td>'
            . '<td width="50%"><span style="color:#665A5E;font-size:7.5pt">' . ($esPrecio ? 'PEDIDO' : 'ORDEN') . '</span><br><b style="font-size:12pt;color:' . $color . '">' . $h($c['codigo']) . '</b>'
            . '<br>Fecha: <b>' . self::f($c['fecha']) . '</b><br>Estado: <b>' . $h($estado) . '</b>'
            . ($c['evento'] ? '<br>Evento: <b>' . $h($c['evento_codigo'] . ' · ' . $c['evento']) . '</b><br>' . $h(DiaEvento::fechaLarga($c['evento_fecha'])) : '<br>Compra general del negocio')
            . '</td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->SetY(max($pdf->GetY(), 62));

        $filas = '';
        foreach ($items as $i => $it) {
            $fondo = $i % 2 ? '#F7F3F4' : '#FFFFFF';
            $filas .= '<tr nobr="true" style="background-color:' . $fondo . '"><td width="8%" align="right">' . self::cant($it['cantidad']) . '</td>'
                . '<td width="52%">' . $h($it['descripcion']) . (!$it['con_iva'] ? ' <span style="color:#8C8286;font-size:7pt">(IVA 0 %)</span>' : '') . '</td>'
                . '<td width="20%" align="right">' . ($esPrecio && (float) $it['precio_unitario'] <= 0 ? '' : Pdf::dinero($it['precio_unitario'])) . '</td>'
                . '<td width="20%" align="right">' . ($esPrecio && (float) $it['subtotal'] <= 0 ? '' : Pdf::dinero($it['subtotal'])) . '</td></tr>';
        }
        $html = '<table cellpadding="4" cellspacing="0" style="font-size:8.5pt"><thead><tr style="background-color:' . $color . ';color:#FFFFFF;font-weight:bold">'
            . '<th width="8%" align="right">Cant.</th><th width="52%">Descripción</th><th width="20%" align="right">Precio unitario</th><th width="20%" align="right">Valor</th></tr></thead>'
            . '<tbody>' . $filas . '</tbody></table>';
        $html .= '<table cellpadding="3" cellspacing="0" style="font-size:9pt"><tr><td width="60%"></td><td width="20%">Subtotal</td><td width="20%" align="right">' . Pdf::dinero($c['subtotal']) . '</td></tr>'
            . '<tr><td></td><td>IVA ' . rtrim(rtrim(number_format((float) $c['iva_pct'], 2, ',', ''), '0'), ',') . ' %</td><td align="right">' . Pdf::dinero($c['iva']) . '</td></tr>'
            . '<tr><td></td><td style="border-top:0.5px solid ' . $color . '"><b>Total</b></td><td align="right" style="border-top:0.5px solid ' . $color . '"><b>' . Pdf::dinero($c['total']) . '</b></td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        if (!$esPrecio) {
            $cond = [
                ['Forma de pago', ProveedoresFicha::textoCredito((int) $p['credito_dias']) === 'contado' ? 'De contado' : 'Crédito de ' . ProveedoresFicha::textoCredito((int) $p['credito_dias'])],
                ['Pago hasta', self::f($c['vence'])],
                ['Anticipo', $c['anticipo'] > 0 ? Pdf::dinero($c['anticipo']) : 'Sin anticipo'],
            ];
            if ($c['retencion'] > 0) $cond[] = ['Retención', Pdf::dinero($c['retencion'])];
            $ep = $c['evento_id'] ? $this->db->prepare('SELECT hora_llegada FROM evento_proveedores WHERE evento_id = ? AND proveedor_id = ? ORDER BY (compra_id = ?) DESC NULLS LAST LIMIT 1') : null;
            if ($ep) {
                $ep->execute([$c['evento_id'], $c['proveedor_id'], $compraId]);
                $llega = $ep->fetchColumn();
                if ($llega) $cond[] = ['Llegada al evento', substr((string) $llega, 0, 5)];
                $lugar = trim(implode(', ', array_filter([$n['nombre'], $n['direccion'] ?? '', $n['ciudad'] ?? ''])));
                $cond[] = ['Lugar', $lugar];
            }
            $html = '<table cellpadding="3" cellspacing="0" style="font-size:9pt"><tr><td style="border-bottom:1px solid ' . $color . ';color:' . $color . ';font-weight:bold">Condiciones</td></tr></table>'
                  . '<table cellpadding="3" cellspacing="0" style="font-size:9pt">';
            foreach ($cond as [$k, $v]) $html .= '<tr><td width="28%" style="color:#665A5E">' . $h($k) . '</td><td width="72%"><b>' . $h($v) . '</b></td></tr>';
            $html .= '</table>';
            if ($p['banco'] && $p['numero_cuenta']) {
                $html .= '<p style="font-size:8pt;color:#665A5E">Pagamos a: ' . $h($p['banco']) . ' · cuenta ' . $h(ProveedoresFicha::TIPOS_CUENTA[$p['tipo_cuenta'] ?? ''] ?? '') . ' ' . $h($p['numero_cuenta'])
                       . ($p['titular_cuenta'] ? ' · ' . $h($p['titular_cuenta']) : '') . '</p>';
            }
            $pdf->writeHTML($html, true, false, true, false, '');
        }
        if ($c['notas']) {
            $pdf->writeHTML('<p style="font-size:9pt"><b style="color:' . $color . '">Notas:</b> ' . nl2br($h($c['notas'])) . '</p>', true, false, true, false, '');
        }
        $texto = $esPrecio
            ? 'Esto es un pedido de precio, no una compra. Por favor envíanos tu mejor precio y disponibilidad.'
            : 'Por favor confirma esta orden y envía tu factura a nombre de ' . ($n['razon_social'] ?: $n['nombre']) . ($n['ruc'] ? ' (RUC ' . $n['ruc'] . ')' : '') . '.';
        $pdf->SetFillColor(251, 240, 211);
        $pdf->SetTextColor(106, 77, 13);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->MultiCell(0, 6, $texto, 0, 'C', true);
        $pdf->SetTextColor(30, 26, 28);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->Cell(0, 6, 'Verifica esta orden escaneando el QR o en ' . preg_replace('#^https?://#', '', $enlaceVerificar), 0, 1, 'C');
        return $pdf;
    }

    /** @return \TCPDF */
    public function estadoCuenta(int $negocioId, int $proveedorId, ?DateTimeImmutable $hoy = null): object
    {
        $hoy ??= new DateTimeImmutable('today');
        $p = (new ProveedoresFicha($this->db))->proveedor($negocioId, $proveedorId);
        if (!$p) throw new RuntimeException('No se encontró el proveedor.');
        $compras = new Compras($this->db);
        $cta = $compras->cuentaProveedor($negocioId, $proveedorId, $hoy);
        $pagos = array_values(array_filter($compras->pagosDeProveedor($negocioId, $proveedorId), fn($x) => $x['estado'] === 'valido'));
        $n = $this->negocio($negocioId);
        $color = self::hex($n);
        $h = [Pdf::class, 'h'];
        $pdf = Pdf::nuevo($n, 'Estado de cuenta del proveedor');
        $pdf->writeHTML('<h2 style="color:' . $color . '">' . $h($p['nombre']) . '</h2><p style="font-size:9pt;color:#665A5E">'
            . $h(implode(' · ', array_filter([$p['razon_social'], $p['ruc'] ? 'RUC ' . $p['ruc'] : null, $p['telefono']]))) . '<br>Al ' . $h(DiaEvento::fechaLarga($hoy->format('Y-m-d')))
            . ' · Crédito: ' . $h(ProveedoresFicha::textoCredito((int) $p['credito_dias'])) . ($p['cupo_credito'] !== null ? ' · Cupo: ' . Pdf::dinero($p['cupo_credito']) : '') . '</p>', true, false, true, false, '');
        $html = '<table cellpadding="5" cellspacing="0" style="font-size:9pt"><tr>'
            . '<td width="25%" style="background-color:#F6F3F2">Comprado<br><b>' . Pdf::dinero($cta['total']) . '</b></td>'
            . '<td width="25%" style="background-color:#F6F3F2">Pagado<br><b>' . Pdf::dinero($cta['pagado']) . '</b></td>'
            . '<td width="25%" style="background-color:#F6F3F2">Retenciones y descuentos<br><b>' . Pdf::dinero($cta['retenciones'] + $cta['descuentos']) . '</b></td>'
            . '<td width="25%" style="background-color:#F6F3F2">Saldo<br><b style="color:' . ($cta['saldo'] > 0 ? '#B53D33' : '#1C5A31') . '">' . Pdf::dinero($cta['saldo']) . '</b></td></tr></table><br>';
        $html .= '<table cellpadding="4" cellspacing="0" style="font-size:8pt"><thead><tr style="background-color:' . $color . ';color:#FFFFFF;font-weight:bold">'
            . '<th width="11%">Orden</th><th width="11%">Fecha</th><th width="24%">Evento</th><th width="13%">Factura</th><th width="11%" align="right">Valor</th>'
            . '<th width="10%" align="right">Pagado</th><th width="10%" align="right">Saldo</th><th width="10%">Vence</th></tr></thead><tbody>';
        foreach ($cta['compras'] as $i => $c) {
            if ($c['estado'] === 'cotizacion') continue;
            $html .= '<tr nobr="true" style="background-color:' . ($i % 2 ? '#F7F3F4' : '#FFFFFF') . '"><td width="11%">' . $h($c['codigo']) . '</td><td width="11%">' . self::f($c['fecha']) . '</td>'
                . '<td width="24%">' . $h($c['evento'] ?: 'Compra general') . '</td><td width="13%">' . $h($c['factura_numero'] ?: '—') . '</td>'
                . '<td width="11%" align="right">' . Pdf::dinero($c['base']) . '</td><td width="10%" align="right">' . Pdf::dinero($c['pagado']) . '</td>'
                . '<td width="10%" align="right"><b>' . Pdf::dinero($c['saldo']) . '</b></td><td width="10%">' . self::f($c['vence'])
                . ($c['estado_pago'] === 'vencida' ? '<br><span style="color:#B53D33">vencida</span>' : '') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($pagos) {
            $html .= '<br><div style="font-size:9pt;font-weight:bold;color:' . $color . '">PAGOS REALIZADOS</div>'
                . '<table cellpadding="4" cellspacing="0" style="font-size:8pt"><tr style="color:#665A5E"><td width="15%">Fecha</td><td width="15%">Orden</td><td width="25%">Forma de pago</td><td width="30%">Referencia</td><td width="15%" align="right">Valor</td></tr>';
            foreach ($pagos as $x) {
                $html .= '<tr><td style="border-top:0.3px solid #E6DCDD">' . self::f($x['fecha']) . '</td><td style="border-top:0.3px solid #E6DCDD">' . $h(Compras::codigo($x['numero'])) . '</td>'
                    . '<td style="border-top:0.3px solid #E6DCDD">' . $h(Compras::METODOS[$x['metodo']] ?? $x['metodo']) . ($x['cheque_fecha'] ? ' (para el ' . self::f($x['cheque_fecha']) . ')' : '') . '</td>'
                    . '<td style="border-top:0.3px solid #E6DCDD">' . $h($x['referencia'] ?? '') . '</td><td align="right" style="border-top:0.3px solid #E6DCDD">' . Pdf::dinero($x['monto']) . '</td></tr>';
            }
            $html .= '</table>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf;
    }
}
