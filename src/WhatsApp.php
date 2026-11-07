<?php
declare(strict_types=1);

namespace TuRenta;

/**
 * WhatsApp con un clic (plan Básica): arma un enlace wa.me con el mensaje
 * listo; el dueño solo toca "Enviar" en su celular.
 */
final class WhatsApp
{
    /** Convierte 0991234567 / +593 99 123 4567 / 593991234567 en 593991234567. */
    public static function normalizarTelefono(?string $tel): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $tel);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '593') && strlen($d) === 12) {
            return $d;
        }
        if (str_starts_with($d, '0') && strlen($d) === 10) {
            return '593' . substr($d, 1);
        }
        if (strlen($d) === 9 && $d[0] === '9') {   // 991234567
            return '593' . $d;
        }
        return strlen($d) >= 10 ? $d : null;       // número de otro país
    }

    public static function enlace(?string $telefono, string $mensaje): ?string
    {
        $n = self::normalizarTelefono($telefono);
        return $n === null ? null : 'https://wa.me/' . $n . '?text=' . rawurlencode($mensaje);
    }

    public static function recordatorio(string $cliente, string $salon, \DateTimeInterface $inicio, string $profesional): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $dia = $dias[(int) $inicio->format('w')] . ' ' . $inicio->format('j/n');
        $nombre = trim(explode(' ', trim($cliente))[0]);
        return "Hola $nombre, te recordamos tu cita en $salon el $dia a las " . $inicio->format('H:i')
             . " con $profesional. Responde SÍ para confirmar. ¡Te esperamos!";
    }
}
