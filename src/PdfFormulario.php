<?php
declare(strict_types=1);

namespace TuRenta;

/**
 * PDF del formulario del evento (migración 070) y la tabla de respuestas que también va en la hoja del evento (PdfDia::hoja).
 */
final class PdfFormulario
{
    /** Tabla de respuestas por sección. $respondidas = Cuestionarios::respondidas(). */
    public static function tabla(array $respondidas, string $color): string
    {
        if (!$respondidas) return '<p style="color:#8C8286;font-size:9pt;">El cliente todavía no responde el formulario.</p>';
        $html = '';
        foreach ($respondidas as $seccion => $filas) {
            $html .= '<table cellpadding="3" cellspacing="0" style="font-size:8.5pt;"><tr><td colspan="2" style="color:' . $color . ';font-weight:bold;font-size:9pt;">'
                   . Pdf::h($seccion) . '</td></tr>';
            foreach ($filas as $i => $f) {
                $html .= '<tr nobr="true" style="background-color:' . ($i % 2 ? '#F7F3F4' : '#FFFFFF') . ';"><td width="40%" style="color:#665A5E;border-bottom:0.3px solid #E6DCDD;">'
                       . Pdf::h($f['pregunta']) . '</td><td width="60%" style="border-bottom:0.3px solid #E6DCDD;"><b>' . nl2br(Pdf::h($f['respuesta'])) . '</b></td></tr>';
            }
            $html .= '</table><br>';
        }
        return $html;
    }

    /** Documento con todas las respuestas del formulario. $form = Cuestionarios::deEvento(). */
    public static function documento(array $negocio, array $ev, array $form, array $respondidas): object
    {
        [$r, $g, $b] = Pdf::rgb((string) ($negocio['color'] ?? '#6B2D3C'));
        $c = sprintf('#%02X%02X%02X', $r, $g, $b);
        $pdf = Pdf::nuevo($negocio, 'Formulario del evento · ' . $ev['codigo']);
        $pdf->writeHTML('<h2 style="color:' . $c . ';">' . Pdf::h($ev['nombre']) . '</h2>'
            . '<p style="font-size:9pt;color:#665A5E;">' . Pdf::h(DiaEvento::fechaLarga($ev['fecha'])) . ' · ' . Pdf::h(substr((string) $ev['hora_inicio'], 0, 5))
            . ' · Cliente: <b>' . Pdf::h($ev['cliente']) . '</b> · <b>' . (int) $form['respondidas'] . '</b> de ' . (int) $form['total'] . ' preguntas respondidas'
            . ($form['enviado_en'] ? ' · enviado por el cliente el ' . Pdf::h((new \DateTimeImmutable(substr((string) $form['enviado_en'], 0, 10)))->format('d/m/Y')) : '') . '</p>'
            . self::tabla($respondidas, $c), true, false, true, false, '');
        $sin = array_values(array_filter($form['preguntas'], fn($p) => $p['respuesta'] === null));
        if ($sin) {
            $pdf->writeHTML('<p style="font-size:8.5pt;color:#665A5E;"><b>Sin responder:</b> ' . Pdf::h(implode(' · ', array_map(fn($p) => $p['texto'], $sin))) . '</p>',
                true, false, true, false, '');
        }
        return $pdf;
    }
}
