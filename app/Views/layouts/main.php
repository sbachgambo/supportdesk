<?php
declare(strict_types=1);
/**
 * Main layout. $content is pre-rendered HTML from the page template.
 * $title is escaped here. The full themed shell arrives in Phase 4.
 *
 * @var string $content
 * @var string $title
 */
$title = $title ?? 'P3A Support';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
    <main class="p3a-main">
        <?php // page content is already-escaped HTML built by the page template ?>
        <?= raw($content) /* trusted: assembled by View from an escaped page template */ ?>
    </main>
    <?php if (!empty($pageScript)): ?>
        <?php // page-specific script, self-hosted (CSP script-src 'self') ?>
        <script src="<?= e(asset('js/' . $pageScript)) ?>" defer></script>
    <?php endif; ?>
</body>
</html>
