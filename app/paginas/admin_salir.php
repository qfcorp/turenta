<?php
unset($_SESSION['admin_id'], $_SESSION['admin_viendo'], $_SESSION['desde_central']);
if (!empty($_SESSION['admin_viendo_usuario'])) unset($_SESSION['usuario_id'], $_SESSION['admin_viendo_usuario']);
session_regenerate_id(true);
redirigir('admin_entrar');
