<?php
if (usuario()) {
    redirigir('inicio');
}
$error = null;
$email = '';
if (es_post()) {
    verificar_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $clave = (string) ($_POST['clave'] ?? '');
    // Freno simple contra intentos repetidos: 5 fallos -> esperar 5 minutos
    $intentos = $_SESSION['intentos_login'] ?? ['n' => 0, 'hasta' => 0];
    if ($intentos['hasta'] > time()) {
        $error = 'Demasiados intentos. Espera unos minutos y vuelve a probar.';
    } else {
        // Un negocio en la papelera (Anexo A) ya no puede entrar: para el que intenta, es como si no existiera
        $st = db()->prepare('SELECT u.id, u.password_hash FROM usuarios u JOIN negocios n ON n.id = u.negocio_id
                              WHERE u.email = ? AND u.activo AND n.eliminado_en IS NULL');
        $st->execute([$email]);
        $fila = $st->fetch();
        if ($fila && password_verify($clave, $fila['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int) $fila['id'];
            unset($_SESSION['intentos_login']);
            db()->prepare('UPDATE usuarios SET ultimo_ingreso = now() WHERE id = ?')->execute([$fila['id']]);
            $temporal = false;
            try {
                $t = db()->prepare('SELECT clave_temporal FROM usuarios WHERE id = ?');
                $t->execute([$fila['id']]);
                $temporal = (bool) $t->fetchColumn();
            } catch (\PDOException) { /* base sin la migración 050 */ }
            if ($temporal) {
                aviso('Tu contraseña es temporal. Cámbiala por una tuya para seguir.');
                header('Location: ' . url('mi_cuenta') . '#clave');
                exit;
            }
            redirigir('inicio');
        }
        $intentos['n']++;
        if ($intentos['n'] >= 5) $intentos = ['n' => 0, 'hasta' => time() + 300];
        $_SESSION['intentos_login'] = $intentos;
        $error = 'El correo o la contraseña no coinciden.';
    }
}
vista('login', ['error' => $error, 'email' => $email], 'Entrar · TuRenta');
