<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;
use RuntimeException;

/**
 * "Todo se pide demo": ya no hay registro abierto con periodo de prueba. Quien quiere conocer TuRenta llena
 * el formulario de /?r=registro (pedido de demo); Tukán lo ve en el Panel Tukán Central (solicitud demo_pedida) o en
 * el panel de Tukán del programa y, si corresponde, crea la demo (crear_demo) con los módulos que le interesan.
 * Estados: nueva → contactada → demo_creada (o descartada).
 */
final class PedidosDemo
{
    public const ESTADOS = ['nueva' => 'Nueva', 'contactada' => 'Contactada', 'demo_creada' => 'Demo creada', 'descartada' => 'Descartada'];
    public const MAX_POR_HORA = 5;   // pedidos por IP en una hora (anti-abuso)

    public function __construct(private PDO $db) {}

    /** Guarda un pedido. $d: nombre, negocio, ciudad, whatsapp, email, necesita, modulos (lista de ids). Devuelve el id. */
    public function crear(array $d, string $ip = ''): int
    {
        $nombre = trim((string) ($d['nombre'] ?? ''));
        $negocio = trim((string) ($d['negocio'] ?? ''));
        $ciudad = trim((string) ($d['ciudad'] ?? ''));
        $wa = trim((string) ($d['whatsapp'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        $necesita = trim((string) ($d['necesita'] ?? ''));
        $errores = [];
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) $errores[] = 'Escribe tu nombre.';
        if (mb_strlen($negocio) < 3 || mb_strlen($negocio) > 120) $errores[] = 'Escribe el nombre de tu salón, quinta o hacienda.';
        if (mb_strlen($ciudad) > 80) $errores[] = 'La ciudad es muy larga.';
        $digitos = preg_replace('/\D+/', '', $wa);
        if (strlen((string) $digitos) < 9 || strlen((string) $digitos) > 15 || mb_strlen($wa) > 30) $errores[] = 'Escribe tu WhatsApp (ej. 0991234567).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120) $errores[] = 'El correo no es válido.';
        if (mb_strlen($necesita) > 1000) $errores[] = 'Cuéntanos en menos palabras qué necesitas (máximo 1000 letras).';
        $mods = $d['modulos'] ?? [];
        if (!is_array($mods)) $mods = [];
        $mods = array_values(array_filter($mods, fn($m) => is_string($m) && Modulos::existe($m)));
        if ($errores) throw new RuntimeException(implode(' ', $errores));
        if ($ip !== '') {
            $st = $this->db->prepare("SELECT count(*) FROM pedidos_demo WHERE ip = ? AND creado > now() - interval '1 hour'");
            $st->execute([$ip]);
            if ((int) $st->fetchColumn() >= self::MAX_POR_HORA) throw new RuntimeException('Ya recibimos varios pedidos desde aquí. Escríbenos por WhatsApp y te ayudamos.');
        }
        // El mismo correo con un pedido abierto: se actualiza ese pedido (no se repite)
        $st = $this->db->prepare("SELECT id FROM pedidos_demo WHERE lower(email) = ? AND estado IN ('nueva','contactada') ORDER BY id DESC LIMIT 1");
        $st->execute([$email]);
        $abierto = $st->fetchColumn();
        if ($abierto) {
            $this->db->prepare('UPDATE pedidos_demo SET nombre = ?, negocio = ?, ciudad = COALESCE(?, ciudad), whatsapp = ?, necesita = COALESCE(?, necesita), modulos = COALESCE(NULLIF(?, \'\'), modulos), actualizado = now() WHERE id = ?')
                     ->execute([$nombre, $negocio, $ciudad ?: null, $wa, $necesita ?: null, Modulos::csv($mods), $abierto]);
            return (int) $abierto;
        }
        $st = $this->db->prepare('INSERT INTO pedidos_demo (nombre, negocio, ciudad, whatsapp, email, necesita, modulos, ip) VALUES (?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$nombre, $negocio, $ciudad ?: null, $wa, $email, $necesita ?: null, Modulos::csv($mods), mb_substr($ip, 0, 45) ?: null]);
        return (int) $st->fetchColumn();
    }

    public function obtener(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM pedidos_demo WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Pedidos abiertos (nueva, contactada) y los cerrados desde $desde. */
    public function lista(?string $desde = null): array
    {
        $st = $this->db->prepare("SELECT * FROM pedidos_demo WHERE estado IN ('nueva','contactada') OR actualizado >= ?::date ORDER BY creado DESC, id DESC");
        $st->execute([$desde ?? '1970-01-01']);
        return $st->fetchAll();
    }

    public function abiertos(): array
    {
        return $this->db->query("SELECT * FROM pedidos_demo WHERE estado IN ('nueva','contactada') ORDER BY creado DESC, id DESC")->fetchAll();
    }

    /** Cambia el estado (contactada / descartada / nueva). */
    public function marcar(int $id, string $estado, string $nota = ''): void
    {
        if (!isset(self::ESTADOS[$estado]) || $estado === 'demo_creada') throw new RuntimeException('Estado no válido.');
        $st = $this->db->prepare("UPDATE pedidos_demo SET estado = ?, nota = COALESCE(?, nota), actualizado = now() WHERE id = ? AND estado <> 'demo_creada'");
        $st->execute([$estado, mb_substr(trim($nota), 0, 300) ?: null, $id]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Ese pedido no existe o ya tiene su demo.');
    }

    /** La demo ya se creó: queda unida al negocio. */
    public function demoCreada(int $id, int $negocioId): void
    {
        $this->db->prepare("UPDATE pedidos_demo SET estado = 'demo_creada', negocio_id = ?, actualizado = now() WHERE id = ?")->execute([$negocioId, $id]);
    }
}
