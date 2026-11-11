<?php
declare(strict_types=1);

/**
 * «Cancelar mi plan» — módulo común de los programas Tukán (7 nov 2026, pedido del dueño de Tukán: «el botón cancelar mi plan
 * debía estar ya»). La copia original vive en el repo tukan-central (herramientas/cancelar_plan/); cada programa trae una
 * copia IGUAL (no editarla a mano en un programa: se cambia aquí y se copia a todos con herramientas/cancelar_plan/copiar.sh).
 *
 * Qué hace:
 *   - El dueño toca «Cancelar mi plan» en su Mi plan (con confirmación y motivo opcional).
 *   - Nada se borra: el plan sigue hasta el último día pagado y después no se renueva.
 *   - Se guarda aquí mismo (tabla tukan_cancelaciones, se crea sola; sirve en PostgreSQL, MariaDB y SQLite) para mostrar
 *     «Cancelaste tu plan · sigue hasta el …» con el botón «Seguir con mi plan».
 *   - Avisa al Panel Tukán Central (plux_api.php, acción «cancelar_plan», token v2 «plux» firmado con TUKAN_CENTRAL_SECRET):
 *     el Panel cancela sus suscripciones con tarjeta (Plux), lo muestra en Inicio y avisa por Telegram.
 *   - Si el Panel no contesta, la cancelación igual queda guardada aquí (y se reintenta el aviso al volver a abrir Mi plan).
 *   - En la app de Google Play no se cancela aquí: se indica cancelar en Google Play → Pagos y suscripciones.
 *
 * Configuración: TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET y TUKAN_CENTRAL_URL (las mismas del conector con el Panel),
 * o un arreglo ['id','secreto','url'] en TukanCancelarPlan::$config. En pruebas, TUKAN_CANCELAR_FALSO=archivo guarda
 * los pedidos al Panel en ese archivo en vez de enviarlos.
 */
final class TukanCancelarPlan
{
    public const URL_PANEL = 'https://panel.tukanec.com';
    /** @var array{id?:string,secreto?:string,url?:string}|null */
    public static ?array $config = null;

    public static function asegurar(PDO $db): void
    {
        static $listo = [];
        $k = spl_object_id($db);
        if (isset($listo[$k])) return;
        $db->exec('CREATE TABLE IF NOT EXISTS tukan_cancelaciones (cliente_id VARCHAR(80) NOT NULL PRIMARY KEY, nombre VARCHAR(160) NOT NULL,
                   hasta VARCHAR(10), motivo TEXT, quien VARCHAR(200), pedida VARCHAR(40) NOT NULL, avisada INTEGER NOT NULL DEFAULT 0)');
        $listo[$k] = true;
    }

