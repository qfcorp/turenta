<?php
/**
 * Diseño de las páginas públicas (página del negocio, portal del cliente, invitación, firma, verificación).
 * @var string $contenido @var string $titulo @var ?array $marca (nombre, color, logo, slug del negocio)
 */
$colorNegocio = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($marca['color'] ?? '')) ? $marca['color'] : '#6B2D3C';
$modulosCss = glob(RAIZ . '/public/assets/mod_*.css') ?: [];
$modulosJs = glob(RAIZ . '/public/assets/mod_*.js') ?: [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($colorNegocio) ?>">
<?php if (empty($indexar)): ?><meta name="robots" content="noindex"><?php endif; ?>
<title><?= e($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(RAIZ . '/public/assets/app.css') ?>">
<?php foreach ($modulosCss as $css): ?>
<link rel="stylesheet" href="/assets/<?= e(basename($css)) ?>?v=<?= filemtime($css) ?>">
<?php endforeach; ?>
<style>:root { --color-negocio: <?= e($colorNegocio) ?>; }</style>
</head>
<body class="publico">
<main class="principal publico-principal" id="contenido">
    <?php foreach (tomar_avisos() as $a): ?>
        <p class="aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
    <p class="publico-pie"><?= $marca ? e($marca['nombre']) . ' · ' : '' ?>con <strong>TuRenta</strong> de Tukán</p>
</main>
<script src="/assets/app.js?v=<?= filemtime(RAIZ . '/public/assets/app.js') ?>" defer></script>
<?php foreach ($modulosJs as $js): ?>
<script src="/assets/<?= e(basename($js)) ?>?v=<?= filemtime($js) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
