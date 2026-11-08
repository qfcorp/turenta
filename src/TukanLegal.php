<?php
declare(strict_types=1);

/**
 * Aceptar los términos y la política de privacidad — módulo común de los programas Tukán (7 nov 2026, pedido del dueño
 * de Tukán: «pide que acepten las políticas, enlaza los términos»). La copia original vive en el repo tukan-central
 * (herramientas/cancelar_plan/); cada programa trae una copia IGUAL (se copia con herramientas/cancelar_plan/copiar.sh).
 *
 *  - El dueño (cliente de Tukán) acepta los Términos y condiciones y la Política de privacidad de Tukán Software: mientras
 *    no lo haga, ve un aviso arriba en todas las pantallas con la casilla y el botón «Aceptar». Se guarda quién, cuándo,
 *    la versión y la IP (tabla tukan_aceptaciones). Si cambia VERSION, se vuelve a pedir.
 *  - Los clientes del negocio (reservas públicas, cuentas de clientes) aceptan la política de privacidad del programa
 *    con una casilla obligatoria; se guarda en tukan_consentimientos.
 *  - enlaces(): «Términos · Privacidad» para el pie de página y la pantalla de entrar.
 * Tablas: se crean solas y sirven en PostgreSQL, MariaDB y SQLite.
 */
final class TukanLegal
{
    /** Versión de los términos de tukansoftware.com que se acepta (cambiarla cuando cambien los términos). */
    public const VERSION = '2026-10-27';
    public const URL_TERMINOS = 'https://tukansoftware.com/terminos.html';
    public const URL_PRIVACIDAD = 'https://tukansoftware.com/privacidad.html';

    public static function asegurar(PDO $db): void
    {
        static $listo = [];
        $k = spl_object_id($db);
        if (isset($listo[$k])) return;
        $db->exec('CREATE TABLE IF NOT EXISTS tukan_aceptaciones (cliente_id VARCHAR(80) NOT NULL, version VARCHAR(20) NOT NULL, usuario VARCHAR(200),
                   aceptado VARCHAR(40) NOT NULL, ip VARCHAR(64), PRIMARY KEY (cliente_id, version))');
        $db->exec('CREATE TABLE IF NOT EXISTS tukan_consentimientos (cliente_id VARCHAR(80) NOT NULL, quien VARCHAR(200) NOT NULL, que VARCHAR(80) NOT NULL,
                   version VARCHAR(20) NOT NULL, ip VARCHAR(64), creado VARCHAR(40) NOT NULL)');
        $listo[$k] = true;
    }

    private static function ip(): string
    {
        return substr((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    /** ¿El negocio (o la licencia, "_licencia") ya aceptó la versión vigente de los términos? */
    public static function aceptado(PDO $db, string $clienteId): bool
    {
        self::asegurar($db);
        $st = $db->prepare('SELECT 1 FROM tukan_aceptaciones WHERE cliente_id = ? AND version = ?');
        $st->execute([$clienteId, self::VERSION]);
        return (bool) $st->fetchColumn();
    }

    public static function aceptar(PDO $db, string $clienteId, string $usuario): void
    {
        self::asegurar($db);
        if (self::aceptado($db, $clienteId)) return;
        $db->prepare('INSERT INTO tukan_aceptaciones (cliente_id, version, usuario, aceptado, ip) VALUES (?, ?, ?, ?, ?)')
           ->execute([$clienteId, self::VERSION, mb_substr($usuario, 0, 200), date('c'), self::ip()]);
    }

    /** Consentimiento de un cliente del negocio (reserva pública, cuenta de cliente…). $que = 'privacidad_reserva', etc. */
    public static function consentimiento(PDO $db, string $clienteId, string $quien, string $que = 'privacidad'): void
    {
        self::asegurar($db);
        $db->prepare('INSERT INTO tukan_consentimientos (cliente_id, quien, que, version, ip, creado) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$clienteId, mb_substr($quien, 0, 200), mb_substr($que, 0, 80), self::VERSION, self::ip(), date('c')]);
    }

    /**
     * ¿Es el formulario de aceptar? Si lo es, lo guarda (si marcó la casilla) y devuelve true; quien llama redirige.
     * Devuelve null si no es ese formulario, false si no marcó la casilla.
     */
    public static function procesar(PDO $db, string $clienteId, string $usuario): ?bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['accion'] ?? '') !== 'aceptar_terminos_tukan') return null;
        if (empty($_POST['acepto_terminos'])) return false;
        self::aceptar($db, $clienteId, $usuario);
        return true;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Aviso de arriba para el dueño que todavía no aceptó. $accion = página que recibe el formulario (la misma donde está:
     * cada programa llama a procesar() al empezar). $privacidad = la política de privacidad del programa.
     */
    public static function aviso(string $csrf, string $accion, string $privacidad, bool $usted = false): string
    {
        return '<div class="tukan-legal-aviso" role="region" aria-label="Términos y privacidad"><form method="post" action="' . self::e($accion) . '">' . $csrf
            . '<input type="hidden" name="accion" value="aceptar_terminos_tukan">'
            . '<p><strong>' . ($usted ? 'Antes de seguir, lea y acepte' : 'Antes de seguir, lee y acepta') . '</strong> los '
            . '<a href="' . self::URL_TERMINOS . '" target="_blank" rel="noopener">Términos y condiciones</a> y la '
            . '<a href="' . self::e($privacidad) . '" target="_blank" rel="noopener">Política de privacidad</a> de Tukán Software.</p>'
            . '<label><input type="checkbox" name="acepto_terminos" value="1" required> Leí y acepto los términos y la política de privacidad</label> '
            . '<button class="boton" type="submit">Aceptar</button></form></div>';
    }

    /** «Términos · Privacidad» (pie de página, pantalla de entrar). */
    public static function enlaces(string $privacidad): string
    {
        return '<span class="tukan-legal-enlaces"><a href="' . self::URL_TERMINOS . '" target="_blank" rel="noopener">Términos</a> · '
            . '<a href="' . self::e($privacidad) . '" target="_blank" rel="noopener">Privacidad</a></span>';
    }

    /** Casilla obligatoria para los clientes del negocio (reservas públicas). $marcada = volver a mostrarla marcada. */
    public static function casillaCliente(string $privacidad, bool $marcada = false, bool $usted = false): string
    {
        return '<label class="opcion tukan-legal-cliente" style="margin-bottom:14px"><input type="checkbox" name="acepta_privacidad" value="1" required' . ($marcada ? ' checked' : '') . '> '
            . '<span><strong>' . ($usted ? 'Acepto la ' : 'Acepto la ') . '<a href="' . self::e($privacidad) . '" target="_blank" rel="noopener">política de privacidad</a></strong>'
            . '<small>' . ($usted ? 'Sus datos se usan solo para su reserva y sus avisos. Puede pedir que los borren.' : 'Tus datos se usan solo para tu reserva y sus avisos. Puedes pedir que los borren.') . '</small></span></label>';
    }
}
