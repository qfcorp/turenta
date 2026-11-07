<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * PDF del módulo de cobros: recibo de un pago y estado de cuenta del evento.
 * Se usan desde el sistema (/?r=pdf&tipo=recibo&id=<pago>, /?r=pdf&tipo=estado_cuenta&id=<evento>)
 * y desde el portal del cliente (?r=portal&t=<token>&recibo=<pago>).
 */
final class PdfCobros
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

    private static function fechaLarga(string $fecha): string
    {
        $d = new DateTimeImmutable($fecha);
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $d->format('j') . ' de ' . $meses[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }

    private static function fechaCorta(string $fecha): string
    {
        return (new DateTimeImmutable($fecha))->format('d/m/Y');
    }

    /** Saldo del evento justo después de este pago (pagos confirmados hasta ese momento). */
    private function saldoDespues(array $p): float
    {
        $st = $this->db->prepare("SELECT e.total - COALESCE((SELECT sum(monto) FROM pagos q WHERE q.evento_id = e.id AND q.tipo = 'evento' AND q.estado = 'confirmado'
                                                                AND (q.fecha < ?::timestamp OR (q.fecha = ?::timestamp AND q.id <= ?))),0)
                                    FROM eventos e WHERE e.id = ?");
        $st->execute([$p['fecha'], $p['fecha'], $p['id'], $p['evento_id']]);
        return max(0.0, round((float) $st->fetchColumn(), 2));
    }

    /**
     * Recibo de un pago confirmado (pago del evento o garantía recibida).
     * @return \TCPDF
     */
    public function recibo(int $negocioId, int $pagoId): object
    {
        $p = (new Pagos($this->db))->pago($negocioId, $pagoId);
        if (!$p) throw new RuntimeException('No se encontró el pago.');
        if ($p['estado'] !== 'confirmado' || !$p['numero_recibo']) {
            throw new RuntimeException('Este pago no tiene recibo (todavía no está confirmado o fue anulado).');
        }
        $n = $this->negocio($negocioId);
        $num = str_pad((string) $p['numero_recibo'], 5, '0', STR_PAD_LEFT);
        $pdf = Pdf::nuevo($n, 'Recibo N.º ' . $num);
        [$r, $g, $b] = Pdf::rgb((string) $n['color']);
        $h = [Pdf::class, 'h'];
        $monto = (float) $p['monto'];
        $esGarantia = $p['tipo'] === 'garantia';
        $saldo = $esGarantia ? null : $this->saldoDespues($p);
        $cuotas = $esGarantia ? [] : (new Pagos($this->db))->cuotas((int) $p['evento_id']);
        $concepto = $esGarantia ? 'Garantía por daños (se devuelve después del evento si no hay daños)' : 'Abono al evento';
        $metodo = Pagos::METODOS[$p['metodo']] ?? $p['metodo'];
        $color = sprintf('#%02X%02X%02X', $r, $g, $b);

        $html = '<table cellpadding="0" cellspacing="0" width="100%"><tr>'
            . '<td width="60%"><span style="font-size:8pt;color:#665A5E">FECHA DEL PAGO</span><br><b>' . $h(self::fechaLarga($p['fecha'])) . '</b></td>'
            . '<td width="40%" align="right"><span style="font-size:8pt;color:#665A5E">VALOR RECIBIDO</span><br>'
            . '<span style="font-size:18pt;font-weight:bold;color:' . $color . '">' . Pdf::dinero($monto) . '</span></td>'
            . '</tr></table><br>';
        $html .= '<table cellpadding="5" cellspacing="0" border="0" width="100%" style="border:0.3px solid #E6DCDD">'
            . '<tr><td width="34%" style="background-color:#F6F3F2"><b>Recibimos de</b></td><td width="66%">' . $h($p['cliente']) . '</td></tr>'
            . '<tr><td style="background-color:#F6F3F2"><b>Cédula / RUC</b></td><td>' . $h($p['cliente_identificacion'] ?: '—') . '</td></tr>'
            . '<tr><td style="background-color:#F6F3F2"><b>La cantidad de</b></td><td>' . $h(ucfirst(Letras::dolares($monto))) . '</td></tr>'
            . '<tr><td style="background-color:#F6F3F2"><b>Por concepto de</b></td><td>' . $h($concepto) . '</td></tr>'
            . '<tr><td style="background-color:#F6F3F2"><b>Evento</b></td><td>' . $h($p['codigo'] . ' · ' . $p['evento']) . '<br><span style="color:#665A5E">'
            . $h(self::fechaLarga($p['evento_fecha'])) . '</span></td></tr>'
            . '<tr><td style="background-color:#F6F3F2"><b>Forma de pago</b></td><td>' . $h($metodo) . ($p['referencia'] && $p['metodo'] !== 'payphone' ? ' · Ref. ' . $h($p['referencia']) : '')
            . ($p['metodo'] === 'payphone' && $p['notas'] ? ' · ' . $h($p['notas']) : '') . '</td></tr>';
        if ($saldo !== null) {
            $html .= '<tr><td style="background-color:#F6F3F2"><b>Saldo después de este pago</b></td><td><b>' . ($saldo > 0 ? Pdf::dinero($saldo) : '$0,00 · ¡Evento pagado por completo!') . '</b></td></tr>';
        }
        $html .= '</table>';

        if ($cuotas) {
            $html .= '<br><div style="font-size:9pt;font-weight:bold;color:' . $color . '">PLAN DE PAGOS DEL EVENTO (HOY)</div>'
                . '<table cellpadding="4" cellspacing="0" width="100%" style="font-size:8.5pt">'
                . '<tr style="color:#665A5E"><td width="40%">Concepto</td><td width="20%">Vence</td><td width="20%" align="right">Valor</td><td width="20%" align="right">Estado</td></tr>';
            foreach ($cuotas as $c) {
                $est = ['pagada' => 'Pagada', 'parcial' => 'Abonada', 'pendiente' => 'Pendiente', 'vencida' => 'Vencida'][$c['estado']];
                $html .= '<tr><td style="border-top:0.3px solid #E6DCDD">' . $h($c['concepto']) . '</td><td style="border-top:0.3px solid #E6DCDD">' . self::fechaCorta($c['vence'])
                    . '</td><td align="right" style="border-top:0.3px solid #E6DCDD">' . Pdf::dinero($c['monto']) . '</td><td align="right" style="border-top:0.3px solid #E6DCDD">' . $est . '</td></tr>';
            }
            $html .= '</table>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        // Firmas
        $y = max($pdf->GetY() + 22, 170.0);
        if ($y > 250) {
            $pdf->AddPage();
            $y = 70;
        }
        $pdf->SetDrawColor(150, 140, 144);
        $pdf->SetLineWidth(0.2);
        $pdf->Line(25, $y, 90, $y);
        $pdf->Line(120, $y, 185, $y);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(25, $y + 1);
        $pdf->Cell(65, 4, 'Recibí conforme · ' . ($p['registrado'] ?: $n['nombre']), 0, 0, 'C');
        $pdf->SetXY(120, $y + 1);
        $pdf->Cell(65, 4, 'Cliente · ' . $p['cliente'], 0, 0, 'C');

        $pdf->SetXY(15, $y + 14);
        $pdf->SetFillColor(251, 240, 211);
        $pdf->SetTextColor(106, 77, 13);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->MultiCell(0, 6, 'Este recibo no reemplaza a la factura. Si necesitas factura, pídela al local con tus datos.', 0, 'C', true);
        $pdf->SetTextColor(30, 26, 28);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetX(15);
        $pdf->Cell(0, 5, 'Recibo N.º ' . $num . ' · emitido el ' . date('d/m/Y H:i') . ' · ' . $n['nombre'], 0, 1, 'C');
        return $pdf;
    }

    /**
     * Estado de cuenta del evento: plan de cuotas, pagos, saldo y garantía.
     * @return \TCPDF
     */
    public function estadoCuenta(int $negocioId, int $eventoId, ?DateTimeImmutable $hoy = null): object
    {
        $hoy ??= new DateTimeImmutable('today');
        $ev = (new Eventos($this->db))->obtener($negocioId, $eventoId);
        if (!$ev) throw new RuntimeException('No se encontró el evento.');
        $n = $this->negocio($negocioId);
        $pag = new Pagos($this->db);
        $res = $pag->resumen($eventoId, $hoy);
        $pagos = array_reverse($pag->pagos($negocioId, $eventoId));
        $pdf = Pdf::nuevo($n, 'Estado de cuenta');
        [$r, $g, $b] = Pdf::rgb((string) $n['color']);
        $color = sprintf('#%02X%02X%02X', $r, $g, $b);
        $h = [Pdf::class, 'h'];
        $td = 'style="border-top:0.3px solid #E6DCDD"';

        $html = '<table cellpadding="2" width="100%"><tr>'
            . '<td width="60%"><span style="font-size:8pt;color:#665A5E">CLIENTE</span><br><b>' . $h($ev['cliente']) . '</b>'
            . ($ev['cliente_identificacion'] ? '<br>Cédula / RUC ' . $h($ev['cliente_identificacion']) : '') . '</td>'
            . '<td width="40%" align="right"><span style="font-size:8pt;color:#665A5E">EVENTO</span><br><b>' . $h($ev['codigo']) . '</b><br>'
            . $h($ev['nombre']) . '<br>' . $h(self::fechaLarga($ev['fecha'])) . ' · ' . substr($ev['hora_inicio'], 0, 5) . '</td></tr></table><br>';

        $html .= '<table cellpadding="6" cellspacing="3" width="100%"><tr>'
            . '<td style="background-color:#F6F3F2"><span style="font-size:7.5pt;color:#665A5E">VALOR DEL EVENTO</span><br><b style="font-size:12pt">' . Pdf::dinero($res['total']) . '</b></td>'
            . '<td style="background-color:#F6F3F2"><span style="font-size:7.5pt;color:#665A5E">PAGADO</span><br><b style="font-size:12pt;color:#1C5A31">' . Pdf::dinero($res['pagado']) . '</b></td>'
            . '<td style="background-color:#F6F3F2"><span style="font-size:7.5pt;color:#665A5E">SALDO</span><br><b style="font-size:12pt;color:' . $color . '">' . Pdf::dinero($res['saldo']) . '</b></td>'
            . '<td style="background-color:#F6F3F2"><span style="font-size:7.5pt;color:#665A5E">VENCIDO</span><br><b style="font-size:12pt;color:' . ($res['vencido'] > 0 ? '#B53D33' : '#1F1A1C') . '">' . Pdf::dinero($res['vencido']) . '</b></td>'
            . '</tr></table>';
        if ($res['a_favor'] > 0) {
            $html .= '<p>Saldo a favor del cliente: <b>' . Pdf::dinero($res['a_favor']) . '</b></p>';
        }

        $html .= '<br><div style="font-size:10pt;font-weight:bold;color:' . $color . '">PLAN DE PAGOS</div>';
        if ($res['cuotas']) {
            $html .= '<table cellpadding="4" width="100%" style="font-size:8.5pt"><tr style="color:#665A5E">'
                . '<td width="34%">Concepto</td><td width="16%">Vence</td><td width="16%" align="right">Valor</td><td width="16%" align="right">Pagado</td><td width="18%" align="right">Estado</td></tr>';
            foreach ($res['cuotas'] as $c) {
                $est = ['pagada' => 'Pagada', 'parcial' => 'Abonada', 'pendiente' => 'Pendiente', 'vencida' => 'Vencida'][$c['estado']];
                $html .= '<tr><td ' . $td . '>' . $h($c['concepto']) . '</td><td ' . $td . '>' . self::fechaCorta($c['vence']) . '</td>'
                    . '<td align="right" ' . $td . '>' . Pdf::dinero($c['monto']) . '</td><td align="right" ' . $td . '>' . Pdf::dinero($c['pagado']) . '</td>'
                    . '<td align="right" ' . $td . '>' . ($c['estado'] === 'vencida' ? '<b style="color:#B53D33">' . $est . '</b>' : $est) . '</td></tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p style="color:#665A5E">Todavía no hay plan de pagos.</p>';
        }

        $html .= '<br><div style="font-size:10pt;font-weight:bold;color:' . $color . '">PAGOS RECIBIDOS</div>';
        $filas = array_filter($pagos, fn($p) => $p['estado'] === 'confirmado');
        if ($filas) {
            $html .= '<table cellpadding="4" width="100%" style="font-size:8.5pt"><tr style="color:#665A5E">'
                . '<td width="15%">Fecha</td><td width="10%">Recibo</td><td width="39%">Concepto</td><td width="18%">Forma de pago</td><td width="18%" align="right">Valor</td></tr>';
            foreach ($filas as $p) {
                $neg = in_array($p['tipo'], ['devolucion_garantia', 'cargo_danos'], true);
                $html .= '<tr><td ' . $td . '>' . self::fechaCorta($p['fecha']) . '</td><td ' . $td . '>' . ($p['numero_recibo'] ? str_pad((string) $p['numero_recibo'], 5, '0', STR_PAD_LEFT) : '—') . '</td>'
                    . '<td ' . $td . '>' . $h(Pagos::TIPOS[$p['tipo']]) . ($p['referencia'] && $p['metodo'] !== 'payphone' ? ' · Ref. ' . $h($p['referencia']) : '') . '</td>'
                    . '<td ' . $td . '>' . $h(Pagos::METODOS[$p['metodo']] ?? $p['metodo']) . '</td>'
                    . '<td align="right" ' . $td . '>' . ($neg ? '−' : '') . Pdf::dinero($p['monto']) . '</td></tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p style="color:#665A5E">Todavía no hay pagos registrados.</p>';
        }
        $revisar = array_filter($pagos, fn($p) => $p['estado'] === 'por_revisar');
        if ($revisar) {
            $html .= '<p style="font-size:8.5pt;color:#7A5300">Comprobantes en revisión por el local: ' . count($revisar) . ' por '
                . Pdf::dinero(array_sum(array_map(fn($p) => (float) $p['monto'], $revisar))) . ' (no se suman hasta que se confirmen).</p>';
        }

        $gar = $res['garantia'];
        if ($gar['pedida'] > 0 || $gar['recibida'] > 0) {
            $html .= '<br><div style="font-size:10pt;font-weight:bold;color:' . $color . '">GARANTÍA POR DAÑOS</div>'
                . '<table cellpadding="4" width="100%" style="font-size:8.5pt">'
                . '<tr><td width="40%" ' . $td . '>Garantía pedida</td><td width="60%" align="right" ' . $td . '>' . Pdf::dinero($gar['pedida']) . '</td></tr>'
                . '<tr><td ' . $td . '>Recibida</td><td align="right" ' . $td . '>' . Pdf::dinero($gar['recibida']) . '</td></tr>'
                . ($gar['devuelta'] > 0 ? '<tr><td ' . $td . '>Devuelta</td><td align="right" ' . $td . '>' . Pdf::dinero($gar['devuelta']) . '</td></tr>' : '')
                . ($gar['retenida'] > 0 ? '<tr><td ' . $td . '>Retenida por daños</td><td align="right" ' . $td . '>' . Pdf::dinero($gar['retenida']) . '</td></tr>' : '')
                . '<tr><td ' . $td . '><b>En manos del local</b></td><td align="right" ' . $td . '><b>' . Pdf::dinero($gar['saldo']) . '</b></td></tr></table>'
                . '<p style="font-size:8pt;color:#665A5E">La garantía no forma parte del valor del evento: se devuelve después del evento si no hay daños.</p>';
        }

        $datos = array_filter([trim((string) $n['pago_banco']) ? 'Transferencia: ' . trim((string) $n['pago_banco']) : null,
                               trim((string) $n['pago_deuna']) ? 'DeUna: ' . trim((string) $n['pago_deuna']) : null]);
        if ($datos && $res['saldo'] > 0) {
            $html .= '<br><div style="font-size:10pt;font-weight:bold;color:' . $color . '">CÓMO PAGAR</div><p style="font-size:8.5pt">'
                . nl2br($h(implode("\n", $datos))) . '</p>';
        }
        $html .= '<br><p style="font-size:7.5pt;color:#665A5E">Estado de cuenta al ' . self::fechaLarga($hoy->format('Y-m-d'))
            . '. Este documento no reemplaza a la factura.</p>';
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf;
    }
}
