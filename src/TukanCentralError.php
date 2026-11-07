<?php
declare(strict_types=1);

namespace TuRenta;

use RuntimeException;

/**
 * Error de un pedido del protocolo v2 del Panel Tukán Central (PROTOCOLO_V2.md §3.2).
 * Se convierte tal cual en {"ok":false,"protocolo":2,"codigo":…,"error":…,"campo":…,"detalle":{…}}.
 */
final class TukanCentralError extends RuntimeException
{
    public const CODIGOS = ['firma', 'inst', 'tipo', 'version', 'vencido', 'futuro', 'nonce', 'sin_configurar', 'limite',
                            'no_encontrado', 'invalido', 'no_permitido', 'conflicto', 'interno'];

    public function __construct(public readonly string $codigo, string $mensaje, public readonly int $http = 400,
                                public readonly ?string $campo = null, public readonly array $detalle = [])
    {
        parent::__construct($mensaje);
    }

    public static function campo(string $campo, string $mensaje): self
    {
        return new self('invalido', $mensaje, 422, $campo);
    }

    public function json(): array
    {
        $j = ['ok' => false, 'protocolo' => 2, 'codigo' => $this->codigo, 'error' => $this->getMessage()];
        if ($this->campo !== null) $j['campo'] = $this->campo;
        if ($this->detalle) $j['detalle'] = $this->detalle;
        return $j;
    }
}
