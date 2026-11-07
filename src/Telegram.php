<?php
declare(strict_types=1);

namespace TuRenta;

use PDO;

/**
 * Avisos por Telegram con el bot de TuRenta (mismo sistema que TuSalón).
 * config/.env: TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME, TELEGRAM_WEBHOOK_SECRET, (pruebas) TELEGRAM_API_BASE.
 * Cada usuario conecta su Telegram con un enlace de un solo uso (/start <código>).
 * Si Telegram no está configurado, todo sigue funcionando sin él.
 */
final class Telegram
{
    public function __construct(private PDO $db) {}

    public static function configurado(): bool
    {
        return (string) getenv('TELEGRAM_BOT_TOKEN') !== '' && (string) getenv('TELEGRAM_BOT_USERNAME') !== '';
    }

    public function api(string $metodo, array $datos): ?array
    {
        $token = (string) getenv('TELEGRAM_BOT_TOKEN');
        if ($token === '') return null;
        $base = rtrim((string) (getenv('TELEGRAM_API_BASE') ?: 'https://api.telegram.org'), '/');
        $ch = curl_init("$base/bot$token/$metodo");
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($datos, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
        ]);
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $codigo >= 400) {
            error_log("TuRenta Telegram: $metodo falló (HTTP $codigo)");
            return null;
        }
        return json_decode((string) $r, true);
    }

    public function enlaceConectar(int $usuarioId): ?string
    {
        if (!self::configurado()) return null;
        $codigo = bin2hex(random_bytes(12));
        $this->db->prepare('UPDATE usuarios SET telegram_codigo = ? WHERE id = ?')->execute([$codigo, $usuarioId]);
        return 'https://t.me/' . getenv('TELEGRAM_BOT_USERNAME') . '?start=' . $codigo;
    }

    public function desconectar(int $usuarioId): void
    {
        $this->db->prepare('UPDATE usuarios SET telegram_chat_id = NULL, telegram_codigo = NULL WHERE id = ?')->execute([$usuarioId]);
    }

    /** Envía un texto a los usuarios que tienen Telegram conectado. Devuelve cuántos se enviaron. */
    public function textoAUsuarios(array $usuarioIds, string $texto): int
    {
        if (!self::configurado() || !$usuarioIds) return 0;
        $ids = array_values(array_unique(array_map('intval', $usuarioIds)));
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare("SELECT telegram_chat_id, negocio_id FROM usuarios WHERE id IN ($marcas) AND telegram_chat_id IS NOT NULL AND activo");
        $st->execute($ids);
        $n = 0;
        $modulos = new Modulos($this->db);
        $conModulo = [];
        foreach ($st->fetchAll() as $f) {
            // Anexo B: sin el módulo "Avisos por Telegram" no sale nada por Telegram (los avisos de la campana siguen)
            $nid = (int) $f['negocio_id'];
            if (!($conModulo[$nid] ??= $modulos->activo($nid, 'telegram'))) continue;
            $chat = $f['telegram_chat_id'];
            if ($this->api('sendMessage', ['chat_id' => $chat, 'text' => mb_substr($texto, 0, 3500)])) $n++;
        }
        return $n;
    }

    /** Lo que Telegram manda al sistema (webhook). Solo se atiende "/start <código>" para conectar. */
    public function procesar(array $update): void
    {
        $msg = $update['message'] ?? null;
        if (!$msg || !isset($msg['chat']['id'])) return;
        $texto = trim((string) ($msg['text'] ?? ''));
        $chat = (string) $msg['chat']['id'];
        if (preg_match('/^\/start\s+([a-f0-9]{24})$/', $texto, $m)) {
            $st = $this->db->prepare('UPDATE usuarios SET telegram_chat_id = ?, telegram_codigo = NULL WHERE telegram_codigo = ? RETURNING nombre');
            $st->execute([$chat, $m[1]]);
            $nombre = $st->fetchColumn();
            $this->api('sendMessage', ['chat_id' => $chat, 'text' => $nombre
                ? "¡Listo, $nombre! Desde ahora recibirás aquí los avisos de TuRenta (pagos, firmas, consultas nuevas)."
                : 'El enlace ya se usó o caducó. Pide uno nuevo desde TuRenta → Mi cuenta.']);
        } elseif ($texto === '/start') {
            $this->api('sendMessage', ['chat_id' => $chat, 'text' => 'Para conectar tu cuenta, entra a TuRenta → Mi cuenta → Conectar Telegram.']);
        }
    }
}
