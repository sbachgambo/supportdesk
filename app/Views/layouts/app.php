<?php
declare(strict_types=1);
/**
 * Authenticated app shell (Phase 4). Strict-CSP friendly: NO inline scripts and NO
 * inline event handlers (D5). Theme is applied by a tiny blocking head script
 * (self-hosted) to avoid a flash, and all interactivity is delegated in app.js.
 *
 * @var string $content @var string $title @var string $csrf
 * @var string $role @var string $email @var string $company
 */
$title = $title ?? 'P3A Support';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= e($csrf ?? '') ?>">
    <title><?= e($title) ?></title>
    <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body data-role="<?= e($role ?? '') ?>" data-asset-base="<?= e(asset('')) ?>">
    <header class="p3a-topbar">
        <div class="p3a-brand"><?= e($company ?? 'P3A Support') ?></div>
        <nav class="p3a-nav">
            <span class="p3a-user" data-bind="user-name"><?= e($email ?? '') ?></span>
            <button type="button" class="p3a-theme-toggle" data-action="toggle-theme"
                    aria-label="Toggle dark mode" title="Toggle theme">◑</button>
            <form method="post" action="<?= e(url('logout')) ?>" class="p3a-logout">
                <?= csrf_field() /* session-bound token (§10.8) */ ?>
                <button type="submit">Sign out</button>
            </form>
        </nav>
    </header>
    <main class="p3a-app">
        <?= raw($content) /* trusted: assembled by View from an escaped page template */ ?>
    </main>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <?php if (!empty($pageScript)): ?>
        <?php // page-specific script, self-hosted (CSP script-src 'self') ?>
        <script src="<?= e(asset('js/' . $pageScript)) ?>" defer></script>
    <?php endif; ?>
</body>
</html>
