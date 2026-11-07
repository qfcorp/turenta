<?php
declare(strict_types=1);

namespace TuRenta;

use Throwable;

require_once __DIR__ . '/tukan_correo.php';

/**
 * «Enviar por correo» en la pantalla de la demo lista del panel del súper usuario (compartido/LEEME_CORREO.md del Panel
 * Tukán Central), desde info@tukansoftware.com con la librería compartida src/tukan_correo.php (copiarla SIN cambios).
 *
 * La contraseña de la demo se ve UNA vez y nunca se guarda (ni en la base, ni en registro_admin, ni en la sesión). Para
 * mandarla por correo, la misma pantalla lleva un «sello»: los datos de la demo CIFRADOS (XChaCha20-Poly1305) con una
 * llave al azar que vive solo en la sesión del súper usuario, atados a su id y al negocio, que vencen en 30 minutos.
 * En la sesión queda además un número de un solo uso (sin la clave): al enviar bien se borra y el sello ya no sirve.
 */
final class CorreoDemo
{
    public const DURA_SEG = 1800;
    private const MAX_PENDIENTES = 20;
    public const COMANDO = 'cd /var/www/tukan-central && git pull && bash deploy/configurar_correo.sh';

    public static function listo(): bool
    {
        return tukan_correo_listo();
    }

    private static function llave(array &$sesion): string
    {
        $k = is_string($sesion['correo_demo_llave'] ?? null) ? base64_decode($sesion['correo_demo_llave'], true) : false;
        if (!is_string($k) || strlen($k) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            $k = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
            $sesion['correo_demo_llave'] = base64_encode($k);
        }
        return $k;
    }

    /**
     * Sella los datos de la demo (programa, enlace, usuario, clave, hasta, contacto, negocio, negocio_id) para el súper $adminId.
     * $sesion por referencia (normalmente $_SESSION): guarda la llave y el número de un solo uso.
     */
    public static function sellar(array $datos, int $adminId, array &$sesion, ?int $ahora = null): string
    {
        $ahora ??= time();
        $nonce = bin2hex(random_bytes(16));
        $pend = array_filter(is_array($sesion['correo_demo'] ?? null) ? $sesion['correo_demo'] : [], fn($exp) => is_int($exp) && $exp > $ahora);
        if (count($pend) >= self::MAX_PENDIENTES) $pend = array_slice($pend, -(self::MAX_PENDIENTES - 1), null, true);
        $pend[$nonce] = $ahora + self::DURA_SEG;
        $sesion['correo_demo'] = $pend;
        $json = json_encode(['n' => $nonce, 'exp' => $ahora + self::DURA_SEG, 'd' => $datos], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $iv = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $c = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($json, "correo_demo:$adminId", $iv, self::llave($sesion));
        return rtrim(strtr(base64_encode($iv . $c), '+/', '-_'), '=');
    }

    /** Abre un sello: ['n', 'd', 'usado'] o null si es falso, de otro súper usuario, de otra sesión o venció. */
    public static function abrir(string $sello, int $adminId, array $sesion, ?int $ahora = null): ?array
    {
        $ahora ??= time();
        if ($sello === '' || strlen($sello) > 20000 || !is_string($sesion['correo_demo_llave'] ?? null)) return null;
        $bin = base64_decode(strtr($sello, '-_', '+/'), true);
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (!is_string($bin) || strlen($bin) <= $n + 16) return null;
        $k = base64_decode($sesion['correo_demo_llave'], true);
        if (!is_string($k) || strlen($k) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) return null;
        try {
            $json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bin, $n), "correo_demo:$adminId", substr($bin, 0, $n), $k);
        } catch (Throwable) {
            return null;
        }
        $x = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($x) || !is_string($x['n'] ?? null) || !is_int($x['exp'] ?? null) || !is_array($x['d'] ?? null) || $x['exp'] <= $ahora) return null;
        $pend = is_array($sesion['correo_demo'] ?? null) ? $sesion['correo_demo'] : [];
        return ['n' => $x['n'], 'd' => $x['d'], 'usado' => !isset($pend[$x['n']])];
    }

    /** Borra el número de un solo uso (después de enviar bien). */
    public static function gastar(string $nonce, array &$sesion): void
    {
        unset($sesion['correo_demo'][$nonce]);
    }

    /** El correo «Su demo de {Programa} está lista» (plantilla de la librería: sin precios ni «gratis»). */
    public static function mensaje(array $d): array
    {
        return tukan_correo_demo([
            'programa' => (string) ($d['programa'] ?? ''), 'enlace' => (string) ($d['enlace'] ?? ''),
            'usuario' => (string) ($d['usuario'] ?? ''), 'clave' => (string) ($d['clave'] ?? ''),
            'hasta' => (string) ($d['hasta'] ?? ''), 'contacto' => (string) ($d['contacto'] ?? ''), 'negocio' => (string) ($d['negocio'] ?? ''),
        ]);
    }

    /** Manda el correo. El $error nunca trae la contraseña de la demo ni la del buzón. */
    public static function enviar(array $d, string $para, ?string &$error = null): bool
    {
        $m = self::mensaje($d);
        $ok = tukan_correo_enviar($para, $m['asunto'], $m['html'], $m['texto'], $error, array_filter(['nombre_para' => (string) ($d['contacto'] ?? '')]));
        $clave = (string) ($d['clave'] ?? '');
        if ($error !== null && $clave !== '') $error = str_replace($clave, '***', $error);
        return $ok;
    }
}
