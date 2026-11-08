<?php
/** Diseño general del sistema. @var string $contenido @var string $titulo @var ?array $u */
$rutaActual = $_GET['r'] ?? 'inicio';
$iconos = [
    'inicio'     => 'M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z',
    'calendario' => 'M7 3v3M17 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z',
    'eventos'    => 'M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z',
    'cobros'     => 'M3 7h18v12H3zM3 11h18M7 15h3',
    'mas'        => 'M4 6h16M4 12h16M4 18h16',
];
$menu = ['inicio' => 'Inicio', 'calendario' => 'Calendario', 'eventos' => 'Eventos', 'cobros' => 'Cobros', 'mas' => 'Más'];
$grupoMas = ['mas', 'clientes', 'cliente', 'seguimiento', 'catalogo', 'paquetes', 'espacios', 'personal', 'proveedores', 'mobiliario',
             'reportes', 'configuracion', 'usuarios', 'mi_cuenta', 'planes', 'notificaciones', 'mensajes', 'exportar'];
$grupoEventos = ['eventos', 'evento', 'evento_nuevo', 'evento_editar', 'cotizacion', 'contrato', 'evento_pagos', 'evento_dia', 'invitados'];
array_push($grupoMas, 'cuestionarios', 'extras'); $grupoEventos[] = 'evento_formulario';   // portal del cliente (070)
$grupoMas[] = 'metas'; if (!empty($_GET['id'])) $grupoEventos[] = 'plano'; else $grupoMas[] = 'plano';   // plano y metas (080)
array_push($grupoMas, 'proveedor', 'compras', 'compra', 'por_pagar', 'incidencias', 'puntaje_proveedores');   // proveedores y compras (060, 091)
$grupoMas[] = 'ayuda'; $grupoEventos[] = 'ia_cotizacion';   // ayuda e IA (110)
$campana = 0;
if ($u) {
    $campana = (new \TuRenta\Notificaciones(db()))->sinLeer((int) $u['id']);
    if ($u['rol'] === 'coordinador') unset($menu['cobros']);
}
$avisos = tomar_avisos();
$diasPrueba = $u ? dias_prueba($u) : null;
$estadoCuenta = $u ? planes()->estadoCuenta($u) : null;
$diasPlan = ($estadoCuenta === 'activo' && !empty($u['activo_hasta']))
    ? (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($u['activo_hasta']))->days : null;
