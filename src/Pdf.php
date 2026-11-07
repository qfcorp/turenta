<?php
declare(strict_types=1);

namespace TuRenta;

use RuntimeException;

/**
 * Base para todos los PDF de TuRenta (cotización, contrato, recibo, hoja del evento, invitados...).
 * Usa TCPDF (composer). Pone la cabecera con el logo y los datos del negocio, y el pie con la página.
 * Uso:
 *   $pdf = Pdf::nuevo($negocio, 'Cotización N.º 12');   // $negocio = fila de la tabla negocios
 *   $pdf->writeHTML($html);  // o celdas
 *   Pdf::enviar($pdf, 'cotizacion-12.pdf');            // al navegador (inline)
 * Los colores salen del color del negocio. El QR: $pdf->write2DBarcode($texto, 'QRCODE,M', $x, $y, 26, 26) (26 mm se lee bien).
 */
final class Pdf
{
    public static function disponible(): bool
    {
        return class_exists(\TCPDF::class);
    }

    /** @return \TCPDF */
    public static function nuevo(array $negocio, string $titulo, string $orientacion = 'P'): object
    {
        if (!self::disponible()) {
            throw new RuntimeException('Falta la librería de PDF en el servidor. Corre el instalador otra vez (instala composer y TCPDF).');
        }
        $pdf = new class($orientacion, 'mm', 'A4', true, 'UTF-8', false) extends \TCPDF {
            public array $negocio = [];
            public string $titulo = '';
            public array $rgb = [107, 45, 60];

            public function Header(): void
            {
                $n = $this->negocio;
                $x = 15;
                $logo = Fotos::ruta('logos', $n['logo'] ?? null);
                if ($logo) {
                    $this->Image($logo, 15, 10, 18, 18, '', '', '', true, 150, '', false, false, 0, 'CM');
                    $x = 37;
                }
                $this->SetTextColor($this->rgb[0], $this->rgb[1], $this->rgb[2]);
                $this->SetFont('dejavusans', 'B', 13);
                $this->SetXY($x, 10);
                $this->Cell(0, 6, (string) ($n['nombre'] ?? ''), 0, 1);
                $this->SetTextColor(90, 80, 84);
                $this->SetFont('dejavusans', '', 8);
                $linea = array_filter([
                    !empty($n['razon_social']) && $n['razon_social'] !== $n['nombre'] ? $n['razon_social'] : null,
                    !empty($n['ruc']) ? 'RUC ' . $n['ruc'] : null,
                ]);
                $this->SetX($x);
                $this->Cell(0, 4, implode(' · ', $linea), 0, 1);
                $this->SetX($x);
                $this->Cell(0, 4, implode(' · ', array_filter([$n['direccion'] ?? null, $n['ciudad'] ?? null, $n['telefono'] ?? null, $n['email'] ?? null])), 0, 1);
                $this->SetTextColor($this->rgb[0], $this->rgb[1], $this->rgb[2]);
                $this->SetFont('dejavusans', 'B', 11);
                $this->SetXY(15, 10);
                $this->Cell(0, 6, $this->titulo, 0, 1, 'R');
                $this->SetDrawColor($this->rgb[0], $this->rgb[1], $this->rgb[2]);
                $this->SetLineWidth(0.6);
                $this->Line(15, 31, $this->getPageWidth() - 15, 31);
                $this->SetTextColor(30, 26, 28);
            }

            public function Footer(): void
            {
                $this->SetY(-12);
                $this->SetFont('dejavusans', '', 7);
                $this->SetTextColor(140, 130, 134);
                $this->Cell(0, 5, 'Generado con TuRenta · Tukán Software', 0, 0, 'L');
                $this->Cell(0, 5, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        };
        $pdf->negocio = $negocio;
        $pdf->titulo = $titulo;
        $pdf->rgb = self::rgb((string) ($negocio['color'] ?? '#6B2D3C'));
        $pdf->SetCreator('TuRenta');
        $pdf->SetAuthor((string) ($negocio['nombre'] ?? 'TuRenta'));
        $pdf->SetTitle($titulo);
        $pdf->SetMargins(15, 36, 15);
        $pdf->SetHeaderMargin(8);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetFont('dejavusans', '', 9.5);
        $pdf->AddPage();
        return $pdf;
    }

    /** '#6B2D3C' -> [107, 45, 60] */
    public static function rgb(string $hex): array
    {
        if (!preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $hex, $m)) return [107, 45, 60];
        return [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];
    }

    /** Envía el PDF al navegador. $descargar = true para bajarlo en vez de abrirlo. */
    public static function enviar(object $pdf, string $archivo, bool $descargar = false): void
    {
        while (ob_get_level() > 0) ob_end_clean();   // nada antes del PDF (lo corrompería)
        $archivo = preg_replace('/[^A-Za-z0-9._-]+/', '-', $archivo) ?: 'documento.pdf';
        $pdf->Output($archivo, $descargar ? 'D' : 'I');
    }

    /** El PDF como texto (para pruebas o para adjuntar). */
    public static function comoTexto(object $pdf): string
    {
        return $pdf->Output('documento.pdf', 'S');
    }

    /** Escapa texto para el HTML que se le pasa a TCPDF. */
    public static function h(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function dinero(float|string|null $v): string
    {
        $v = (float) $v;
        return ($v < 0 ? '-$' : '$') . number_format(abs($v), 2, ',', '.');
    }
}
