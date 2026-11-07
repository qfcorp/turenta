<?php
declare(strict_types=1);

namespace TuRenta;

use RuntimeException;

/**
 * Guardado seguro de imágenes (fotos de espacios, logos, comprobantes de pago, firmas).
 * Se guardan fuera de la carpeta pública, se achican y se les quita la información oculta
 * (ubicación, etc.) porque se vuelven a guardar como JPG o PNG nuevos.
 */
final class Fotos
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Carpeta base de archivos subidos (uploads/). */
    public static function base(): string
    {
        return rtrim((string) (getenv('TURENTA_UPLOADS') ?: dirname(__DIR__) . '/uploads'), '/');
    }

    /** Subcarpeta: "espacios", "logos", "comprobantes", "firmas". */
    public static function carpeta(string $nombre): string
    {
        return self::base() . '/' . $nombre;
    }

    /** Nombre de archivo válido (evita leer cualquier archivo del servidor). */
    public static function nombreValido(?string $nombre): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png)$/', (string) $nombre);
    }

    /**
     * Revisa que sea una foto, la gira según la cámara, la achica y la guarda como JPG nuevo.
     * Devuelve el nombre del archivo.
     * @param array $archivo un elemento de $_FILES
     */
    public static function guardarImagen(array $archivo, string $carpeta, int $maximo = 1600): string
    {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No llegó la foto. Intenta de nuevo.');
        }
        if (($archivo['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('La foto pesa más de 10 MB.');
        }
        $tipo = (new \finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        $img = match ($tipo) {
            'image/jpeg' => @imagecreatefromjpeg($archivo['tmp_name']),
            'image/png'  => @imagecreatefrompng($archivo['tmp_name']),
            'image/webp' => @imagecreatefromwebp($archivo['tmp_name']),
            default      => false,
        };
        if (!$img) throw new RuntimeException('El archivo debe ser una foto (JPG, PNG o WebP).');

        if ($tipo === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($archivo['tmp_name']);
            $giro = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($giro) $img = imagerotate($img, $giro, 0);
        }
        $img = self::achicar($img, $maximo);
        // Fondo blanco para las fotos con transparencia
        $w = imagesx($img); $h = imagesy($img);
        $lienzo = imagecreatetruecolor($w, $h);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        self::crearCarpeta($carpeta);
        $nombre = bin2hex(random_bytes(16)) . '.jpg';
        $ok = imagejpeg($lienzo, rtrim($carpeta, '/') . '/' . $nombre, 82);
        imagedestroy($lienzo);
        if (!$ok) throw new RuntimeException('No se pudo guardar la foto en el servidor.');
        return $nombre;
    }

    /**
     * Firma dibujada en el celular: llega como "data:image/png;base64,...".
     * Se valida que sea un PNG de verdad, que no esté vacía y se guarda limpia.
     */
    public static function guardarFirma(string $dataUrl, string $carpeta): string
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
            throw new RuntimeException('Dibuja tu firma en el recuadro.');
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < 200 || strlen($bin) > 2 * 1024 * 1024) {
            throw new RuntimeException('La firma no se pudo leer. Vuelve a dibujarla.');
        }
        $img = @imagecreatefromstring($bin);
        if (!$img) throw new RuntimeException('La firma no se pudo leer. Vuelve a dibujarla.');
        // ¿Está vacía? Se cuentan los puntos con tinta.
        $w = imagesx($img); $h = imagesy($img);
        $tinta = 0;
        $paso = max(1, (int) floor(min($w, $h) / 120));
        for ($x = 0; $x < $w; $x += $paso) {
            for ($y = 0; $y < $h; $y += $paso) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                if ($a < 100 && ($r + $g + $b) < 600) $tinta++;
            }
        }
        if ($tinta < 25) {
            imagedestroy($img);
            throw new RuntimeException('La firma está vacía. Dibújala con el dedo en el recuadro.');
        }
        $img = self::achicar($img, 900);
        $w = imagesx($img); $h = imagesy($img);
        $lienzo = imagecreatetruecolor($w, $h);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
        imagecopy($lienzo, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        self::crearCarpeta($carpeta);
        $nombre = bin2hex(random_bytes(16)) . '.png';
        $ok = imagepng($lienzo, rtrim($carpeta, '/') . '/' . $nombre, 6);
        imagedestroy($lienzo);
        if (!$ok) throw new RuntimeException('No se pudo guardar la firma.');
        return $nombre;
    }

    /** Ruta de un archivo de una subcarpeta, solo si existe y el nombre es válido. */
    public static function ruta(string $subcarpeta, ?string $nombre): ?string
    {
        if (!self::nombreValido($nombre)) return null;
        $ruta = self::carpeta($subcarpeta) . '/' . $nombre;
        return is_file($ruta) ? $ruta : null;
    }

    /** Envía un archivo de imagen al navegador. */
    public static function enviar(string $ruta, bool $privado = true): void
    {
        header('Content-Type: ' . (str_ends_with($ruta, '.png') ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: ' . ($privado ? 'private' : 'public') . ', max-age=86400');
        readfile($ruta);
    }

    public static function borrar(string $subcarpeta, ?string $nombre): void
    {
        if (self::nombreValido($nombre)) @unlink(self::carpeta($subcarpeta) . '/' . $nombre);
    }

    private static function achicar(\GdImage $img, int $maximo): \GdImage
    {
        $w = imagesx($img); $h = imagesy($img);
        $escala = min(1, $maximo / max($w, $h));
        if ($escala < 1) {
            $nueva = imagescale($img, (int) round($w * $escala), (int) round($h * $escala));
            imagedestroy($img);
            return $nueva;
        }
        return $img;
    }

    private static function crearCarpeta(string $carpeta): void
    {
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0750, true) && !is_dir($carpeta)) {
            throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
        }
    }
}
