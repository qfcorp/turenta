<?php
/** Diseño del panel del super administrador. @var string $contenido @var string $titulo @var ?array $admin */
$rutaActual = (string) ($_GET['r'] ?? '');
$menuAdmin = ['admin' => 'Resumen', 'admin_negocios' => 'Negocios', 'admin_nuevo' => 'Nueva demo', 'admin_planes' => 'Planes y precios', 'admin_faq' => 'Preguntas IA'];
$avisos = tomar_avisos();
// Si entró desde el Panel Tukán Central, botón para volver allá
$volverCentral = (!empty($_SESSION['desde_central']) && \TuRenta\TukanCentral::config()) ? \TuRenta\TukanCentral::urlCentral() : null;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#4A1E2A">
<meta name="robots" content="noindex">
<title><?= e($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(RAIZ . '/public/assets/app.css') ?>">
<?php foreach (glob(RAIZ . '/public/assets/mod_*.css') ?: [] as $css): ?>
<link rel="stylesheet" href="/assets/<?= e(basename($css)) ?>?v=<?= filemtime($css) ?>">
<?php endforeach; ?>
</head>
<body class="admin">
<?php if ($admin): ?>
<header class="admin-barra">
    <a class="admin-marca" href="<?= e(url('admin')) ?>"><span class="marca-sello" aria-hidden="true">Tk</span>
        <span><strong>Panel Tukán</strong><small>TuRenta · super administrador</small></span></a>
    <nav class="admin-menu" aria-label="Menú del panel">
        <?php if ($volverCentral): ?><a class="admin-central" href="<?= e($volverCentral) ?>">← Panel Tukán</a><?php endif; ?>
        <?php foreach ($menuAdmin as $r => $txt): ?>
            <a href="<?= e(url($r)) ?>" class="<?= $rutaActual === $r || ($r === 'admin_negocios' && $rutaActual === 'admin_negocio') ? 'activo' : '' ?>"
               <?= $rutaActual === $r ? 'aria-current="page"' : '' ?>><?= $txt ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('admin_salir')) ?>">Salir</a>
    </nav>
</header>
<?php endif; ?>
<main class="principal admin-principal" id="contenido">
    <?php foreach ($avisos as $a): ?>
        <p class="aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>
<script src="/assets/app.js?v=<?= filemtime(RAIZ . '/public/assets/app.js') ?>" defer></script>
<script src="/assets/mod_modulos.js?v=<?= filemtime(RAIZ . '/public/assets/mod_modulos.js') ?>" defer></script>
</body>
</html>
