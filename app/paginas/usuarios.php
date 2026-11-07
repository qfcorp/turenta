<?php
use TuRenta\{Planes, Usuarios};

/** Usuarios del negocio (el dueño y los administradores): administradores (máx. 4), vendedores, coordinadores y cuentas de solo consulta. */
exigir(es_admin_total($u), 'Solo el dueño o un administrador puede crear y cambiar usuarios.');
$nid = (int) $u['negocio_id'];
$usuarios = new Usuarios(db());
if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_GET['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        switch ($accion) {
            case 'crear':
                $r = $usuarios->crear($nid, campo('nombre'), campo('email'), campo('telefono'), campo('rol'));
                $_SESSION['clave_usuario'] = $r + ['nuevo' => true, 'telefono' => campo('telefono')];
                aviso($r['nombre'] . ' ya puede entrar. Envíale su contraseña temporal (se muestra una sola vez).');
                break;
            case 'rol':
                $usuarios->cambiarRol($nid, $id, campo('rol'), (int) $u['id']);
                aviso('Listo: cambió lo que puede hacer en el sistema.');
                break;
            case 'activar':
                $usuarios->activar($nid, $id, campo('activo') === '1', (int) $u['id']);
                aviso(campo('activo') === '1' ? 'Usuario activado: ya puede entrar.' : 'Usuario desactivado: ya no puede entrar. Sus datos y lo que hizo se guardan.');
                break;
            case 'clave':
                $r = $usuarios->nuevaClave($nid, $id, (int) $u['id']);
                $obj = $usuarios->obtener($nid, $id);
                $_SESSION['clave_usuario'] = $r + ['nuevo' => false, 'telefono' => $obj['telefono'] ?? ''];
                aviso('Contraseña nueva creada. La anterior ya no sirve.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        $_SESSION['form_usuario'] = $accion === 'crear' ? ['nombre' => campo('nombre'), 'email' => campo('email'), 'telefono' => campo('telefono'), 'rol' => campo('rol')] : null;
    }
    redirigir('usuarios');
}
$lista = $usuarios->lista($nid);
$claveNueva = $_SESSION['clave_usuario'] ?? null;
$form = $_SESSION['form_usuario'] ?? null;
unset($_SESSION['clave_usuario'], $_SESSION['form_usuario']);
$puedeAgregar = planes()->puedeAgregarUsuario($nid);   // usuarios del plan (los administradores no cuentan)
$admins = $usuarios->adminsActivos($nid);
$puedeAdmin = $usuarios->puedeAgregarAdmin($nid);
$st = db()->prepare('SELECT p.nombre, p.max_usuarios FROM negocios n JOIN planes p ON p.codigo = n.plan_codigo WHERE n.id = ?');
$st->execute([$nid]);
$plan = $st->fetch();
$activos = planes()->usuariosDelPlan($nid);
vista('usuarios', compact('lista', 'claveNueva', 'form', 'puedeAgregar', 'plan', 'activos', 'admins', 'puedeAdmin'), 'Usuarios · TuRenta');
