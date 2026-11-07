<?php
declare(strict_types=1);

namespace TuRenta;

use DateTimeImmutable;
use PDO;

/**
 * Conector con el Panel Tukán Central (protocolo v1, igual en todos los programas Tukán; el v2 está en TukanCentralV2).
 *  - Configuración: TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET, TUKAN_CENTRAL_URL y TUKAN_SOLO_CENTRAL en config/.env
 *  - public/tukan_central.php usa esta clase: ?accion=sso (entrar como super usuario, con enlace directo "ir")
 *    y ?accion=estado (cifras en JSON)
 *  - Token: b64url(json) . "." . b64url(HMAC-SHA256(b64url(json), secreto)), dura máximo 60 s y sirve una sola vez
 *  - Los rechazos traen el "codigo" del protocolo v2 (§3.2) y el límite es por IP: 120 pedidos y 30 rechazados por minuto
 */
final class TukanCentral
{
    public const PRODUCTO = 'turenta';
    public const NOMBRE = 'TuRenta';
    public const PROTOCOLO = 1;
    public const URL_POR_DEFECTO = 'https://panel.tukanec.com';
    public const LIMITE_POR_MINUTO = 120;      // pedidos por minuto por IP (protocolo v2 §3.1)
    public const RECHAZOS_POR_MINUTO = 30;     // rechazados (401) por minuto por IP
    public const TOLERANCIA = 30;   // segundos de diferencia de reloj permitidos
    public const VIDA_MAXIMA = 60;  // exp - iat
    public const MAX_LISTA = 20;
    public const RUTAS_SSO = ['/?r=admin'];   // a dónde puede llevar el enlace directo "ir" del SSO

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------------
    // Configuración
    // ------------------------------------------------------------------

