<?php
declare(strict_types=1);
/** @var string $csrf @var ?string $notice */
?>
<section class="p3a-auth">
    <h1>Reset your password</h1>
    <?php if (!empty($notice)): ?>
        <p class="p3a-notice" role="status"><?= e($notice) ?></p>
    <?php endif; ?>
    <p>Enter your account email and we'll send a reset link.</p>
    <form method="post" action="<?= e(url('forgot')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Email
            <input type="email" name="email" required autofocus>
        </label>
        <button type="submit">Send reset link</button>
    </form>
    <p><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
</section>
