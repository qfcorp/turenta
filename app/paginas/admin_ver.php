<?php
/** El super administrador entra al negocio como su dueño, para dar soporte. Queda registrado. */
$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare("SELECT u.id, n.nombre FROM usuarios u JOIN negocios n ON n.id = u.negocio_id
                      WHERE u.negocio_id = ? AND u.rol = 'dueno' AND u.activo ORDER BY u.id LIMIT 1");
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { aviso('Este negocio no tiene un dueño activo.', 'error'); redirigir('admin_negocio', ['id' => $id]); }
if (!es_post()) {   // se entra solo con el botón (POST), no con un enlace
    redirigir('admin_negocio', ['id' => $id]);
}
verificar_csrf();
registrar_admin('Entró al negocio como el dueño (soporte)', $id);
session_regenerate_id(true);
$_SESSION['usuario_id'] = (int) $d['id'];
$_SESSION['admin_viendo'] = $id;
$_SESSION['admin_viendo_usuario'] = 1;
redirigir('inicio');
