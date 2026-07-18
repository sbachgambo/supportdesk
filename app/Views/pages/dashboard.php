<?php
declare(strict_types=1);
/**
 * Dashboard stub (Phase 3) — proves the authenticated session works. The real
 * role-branched dashboard shell arrives in Phase 4.
 *
 * @var string $email @var string $role
 */
?>
<section class="p3a-dash">
    <h1>Dashboard</h1>
    <p>Signed in as <strong><?= e($email) ?></strong> (<?= e($role) ?>).</p>
    <form method="post" action="<?= e(url('logout')) ?>">
        <?= csrf_field() /* session-bound token (§10.8) */ ?>
        <button type="submit">Sign out</button>
    </form>
</section>
