<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * Usuarios del negocio (los crea el dueño o un administrador):
 *  - admin: administrador, el MISMO nivel que el dueño en todo el programa (máximo 4 activos, no cuentan en el plan)
 *  - vendedor: ventas, cotizaciones, contratos y cobros
 *  - coordinador: solo lo del día del evento (sin dinero)
 *  - consulta: ve todo, no cambia nada (contador, socio)
 * El plan limita cuántos usuarios activos puede tener el negocio (cuenta al dueño, no a los administradores).
 * Exclusivo del dueño: ser la cuenta registrada ante Tukán (contacto del Panel Central, «Nueva clave del dueño»).
 * Nadie (ni un administrador) puede cambiar o desactivar al dueño, y nadie se cambia ni se desactiva a sí mismo.
 */
final class Usuarios
{
    public const ROLES = [
        'dueno' => 'Dueño', 'admin' => 'Administrador', 'vendedor' => 'Vendedor', 'coordinador' => 'Coordinador', 'consulta' => 'Solo consulta',
    ];
    public const ROLES_AYUDA = [
        'dueno'       => 'Todo, incluida la configuración, los usuarios y el plan.',
        'admin'       => 'Mismos permisos que el dueño: todo, incluida la configuración, los usuarios, el plan y el dinero. No cuenta en el límite de usuarios del plan.',
        'vendedor'    => 'Atiende consultas, cotiza, hace contratos y registra cobros.',
        'coordinador' => 'Ve los eventos y organiza el día: personal, proveedores, invitados y mobiliario. No ve dinero.',
        'consulta'    => 'Ve todo (eventos, cobros, reportes) pero no puede cambiar nada. Ideal para el contador o un socio.',
    ];
    /** Roles que el dueño o un administrador pueden crear o asignar. */
    public const ROLES_ASIGNABLES = ['admin', 'vendedor', 'coordinador', 'consulta'];
    /** Texto de la opción en la pantalla Usuarios. */
    public const OPCION_ADMIN = 'Administrador (mismos permisos que el dueño)';
    /** Administradores activos que puede tener el negocio, además del dueño. */
    public const MAX_ADMINS = 4;
    public const MENSAJE_MAX_ADMINS = 'Puede tener hasta 4 administradores además del dueño.';
    private const MENSAJE_ROL = 'Ese tipo de usuario no existe. Elige qué va a hacer: administrador, vendedor, coordinador o solo consulta. No se guardó nada.';

    public function __construct(private PDO $db) {}

    public function lista(int $negocioId): array
    {
        $st = $this->db->prepare("SELECT id, nombre, email, telefono, rol, activo, telegram_chat_id IS NOT NULL AS telegram,
                                         clave_temporal, creado_en, ultimo_ingreso
                                    FROM usuarios WHERE negocio_id = ?
                                ORDER BY activo DESC, CASE rol WHEN 'dueno' THEN 0 WHEN 'admin' THEN 1 WHEN 'vendedor' THEN 2 WHEN 'coordinador' THEN 3 ELSE 4 END, nombre");
        $st->execute([$negocioId]);
        return $st->fetchAll();
    }

    public function obtener(int $negocioId, int $usuarioId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM usuarios WHERE id = ? AND negocio_id = ?');
        $st->execute([$usuarioId, $negocioId]);
        return $st->fetch() ?: null;
    }

    /** Administradores activos del negocio (sin contar al dueño). */
    public function adminsActivos(int $negocioId): int
    {
        $st = $this->db->prepare("SELECT count(*) FROM usuarios WHERE negocio_id = ? AND activo AND rol = 'admin'");
        $st->execute([$negocioId]);
        return (int) $st->fetchColumn();
    }

    /** ¿Se puede crear o activar otro administrador? */
    public function puedeAgregarAdmin(int $negocioId): bool
    {
        return $this->adminsActivos($negocioId) < self::MAX_ADMINS;
    }

    /** Valida el rol que llega del formulario: si no es uno permitido, error y no se guarda nada (nunca un rol por defecto). */
    public static function validarRol(string $rol): string
    {
        if (!in_array($rol, self::ROLES_ASIGNABLES, true)) throw new RuntimeException(self::MENSAJE_ROL);
        return $rol;
    }

    /**
     * Bloquea la fila del negocio mientras se cuentan los cupos (dos personas creando a la vez no pasan el límite).
     * Devuelve true si abrió la transacción (y hay que cerrarla).
     */
    private function bloquearNegocio(int $negocioId): bool
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        $this->db->prepare('SELECT id FROM negocios WHERE id = ? FOR UPDATE')->execute([$negocioId]);
        return $propia;
    }

