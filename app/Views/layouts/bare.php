<?php
declare(strict_types=1);
/**
 * Bare layout — a minimal full-screen wrapper for standalone pages (login, MFA,
 * reset, public forms) that supply their own full-width design. No app chrome.
 *
 * @var string $content @var string $title @var ?string $pageScript
 */
$title = $title ?? 'Support';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (!empty($csrf)): ?><meta name="csrf" content="<?= e($csrf) ?>"><?php endif; ?>
    <title><?= e($title) ?></title>
    <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
    <?= raw($content) /* trusted: assembled by View from an escaped page template */ ?>
    <?php if (!empty($pageScript)): ?>
        <script src="<?= e(asset('js/' . $pageScript)) ?>" defer></script>
    <?php endif; ?>
</body>
</html>
