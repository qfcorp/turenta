<?php
declare(strict_types=1);

namespace TuRenta;

/**
 * PDF del plano del salón (TCPDF a través de Pdf::nuevo), hoja horizontal A4:
 *   página 1: el dibujo a escala (cuadrícula de 1 m), con la ocupación de cada mesa si es de un evento;
 *   página 2: mesas con sus invitados (solo eventos), para el montaje y la puerta.
 * Las formas se dibujan girando sus esquinas a mano (polígonos), así el texto siempre queda derecho.
 */
final class PdfPlano
{
    /** '#RRGGBB' -> [r, g, b] */
    private static function rgb(string $hex): array
    {
        return Pdf::rgb($hex);
    }

    /** Gira un punto (dx, dy) alrededor del centro, igual que rotate() de SVG (sentido del reloj, y hacia abajo). */
    private static function girar(float $dx, float $dy, int $rot): array
    {
        if (!$rot) return [$dx, $dy];
        $a = deg2rad($rot);
        return [$dx * cos($a) - $dy * sin($a), $dx * sin($a) + $dy * cos($a)];
    }

    /**
     * @param array $negocio fila de negocios
     * @param array $datos   Planos::datos()
     * @param ?array $ev     evento (para el título) o null si es una distribución
     */
    public static function plano(array $negocio, array $datos, ?array $ev = null): object
    {
        $p = $datos['plano'];
        $esEvento = $p['tipo'] === 'evento';
        $A = (float) $p['ancho'];
        $L = (float) $p['largo'];
        $orientacion = $L > $A * 1.15 ? 'P' : 'L';          // salón largo: hoja parada
        $pdf = Pdf::nuevo($negocio, $esEvento ? 'Plano del salón' : 'Distribución del salón', $orientacion);
        [$cr, $cg, $cb] = Pdf::rgb((string) ($negocio['color'] ?? '#6B2D3C'));

        // Título
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetTextColor(30, 26, 28);
        $titulo = $esEvento && $ev ? $ev['nombre'] : $p['nombre'];
        $pdf->Cell(0, 6, $titulo, 0, 1);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(102, 90, 94);
        $sub = array_filter([
            $esEvento && $ev ? DiaEvento::fechaLarga($ev['fecha']) : null,
            $esEvento ? $p['nombre'] : null,
            $p['espacio'] ?? null,
            Planos::m($A) . ' × ' . Planos::m($L) . ' m',
        ]);
        $pdf->Cell(0, 5, implode(' · ', $sub), 0, 1);

        // Área para el dibujo
        $x0 = 15.0;
        $y0 = $pdf->GetY() + 3;
        $anchoUtil = $pdf->getPageWidth() - 30;
        $altoUtil = $pdf->getPageHeight() - $y0 - ($esEvento ? 30 : 24);
        $pdf->SetAutoPageBreak(false);                       // el dibujo y su pie van en una sola hoja
        $s = min($anchoUtil / $A, $altoUtil / $L);         // mm por metro
        $ox = $x0 + ($anchoUtil - $A * $s) / 2;
        $oy = $y0;
        $X = fn(float $m) => $ox + $m * $s;
        $Y = fn(float $m) => $oy + $m * $s;

        // Salón y cuadrícula
        $pdf->SetLineStyle(['width' => 0.1, 'color' => [234, 226, 228]]);
        $pdf->Rect($X(0), $Y(0), $A * $s, $L * $s, 'F', [], [251, 249, 248]);
        for ($i = 1; $i < $A; $i++) {
            $pdf->SetLineStyle(['width' => $i % 5 ? 0.08 : 0.16, 'color' => $i % 5 ? [236, 228, 230] : [222, 210, 213]]);
            $pdf->Line($X($i), $Y(0), $X($i), $Y($L));
        }
        for ($i = 1; $i < $L; $i++) {
            $pdf->SetLineStyle(['width' => $i % 5 ? 0.08 : 0.16, 'color' => $i % 5 ? [236, 228, 230] : [222, 210, 213]]);
            $pdf->Line($X(0), $Y($i), $X($A), $Y($i));
        }
        $pdf->SetLineStyle(['width' => 0.5, 'color' => [74, 30, 42]]);
        $pdf->Rect($X(0), $Y(0), $A * $s, $L * $s, 'D');

        // Elementos: lo grande abajo, las mesas encima
        $orden = fn($el) => in_array($el['tipo'], ['pista', 'tarima', 'banos'], true) ? 0 : ($el['tipo'] === 'texto' ? 3 : (Planos::esMesa($el['tipo']) ? 2 : 1));
        $els = $datos['elementos'];
        usort($els, fn($a, $b) => $orden($a) <=> $orden($b));
        foreach ($els as $el) {
            $mesa = Planos::esMesa($el['tipo']);
            $estado = $mesa ? ($esEvento ? Planos::ocupacion($el) : 'mesa') : $el['tipo'];
            [$rell, $borde, $tinta] = Planos::COLORES[$estado] ?? Planos::COLORES['mesa'];
            $cx = (float) $el['x'];
            $cy = (float) $el['y'];
            $an = (float) $el['an'];
            $al = (float) $el['al'];
            $rot = (int) $el['rot'];
            if ($mesa) {
                $pdf->SetLineStyle(['width' => 0.12, 'color' => [185, 174, 177]]);
                foreach (Planos::sillas($el['tipo'], $an, $al, (int) $el['puestos']) as [$sx, $sy]) {
                    [$gx, $gy] = self::girar($sx, $sy, $rot);
                    $pdf->Circle($X($cx + $gx), $Y($cy + $gy), 0.2 * $s, 0, 360, 'DF', [], [255, 255, 255]);
                }
            }
            if ($el['tipo'] !== 'texto') {
                $pdf->SetLineStyle(['width' => $mesa ? 0.3 : 0.22, 'color' => self::rgb($borde)]);
                if ($el['tipo'] === 'redonda') {
                    $pdf->Circle($X($cx), $Y($cy), $an / 2 * $s, 0, 360, 'DF', [], self::rgb($rell));
                } else {
                    $pts = [];
                    foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$ix, $iy]) {
                        [$gx, $gy] = self::girar($ix * $an / 2, $iy * $al / 2, $rot);
                        array_push($pts, $X($cx + $gx), $Y($cy + $gy));
                    }
                    $pdf->Polygon($pts, 'DF', [], self::rgb($el['tipo'] === 'pista' ? '#EFE4CF' : $rell));
                }
            }
            // Texto (siempre derecho)
            $texto = $mesa ? Planos::etiquetaCorta($el['etq']) : ($el['etq'] !== '' ? $el['etq'] : ($el['tipo'] === 'columna' ? '' : Planos::TIPOS[$el['tipo']]['corto']));
            if ($texto === '') continue;
            $lado = $el['tipo'] === 'texto' ? $al : min($an, $al);
            $tamM = max(0.22, min($mesa ? 0.62 : 0.55, $lado * ($mesa ? 0.36 : 0.42)));
            if ($el['tipo'] === 'texto') $tamM = min(0.9, max(0.3, $al * 0.6));
            if ($mesa) { $texto = Planos::recortar($texto, 14); $tamM = max(0.16, min($tamM, $an * 0.95 / max(1, mb_strlen($texto) * 0.56))); }
            $pt = max(4.0, min(11.0, $tamM * $s * 2.835));   // mm -> puntos
            [$tr, $tg, $tb] = self::rgb($tinta);
            $pdf->SetTextColor($tr, $tg, $tb);
            $pdf->SetFont('dejavusans', 'B', $pt);
            $anchoTexto = $mesa ? max($an, $al) * $s + 10 : max(4.0, $an * $s - 1);
            $alto = $pt * 0.42;
            $conCuenta = $mesa && $esEvento;
            $angulo = $rot + Planos::giroTexto($el['tipo'], $rot);   // en la hoja (mesas: 0)
            if ($angulo % 360) { $pdf->StartTransform(); $pdf->Rotate(-$angulo, $X($cx), $Y($cy)); }
            $pdf->SetXY($X($cx) - $anchoTexto / 2, $Y($cy) - $alto / 2 - ($conCuenta ? $alto * 0.35 : 0));
            $pdf->Cell($anchoTexto, $alto, $texto, 0, 0, 'C', false, '', 1);
            if ($conCuenta) {
                $pdf->SetFont('dejavusans', '', max(3.5, $pt * 0.62));
                $pdf->SetXY($X($cx) - $anchoTexto / 2, $Y($cy) + $alto * 0.25);
                $pdf->Cell($anchoTexto, $alto * 0.7, (int) ($el['ocupados'] ?? 0) . '/' . (int) $el['puestos'], 0, 0, 'C');
            }
            if ($angulo % 360) $pdf->StopTransform();
        }

        // Pie: escala, resumen y colores
        $yPie = $Y($L) + 3;
        $pdf->SetTextColor(102, 90, 94);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetLineStyle(['width' => 0.4, 'color' => [74, 30, 42]]);
        $pdf->Line($X(0), $yPie + 2, $X(0) + $s, $yPie + 2);
        $pdf->SetXY($X(0) + $s + 1.5, $yPie);
        $pdf->Cell(40, 4, '= 1 metro', 0, 0);
        $r = $datos['resumen'] ?? [];
        $pdf->SetXY($X(0) + $s + 22, $yPie);
        $linea = (int) ($r['mesas'] ?? 0) . ' mesas · ' . (int) ($r['puestos'] ?? 0) . ' puestos';
        if ($esEvento) $linea .= ' · ' . (int) ($r['confirmados'] ?? 0) . ' confirmados · ' . (int) ($r['esperados'] ?? 0) . ' esperados · ' . (int) ($r['sin_mesa'] ?? 0) . ' sin mesa';
        $pdf->Cell(0, 4, $linea, 0, 1);
        if ($esEvento) {
            $x = $X(0);
            $y = $yPie + 6;
            foreach (['vacia' => 'Vacía', 'parcial' => 'Con lugar', 'llena' => 'Llena', 'pasa' => 'Se pasa'] as $k => $txt) {
                [$f, $b] = Planos::COLORES[$k];
                $pdf->SetLineStyle(['width' => 0.3, 'color' => self::rgb($b)]);
                $pdf->Rect($x, $y, 4, 3, 'DF', [], self::rgb($f));
                $pdf->SetXY($x + 5, $y - 0.4);
                $pdf->Cell(22, 4, $txt, 0, 0);
                $x += 26;
            }
            $pdf->SetXY($X(0), $y + 4);
            $pdf->Cell(0, 4, 'En cada mesa: sentados / puestos (los que no confirman cuentan con todos sus pases).', 0, 0);
        }
        $pdf->SetAutoPageBreak(true, 18);

        // Página 2: mesas con sus invitados
        if ($esEvento) {
            $mesas = array_values(array_filter($datos['elementos'], fn($el) => Planos::esMesa($el['tipo'])));
            usort($mesas, fn($a, $b) => strnatcasecmp((string) $a['etq'], (string) $b['etq']));
            $sinUbicar = $datos['sin_ubicar'] ?? [];
            if ($mesas || $sinUbicar) {
                $pdf->AddPage($orientacion);
                $color = sprintf('#%02X%02X%02X', $cr, $cg, $cb);
                $pdf->SetTextColor(30, 26, 28);
                $html = '<table cellpadding="3" cellspacing="0"><tr><td style="border-bottom:1px solid ' . $color . ';color:' . $color . ';font-size:11pt;font-weight:bold;">Mesas e invitados</td></tr></table><br>';
                $html .= '<table cellpadding="4" cellspacing="0" style="font-size:8.5pt;"><thead><tr style="background-color:' . $color . ';color:#FFFFFF;font-weight:bold;">'
                       . '<th width="18%">Mesa</th><th width="12%" align="center">Sentados</th><th width="70%">Invitados</th></tr></thead><tbody>';
                $i = 0;
                foreach (array_merge($mesas, array_map(fn($m) => $m + ['sin_ubicar' => true], $sinUbicar)) as $m) {
                    $nombres = array_map(fn($x) => Pdf::h($x['n']) . ($x['c'] > 1 ? ' (' . $x['c'] . ')' : '') . ($x['p'] ? ' ?' : ''), $m['invitados'] ?? []);
                    $est = Planos::ocupacion($m);
                    $html .= '<tr nobr="true" style="background-color:' . ($i++ % 2 ? '#F7F3F4' : '#FFFFFF') . ';">'
                           . '<td width="18%" style="border-bottom:0.3px solid #E6DCDD;"><b>' . Pdf::h($m['etq']) . '</b>' . (!empty($m['sin_ubicar']) ? '<br><span style="color:#8C8286;font-size:7pt;">no está en el plano</span>' : '') . '</td>'
                           . '<td width="12%" align="center" style="border-bottom:0.3px solid #E6DCDD;' . ($est === 'pasa' ? 'color:#B53D33;font-weight:bold;' : '') . '">' . (int) ($m['ocupados'] ?? 0) . ' / ' . (int) $m['puestos'] . '</td>'
                           . '<td width="70%" style="border-bottom:0.3px solid #E6DCDD;">' . ($nombres ? implode(' · ', $nombres) : '<span style="color:#8C8286;">Vacía</span>') . '</td></tr>';
                }
                $html .= '</tbody></table><p style="color:#8C8286;font-size:7.5pt;">? = todavía no confirma. Entre paréntesis: cuántas personas van con esa invitación.</p>';
                $pdf->writeHTML($html, true, false, true, false, '');
            }
        }
        return $pdf;
    }
}
