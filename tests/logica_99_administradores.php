<?php
/**
 * Administradores (migración 099): el dueño crea hasta 4 administradores con su mismo nivel,
 * que NO cuentan en el límite de usuarios del plan. Nadie toca al dueño ni se cambia a sí mismo.
 */
use TuRenta\{Metas, Notificaciones, Planes, Usuarios, Ventas};

$db = base_limpia();
planes_de_antes($db);   // estas pruebas usan los planes de antes (Esencial $19, Salón $39, Quinta $69); los de $20 y $45: logica_112
$usu = new Usuarios($db);
$planes = new Planes($db);

titulo('1. La base acepta el rol admin (migración repetible)');
$db->exec(file_get_contents(RAIZ . '/db/migraciones/099_administradores.sql'));
$db->exec(file_get_contents(RAIZ . '/db/migraciones/099_administradores.sql'));
check('La migración se puede correr otra vez', 1, (int) $db->query("SELECT count(*) FROM pg_constraint WHERE conname = 'usuarios_rol_check'")->fetchColumn());
$def = (string) $db->query("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'usuarios_rol_check'")->fetchColumn();
check('El CHECK incluye admin', true, str_contains($def, "'admin'"));
check('El CHECK sigue sin aceptar roles inventados', false, str_contains($def, "'jefe'"));

titulo('2. Crear administradores');
[$nid, $dueno] = negocio_prueba($db, 'salon', 'Salón Administradores');   // Salón: máximo 3 usuarios
$duenoId = (int) $dueno['id'];
check('Administrador está en los roles asignables', true, in_array('admin', Usuarios::ROLES_ASIGNABLES, true));
check('El dueño no es asignable', false, in_array('dueno', Usuarios::ROLES_ASIGNABLES, true));
check('Texto de la opción', 'Administrador (mismos permisos que el dueño)', Usuarios::OPCION_ADMIN);
$a1 = $usu->crear($nid, 'Ana Administradora', 'ana.admin@prueba.ec', '', 'admin');
check('Administrador creado', 'admin', $db->query("SELECT rol FROM usuarios WHERE id = {$a1['id']}")->fetchColumn());
check('Con clave temporal', true, password_verify($a1['clave'], (string) $db->query("SELECT password_hash FROM usuarios WHERE id = {$a1['id']}")->fetchColumn()));
check('Lleva 1 administrador', 1, $usu->adminsActivos($nid));

titulo('3. Los administradores NO cuentan en el plan');
check('Al inicio el plan cuenta solo al dueño', 1, $planes->usuariosDelPlan($nid));
$v1 = $usu->crear($nid, 'Vendedor Uno', 'v1.admin@prueba.ec', '', 'vendedor');
$v2 = $usu->crear($nid, 'Vendedor Dos', 'v2.admin@prueba.ec', '', 'coordinador');
check('Dueño + 2 = 3 usuarios del plan (el administrador no cuenta)', 3, $planes->usuariosDelPlan($nid));
check('Plan Salón lleno', false, $planes->puedeAgregarUsuario($nid));
check_error('Con el plan lleno no entra otro vendedor', 'permite 3 usuarios', fn() => $usu->crear($nid, 'Tres', 'v3.admin@prueba.ec', '', 'vendedor'));
$a2 = $usu->crear($nid, 'Beto Admin', 'beto.admin@prueba.ec', '', 'admin');
$a3 = $usu->crear($nid, 'Caro Admin', 'caro.admin@prueba.ec', '', 'admin');
$a4 = $usu->crear($nid, 'Dani Admin', 'dani.admin@prueba.ec', '', 'admin');
check('Con el plan lleno igual se crean administradores', 4, $usu->adminsActivos($nid));
check('Activos en total: dueño + 2 + 4 administradores', 7, (int) $db->query("SELECT count(*) FROM usuarios WHERE negocio_id = $nid AND activo")->fetchColumn());
check('El plan sigue contando 3', 3, $planes->usuariosDelPlan($nid));

