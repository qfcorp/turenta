<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Planes, demo y suscripciones de TuRenta (mismas reglas que la familia Tukán).
 *  - Solo dos planes (migración 112, decisión de Dimitry del 28 oct 2026; se cambian en el panel de Tukán):
 *    Básico $20 (código esencial: la base + 3 módulos que elige el cliente; cada uno más $5) y
 *    Completo $45 (código quinta: todos los módulos, con el Asistente IA). El Asistente IA en Básico: $5 aparte.
 *  - Semestral: paga 5, recibe 6
 *  - Anual: paga 10, recibe 12 + dominio propio (a nombre de Tukán)
 *  - Demo: 7 días (solo la crea Tukán; no hay registro abierto con periodo de prueba). Cada plan = base + módulos (Modulos)
 */
final class Planes
{
    public const AJUSTES = [
        'dias_prueba' => '7',
        'semestral_paga' => '5', 'semestral_recibe' => '6',
        'anual_paga' => '10', 'anual_recibe' => '12',
        'dias_gracia' => '3',
        'whatsapp_ventas' => '593983662181',
    ];
    public const PERIODOS_NOMBRE = ['mensual' => 'Mensual', 'semestral' => 'Semestral', 'anual' => 'Anual'];

    private ?array $ajustes = null;

    public function __construct(private PDO $db) {}

    public function ajustes(): array
    {
        if ($this->ajustes === null) {
            $this->ajustes = self::AJUSTES;
            foreach ($this->db->query('SELECT clave, valor FROM ajustes')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                $this->ajustes[$k] = (string) $v;
            }
        }
        return $this->ajustes;
    }

    public function ajuste(string $clave): string
    {
        return $this->ajustes()[$clave] ?? '';
    }

    public function guardarAjustes(array $valores): void
    {
        $n = fn($k, $min, $max) => (isset($valores[$k]) && ctype_digit((string) $valores[$k])
            && (int) $valores[$k] >= $min && (int) $valores[$k] <= $max) ? (string) (int) $valores[$k]
            : throw new RuntimeException("Revisa el valor de \"$k\".");
        $limpio = [
            'dias_prueba' => $n('dias_prueba', 0, 90),
            'semestral_paga' => $n('semestral_paga', 1, 6), 'semestral_recibe' => $n('semestral_recibe', 6, 12),
            'anual_paga' => $n('anual_paga', 1, 12), 'anual_recibe' => $n('anual_recibe', 12, 24),
            'dias_gracia' => $n('dias_gracia', 0, 30),
        ];
        if ((int) $limpio['semestral_paga'] > (int) $limpio['semestral_recibe'] || (int) $limpio['anual_paga'] > (int) $limpio['anual_recibe']) {
            throw new RuntimeException('Los meses que paga no pueden ser más que los que recibe.');
        }
        $wa = preg_replace('/\D+/', '', (string) ($valores['whatsapp_ventas'] ?? ''));
        if (strlen($wa) < 10 || strlen($wa) > 15) throw new RuntimeException('El WhatsApp de ventas debe tener el código del país, ej. 593991234567.');
        $limpio['whatsapp_ventas'] = $wa;
        $st = $this->db->prepare('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON CONFLICT (clave) DO UPDATE SET valor = EXCLUDED.valor');
        foreach ($limpio as $k => $v) $st->execute([$k, $v]);
        $this->ajustes = null;
    }

    public function periodos(): array
    {
        $a = $this->ajustes();
        return [
            'mensual'   => ['paga' => 1, 'recibe' => 1, 'dominio' => false],
            'semestral' => ['paga' => (int) $a['semestral_paga'], 'recibe' => (int) $a['semestral_recibe'], 'dominio' => false],
            'anual'     => ['paga' => (int) $a['anual_paga'], 'recibe' => (int) $a['anual_recibe'], 'dominio' => true],
        ];
    }

    public function textoPeriodo(string $periodo): string
    {
        $p = $this->periodos()[$periodo];
        return $periodo === 'mensual' ? 'Mensual'
            : 'Paga ' . $p['paga'] . ', recibe ' . $p['recibe'] . ($p['dominio'] ? ' + dominio propio' : '');
    }

    public function lista(): array
    {
        return $this->db->query('SELECT * FROM planes ORDER BY precio_mensual')->fetchAll();
    }

