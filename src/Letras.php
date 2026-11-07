<?php
declare(strict_types=1);

namespace TuRenta;

/** Números en letras para contratos: 4.850,50 -> "cuatro mil ochocientos cincuenta dólares con 50/100". */
final class Letras
{
    private const UNIDADES = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
        'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
        'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
    private const DECENAS = ['', '', '', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    private const CENTENAS = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    public static function dolares(float $valor): string
    {
        $valor = round(abs($valor), 2);
        $entero = (int) floor($valor);
        $centavos = (int) round(($valor - $entero) * 100);
        $texto = $entero === 0 ? 'cero' : self::numero($entero);
        $texto = preg_replace('/uno$/', 'un', $texto);
        return $texto . ($entero === 1 ? ' dólar' : ' dólares') . ' con ' . sprintf('%02d', $centavos) . '/100';
    }

    public static function numero(int $n): string
    {
        if ($n < 30) return self::UNIDADES[$n];
        if ($n < 100) {
            $d = intdiv($n, 10); $u = $n % 10;
            return self::DECENAS[$d] . ($u ? ' y ' . self::UNIDADES[$u] : '');
        }
        if ($n === 100) return 'cien';
        if ($n < 1000) {
            $c = intdiv($n, 100); $r = $n % 100;
            return self::CENTENAS[$c] . ($r ? ' ' . self::numero($r) : '');
        }
        if ($n < 1000000) {
            $m = intdiv($n, 1000); $r = $n % 1000;
            $miles = $m === 1 ? 'mil' : preg_replace('/uno$/', 'ún', self::numero($m)) . ' mil';
            return $miles . ($r ? ' ' . self::numero($r) : '');
        }
        $mm = intdiv($n, 1000000); $r = $n % 1000000;
        $millones = $mm === 1 ? 'un millón' : preg_replace('/uno$/', 'ún', self::numero($mm)) . ' millones';
        return $millones . ($r ? ' ' . self::numero($r) : '');
    }
}
