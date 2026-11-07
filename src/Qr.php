<?php
declare(strict_types=1);

namespace TuRenta;

use RuntimeException;

/**
 * Códigos QR sin librerías externas: usa el codificador que trae TCPDF (\TCPDF2DBarcode)
 * y dibuja el resultado con fondo blanco y margen (los lectores de los celulares lo necesitan).
 *   Qr::svg($texto)  -> <svg ...> listo para poner en la página (se ajusta al ancho)
 *   Qr::png($texto)  -> bytes de una imagen PNG
 */
final class Qr
{
    /** Margen blanco alrededor (en módulos). El estándar pide 4. */
    private const MARGEN = 4;

    /** Matriz de 0 y 1 del código (fila por fila). */
    public static function matriz(string $texto): array
    {
        if ($texto === '') throw new RuntimeException('No hay nada que poner en el código QR.');
        if (!class_exists(\TCPDF2DBarcode::class)) {
            throw new RuntimeException('Falta la librería de códigos QR en el servidor (TCPDF).');
        }
        $qr = new \TCPDF2DBarcode($texto, 'QRCODE,M');
        $a = $qr->getBarcodeArray();
        if (empty($a['bcode']) || empty($a['num_rows'])) {
            throw new RuntimeException('No se pudo crear el código QR (el texto es muy largo).');
        }
        return $a['bcode'];
    }

    /**
     * SVG del código. $px = ancho y alto en pantalla (null = se estira al contenedor).
     * Un solo <path> con todos los cuadritos: liviano y nítido a cualquier tamaño.
     */
    public static function svg(string $texto, ?int $px = 240, string $color = '#1F1A1C', string $titulo = 'Código QR'): string
    {
        $m = self::matriz($texto);
        $n = count($m);
        $lado = $n + 2 * self::MARGEN;
        $d = '';
        foreach ($m as $y => $fila) {
            $x = 0;
            $ancho = count($fila);
            while ($x < $ancho) {
                if ((int) $fila[$x] === 1) {
                    $inicio = $x;
                    while ($x < $ancho && (int) $fila[$x] === 1) $x++;
                    $d .= 'M' . ($inicio + self::MARGEN) . ' ' . ($y + self::MARGEN) . 'h' . ($x - $inicio) . 'v1h-' . ($x - $inicio) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#1F1A1C';
        $tam = $px ? ' width="' . $px . '" height="' . $px . '"' : '';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '"' . $tam
             . ' shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '">'
             . '<rect width="' . $lado . '" height="' . $lado . '" fill="#fff"/>'
             . '<path fill="' . $color . '" d="' . $d . '"/></svg>';
    }

    /** PNG del código (fondo blanco, $modulo píxeles por cuadrito). */
    public static function png(string $texto, int $modulo = 8): string
    {
        if (!function_exists('imagecreate')) throw new RuntimeException('Falta la extensión GD de PHP para crear imágenes.');
        $m = self::matriz($texto);
        $n = count($m);
        $modulo = max(1, min(40, $modulo));
        $lado = ($n + 2 * self::MARGEN) * $modulo;
        $img = imagecreate($lado, $lado);
        imagecolorallocate($img, 255, 255, 255);            // primer color = fondo
        $negro = imagecolorallocate($img, 31, 26, 28);
        foreach ($m as $y => $fila) {
            foreach ($fila as $x => $v) {
                if ((int) $v === 1) {
                    $px = ($x + self::MARGEN) * $modulo;
                    $py = ($y + self::MARGEN) * $modulo;
                    imagefilledrectangle($img, $px, $py, $px + $modulo - 1, $py + $modulo - 1, $negro);
                }
            }
        }
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    /** Envía el SVG al navegador (para <img src="...&qr=1">). */
    public static function enviarSvg(string $texto): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: private, max-age=86400');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . self::svg($texto, 480);
    }
}
