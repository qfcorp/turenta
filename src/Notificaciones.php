<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;

/**
 * Avisos dentro del sistema (campana) y por Telegram a los usuarios que lo conectaron.
 * (WhatsApp automático: pendiente, con la API oficial. Hoy hay WhatsApp con un toque.)
 */
final class Notificaciones
{
    public function __construct(private PDO $db) {}

    /**
     * Usuarios que reciben avisos del negocio: dueño, vendedores y coordinadores activos.
     * Los administradores tienen el mismo nivel que el dueño: todo aviso para el dueño les llega también.
     */
    public function destinos(int $negocioId, array $roles = ['dueno', 'vendedor', 'coordinador']): array
    {
        if (in_array('dueno', $roles, true) && !in_array('admin', $roles, true)) $roles[] = 'admin';
        $marcas = implode(',', array_fill(0, count($roles), '?'));
        $st = $this->db->prepare("SELECT id FROM usuarios WHERE negocio_id = ? AND activo AND rol IN ($marcas)");
        $st->execute([$negocioId, ...$roles]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Aviso a todo el equipo del negocio (o a los roles indicados). Devuelve a cuántos llegó. */
    public function alNegocio(int $negocioId, string $texto, ?int $eventoId = null, array $roles = ['dueno', 'vendedor']): int
    {
        $ids = $this->destinos($negocioId, $roles);
        if (!$ids) $ids = $this->destinos($negocioId, ['dueno']);
        $this->aUsuarios($negocioId, $ids, $texto, $eventoId);
        return count($ids);
    }

    public function aUsuarios(int $negocioId, array $usuarioIds, string $texto, ?int $eventoId = null, bool $telegram = true): void
    {
        $ins = $this->db->prepare('INSERT INTO notificaciones (negocio_id, usuario_id, evento_id, texto) VALUES (?,?,?,?)');
        foreach (array_unique($usuarioIds) as $uid) $ins->execute([$negocioId, $uid, $eventoId, mb_substr($texto, 0, 400)]);
        if ($telegram) (new Telegram($this->db))->textoAUsuarios($usuarioIds, $texto);
    }

    public function sinLeer(int $usuarioId): int
    {
        $st = $this->db->prepare('SELECT count(*) FROM notificaciones WHERE usuario_id = ? AND NOT leida');
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    public function recientes(int $usuarioId, int $limite = 40): array
    {
        $st = $this->db->prepare('SELECT n.*, e.codigo FROM notificaciones n LEFT JOIN eventos e ON e.id = n.evento_id
                                   WHERE n.usuario_id = ? ORDER BY n.creada_en DESC, n.id DESC LIMIT ' . (int) $limite);
        $st->execute([$usuarioId]);
        return $st->fetchAll();
    }

    public function marcarLeidas(int $usuarioId): void
    {
        $this->db->prepare('UPDATE notificaciones SET leida = true WHERE usuario_id = ? AND NOT leida')->execute([$usuarioId]);
    }
}