$adminViendo = !empty($_SESSION['admin_viendo']) && superadmin();
// (7 nov 2026) El dueño acepta los términos y la privacidad de Tukán (src/TukanLegal.php); se acepta desde Mi plan
require_once dirname(__DIR__, 2) . '/src/TukanLegal.php';
$pideTerminos = $u && es_admin_total($u) && !$adminViendo && !TukanLegal::aceptado(db(), (string) $u['negocio_id']);
$enApp = en_app_android();   // app de Google Play: sin «Renovar» ni enlaces para pagar el software (docs/GOOGLE_PLAY.md)
$modulosCss = glob(RAIZ . '/public/assets/mod_*.css') ?: [];
$modulosJs = glob(RAIZ . '/public/assets/mod_*.js') ?: [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#4A1E2A">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="TuRenta">
<title><?= e($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(RAIZ . '/public/assets/app.css') ?>">
<?php foreach ($modulosCss as $css): ?>
<link rel="stylesheet" href="/assets/<?= e(basename($css)) ?>?v=<?= filemtime($css) ?>">
<?php endforeach; ?>
</head>
<body class="<?= ($u ? 'con-sesion' : 'sin-sesion') . ($enApp ? ' en-app' : '') ?>">
<?php if ($u): ?>
<aside class="lateral" aria-label="Menú principal">
    <a class="marca" href="<?= e(url('inicio')) ?>">
        <span class="marca-sello" aria-hidden="true">Te</span>
        <span class="marca-texto">
            <strong><?= e($u['negocio']) ?></strong>
            <small>Plan <?= e($u['plan_nombre'] ?? $u['plan_codigo']) ?></small>
        </span>
    </a>
    <div class="buscar-lateral solo-ancho">
        <input type="search" id="buscar-rapido" placeholder="Buscar evento o cliente…" autocomplete="off" aria-label="Buscar">
        <div id="buscar-resultados" class="buscar-resultados" hidden></div>
    </div>
    <nav class="menu">
        <?php foreach ($menu as $r => $texto):
            $activo = $rutaActual === $r || ($r === 'mas' && in_array($rutaActual, $grupoMas, true)) || ($r === 'eventos' && in_array($rutaActual, $grupoEventos, true)); ?>
            <a href="<?= e(url($r)) ?>" class="<?= $activo ? 'activo' : '' ?><?= $r === 'mas' ? ' solo-celular-flex' : '' ?>" <?= $rutaActual === $r ? 'aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $iconos[$r] ?>"/></svg>
                <span><?= e($texto) ?></span>
                <?php if ($r === 'inicio' && $campana > 0): ?><span class="contador" aria-label="<?= $campana ?> avisos sin leer"><?= $campana ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="lateral-pie">
        <a href="<?= e(url('notificaciones')) ?>">Avisos<?= $campana ? " ($campana)" : '' ?></a>
        <?php if ($u['rol'] !== 'coordinador'): ?>
        <a href="<?= e(url('clientes')) ?>">Clientes</a>
        <a href="<?= e(url('seguimiento')) ?>">Seguimiento</a>
        <a href="<?= e(url('mensajes')) ?>">Mensajes por WhatsApp</a>
        <a href="<?= e(url('catalogo')) ?>">Catálogo y precios</a>
        <?php if (modulo_activo($u, 'paquetes')): ?><a href="<?= e(url('paquetes')) ?>">Paquetes</a><?php endif; ?>
        <?php if (modulo_activo($u, 'cuestionarios')): ?><a href="<?= e(url('extras')) ?>">Extras del portal</a><?php endif; ?>
        <?php endif; ?>
        <a href="<?= e(url('espacios')) ?>">Espacios</a>
        <?php if (modulo_activo($u, 'dia_evento')): ?><a href="<?= e(url('personal')) ?>">Personal</a><?php endif; ?>
        <?php if (modulo_activo($u, 'proveedores')): ?><a href="<?= e(url('proveedores')) ?>">Proveedores</a><?php endif; ?>
        <?php if (modulo_activo($u, 'proveedores')): ?><?php if ($u['rol'] !== 'coordinador'): ?><a href="<?= e(url('por_pagar')) ?>">Por pagar</a><?php endif; ?><?php endif; ?>
        <?php if (modulo_activo($u, 'incidencias')): ?><a href="<?= e(url('incidencias')) ?>">Incidencias</a><?php endif; ?>
        <?php if (modulo_activo($u, 'mobiliario')): ?><a href="<?= e(url('mobiliario')) ?>">Mobiliario</a><?php endif; ?>
        <?php if ($u['rol'] !== 'coordinador'): ?>
        <a href="<?= e(url('reportes')) ?>">Reportes</a>
        <?php endif; ?>
        <?php if (es_admin_total($u)): ?>
        <a href="<?= e(url('configuracion')) ?>">Configuración</a>
        <?php if (modulo_activo($u, 'contratos')): ?><a href="<?= e(url('contrato')) ?>">Plantillas de contrato</a><?php endif; ?>
        <?php if (modulo_activo($u, 'cuestionarios')): ?><a href="<?= e(url('cuestionarios')) ?>">Formularios del evento</a><?php endif; ?>
        <a href="<?= e(url('usuarios')) ?>">Usuarios</a>
        <a href="<?= e(url('planes')) ?>">Mi plan</a>
        <?php endif; ?>
        <a href="<?= e(url('ayuda')) ?>">Ayuda</a>
        <a href="<?= e(url('mi_cuenta')) ?>">Mi cuenta</a>
        <a href="<?= e(url('salir')) ?>">Cerrar sesión</a>
        <p class="pie-legal"><?= TukanLegal::enlaces(url('privacidad')) ?></p>
    </div>
</aside>
<?php endif; ?>

<main class="principal" id="contenido">
    <?php if ($diasPrueba !== null && $diasPrueba >= 0): ?>
        <p class="franja-prueba no-imprimir">
            Demo: <?= $diasPrueba === 0 ? 'termina hoy' : "te quedan $diasPrueba " . ($diasPrueba === 1 ? 'día' : 'días') ?>.
            <?php if (es_admin_total($u)): ?><a href="<?= e(url('planes')) ?>">Ver Mi plan</a><?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($adminViendo): ?>
        <p class="franja-admin no-imprimir">Estás viendo <strong><?= e($u['negocio']) ?></strong> como administrador de Tukán.
            <a href="<?= e(url('admin_volver')) ?>">Volver al panel</a></p>
    <?php endif; ?>
    <?php if ($estadoCuenta === 'por_vencer'): ?>
        <p class="franja-prueba no-imprimir">Tu plan venció el <?= (new DateTimeImmutable($u['activo_hasta']))->format('d/m') ?>. <?php if (!$enApp): ?>Renueva en los próximos días para no perder el acceso.
            <a href="<?= e(url('planes')) ?>">Renovar</a><?php endif; ?></p>
    <?php elseif ($diasPlan !== null && $diasPlan <= 5): ?>
        <p class="franja-prueba no-imprimir">Tu plan vence <?= $diasPlan === 0 ? 'hoy' : "en $diasPlan " . ($diasPlan === 1 ? 'día' : 'días') ?>.
            <?php if (!$enApp): ?><a href="<?= e(url('planes')) ?>">Renovar</a><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($pideTerminos): ?><?= TukanLegal::aviso(campo_csrf(), url('planes'), url('privacidad')) ?><?php endif; ?>
    <?php foreach ($avisos as $a): ?>
        <p class="aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>
<script src="/assets/app.js?v=<?= filemtime(RAIZ . '/public/assets/app.js') ?>" defer></script>
<?php foreach ($modulosJs as $js): ?>
<script src="/assets/<?= e(basename($js)) ?>?v=<?= filemtime($js) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