titulo('4. Límite de 4 administradores (sin contar al dueño)');
check('No se puede agregar otro', false, $usu->puedeAgregarAdmin($nid));
check_error('El quinto administrador no se crea', 'Puede tener hasta 4 administradores además del dueño', fn() => $usu->crear($nid, 'Quinto', 'quinto.admin@prueba.ec', '', 'admin'));
check('No se guardó el quinto', 0, (int) $db->query("SELECT count(*) FROM usuarios WHERE email = 'quinto.admin@prueba.ec'")->fetchColumn());
$usu->activar($nid, $a4['id'], false, $duenoId);
check('Desactivado deja de contar', 3, $usu->adminsActivos($nid));
$a5 = $usu->crear($nid, 'Eva Admin', 'eva.admin@prueba.ec', '', 'admin');
check('Al desactivar a uno, entra otro', 4, $usu->adminsActivos($nid));
check_error('Activar un quinto administrador', 'Puede tener hasta 4 administradores además del dueño', fn() => $usu->activar($nid, $a4['id'], true, $duenoId));
check('Sigue desactivado', false, (bool) $db->query("SELECT activo FROM usuarios WHERE id = {$a4['id']}")->fetchColumn());
check_error('Pasar a un vendedor a administrador (ya hay 4)', 'Puede tener hasta 4 administradores además del dueño', fn() => $usu->cambiarRol($nid, $v1['id'], 'admin', $duenoId));
check('El vendedor sigue de vendedor', 'vendedor', $db->query("SELECT rol FROM usuarios WHERE id = {$v1['id']}")->fetchColumn());
check_error('Un administrador que pasa a vendedor empieza a contar en el plan (lleno)', 'permite 3 usuarios', fn() => $usu->cambiarRol($nid, $a5['id'], 'vendedor', $duenoId));
check('Sigue de administrador', 'admin', $db->query("SELECT rol FROM usuarios WHERE id = {$a5['id']}")->fetchColumn());
$usu->activar($nid, $a5['id'], false, $duenoId);
$usu->cambiarRol($nid, $v1['id'], 'admin', $duenoId);
check('Con cupo, un vendedor pasa a administrador', 'admin', $db->query("SELECT rol FROM usuarios WHERE id = {$v1['id']}")->fetchColumn());
check('Y libera su lugar en el plan', 2, $planes->usuariosDelPlan($nid));
check_error('Un administrador desactivado no vuelve si ya hay 4', 'Puede tener hasta 4 administradores', fn() => $usu->activar($nid, $a5['id'], true, $duenoId));
$usu->cambiarRol($nid, $a5['id'], 'consulta', $duenoId);
check('Un administrador desactivado puede pasar a otro rol', 'consulta', $db->query("SELECT rol FROM usuarios WHERE id = {$a5['id']}")->fetchColumn());

titulo('5. Los administradores manejan a los demás, pero no al dueño ni a sí mismos');
$usu->cambiarRol($nid, $a2['id'], 'consulta', $a1['id']);
check('Un administrador cambia el rol de otro administrador', 'consulta', $db->query("SELECT rol FROM usuarios WHERE id = {$a2['id']}")->fetchColumn());
$usu->cambiarRol($nid, $a2['id'], 'admin', $a1['id']);
$usu->activar($nid, $a3['id'], false, $a1['id']);
check('Un administrador desactiva a otro administrador', false, (bool) $db->query("SELECT activo FROM usuarios WHERE id = {$a3['id']}")->fetchColumn());
$usu->activar($nid, $a3['id'], true, $a1['id']);
check('Y lo vuelve a activar', true, (bool) $db->query("SELECT activo FROM usuarios WHERE id = {$a3['id']}")->fetchColumn());
$usu->activar($nid, $v2['id'], false, $a1['id']);
check('Un administrador desactiva a un usuario', false, (bool) $db->query("SELECT activo FROM usuarios WHERE id = {$v2['id']}")->fetchColumn());
$c = $usu->crear($nid, 'Creado por admin', 'creado.admin@prueba.ec', '', 'vendedor');
check('Un administrador crea usuarios (la lógica no depende de quién)', true, $c['id'] > 0);
$nc = $usu->nuevaClave($nid, $a2['id'], $a1['id']);
check('Un administrador da clave nueva a otro administrador', true, password_verify($nc['clave'], (string) $db->query("SELECT password_hash FROM usuarios WHERE id = {$a2['id']}")->fetchColumn()));
check_error('Un administrador no desactiva al dueño', 'dueño no se puede', fn() => $usu->activar($nid, $duenoId, false, $a1['id']));
check_error('Un administrador no cambia el rol del dueño', 'dueño no se puede', fn() => $usu->cambiarRol($nid, $duenoId, 'consulta', $a1['id']));
check_error('Un administrador no le cambia la clave al dueño', 'dueño no se puede', fn() => $usu->nuevaClave($nid, $duenoId, $a1['id']));
check('El dueño sigue intacto', 'dueno|1', $db->query("SELECT rol || '|' || activo::int FROM usuarios WHERE id = $duenoId")->fetchColumn());
check_error('Un administrador no se cambia su propio rol', 'a ti mismo', fn() => $usu->cambiarRol($nid, $a1['id'], 'vendedor', $a1['id']));
check_error('Un administrador no se desactiva a sí mismo', 'a ti mismo', fn() => $usu->activar($nid, $a1['id'], false, $a1['id']));
check_error('El dueño tampoco se cambia a sí mismo', 'a ti mismo', fn() => $usu->cambiarRol($nid, $duenoId, 'admin', $duenoId));
check('El administrador sigue igual', 'admin|1', $db->query("SELECT rol || '|' || activo::int FROM usuarios WHERE id = {$a1['id']}")->fetchColumn());