    /**
     * Guarda un plan. $modulosElegir: módulos extra incluidos en el precio (null = no cambiar);
     * $precioModuloExtra: lo que cuesta cada módulo extra después de esos (false = no cambiar, null = el precio del catálogo).
     */
    public function guardarPlan(string $codigo, string $nombre, float $precio, ?int $maxEspacios, ?int $maxUsuarios, string $descripcion = '',
                                ?int $modulosElegir = null, float|null|false $precioModuloExtra = false): void
    {
        if ($modulosElegir !== null && ($modulosElegir < 0 || $modulosElegir > 50)) throw new RuntimeException('Los módulos a elegir deben ser de 0 a 50.');
        if ($precioModuloExtra !== false && $precioModuloExtra !== null && ($precioModuloExtra < 0 || $precioModuloExtra > 10000)) {
            throw new RuntimeException('El precio de cada módulo extra no es válido.');
        }
        if (mb_strlen(trim($nombre)) < 2) throw new RuntimeException('Escribe el nombre del plan.');
        if ($precio < 0 || $precio > 10000) throw new RuntimeException('Precio no válido.');
        if ($maxEspacios !== null && $maxEspacios < 1) throw new RuntimeException('El máximo de espacios debe ser 1 o más (vacío = sin límite).');
        if ($maxUsuarios !== null && $maxUsuarios < 1) throw new RuntimeException('El máximo de usuarios debe ser 1 o más (vacío = sin límite).');
        $st = $this->db->prepare('UPDATE planes SET nombre = ?, precio_mensual = ?, max_espacios = ?, max_usuarios = ?, descripcion = ? WHERE codigo = ?');
        $st->execute([trim($nombre), round($precio, 2), $maxEspacios, $maxUsuarios, trim($descripcion) ?: null, $codigo]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Plan no encontrado.');
        if ($modulosElegir !== null) $this->db->prepare('UPDATE planes SET modulos_elegir = ? WHERE codigo = ?')->execute([$modulosElegir, $codigo]);
        if ($precioModuloExtra !== false) {
            $this->db->prepare('UPDATE planes SET precio_modulo_extra = ? WHERE codigo = ?')
                     ->execute([$precioModuloExtra === null ? null : round($precioModuloExtra, 2), $codigo]);
        }
    }

    /** prueba, prueba_vencida, activo, por_vencer (en días de gracia), vencido, suspendido, cancelado. */
    public function estadoCuenta(array $negocio, ?DateTimeImmutable $hoy = null): string
    {
        $hoy = ($hoy ?? new DateTimeImmutable('today'))->format('Y-m-d');
        return match ($negocio['estado']) {
            'suspendido', 'cancelado' => $negocio['estado'],
            'prueba' => (!empty($negocio['prueba_hasta']) && $negocio['prueba_hasta'] < $hoy) ? 'prueba_vencida' : 'prueba',
            default => (function () use ($negocio, $hoy) {
                if (empty($negocio['activo_hasta']) || $negocio['activo_hasta'] >= $hoy) return 'activo';
                $gracia = (new DateTimeImmutable($negocio['activo_hasta']))->modify('+' . (int) $this->ajuste('dias_gracia') . ' days')->format('Y-m-d');
                return $gracia >= $hoy ? 'por_vencer' : 'vencido';
            })(),
        };
    }

    public function alDia(array $negocio, ?DateTimeImmutable $hoy = null): bool
    {
        return in_array($this->estadoCuenta($negocio, $hoy), ['prueba', 'activo', 'por_vencer'], true);
    }

    public function crearNegocio(string $nombre, string $slug, string $plan, ?DateTimeImmutable $hoy = null): int
    {
        $hoy ??= new DateTimeImmutable('today');
        $hasta = $hoy->modify('+' . (int) $this->ajuste('dias_prueba') . ' days')->format('Y-m-d');
        $st = $this->db->prepare("INSERT INTO negocios (nombre, slug, plan_codigo, estado, prueba_hasta) VALUES (?, ?, ?, 'prueba', ?) RETURNING id");
        $st->execute([$nombre, $slug, $plan, $hasta]);
        return (int) $st->fetchColumn();
    }

    /**
     * Lo que paga un negocio (Anexo B): precio del plan + módulos extra − módulos quitados (o su precio acordado),
     * por el periodo elegido. Con $plan distinto al suyo, se calcula con los módulos de ese plan.
     */
    public function cotizarNegocio(int $negocioId, string $plan, string $periodo): array
    {
        $c = $this->cotizar($plan, $periodo);
        $mensual = (new Modulos($this->db))->mensual($negocioId, $plan);
        $c['monto'] = round($mensual * $c['meses_pagados'], 2);
        $c['precio_por_mes'] = round($c['monto'] / $c['meses_recibidos'], 2);
        $c['mensual'] = $mensual;
        return $c;
    }

    public function cotizar(string $plan, string $periodo): array
    {
        $periodos = $this->periodos();
        if (!isset($periodos[$periodo])) throw new RuntimeException("Periodo no válido: $periodo");
        $st = $this->db->prepare('SELECT precio_mensual FROM planes WHERE codigo = ?');
        $st->execute([$plan]);
        $precio = $st->fetchColumn();
        if ($precio === false) throw new RuntimeException("Plan no válido: $plan");
        $p = $periodos[$periodo];
        $monto = round((float) $precio * $p['paga'], 2);
        return [
            'plan' => $plan, 'periodo' => $periodo,
            'meses_pagados' => $p['paga'], 'meses_recibidos' => $p['recibe'],
            'monto' => $monto, 'precio_por_mes' => round($monto / $p['recibe'], 2),
            'incluye_dominio' => $p['dominio'],
        ];
    }

    public function inicioSugerido(int $negocioId, ?DateTimeImmutable $hoy = null): DateTimeImmutable
    {
        $hoy ??= new DateTimeImmutable('today');
        $st = $this->db->prepare('SELECT activo_hasta FROM negocios WHERE id = ?');
        $st->execute([$negocioId]);
        $hasta = $st->fetchColumn();
        if ($hasta && $hasta >= $hoy->format('Y-m-d')) return (new DateTimeImmutable($hasta))->modify('+1 day');
        return $hoy;
    }

    /**
     * Registra un pago de suscripción. $pagadoEn: fecha en que pagó (por defecto hoy; el Panel Tukán Central la manda).
     */
    public function suscribir(int $negocioId, string $plan, string $periodo, DateTimeImmutable $inicio,
                              string $metodoPago, ?string $dominio = null, ?float $monto = null, ?DateTimeImmutable $pagadoEn = null): int
    {
        $c = $this->cotizarNegocio($negocioId, $plan, $periodo);
        if ($monto !== null) {
            if ($monto < 0) throw new RuntimeException('El monto no puede ser negativo.');
            $c['monto'] = round($monto, 2);
        }
        $fin = $inicio->modify('+' . $c['meses_recibidos'] . ' months')->modify('-1 day');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        $st = $this->db->prepare(
            'INSERT INTO suscripciones (negocio_id, plan_codigo, periodo, meses_pagados, meses_recibidos,
                                        monto, inicio, fin, metodo_pago, incluye_dominio, dominio, pagado_en)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, COALESCE(?::date, CURRENT_DATE)) RETURNING id'
        );
        $st->execute([
            $negocioId, $plan, $periodo, $c['meses_pagados'], $c['meses_recibidos'], $c['monto'],
            $inicio->format('Y-m-d'), $fin->format('Y-m-d'), $metodoPago,
            $c['incluye_dominio'] ? 'true' : 'false', $c['incluye_dominio'] ? $dominio : null,
            $pagadoEn?->format('Y-m-d'),
        ]);
        $id = (int) $st->fetchColumn();
        $this->db->prepare("UPDATE negocios SET estado = 'activo', plan_codigo = ?,
                                   activo_hasta = GREATEST(COALESCE(activo_hasta, ?::date), ?::date) WHERE id = ?")
                 ->execute([$plan, $fin->format('Y-m-d'), $fin->format('Y-m-d'), $negocioId]);
        if ($propia) $this->db->commit();
        return $id;
    }