    /** ['id' => ..., 'secreto' => ...] o null si el conector está apagado (falta el ID o el secreto, o el secreto es corto). */
    public static function config(): ?array
    {
        $id = trim((string) getenv('TUKAN_CENTRAL_ID'));
        $secreto = trim((string) getenv('TUKAN_CENTRAL_SECRET'));
        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $id) || strlen($secreto) < 32) return null;
        return ['id' => $id, 'secreto' => $secreto];
    }

    /** Dirección del panel central (para el botón "← Panel Tukán" y los mensajes). */
    public static function urlCentral(): string
    {
        $url = rtrim(trim((string) getenv('TUKAN_CENTRAL_URL')), '/');
        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : self::URL_POR_DEFECTO;
    }

    /**
     * TUKAN_SOLO_CENTRAL=1: el ingreso local del super usuario queda apagado (solo se entra desde el panel central).
     * Solo vale si el conector está configurado; si no, nadie podría entrar al panel.
     */
    public static function soloCentral(): bool
    {
        return trim((string) getenv('TUKAN_SOLO_CENTRAL')) === '1' && self::config() !== null;
    }

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    /** Firma un contenido (lo usa el panel central; aquí sirve para las pruebas y la prueba de conectar_central.sh). */
    public static function firmar(array $p, string $secreto): string
    {
        $a = self::b64((string) json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $a . '.' . self::b64(hash_hmac('sha256', $a, $secreto, true));
    }

    // ------------------------------------------------------------------
    // Validación del token (en el orden del protocolo)
    // ------------------------------------------------------------------

    /**
     * Revisa el token para la acción pedida ('sso' o 'estado'). Devuelve su contenido.
     * Si algo falla lanza TukanCentralRechazo con el motivo (en español).
     */
    public function validar(string $token, string $accion, ?int $ahora = null): array
    {
        $conf = self::config();
        if (!$conf) throw new TukanCentralRechazo('El conector no está configurado.', 'sin_configurar', 404);
        $ahora ??= time();

        // 1. Dos partes separadas por "." y firma correcta
        $partes = explode('.', $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') throw new TukanCentralRechazo('El enlace no es válido.', 'firma');
        $esperada = self::b64(hash_hmac('sha256', $partes[0], $conf['secreto'], true));
        if (!hash_equals($esperada, $partes[1])) throw new TukanCentralRechazo('La firma del enlace no es correcta.', 'firma');
        $json = self::unb64($partes[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) throw new TukanCentralRechazo('El enlace no es válido.', 'firma');

        // 2. Versión, tipo e instancia
        if (($p['v'] ?? null) !== 1) throw new TukanCentralRechazo('Versión del protocolo no soportada.', 'version');
        if (($p['tipo'] ?? null) !== $accion) throw new TukanCentralRechazo('El enlace no es para esta acción.', 'tipo');
        if (($p['inst'] ?? null) !== $conf['id']) throw new TukanCentralRechazo('El enlace es de otra instalación.', 'inst');

        // 3. Tiempo (30 s de tolerancia de reloj)
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp)) throw new TukanCentralRechazo('El enlace no tiene fecha válida.', 'invalido');
        if ($ahora > $exp + self::TOLERANCIA) throw new TukanCentralRechazo('El enlace ya venció. Vuelve a intentar desde el Panel Tukán.', 'vencido');
        if ($iat > $ahora + self::TOLERANCIA) throw new TukanCentralRechazo('El enlace tiene una fecha futura. Revisa la hora del servidor.', 'futuro');
        if ($exp < $iat || $exp - $iat > self::VIDA_MAXIMA) throw new TukanCentralRechazo('El enlace dura más de lo permitido.', 'invalido');

        // 4. Nonce de un solo uso (se guarda hasta que el token ya no podría servir)
        if (!$this->usarNonce($p['nonce'] ?? null, $exp, $ahora)) {
            throw new TukanCentralRechazo(is_string($p['nonce'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $p['nonce'])
                ? 'Este enlace ya se usó. Vuelve a intentar desde el Panel Tukán.' : 'El enlace no es válido (nonce).', 'nonce');
        }

        // 5. Correo válido
        $email = $p['email'] ?? null;
        if (!is_string($email) || mb_strlen($email) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new TukanCentralRechazo('El correo del enlace no es válido.', 'invalido');
        }
        $p['email'] = strtolower(trim($email));
        return $p;
    }

    /**
     * Nonce de un solo uso: 32 hex en minúsculas que no se haya usado. Se guarda hasta exp + 30 s (los vencidos se borran).
     * Devuelve false si está mal formado o ya se usó. La usan v1 y v2 (misma tabla).
     */
    public function usarNonce(mixed $nonce, int $exp, ?int $ahora = null): bool
    {
        $ahora ??= time();
        if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/', $nonce)) return false;
        $this->db->prepare('DELETE FROM tukan_central_nonces WHERE vence < to_timestamp(?)')->execute([$ahora]);
        $st = $this->db->prepare('INSERT INTO tukan_central_nonces (nonce, vence) VALUES (?, to_timestamp(?)) ON CONFLICT (nonce) DO NOTHING');
        $st->execute([$nonce, $exp + self::TOLERANCIA]);
        return $st->rowCount() === 1;
    }

    /**
     * 6. Límite por IP (cuenta este pedido): máximo 120 pedidos por minuto y 30 rechazados (401) por minuto.
     * Devuelve false si ya se pasó.
     */
    public function dentroDelLimite(string $ip, ?int $ahora = null): bool
    {
        $ahora ??= time();
        $minuto = $ahora - $ahora % 60;
        $this->db->prepare("DELETE FROM tukan_central_pedidos WHERE minuto < to_timestamp(?) - interval '5 minutes'")->execute([$minuto]);
        $st = $this->db->prepare('INSERT INTO tukan_central_pedidos (ip, minuto, n) VALUES (?, to_timestamp(?), 1)
                                  ON CONFLICT (ip, minuto) DO UPDATE SET n = tukan_central_pedidos.n + 1 RETURNING n, rechazados');
        $st->execute([mb_substr($ip, 0, 45), $minuto]);
        $f = $st->fetch();
        return (int) $f['n'] <= self::LIMITE_POR_MINUTO && (int) $f['rechazados'] < self::RECHAZOS_POR_MINUTO;
    }

    /** Anota un pedido rechazado (401) de esa IP en este minuto. */
    public function anotarRechazo(string $ip, ?int $ahora = null): void
    {
        $ahora ??= time();
        $minuto = $ahora - $ahora % 60;
        $this->db->prepare('INSERT INTO tukan_central_pedidos (ip, minuto, n, rechazados) VALUES (?, to_timestamp(?), 1, 1)
                            ON CONFLICT (ip, minuto) DO UPDATE SET rechazados = tukan_central_pedidos.rechazados + 1')
                 ->execute([mb_substr($ip, 0, 45), $minuto]);
    }

    /**
     * Enlace directo del SSO ("ir", protocolo v2 §5.3): ruta relativa segura que empieza con una de RUTAS_SSO.
     */
    public static function irValido(mixed $ir): bool
    {
        if (!is_string($ir) || $ir === '' || strlen($ir) > 300 || $ir[0] !== '/' || str_starts_with($ir, '//')) return false;
        if (str_contains($ir, '\\') || preg_match('/[\x00-\x1F\x7F]/', $ir)) return false;
        if (!preg_match('/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/?#-]*$/', $ir)) return false;
        foreach (self::RUTAS_SSO as $r) if (str_starts_with($ir, $r)) return true;
        return false;
    }

    // ------------------------------------------------------------------
    // SSO: busca o crea el super usuario
    // ------------------------------------------------------------------

    /**
     * Super usuario con el correo del token (lo crea la primera vez, con una contraseña aleatoria que nadie conoce).
     * Deja constancia en el registro del panel. Devuelve su id. La sesión la inicia tukan_central.php.
     * $ir: enlace directo pedido (null = no vino; false = vino pero no es válido).
     */
    public function superUsuario(array $p, string $ip = '', string|false|null $ir = null): int
    {
        [$id] = $this->buscarSuper($p);
        $this->db->prepare('UPDATE superadmins SET ultimo_ingreso = now() WHERE id = ?')->execute([$id]);
        $texto = 'Entró desde el Panel Tukán Central' . (is_string($ir) ? ' → ' . $ir : ($ir === false ? ' (ir no válido: abrió el inicio)' : ''));
        $this->db->prepare('INSERT INTO registro_admin (admin_id, accion) VALUES (?, ?)')
                 ->execute([$id, mb_substr($texto . ($ip !== '' ? ' · IP ' . $ip : ''), 0, 300)]);
        return $id;
    }

    /**
     * Busca (o crea, marcado creado_por_central) el super usuario del correo del token. Devuelve [id, nuevo].
     * Lo usan el SSO y la auditoría de "ejecutar" (protocolo v2 §7.3).
     */
    public function buscarSuper(array $p): array
    {
        $email = strtolower(trim((string) $p['email']));
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($p['nombre'] ?? '')) ?? '');
        if (mb_strlen($nombre) < 2) $nombre = explode('@', $email)[0];
        $nombre = mb_substr($nombre, 0, 100);

        $buscar = $this->db->prepare('SELECT id, activo FROM superadmins WHERE lower(email) = ? ORDER BY id LIMIT 1');
        $buscar->execute([$email]);
        $a = $buscar->fetch();
        $nuevo = false;
        if (!$a) {
            $st = $this->db->prepare('INSERT INTO superadmins (nombre, email, password_hash, creado_por_central) VALUES (?, ?, ?, true)
                                      ON CONFLICT (email) DO NOTHING RETURNING id');
            $st->execute([$nombre, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
            $id = $st->fetchColumn();
            $nuevo = $id !== false;
            if (!$nuevo) {   // otro pedido lo creó al mismo tiempo
                $buscar->execute([$email]);
                $a = $buscar->fetch();
            } else {
                $a = ['id' => (int) $id, 'activo' => true];
            }
        }
        if (!$a['activo']) throw new TukanCentralRechazo('Tu super usuario está desactivado en ' . self::NOMBRE . '.', 'no_permitido', 403);
        $id = (int) $a['id'];
        if ($nuevo) {
            $this->db->prepare('INSERT INTO registro_admin (admin_id, accion) VALUES (?, ?)')->execute([$id, 'Super usuario creado por el Panel Tukán Central']);
        }
        return [$id, $nuevo];
    }

    // ------------------------------------------------------------------
    // Estado: cifras del programa (solo lectura, sin datos personales salvo nombres de negocios)
    // ------------------------------------------------------------------

    public function estado(?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $panel = new PanelTukan($this->db);
        $todos = $panel->negocios('', '', $hoy);   // mismo estado de cuenta que el panel (Planes::estadoCuenta)
        $cuenta = array_fill_keys(array_keys(PanelTukan::ESTADOS), 0);
        foreach ($todos as $n) $cuenta[$n['estado_cuenta']]++;
        // Cifras con la regla única del protocolo v2 (§4.2), las mismas de "clientes.contadores"
        $v2 = new TukanCentralV2($this->db, $hoy);
        $contadores = $v2->contadores($todos);
        $abiertas = $v2->solicitudesAbiertas();

        $hace30 = $hoy->modify('-30 days')->format('Y-m-d');
        $en7 = $hoy->modify('+7 days')->format('Y-m-d');
        $pruebas = array_filter($todos, fn($n) => $n['estado_cuenta'] === 'prueba' && $n['prueba_hasta'] && $n['prueba_hasta'] <= $en7);
        usort($pruebas, fn($a, $b) => [$a['prueba_hasta'], $a['nombre']] <=> [$b['prueba_hasta'], $b['nombre']]);
        // "Sin pagar" del panel: prueba terminada o plan vencido (no pueden entrar hasta pagar). "desde" = primer día sin pagar.
        $vencidos = [];
        foreach ($todos as $n) {
            if (!in_array($n['estado_cuenta'], ['prueba_vencida', 'vencido'], true)) continue;
            $fin = $n['estado_cuenta'] === 'vencido' ? $n['activo_hasta'] : $n['prueba_hasta'];
            $vencidos[] = ['nombre' => (string) $n['nombre'], 'desde' => $fin ? (new DateTimeImmutable($fin))->modify('+1 day')->format('Y-m-d') : null];
        }
        usort($vencidos, fn($a, $b) => [$a['desde'], $a['nombre']] <=> [$b['desde'], $b['nombre']]);

        $mes = $hoy->format('Y-m');
        $inicioMes = $hoy->modify('first day of this month')->format('Y-m-d');
        $inicioSiguiente = $hoy->modify('first day of next month')->format('Y-m-d');
        $contar = function (string $sql, array $params = []): int {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            return (int) $st->fetchColumn();
        };

        return [
            'ok' => true,
            'protocolo' => self::PROTOCOLO,
            'producto' => self::PRODUCTO,
            'nombre' => self::NOMBRE,
            'version' => self::version(dirname(__DIR__)),
            'generado' => (new DateTimeImmutable('now'))->format('c'),
            'negocios' => [
                'total' => $contadores['total'],
                'prueba' => $contadores['prueba'],
                'activos' => $contadores['activo'],
                'por_vencer' => $contadores['por_vencer'],         // pagado y vence en 0 a 7 días
                'vencidos' => $contadores['vencido'],              // ya venció (incluidos los días de gracia y las pruebas terminadas)
                'suspendidos' => $contadores['suspendido'],        // suspendidos y cancelados
            ],
            'nuevos_30d' => count(array_filter($todos, fn($n) => substr((string) $n['creado_en'], 0, 10) >= $hace30)),
            'ingresos_mes' => $panel->cobradoMes($mes),
            'ingresos_mes_anterior' => $panel->cobradoMes($hoy->modify('first day of last month')->format('Y-m')),
            'pruebas_vencen_7d' => array_slice(array_map(fn($n) => ['nombre' => (string) $n['nombre'], 'vence' => (string) $n['prueba_hasta']], $pruebas), 0, self::MAX_LISTA),
            'pagos_vencidos' => array_slice($vencidos, 0, self::MAX_LISTA),
            'extra' => [
                // Sin los negocios que están en la papelera (Anexo A)
                'Eventos este mes' => $contar("SELECT count(*) FROM eventos x JOIN negocios n ON n.id = x.negocio_id AND n.eliminado_en IS NULL
                                                WHERE x.fecha >= ? AND x.fecha < ? AND x.estado IN ('separado','confirmado','realizado')",
                                              [$inicioMes, $inicioSiguiente]),
                'Cotizaciones del mes' => $contar('SELECT count(*) FROM cotizaciones x JOIN negocios n ON n.id = x.negocio_id AND n.eliminado_en IS NULL
                                                    WHERE x.creado_en >= ? AND x.creado_en < ?', [$inicioMes, $inicioSiguiente]),
                'Usuarios activos' => $contar('SELECT count(*) FROM usuarios x JOIN negocios n ON n.id = x.negocio_id AND n.eliminado_en IS NULL WHERE x.activo'),
                'Eventos registrados (30 días)' => array_sum(array_map(fn($n) => (int) $n['eventos_30'], $todos)),
                'Ingreso mensual recurrente' => $panel->ingresoMensual($hoy),
                'Pruebas terminadas sin pagar' => $cuenta['prueba_vencida'],
                'Cancelados' => $cuenta['cancelado'],
            ],
            // Agregados del protocolo v2 (§2.4; el Central v1 los ignora)
            'protocolo_max' => TukanCentralV2::PROTOCOLO,
            'solicitudes_abiertas' => count($abiertas),
            'solicitudes_huella' => TukanCentralV2::huella($abiertas),
        ];
    }

    /** Commit corto de git leído de .git/HEAD (sin ejecutar comandos), o un texto si no se puede. */
    public static function version(string $raiz): string
    {
        $git = $raiz . '/.git';
        if (is_file($git) && is_readable($git)) {   // .git como archivo ("gitdir: ...", worktrees)
            if (preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($git), $m)) {
                $git = str_starts_with(trim($m[1]), '/') ? trim($m[1]) : $raiz . '/' . trim($m[1]);
            }
        }
        if (!is_file("$git/HEAD") || !is_readable("$git/HEAD")) return 'sin-version';
        $head = trim((string) file_get_contents("$git/HEAD"));
        if (preg_match('/^[0-9a-f]{40,64}$/', $head)) return substr($head, 0, 7);
        if (!preg_match('#^ref:\s*(refs/[A-Za-z0-9._/-]+)$#', $head, $m) || str_contains($m[1], '..')) return 'sin-version';
        $ref = $m[1];
        $dirs = [$git];
        if (is_file("$git/commondir") && is_readable("$git/commondir")) {
            $c = trim((string) file_get_contents("$git/commondir"));
            $dirs[] = str_starts_with($c, '/') ? $c : "$git/$c";
        }
        foreach ($dirs as $d) {
            if (is_file("$d/$ref") && is_readable("$d/$ref")) {
                $h = trim((string) file_get_contents("$d/$ref"));
                if (preg_match('/^[0-9a-f]{40,64}$/', $h)) return substr($h, 0, 7);
            }
            if (is_file("$d/packed-refs") && is_readable("$d/packed-refs")) {
                foreach (file("$d/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
                    if (preg_match('/^([0-9a-f]{40,64}) (.+)$/', $linea, $p) && $p[2] === $ref) return substr($p[1], 0, 7);
                }
            }
        }
        return 'sin-version';
    }
}
