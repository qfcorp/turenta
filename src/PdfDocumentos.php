<?php
declare(strict_types=1);

namespace TuRenta;

/**
 * PDF de la cotización y del contrato (con la base src/Pdf.php).
 *  - Cotización: datos del cliente y del evento, detalle por categoría, totales con IVA, precio por persona,
 *    notas, condiciones, validez y un QR con el enlace para verla y aceptarla en línea.
 *  - Contrato: texto completo bien formateado, firma del cliente (imagen) con nombre, cédula, fecha e IP,
 *    línea de firma del local, QR de verificación (26 mm) con el código y la huella SHA-256.
 *    Si no está firmado lleva la marca de agua "BORRADOR – SIN FIRMAR".
 * No toca la base de datos: recibe los datos ya leídos (Cotizaciones::obtener / Contratos::obtener).
 */
final class PdfDocumentos
{
    /**
     * @param array  $negocio  fila de la tabla negocios
     * @param array  $cot      Cotizaciones::obtener()
     * @param ?string $enlace  dirección pública de la cotización (va en el QR)
     * @param string $espacios texto de los espacios del evento
     * @return \TCPDF
     */
    public static function cotizacion(array $negocio, array $cot, ?string $enlace = null, string $espacios = ''): object
    {
        $titulo = 'Cotización N.º ' . $cot['numero'] . ((int) $cot['version'] > 1 ? ' · versión ' . (int) $cot['version'] : '');
        $pdf = Pdf::nuevo($negocio, $titulo);
        $rgb = Pdf::rgb((string) ($negocio['color'] ?? '#6B2D3C'));
        $hex = self::hex($rgb);
        $claro = self::hex(self::aclarar($rgb, .9));
        $h = [Pdf::class, 'h'];

        // Estado y fechas
        $estado = Cotizaciones::ESTADOS[$cot['estado']] ?? $cot['estado'];
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(100, 90, 94);
        $pdf->Cell(0, 5, 'Fecha: ' . self::fechaCorta($cot['fecha']) . '   ·   Válida hasta: ' . self::fechaCorta($cot['valida_hasta']) . '   ·   Estado: ' . $estado, 0, 1, 'R');
        $pdf->Ln(1);

        // Datos del cliente y del evento
        $horario = substr((string) $cot['hora_inicio'], 0, 5) . ' a ' . substr((string) $cot['hora_fin'], 0, 5);
        $cliente = '<b>' . $h($cot['cliente']) . '</b>'
            . ($cot['cliente_identificacion'] ? '<br>Cédula/RUC: ' . $h($cot['cliente_identificacion']) : '')
            . ($cot['cliente_telefono'] ? '<br>Celular: ' . $h($cot['cliente_telefono']) : '')
            . ($cot['cliente_email'] ? '<br>Correo: ' . $h($cot['cliente_email']) : '');
        $evento = '<b>' . $h($cot['evento']) . '</b>'
            . '<br>' . $h(Eventos::TIPOS[$cot['tipo']] ?? '') . ' · ' . $h(self::fechaLarga($cot['evento_fecha']))
            . '<br>Horario: ' . $h($horario) . ($espacios !== '' ? ' · ' . $h($espacios) : '')
            . '<br>Invitados: ' . (int) $cot['invitados'];
        $pdf->SetTextColor(30, 26, 28);
        $pdf->writeHTML('<table cellpadding="6" cellspacing="0" style="font-size:9pt">
            <tr><td width="50%" style="background-color:' . $claro . ';border-left:2px solid ' . $hex . '"><span style="color:' . $hex . ';font-size:7.5pt">CLIENTE</span><br>' . $cliente . '</td>
                <td width="50%" style="background-color:' . $claro . ';border-left:2px solid ' . $hex . '"><span style="color:' . $hex . ';font-size:7.5pt">EVENTO</span><br>' . $evento . '</td></tr>
            </table>', true, false, false, false, '');
        $pdf->Ln(3);

        // Detalle por categoría
        $grupos = [];
        foreach ($cot['items'] as $i) $grupos[$i['categoria']][] = $i;
        $orden = array_keys(Cotizaciones::CATEGORIAS);
        uksort($grupos, fn($a, $b) => array_search($a, $orden, true) <=> array_search($b, $orden, true));
        $filas = '';
        foreach ($grupos as $cat => $lineas) {
            $sub = array_sum(array_map(fn($l) => (float) $l['total'], $lineas));
            $filas .= '<tr style="background-color:' . $claro . '"><td width="84%" colspan="3" style="color:' . $hex . '"><b>' . $h(Cotizaciones::CATEGORIAS[$cat] ?? 'Otros') . '</b></td>'
                    . '<td width="16%" align="right" style="color:' . $hex . '"><b>' . Pdf::dinero($sub) . '</b></td></tr>';
            foreach ($lineas as $l) {
                $filas .= '<tr nobr="true"><td width="55%">' . $h($l['descripcion']) . (!$l['con_iva'] ? ' <span style="color:#8a7d82;font-size:7pt">(0 % IVA)</span>' : '') . '</td>'
                        . '<td width="15%" align="right">' . self::cantidad((float) $l['cantidad']) . ' <span style="color:#8a7d82;font-size:7pt">' . $h(self::unidadCorta($l['unidad'], (float) $l['cantidad'])) . '</span></td>'
                        . '<td width="14%" align="right">' . Pdf::dinero($l['precio_unitario']) . '</td>'
                        . '<td width="16%" align="right">' . Pdf::dinero($l['total']) . '</td></tr>';
            }
        }
        if ($filas === '') $filas = '<tr><td colspan="4" align="center" style="color:#8a7d82">Esta cotización todavía no tiene productos.</td></tr>';
        $pdf->writeHTML('<table cellpadding="4" cellspacing="0" style="font-size:8.8pt;border-bottom:0.5px solid #ddd">
            <thead><tr style="background-color:' . $hex . ';color:#ffffff">
                <th width="55%"><b>Detalle</b></th><th width="15%" align="right"><b>Cantidad</b></th>
                <th width="14%" align="right"><b>Precio</b></th><th width="16%" align="right"><b>Total</b></th></tr></thead>
            <tbody>' . $filas . '</tbody></table>', true, false, false, false, '');
        $pdf->Ln(2);

        // Totales (a la derecha) y QR con el enlace en línea (a la izquierda)
        $iva = rtrim(rtrim(number_format((float) $cot['iva_pct'], 2, ',', ''), '0'), ',');
        $t = '<tr><td width="62%" align="right">Subtotal</td><td width="38%" align="right">' . Pdf::dinero($cot['subtotal']) . '</td></tr>';
        if ((float) $cot['descuento'] > 0) $t .= '<tr><td width="62%" align="right">Descuento</td><td width="38%" align="right">-' . Pdf::dinero($cot['descuento']) . '</td></tr>';
        $t .= '<tr><td width="62%" align="right">Base IVA ' . $iva . ' %</td><td width="38%" align="right">' . Pdf::dinero($cot['base_iva']) . '</td></tr>';
        if ((float) $cot['base_cero'] > 0) $t .= '<tr><td width="62%" align="right">Base 0 %</td><td width="38%" align="right">' . Pdf::dinero($cot['base_cero']) . '</td></tr>';
        $t .= '<tr><td width="62%" align="right">IVA ' . $iva . ' %</td><td width="38%" align="right">' . Pdf::dinero($cot['iva']) . '</td></tr>';
        $t .= '<tr style="background-color:' . $hex . ';color:#ffffff"><td width="62%" align="right"><b>TOTAL</b></td><td width="38%" align="right"><b>' . Pdf::dinero($cot['total']) . '</b></td></tr>';
        $t .= '<tr><td width="62%" align="right" style="color:' . $hex . '"><b>Precio por persona</b><br><span style="color:#8a7d82;font-size:7.5pt">' . (int) $cot['invitados'] . ' invitados, IVA incluido</span></td><td width="38%" align="right" style="color:' . $hex . '"><b>' . Pdf::dinero($cot['por_persona']) . '</b></td></tr>';
        self::saltoSiFalta($pdf, 46);
        $y = $pdf->GetY();
        if ($enlace) {
            $pdf->write2DBarcode($enlace, 'QRCODE,M', 15, $y + 1, 26, 26, ['fgcolor' => [30, 26, 28]], 'N');
            $pdf->SetXY(43, $y + 2);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
            $pdf->MultiCell(44, 4, 'Mírala y acéptala desde tu celular', 0, 'L', false, 1, 43);
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(100, 90, 94);
            $pdf->MultiCell(44, 3.6, 'Escanea el código con la cámara para verla en línea, aceptarla o escribirnos por WhatsApp.', 0, 'L', false, 1, 43);
            $pdf->SetTextColor(30, 26, 28);
        }
        $pdf->SetXY(15, $y);
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->writeHTML('<table cellpadding="0" cellspacing="0"><tr><td width="42%"></td><td width="58%">'
            . '<table cellpadding="3" cellspacing="0" style="font-size:9.5pt">' . $t . '</table></td></tr></table>', true, false, false, false, '');
        $pdf->SetY(max($pdf->GetY(), $y + 30) + 3);

        // Notas y condiciones
        $bloques = '';
        if (trim((string) $cot['notas']) !== '') $bloques .= '<p><b style="color:' . $hex . '">Notas</b><br>' . nl2br($h(trim((string) $cot['notas']))) . '</p>';
        if (trim((string) $cot['condiciones']) !== '') $bloques .= '<p><b style="color:' . $hex . '">Condiciones</b><br>' . nl2br($h(trim((string) $cot['condiciones']))) . '</p>';
        $bloques .= '<p><b style="color:' . $hex . '">Validez</b><br>Esta cotización es válida hasta el ' . $h(self::fechaLarga($cot['valida_hasta']))
                  . '. Pasada esa fecha, los precios pueden cambiar. La fecha del evento se asegura únicamente con el pago de la reserva.</p>';
        $pdf->SetFont('dejavusans', '', 8.8);
        $pdf->writeHTML('<div style="font-size:8.8pt;line-height:1.35">' . $bloques . '</div>', true, false, false, false, '');

        $pdf->SetTextColor(30, 26, 28);
        return $pdf;
    }

    /**
     * @param array   $negocio  fila de la tabla negocios
     * @param array   $c        Contratos::obtener()
     * @param ?string $enlaceVerificar dirección pública de verificación (va en el QR)
     * @return \TCPDF
     */
    public static function contrato(array $negocio, array $c, ?string $enlaceVerificar = null): object
    {
        $pdf = Pdf::nuevo($negocio, $c['documento'] ?? ('Contrato N.º ' . $c['numero']));   // documento: 'Anexo 1 al contrato N.º 3' (Anexos, 090)
        $doc = $c['documento_corto'] ?? 'Contrato';
        $rgb = Pdf::rgb((string) ($negocio['color'] ?? '#6B2D3C'));
        $hex = self::hex($rgb);
        $firmado = $c['estado'] === 'firmado';

        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(100, 90, 94);
        $pdf->Cell(0, 5, 'Evento ' . $c['evento_codigo'] . ' · ' . $c['evento'] . ' · ' . self::fechaCorta($c['evento_fecha'])
            . '   ·   Estado: ' . (Contratos::ESTADOS[$c['estado']] ?? $c['estado']), 0, 1, 'R');
        $pdf->Ln(2);

        // Texto del contrato
        $pdf->SetTextColor(30, 26, 28);
        $pdf->writeHTML(self::htmlContrato((string) $c['texto'], $hex), true, false, false, false, '');
        $pdf->Ln(4);

        // Firmas
        self::saltoSiFalta($pdf, 62);
        $y = $pdf->GetY();
        $ancho = ($pdf->getPageWidth() - 30 - 12) / 2;
        $xCli = 15;
        $xLoc = 15 + $ancho + 12;
        $yLinea = $y + 30;
        $firma = $firmado ? Fotos::ruta('firmas', $c['firma_archivo'] ?? null) : null;
        if ($firma) {
            $pdf->Image($firma, $xCli + 4, $y + 1, $ancho - 8, 27, 'PNG', '', '', true, 150, '', false, false, 0, 'CB');
        }
        $pdf->SetDrawColor(60, 54, 56);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($xCli, $yLinea, $xCli + $ancho, $yLinea);
        $pdf->Line($xLoc, $yLinea, $xLoc + $ancho, $yLinea);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetXY($xCli, $yLinea + 1);
        $pdf->Cell($ancho, 4.5, 'EL CLIENTE', 0, 2, 'C');
        $pdf->SetFont('dejavusans', '', 8);
        if ($firmado) {
            $pdf->MultiCell($ancho, 4, $c['firmante_nombre'] . "\nCédula/RUC: " . $c['firmante_identificacion']
                . "\nFirmó el " . self::fechaHora($c['firmado_en']) . "\nDesde la IP " . ($c['firma_ip'] ?: '—'), 0, 'C', false, 1, $xCli);
        } else {
            $pdf->MultiCell($ancho, 4, $c['cliente'] . "\nCédula/RUC: " . ($c['cliente_identificacion'] ?: '__________'), 0, 'C', false, 1, $xCli);
        }
        $yFinCli = $pdf->GetY();
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetXY($xLoc, $yLinea + 1);
        $pdf->Cell($ancho, 4.5, 'EL LOCAL', 0, 2, 'C');
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->MultiCell($ancho, 4, ($negocio['razon_social'] ?: $negocio['nombre']) . "\nRUC: " . ($negocio['ruc'] ?: '—') . "\n" . $negocio['nombre'], 0, 'C', false, 1, $xLoc);
        $pdf->SetY(max($yFinCli, $pdf->GetY()) + 6);

        // Verificación: QR + código + huella
        self::saltoSiFalta($pdf, 34);
        $y = $pdf->GetY();
        $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetLineWidth(0.3);
        $pdf->RoundedRect(15, $y, $pdf->getPageWidth() - 30, 31, 2, '1111', 'D');
        if ($enlaceVerificar) {
            $pdf->write2DBarcode($enlaceVerificar, 'QRCODE,M', 17.5, $y + 2.5, 26, 26, ['fgcolor' => [30, 26, 28]], 'N');
        }
        $x = $enlaceVerificar ? 47 : 19;
        $pdf->SetXY($x, $y + 3);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->Cell(0, 5, $firmado ? $doc . ' firmado electrónicamente por el cliente' : $doc . ' pendiente de firma', 0, 2);
        $pdf->SetFont('dejavusans', '', 7.8);
        $pdf->SetTextColor(70, 62, 66);
        $pdf->Cell(0, 4, 'Código de verificación: ' . $c['codigo_verificacion'], 0, 2);
        $pdf->MultiCell($pdf->getPageWidth() - 15 - $x - 2, 4, 'Huella SHA-256 del texto: ' . $c['hash_sha256'], 0, 'L', false, 2, $x);
        $pdf->SetX($x);
        $pdf->MultiCell($pdf->getPageWidth() - 15 - $x - 2, 4,
            'Escanea el código QR o entra a ' . ($enlaceVerificar ?: 'la página de verificación') . ' para comprobar que este documento es auténtico y no fue cambiado.', 0, 'L', false, 1, $x);
        $pdf->SetY($y + 33);
        $pdf->SetTextColor(30, 26, 28);

        if (!$firmado) self::marcaDeAgua($pdf, $c['estado'] === 'anulado' ? 'ANULADO' : 'BORRADOR – SIN FIRMAR');
        return $pdf;
    }

    /** Texto plano del contrato -> HTML para TCPDF (título centrado, cláusulas en negrita, viñetas con sangría). */
    public static function htmlContrato(string $texto, string $color = '#6B2D3C'): string
    {
        $h = [Pdf::class, 'h'];
        $parrafos = preg_split("/\n\s*\n/", str_replace("\r", '', trim($texto))) ?: [];
        $html = '';
        foreach ($parrafos as $n => $p) {
            $lineas = explode("\n", $p);
            $salida = [];
            foreach ($lineas as $l) {
                $l = rtrim($l);
                if ($l === '') continue;
                if (preg_match('/^\s*[•\-\*]\s*(.+)$/u', $l, $m)) {
                    $salida[] = '<span>&nbsp;&nbsp;&nbsp;•&nbsp;' . $h($m[1]) . '</span>';
                } elseif (preg_match('/^([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ ]{2,40}\s+[—–-]\s+[^.]{2,60}\.)(.*)$/u', $l, $m)) {
                    $salida[] = '<b style="color:' . $color . '">' . $h($m[1]) . '</b>' . $h($m[2]);
                } else {
                    $salida[] = $h($l);
                }
            }
            if (!$salida) continue;
            $soloMayus = count($lineas) === 1 && mb_strlen($p) < 120 && mb_strtoupper($p) === $p && preg_match('/[A-ZÁÉÍÓÚÑ]/u', $p);
            if ($n === 0 && $soloMayus) {
                $html .= '<h3 style="text-align:center;color:' . $color . ';font-size:12pt">' . $h(trim($p)) . '</h3>';
            } elseif ($soloMayus) {
                $html .= '<p style="text-align:center"><b>' . $h(trim($p)) . '</b></p>';
            } else {
                $html .= '<p style="text-align:justify">' . implode('<br>', $salida) . '</p>';
            }
        }
        return '<div style="font-size:9.3pt;line-height:1.45">' . $html . '</div>';
    }

    /** Texto grande, girado y transparente en todas las páginas. */
    private static function marcaDeAgua(object $pdf, string $texto): void
    {
        $paginas = $pdf->getNumPages();
        $quiebre = $pdf->getAutoPageBreak();
        $margen = $pdf->getBreakMargin();
        $pdf->SetAutoPageBreak(false, 0);
        for ($i = 1; $i <= $paginas; $i++) {
            $pdf->setPage($i);
            $w = $pdf->getPageWidth();
            $hh = $pdf->getPageHeight();
            $pdf->SetAlpha(0.13);
            $pdf->SetTextColor(181, 61, 51);
            $pdf->SetFont('dejavusans', 'B', mb_strlen($texto) > 12 ? 46 : 70);
            $pdf->StartTransform();
            $pdf->Rotate(52, $w / 2, $hh / 2);
            $pdf->SetXY(0, $hh / 2 - 12);
            $pdf->Cell($w, 24, $texto, 0, 0, 'C');
            $pdf->StopTransform();
            $pdf->SetAlpha(1);
        }
        $pdf->SetTextColor(30, 26, 28);
        $pdf->SetAutoPageBreak($quiebre, $margen);
        $pdf->lastPage();
    }

    private static function saltoSiFalta(object $pdf, float $alto): void
    {
        if ($pdf->GetY() + $alto > $pdf->getPageHeight() - $pdf->getBreakMargin()) $pdf->AddPage();
    }

    public static function nombreArchivo(string $tipo, array $fila): string
    {
        $base = $tipo . '-' . ($fila['numero'] ?? '') . '-' . ($fila['evento_codigo'] ?? '');
        return (preg_replace('/[^A-Za-z0-9-]+/', '-', $base) ?: $tipo) . '.pdf';
    }

    private static function hex(array $rgb): string
    {
        return sprintf('#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);
    }

    private static function aclarar(array $rgb, float $cuanto): array
    {
        return array_map(fn($c) => (int) round($c + (255 - $c) * $cuanto), $rgb);
    }

    private static function cantidad(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
    }

    private static function unidadCorta(string $unidad, float $cant): string
    {
        return match ($unidad) {
            'persona' => $cant == 1.0 ? 'persona' : 'personas',
            'hora' => $cant == 1.0 ? 'hora' : 'horas',
            'unidad' => $cant == 1.0 ? 'unidad' : 'unidades',
            default => $cant == 1.0 ? 'evento' : 'x',
        };
    }

    public static function fechaCorta(?string $f): string
    {
        return $f ? (new \DateTimeImmutable($f))->format('d/m/Y') : '';
    }

    public static function fechaHora(?string $f): string
    {
        return $f ? (new \DateTimeImmutable($f))->format('d/m/Y \a \l\a\s H:i') : '';
    }

    public static function fechaLarga(?string $f): string
    {
        if (!$f) return '';
        $d = new \DateTimeImmutable($f);
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $dias[(int) $d->format('w')] . ' ' . $d->format('j') . ' de ' . $meses[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }
}