    public function anularPago(int $suscripcionId): void
    {
        $st = $this->db->prepare('UPDATE suscripciones SET anulada = true WHERE id = ? AND NOT anulada RETURNING negocio_id');
        $st->execute([$suscripcionId]);
        $negocioId = $st->fetchColumn();
        if (!$negocioId) throw new RuntimeException('Ese pago no existe o ya estaba anulado.');
        $this->db->prepare('UPDATE negocios SET activo_hasta = (SELECT max(fin) FROM suscripciones WHERE negocio_id = ? AND NOT anulada) WHERE id = ?')
                 ->execute([$negocioId, $negocioId]);
    }

    /** ¿El plan permite otro espacio activo? */
    public function puedeAgregarEspacio(int $negocioId): bool
    {
        return $this->dentroDelLimite($negocioId, 'max_espacios', 'SELECT count(*) FROM espacios WHERE negocio_id = ? AND activo');
    }

    /** ¿El plan permite otro usuario activo? Cuenta al dueño y a su equipo; los administradores (máx. 4) no cuentan. */
    public function puedeAgregarUsuario(int $negocioId): bool
    {
        return $this->dentroDelLimite($negocioId, 'max_usuarios', self::SQL_USUARIOS_DEL_PLAN);
    }

    /** Usuarios activos que cuentan en planes.max_usuarios (todos menos los administradores). */
    public const SQL_USUARIOS_DEL_PLAN = "SELECT count(*) FROM usuarios WHERE negocio_id = ? AND activo AND rol <> 'admin'";

    public function usuariosDelPlan(int $negocioId): int
    {
        $st = $this->db->prepare(self::SQL_USUARIOS_DEL_PLAN);
        $st->execute([$negocioId]);
        return (int) $st->fetchColumn();
    }

    private function dentroDelLimite(int $negocioId, string $columna, string $sqlConteo): bool
    {
        $st = $this->db->prepare("SELECT p.$columna FROM negocios n JOIN planes p ON p.codigo = n.plan_codigo WHERE n.id = ?");
        $st->execute([$negocioId]);
        $max = $st->fetchColumn();
        if ($max === null || $max === false) return true;
        $st = $this->db->prepare($sqlConteo);
        $st->execute([$negocioId]);
        return (int) $st->fetchColumn() < (int) $max;
    }
}