titulo('6. Rol inválido: error y no se guarda nada');
foreach (['dueno', 'jefe', '', 'ADMIN', 'admin '] as $malo) {
    check_error("Crear con rol [$malo]", 'no existe', fn() => $usu->crear($nid, 'Rol Malo', 'malo' . md5($malo) . '@prueba.ec', '', $malo));
}
check('No se guardó ningún usuario con rol inválido', 0, (int) $db->query("SELECT count(*) FROM usuarios WHERE nombre = 'Rol Malo'")->fetchColumn());
check_error('Cambiar a rol inválido', 'no existe', fn() => $usu->cambiarRol($nid, $c['id'], 'dueno', $a1['id']));
check('El rol no cambió (no hay rol por defecto)', 'vendedor', $db->query("SELECT rol FROM usuarios WHERE id = {$c['id']}")->fetchColumn());
check_error('La base tampoco acepta un rol inventado', 'usuarios_rol_check', fn() => $db->exec("UPDATE usuarios SET rol = 'jefe' WHERE id = {$c['id']}"));

titulo('7. Mismo nivel que el dueño en avisos, ventas y metas');
$ids = (new Notificaciones($db))->destinos($nid, ['dueno']);
check('Los avisos «para el dueño» llegan también a los administradores', true, in_array($a1['id'], $ids, true) && in_array($duenoId, $ids, true));
check('…pero no a un vendedor', false, in_array($c['id'], $ids, true));
$vendedores = array_map('intval', array_column((new Ventas($db))->usuariosVenta($nid), 'id'));
check('Un administrador puede llevar ventas', true, in_array($a1['id'], $vendedores, true));
check('El dueño va primero en la lista', $duenoId, $vendedores[0]);
$conMeta = array_map('intval', array_column((new Metas($db))->vendedores($nid), 'id'));
check('Un administrador puede tener meta de ventas', true, in_array($a1['id'], $conMeta, true));
$lista = $usu->lista($nid);
check('En la lista, el dueño primero y luego los administradores', ['dueno', 'admin'], [$lista[0]['rol'], $lista[1]['rol']]);

titulo('8. Permisos del programa: todo pregunta es_admin_total() (dueño o administrador)');
$boot = file_get_contents(RAIZ . '/app/bootstrap.php');
check('Existe el helper es_admin_total', true, str_contains($boot, 'function es_admin_total(array $u): bool'));
check('es_admin_total = dueño o administrador', true, str_contains($boot, "in_array(\$u['rol'] ?? '', ['dueno', 'admin'], true)"));
$sueltos = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RAIZ . '/app', FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->getExtension() !== 'php') continue;
    $txt = file_get_contents($f->getPathname());
    $rel = substr($f->getPathname(), strlen(RAIZ) + 1);
    if ($rel !== 'app/bootstrap.php' && str_contains($txt, 'es_dueno(')) $sueltos[] = $rel;
    if (preg_match("/\\\$u\['rol'\]\s*===\s*'dueno'/", $txt)) $sueltos[] = $rel . ' (rol === dueno)';
}
check('Ninguna pantalla pregunta solo por el dueño (todas usan es_admin_total)', [], $sueltos);
