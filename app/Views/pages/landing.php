<?php
declare(strict_types=1);
/**
 * Landing page shell (Phase 2). The real marketing landing is Phase 9.
 *
 * @var string $title
 */
?>
<section class="p3a-hero">
    <h1><?= e($title ?? 'P3A Support') ?></h1>
    <p>Welcome. The support portal is being set up.</p>
    <p>
        <a href="<?= e(url('login')) ?>">Staff sign in</a>
        &middot;
        <a href="<?= e(url('submit')) ?>">Submit a ticket</a>
        &middot;
        <a href="<?= e(url('status')) ?>">Check ticket status</a>
    </p>
</section>