    /** La cancelación pedida de este cliente (o null). */
    public static function estado(PDO $db, string $clienteId): ?array
    {
        self::asegurar($db);
        $st = $db->prepare('SELECT * FROM tukan_cancelaciones WHERE cliente_id = ?');
        $st->execute([$clienteId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Pide la cancelación. $hasta = último día pagado (AAAA-MM-DD) o null. Devuelve
     * ['hasta' => ?string, 'avisado' => bool, 'canceladas' => int, 'programadas' => int].
     */
    public static function pedir(PDO $db, string $clienteId, string $nombre, ?string $hasta, string $motivo, string $quien, string $email = ''): array
    {
        self::asegurar($db);
        $hasta = $hasta !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $hasta) ? substr($hasta, 0, 10) : null;
        $motivo = mb_substr(trim($motivo), 0, 500);
        $nombre = mb_substr(trim($nombre), 0, 160) ?: $clienteId;
        $quien = mb_substr(trim($quien), 0, 200);
        $db->prepare('DELETE FROM tukan_cancelaciones WHERE cliente_id = ?')->execute([$clienteId]);
        $db->prepare('INSERT INTO tukan_cancelaciones (cliente_id, nombre, hasta, motivo, quien, pedida, avisada) VALUES (?, ?, ?, ?, ?, ?, 0)')
           ->execute([$clienteId, $nombre, $hasta, $motivo, $quien, date('c')]);
        $r = self::avisar($db, $clienteId, $email);
        return ['hasta' => $hasta] + $r;
    }

    /** «Seguir con mi plan»: quita la cancelación y avisa al Panel. */
    public static function seguir(PDO $db, string $clienteId, string $nombre, string $email = ''): void
    {
        self::asegurar($db);
        $db->prepare('DELETE FROM tukan_cancelaciones WHERE cliente_id = ?')->execute([$clienteId]);
        self::llamarPanel(['accion' => 'cancelar_plan', 'cliente_id' => $clienteId, 'nombre' => $nombre, 'deshacer' => true], $email);
    }

    /** Manda (o vuelve a mandar) el aviso al Panel. Lo marca como avisado si el Panel contestó bien. */
    public static function avisar(PDO $db, string $clienteId, string $email = ''): array
    {
        $c = self::estado($db, $clienteId);
        if (!$c) return ['avisado' => false, 'canceladas' => 0, 'programadas' => 0];
        [$http, $j] = self::llamarPanel(['accion' => 'cancelar_plan', 'cliente_id' => $clienteId, 'nombre' => $c['nombre'], 'hasta' => $c['hasta'],
                                         'motivo' => (string) $c['motivo'], 'quien' => (string) $c['quien']], $email);
        $ok = $http === 200 && !empty($j['ok']);
        if ($ok) $db->prepare('UPDATE tukan_cancelaciones SET avisada = 1 WHERE cliente_id = ?')->execute([$clienteId]);
        return ['avisado' => $ok, 'canceladas' => (int) ($j['canceladas'] ?? 0), 'programadas' => (int) ($j['programadas'] ?? 0)];
    }

    /** Si quedó una cancelación sin avisar (el Panel no contestaba), la avisa ahora. */
    public static function reintentar(PDO $db, string $clienteId, string $email = ''): void
    {
        $c = self::estado($db, $clienteId);
        if ($c && !(int) $c['avisada']) self::avisar($db, $clienteId, $email);
    }

    /** Pedido firmado al Panel. Devuelve [http, json|null]; http 0 = sin configurar o sin conexión. */
    public static function llamarPanel(array $datos, string $email = ''): array
    {
        $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $falso = (string) getenv('TUKAN_CANCELAR_FALSO');
        if ($falso !== '') {
            @file_put_contents($falso, $cuerpo . "\n", FILE_APPEND);
            return [200, ['ok' => true, 'canceladas' => 0, 'programadas' => 0]];
        }
        $c = self::$config ?? ['id' => (string) getenv('TUKAN_CENTRAL_ID'), 'secreto' => (string) getenv('TUKAN_CENTRAL_SECRET'), 'url' => (string) getenv('TUKAN_CENTRAL_URL')];
        $id = trim((string) ($c['id'] ?? ''));
        $secreto = trim((string) ($c['secreto'] ?? ''));
        $url = rtrim(trim((string) ($c['url'] ?? '')), '/') ?: self::URL_PANEL;
        if ($id === '' || strlen($secreto) < 32 || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) return [0, null];
        $ahora = time();
        $p = ['v' => 2, 'tipo' => 'plux', 'inst' => $id, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'sistema@tukanec.com',
              'nombre' => 'Mi plan', 'rol' => 'dueno', 'iat' => $ahora, 'exp' => $ahora + 60, 'nonce' => bin2hex(random_bytes(16)),
              'bh' => self::b64(hash('sha256', (string) $cuerpo, true))];
        $a = self::b64((string) json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $token = $a . '.' . self::b64(hash_hmac('sha256', $a, $secreto, true));
        $ch = curl_init($url . '/plux_api.php');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Tukan-Token: ' . $token]]);
        $r = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($r === false) return [0, null];
        $j = json_decode((string) $r, true);
        return [$http, is_array($j) ? $j : null];
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function fecha(?string $ymd): string
    {
        $t = $ymd ? strtotime($ymd) : false;
        return $t ? date('d/m/Y', $t) : '';
    }

    /**
     * La sección para Mi plan. $csrf = el campo oculto del programa; $accion = adónde se manda el formulario (la misma Mi plan);
     * $hasta = último día pagado; $enApp = app de Google Play; $usted = trato de «usted» (TuDron, TuVocalía…).
     * Los formularios mandan accion=cancelar_plan (con motivo y confirmo) o accion=seguir_plan.
     */
    public static function html(?array $estado, string $csrf, string $accion, ?string $hasta, bool $enApp = false, bool $usted = false): string
    {
        $tu = fn(string $t, string $u) => $usted ? $u : $t;
        $h = '<section class="bloque tukan-cancelar" id="cancelar-plan" aria-labelledby="tc-titulo"><h2 id="tc-titulo">Cancelar mi plan</h2>';
        if ($enApp) {
            return $h . '<p>' . $tu('Si pagas con Google Play, cancelas en', 'Si paga con Google Play, cancele en') . ' <strong>Google Play → Pagos y suscripciones → Suscripciones</strong>. '
                . $tu('Sigues usando todo hasta el fin del período que ya pagaste.', 'Sigue usando todo hasta el fin del período que ya pagó.') . '</p></section>';
        }
        if ($estado) {
            // (10 nov 2026, pedido del dueño de Tukán: «debe salir algún mensaje… aplasté dos veces porque no pasaba nada») confirmación clara, en verde
            $f = self::fecha($estado['hasta'] ?? null);
            $avisada = (int) ($estado['avisada'] ?? 0) === 1;
            return $h . '<div class="aviso aviso-ok tc-cancelado" role="status" style="border-left:4px solid #1F8A4C;background:#E3F4EA;color:#14213D;padding:12px 14px;border-radius:10px">'
                . '<p style="margin:0 0 6px"><strong>✅ ' . $tu('Tu plan quedó cancelado.', 'Su plan quedó cancelado.') . '</strong> ' . $tu('Pediste cancelar tu plan', 'Pidió cancelar su plan') . ' el '
                . self::e(self::fecha(substr((string) $estado['pedida'], 0, 10))) . '.</p>'
                . '<p style="margin:0 0 6px">' . ($f !== '' ? $tu('Sigues usando todo hasta el ', 'Sigue usando todo hasta el ') . '<strong>' . self::e($f) . '</strong> y después no se cobra más.' : 'No se renovará.') . '</p>'
                . '<p style="margin:0">' . ($avisada
                    ? $tu('Si pagabas con tarjeta, ya no se te volverá a cobrar.', 'Si pagaba con tarjeta, ya no se le volverá a cobrar.')
                    : $tu('Estamos avisando al sistema de pagos: si pagabas con tarjeta, el cobro automático se cancela en unos minutos. No hace falta que vuelvas a tocar nada.',
                          'Estamos avisando al sistema de pagos: si pagaba con tarjeta, el cobro automático se cancela en unos minutos. No hace falta que vuelva a tocar nada.'))
                . ' ' . $tu('Tus datos se guardan por si vuelves.', 'Sus datos se guardan por si vuelve.') . '</p></div>'
                . '<form method="post" action="' . self::e($accion) . '" class="tc-form" style="margin-top:12px" onsubmit="' . self::ocupado($tu('Un momento…', 'Un momento…')) . '">' . $csrf
                . '<input type="hidden" name="accion" value="seguir_plan">'
                . '<button class="boton" type="submit">Seguir con mi plan</button></form></section>';
        }
        $f = self::fecha($hasta);
        return $h . '<details><summary>' . $tu('Quiero cancelar mi plan', 'Quiero cancelar mi plan') . '</summary>'
            . '<p>' . $tu('Puedes cancelar cuando quieras, sin multas.', 'Puede cancelar cuando quiera, sin multas.') . ' '
            . ($f !== '' ? $tu('Sigues usando todo hasta el ', 'Sigue usando todo hasta el ') . '<strong>' . self::e($f) . '</strong>' . $tu(' (lo que ya pagaste)', ' (lo que ya pagó)') . ' y después no se cobra más. '
                         : $tu('Sigues usando todo hasta el fin de lo que ya pagaste y después no se cobra más. ', 'Sigue usando todo hasta el fin de lo que ya pagó y después no se cobra más. '))
            . $tu('Si pagas con tarjeta, no se te vuelve a cobrar. Tus datos no se borran: si cambias de idea, vuelves cuando quieras.',
                  'Si paga con tarjeta, no se le vuelve a cobrar. Sus datos no se borran: si cambia de idea, vuelve cuando quiera.') . '</p>'
            . '<form method="post" action="' . self::e($accion) . '" class="tc-form" onsubmit="' . self::ocupado($tu('Cancelando tu plan… espera unos segundos, no cierres esta página.', 'Cancelando su plan… espere unos segundos, no cierre esta página.')) . '">' . $csrf . '<input type="hidden" name="accion" value="cancelar_plan">'
            . '<label>' . $tu('¿Por qué te vas? (opcional)', '¿Por qué se va? (opcional)') . '<textarea name="motivo" rows="2" maxlength="500"></textarea></label>'
            . '<label class="tc-confirmo"><input type="checkbox" name="confirmo" value="1" required> Sí, quiero cancelar mi plan</label>'
            . '<button class="boton boton-peligro" type="submit">Cancelar mi plan</button>'
            . '<p class="tc-espera" role="status" aria-live="polite" style="margin:8px 0 0;font-weight:600"></p></form></details></section>';
    }

    /**
     * (10 nov 2026) Al tocar el botón: lo deja gris y sin poder tocarse otra vez, con el texto «Cancelando…», para que se vea que
     * algo está pasando (avisar al Panel y a Plux tarda unos segundos) y no se aprete dos veces. Va en el onsubmit del formulario.
     */
    private static function ocupado(string $texto): string
    {
        $t = json_encode($texto, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
        $js = "if(this.dataset.enviado){return false;}this.dataset.enviado='1';var b=this.querySelector('button[type=submit]');"
            . "if(b){b.disabled=true;b.style.opacity='.6';b.textContent=$t;}var m=this.querySelector('.tc-espera');if(m){m.textContent=$t;}return true;";
        return self::e($js);
    }
}
