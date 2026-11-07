<?php
/** Sale de la vista del negocio y vuelve a su ficha en el panel. */
$id = (int) ($_SESSION['admin_viendo'] ?? 0);
if ($id) registrar_admin('Salió de la vista del negocio', $id);
unset($_SESSION['usuario_id'], $_SESSION['admin_viendo'], $_SESSION['admin_viendo_usuario']);
session_regenerate_id(true);
redirigir($id ? 'admin_negocio' : 'admin', $id ? ['id' => $id] : []);
