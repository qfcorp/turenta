<?php
declare(strict_types=1);

namespace TuRenta;

use RuntimeException;

/**
 * Archivos de proveedores y compras (documentos, facturas, comprobantes de pago, fotos de incidencias).
 * Aceptan foto (se vuelve a guardar limpia con Fotos), PDF y, para facturas, el XML del SRI.
 * Se guardan fuera de la carpeta pública, en uploads/proveedores, con un nombre al azar.
 */
final class Adjuntos
{
    public const CARPETA = 'proveedores';
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_XML = 2 * 1024 * 1024;

    public static function carpeta(): string
    {
        return Fotos::carpeta(self::CARPETA);
    }

    public static function nombreValido(?string $nombre): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf|xml)$/', (string) $nombre);
    }

    /** ¿Llegó un archivo en este campo del formulario? */
    public static function llego(?array $archivo): bool
    {
        return $archivo !== null && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /** Tipo real del archivo: 'imagen', 'pdf', 'xml' o null. */
    public static function tipo(array $archivo): ?string
    {
        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) return null;
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return 'imagen';
        $inicio = (string) file_get_contents($tmp, false, null, 0, 512);
        if ($mime === 'application/pdf' || str_starts_with($inicio, '%PDF-')) return str_starts_with($inicio, '%PDF-') ? 'pdf' : null;
        $limpio = ltrim($inicio, "\xEF\xBB\xBF \t\r\n");
        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html'], true) && str_starts_with($limpio, '<')) return 'xml';
        return null;
    }

    /**
     * Guarda el archivo subido. $permitidos: lista de 'imagen', 'pdf', 'xml'.
     * @return array{archivo:string, tipo:string}
     */
    public static function guardar(array $archivo, array $permitidos = ['imagen', 'pdf']): array
    {
        $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('El archivo pesa más de 10 MB.');
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('No llegó el archivo. Intenta de nuevo.');
        if (($archivo['size'] ?? 0) > self::MAX_BYTES || filesize((string) $archivo['tmp_name']) > self::MAX_BYTES) {
            throw new RuntimeException('El archivo pesa más de 10 MB.');
        }
        $tipo = self::tipo($archivo);
        $nombres = ['imagen' => 'una foto (JPG o PNG)', 'pdf' => 'un PDF', 'xml' => 'el XML de la factura electrónica'];
        if ($tipo === null || !in_array($tipo, $permitidos, true)) {
            $lista = array_map(fn($p) => $nombres[$p], $permitidos);
            $ultimo = array_pop($lista);
            throw new RuntimeException('El archivo debe ser ' . ($lista ? implode(', ', $lista) . ' o ' : '') . $ultimo . '.');
        }
        if ($tipo === 'imagen') {
            return ['archivo' => Fotos::guardarImagen($archivo, self::carpeta(), 1800), 'tipo' => 'imagen'];
        }
        if ($tipo === 'xml') {
            if (filesize((string) $archivo['tmp_name']) > self::MAX_XML) throw new RuntimeException('El XML pesa demasiado. Debe ser el de una sola factura.');
            return ['archivo' => self::guardarTexto((string) file_get_contents((string) $archivo['tmp_name']), 'xml'), 'tipo' => 'xml'];
        }
        self::crearCarpeta();
        $nombre = bin2hex(random_bytes(16)) . '.pdf';
        if (!@copy((string) $archivo['tmp_name'], self::carpeta() . '/' . $nombre)) throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
        return ['archivo' => $nombre, 'tipo' => 'pdf'];
    }

    /** Guarda un texto (el XML de una factura) y devuelve el nombre del archivo. */
    public static function guardarTexto(string $contenido, string $extension = 'xml'): string
    {
        if (!in_array($extension, ['xml'], true)) throw new RuntimeException('Tipo de archivo no válido.');
        self::crearCarpeta();
        $nombre = bin2hex(random_bytes(16)) . '.' . $extension;
        if (file_put_contents(self::carpeta() . '/' . $nombre, $contenido) === false) throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
        return $nombre;
    }

    public static function ruta(?string $nombre): ?string
    {
        if (!self::nombreValido($nombre)) return null;
        $r = self::carpeta() . '/' . $nombre;
        return is_file($r) ? $r : null;
    }

    public static function leer(?string $nombre): ?string
    {
        $r = self::ruta($nombre);
        return $r === null ? null : (string) file_get_contents($r);
    }

    public static function borrar(?string $nombre): void
    {
        if (self::nombreValido($nombre)) @unlink(self::carpeta() . '/' . $nombre);
    }

    public static function esPdf(?string $nombre): bool
    {
        return str_ends_with((string) $nombre, '.pdf');
    }

    /** Envía el archivo al navegador. El XML se descarga (nunca se abre como página). */
    public static function enviar(string $ruta, string $nombreDescarga = 'archivo'): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
        $tipo = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'xml' => 'application/xml'][$ext] ?? 'application/octet-stream';
        $nombreDescarga = (preg_replace('/[^A-Za-z0-9._-]+/', '-', $nombreDescarga) ?: 'archivo') . '.' . $ext;
        header('Content-Type: ' . $tipo);
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        if ($ext === 'xml') {
            header('Content-Security-Policy: sandbox');
            header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"');
        } else {
            header('Content-Disposition: inline; filename="' . $nombreDescarga . '"');
        }
        readfile($ruta);
    }

    private static function crearCarpeta(): void
    {
        $c = self::carpeta();
        if (!is_dir($c) && !@mkdir($c, 0750, true) && !is_dir($c)) throw new RuntimeException('No se pudo guardar el archivo en el servidor.');
    }
}