    /** Hace $f dentro del bloqueo del negocio. */
    private function conBloqueo(int $negocioId, callable $f): mixed
    {
        $propia = $this->bloquearNegocio($negocioId);
        try {
            $r = $f();
            if ($propia) $this->db->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** ¿Hay cupo para una persona activa más con ese rol? (administradores: máximo 4; los demás: el plan) */
    private function exigirCupo(int $negocioId, string $rol): void
    {
        if ($rol === 'admin') {
            if (!$this->puedeAgregarAdmin($negocioId)) throw new RuntimeException(self::MENSAJE_MAX_ADMINS);
        } elseif (!(new Planes($this->db))->puedeAgregarUsuario($negocioId)) {
            throw new RuntimeException($this->mensajeLimite($negocioId));
        }
    }

    /** Contraseña temporal fácil de dictar (sin 0/O ni 1/l). */
    public static function claveTemporal(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $c = '';
        for ($i = 0; $i < 10; $i++) $c .= $letras[random_int(0, strlen($letras) - 1)];
        return $c;
    }

    private function mensajeLimite(int $negocioId): string
    {
        $st = $this->db->prepare('SELECT p.nombre, p.max_usuarios FROM negocios n JOIN planes p ON p.codigo = n.plan_codigo WHERE n.id = ?');
        $st->execute([$negocioId]);
        $p = $st->fetch() ?: ['nombre' => '', 'max_usuarios' => 0];
        return 'Tu plan ' . $p['nombre'] . ' permite ' . (int) $p['max_usuarios'] . ' usuarios activos (contando al dueño; los administradores no cuentan). '
             . 'Desactiva a alguien que ya no use el sistema o cambia de plan en Mi plan (el plan Completo no tiene límite de usuarios).';
    }

    /**
     * Crea un usuario con una contraseña temporal (se muestra una sola vez a quien lo crea: el dueño o un administrador).
     * @return array{id:int, clave:string, email:string, nombre:string}
     */
    public function crear(int $negocioId, string $nombre, string $email, string $telefono, string $rol): array
    {
        $nombre = trim($nombre);
        $email = strtolower(trim($email));
        $telefono = trim($telefono);
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe el nombre de la persona.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido. Con ese correo va a entrar al sistema.');
        self::validarRol($rol);
        if ($telefono !== '' && WhatsApp::normalizarTelefono($telefono) === null) throw new RuntimeException('El celular no es válido. Escríbelo así: 0991234567.');
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) throw new RuntimeException('Ya existe un usuario con ese correo (en tu negocio o en otro). Usa otro correo.');
        $clave = self::claveTemporal();
        $id = $this->conBloqueo($negocioId, function () use ($negocioId, $nombre, $email, $telefono, $rol, $clave): int {
            $this->exigirCupo($negocioId, $rol);
            $st = $this->db->prepare('INSERT INTO usuarios (negocio_id, nombre, email, telefono, password_hash, rol, clave_temporal)
                                      VALUES (?,?,?,?,?,?, true) RETURNING id');
            $st->execute([$negocioId, mb_substr($nombre, 0, 100), $email, $telefono ?: null, password_hash($clave, PASSWORD_DEFAULT), $rol]);
            return (int) $st->fetchColumn();
        });
        return ['id' => $id, 'clave' => $clave, 'email' => $email, 'nombre' => $nombre];
    }

    /** Usuario de otro (no el dueño, no uno mismo) que se puede cambiar. Los administradores sí pueden cambiar a otros administradores. */
    private function cambiable(int $negocioId, int $usuarioId, int $quienId): array
    {
        $u = $this->obtener($negocioId, $usuarioId);
        if (!$u) throw new RuntimeException('No se encontró ese usuario.');
        if ($usuarioId === $quienId) throw new RuntimeException('No puedes quitarte permisos ni desactivarte a ti mismo.');
        if ($u['rol'] === 'dueno') throw new RuntimeException('La cuenta del dueño no se puede cambiar desde aquí. Escríbenos si necesitas pasarla a otra persona.');
        return $u;
    }

    public function cambiarRol(int $negocioId, int $usuarioId, string $rol, int $quienId): void
    {
        $this->cambiable($negocioId, $usuarioId, $quienId);
        self::validarRol($rol);
        $this->conBloqueo($negocioId, function () use ($negocioId, $usuarioId, $rol): void {
            $u = $this->obtener($negocioId, $usuarioId);
            if ($u['rol'] === $rol) return;
            // Un usuario activo que pasa a administrador ocupa un cupo de administrador;
            // un administrador que pasa a otro rol empieza a contar en el plan.
            if ($u['activo'] && ($rol === 'admin' || $u['rol'] === 'admin')) $this->exigirCupo($negocioId, $rol);
            $this->db->prepare('UPDATE usuarios SET rol = ? WHERE id = ? AND negocio_id = ?')->execute([$rol, $usuarioId, $negocioId]);
        });
    }

    public function activar(int $negocioId, int $usuarioId, bool $activo, int $quienId): void
    {
        $this->cambiable($negocioId, $usuarioId, $quienId);
        $this->conBloqueo($negocioId, function () use ($negocioId, $usuarioId, $activo): void {
            $u = $this->obtener($negocioId, $usuarioId);
            if ($activo && !$u['activo']) $this->exigirCupo($negocioId, (string) $u['rol']);
            $this->db->prepare('UPDATE usuarios SET activo = ? WHERE id = ? AND negocio_id = ?')->execute([$activo ? 'true' : 'false', $usuarioId, $negocioId]);
        });
    }

    /** El dueño (o un administrador) le da una contraseña nueva a alguien que la olvidó. */
    public function nuevaClave(int $negocioId, int $usuarioId, int $quienId): array
    {
        $u = $this->cambiable($negocioId, $usuarioId, $quienId);
        $clave = self::claveTemporal();
        $this->db->prepare('UPDATE usuarios SET password_hash = ?, clave_temporal = true WHERE id = ? AND negocio_id = ?')
                 ->execute([password_hash($clave, PASSWORD_DEFAULT), $usuarioId, $negocioId]);
        return ['id' => $usuarioId, 'clave' => $clave, 'email' => $u['email'], 'nombre' => $u['nombre']];
    }

    // ------------------------------------------------------------------
    // Mi cuenta
    // ------------------------------------------------------------------

    public function guardarPerfil(int $usuarioId, string $nombre, string $telefono): void
    {
        $nombre = trim($nombre);
        $telefono = trim($telefono);
        if (mb_strlen($nombre) < 2) throw new RuntimeException('Escribe tu nombre.');
        if ($telefono !== '' && WhatsApp::normalizarTelefono($telefono) === null) throw new RuntimeException('El celular no es válido. Escríbelo así: 0991234567.');
        $this->db->prepare('UPDATE usuarios SET nombre = ?, telefono = ? WHERE id = ?')->execute([mb_substr($nombre, 0, 100), $telefono ?: null, $usuarioId]);
    }

    public function cambiarClave(int $usuarioId, string $actual, string $nueva, string $repetir): void
    {
        $st = $this->db->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
        $st->execute([$usuarioId]);
        $hash = (string) $st->fetchColumn();
        if ($hash === '' || !password_verify($actual, $hash)) throw new RuntimeException('La contraseña actual no es correcta.');
        if (strlen($nueva) < 8) throw new RuntimeException('La contraseña nueva debe tener al menos 8 caracteres.');
        if ($nueva !== $repetir) throw new RuntimeException('Las dos contraseñas nuevas no son iguales. Escríbelas otra vez.');
        if ($nueva === $actual) throw new RuntimeException('La contraseña nueva debe ser distinta de la actual.');
        $this->db->prepare('UPDATE usuarios SET password_hash = ?, clave_temporal = false WHERE id = ?')
                 ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usuarioId]);
    }
}
