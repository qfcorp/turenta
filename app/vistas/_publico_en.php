<?php
/**
 * Diseño de las páginas del cliente en inglés (reserva en línea y portal "My Party"). Migración 200.
 * @var string $contenido @var string $titulo @var ?array $marca (nombre, color, logo_url, sitio_externo, telefono)
 */
$colorNegocio = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($marca['color'] ?? '')) ? $marca['color'] : '#1259C3';
$logoUrl = (string) ($marca['logo_url'] ?? '');
$sitio = (string) ($marca['sitio_externo'] ?? '');
$telMarca = (string) ($marca['telefono'] ?? '');
$cssEn = RAIZ . '/public/assets/en.css';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($colorNegocio) ?>">
<meta name="robots" content="noindex">
<title><?= e($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700;9..40,800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/en.css?v=<?= is_file($cssEn) ? filemtime($cssEn) : 1 ?>">
<style>:root { --brand: <?= e($colorNegocio) ?>; }</style>
</head>
<body class="en">
<a class="en-skip" href="#main">Skip to content</a>
<header class="en-head">
    <div class="en-wrap en-head-in">
        <a class="en-logo" href="<?= e($sitio !== '' ? $sitio : '#main') ?>">
            <?php if ($logoUrl !== ''): ?><img src="<?= e($logoUrl) ?>" alt="<?= e($marca['nombre'] ?? '') ?>" height="44">
            <?php else: ?><strong><?= e($marca['nombre'] ?? 'TuRenta') ?></strong><?php endif; ?>
        </a>
        <?php if ($telMarca !== ''): ?><a class="en-tel" href="tel:+<?= e(preg_replace('/\D/', '', $telMarca)) ?>"><?= e(telefono_en($telMarca)) ?></a><?php endif; ?>
    </div>
</header>
<main id="main" class="en-main">
    <div class="en-wrap">
    <?php foreach (tomar_avisos() as $a): ?>
        <p class="en-alert en-alert-<?= $a['tipo'] === 'error' ? 'err' : 'ok' ?>" role="<?= $a['tipo'] === 'error' ? 'alert' : 'status' ?>"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
    </div>
</main>
<footer class="en-foot"><div class="en-wrap"><?= e($marca['nombre'] ?? '') ?><?= $marca ? ' · ' : '' ?>Booking system by <strong>TuRenta</strong> · Tukán Software</div></footer>
<script src="/assets/en.js?v=<?= is_file(RAIZ . '/public/assets/en.js') ? filemtime(RAIZ . '/public/assets/en.js') : 1 ?>" defer></script>
</body>
</html>
