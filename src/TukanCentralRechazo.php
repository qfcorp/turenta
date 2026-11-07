<?php
declare(strict_types=1);

namespace TuRenta;

use RuntimeException;

/**
 * Token del Panel Tukán Central rechazado (el mensaje se le muestra a quien entra o va en el JSON de error).
 * $codigo: uno de los códigos del protocolo v2 (§3.2: firma, inst, tipo, version, vencido, futuro, nonce, invalido…).
 */
final class TukanCentralRechazo extends RuntimeException
{
    public function __construct(string $mensaje, public readonly string $codigo = 'invalido', public readonly int $http = 401)
    {
        parent::__construct($mensaje);
    }
}
