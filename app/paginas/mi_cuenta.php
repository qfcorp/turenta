<?php
use TuRenta\{Telegram, Usuarios};

/** Mi cuenta: nombre, celular, contraseña y Telegram. Cualquier usuario (también coordinador y consulta). */
$usuarios = new Usuarios(db());
$telegram = new Telegram(db());
$uid = (int) $u['id'];
if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? '');
    try {
        switch ($accion) {
            case 'perfil':
                $usuarios->guardarPerfil($uid, campo('nombre'), campo('telefono'));
                aviso('Tus datos quedaron guardados.');
                break;
            case 'clave':
                // La cuenta del revisor de Google Play (deploy/demo_google.php) no cambia su contraseña: es la que está en Play Console
                if (\TuRenta\DemoGoogle::esRevisor($u)) throw new RuntimeException('Es una cuenta de demostración: aquí no se cambia la contraseña.');
                $usuarios->cambiarClave($uid, (string) ($_POST['actual'] ?? ''), (string) ($_POST['nueva'] ?? ''), (string) ($_POST['repetir'] ?? ''));
                session_regenerate_id(true);
                aviso('Listo: tu contraseña cambió. Úsala la próxima vez que entres.');
                break;
            case 'tg_conectar':
                exigir(modulo_activo($u, 'telegram'), 'Los avisos por Telegram no están en tu plan. Agrégalos en Mi plan.');
                $enlace = $telegram->enlaceConectar($uid);
                if (!$enlace) throw new RuntimeException('Los avisos por Telegram todavía no están activos en este servidor. Escríbenos y los activamos.');
                $_SESSION['tg_enlace'] = $enlace;
                break;
            case 'tg_desconectar':
                $telegram->desconectar($uid);
                aviso('Ya no recibirás avisos por Telegram.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('mi_cuenta');
}
$st = db()->prepare('SELECT nombre, email, telefono, rol, telegram_chat_id IS NOT NULL AS telegram, clave_temporal, ultimo_ingreso FROM usuarios WHERE id = ?');
$st->execute([$uid]);
$yo = $st->fetch();
$tgEnlace = $_SESSION['tg_enlace'] ?? null;
unset($_SESSION['tg_enlace']);
$tgDisponible = Telegram::configurado();
vista('mi_cuenta', compact('yo', 'tgEnlace', 'tgDisponible'), 'Mi cuenta · TuRenta');
