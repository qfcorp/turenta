<?php
declare(strict_types=1);

namespace TuRenta;

/**
 * App de Android «TuRenta» en Google Play (Trusted Web Activity: abre este sistema a pantalla completa).
 * Android revisa /.well-known/assetlinks.json para saber que la app y la página son del mismo dueño; si no coincide la huella,
 * la app abre con la barra del navegador arriba.
 *  - La huella de nuestra llave de subida (qfcorp/tukan-apps, carpeta llave/) va siempre.
 *  - La huella de «Firma de apps de Google Play» (la da Play Console después de la primera subida) se agrega en el servidor con
 *    sudo bash deploy/app_android.sh HUELLA  → queda en config/.env como TURENTA_ANDROID_HUELLAS (el instalador la conserva).
 * Ver docs/GOOGLE_PLAY.md.
 */
final class AppAndroid
{
    public const PAQUETE = 'com.tukansoftware.turenta';
    public const HUELLA_SUBIDA = '39:0C:08:10:D4:40:C1:71:C4:30:C8:D0:F1:93:7B:8F:E4:37:03:25:27:2E:B2:F6:2E:4E:A6:5E:01:98:40:DC';
    public const VARIABLE = 'TURENTA_ANDROID_HUELLAS';
    private const FORMATO = '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/';

    /** ¿Es una huella SHA-256 bien escrita? (32 pares de letras o números separados por dos puntos) */
    public static function huellaValida(string $h): bool
    {
        return (bool) preg_match(self::FORMATO, strtoupper(trim($h)));
    }

    /** Huellas que se publican: la de nuestra llave + las de config/.env (las mal escritas se ignoran). */
    public static function huellas(): array
    {
        $lista = [self::HUELLA_SUBIDA];
        foreach (preg_split('/[\s,;]+/', (string) getenv(self::VARIABLE)) ?: [] as $h) {
            $h = strtoupper(trim($h));
            if ($h !== '' && self::huellaValida($h) && !in_array($h, $lista, true)) $lista[] = $h;
        }
        return $lista;
    }

    /** Contenido de /.well-known/assetlinks.json */
    public static function assetlinks(): array
    {
        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => self::PAQUETE, 'sha256_cert_fingerprints' => self::huellas()],
        ]];
    }

    /** Responde /.well-known/assetlinks.json (lo llama public/index.php antes de la sesión). */
    public static function responder(): never
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(self::assetlinks(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
