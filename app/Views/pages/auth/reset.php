<?php
declare(strict_types=1);
/** @var string $csrf @var ?string $error */
?>
<section class="p3a-auth">
    <h1>Choose a new password</h1>
    <?php if (!empty($error)): ?>
        <p class="p3a-error-msg" role="alert"><?= e($error) ?></p>
    <?php endif; ?>
    <p>At least 12 characters. Longer passphrases are stronger — no other rules.</p>
    <form method="post" action="<?= e(url('reset')) ?>">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>New password
            <input type="password" name="password" required autofocus minlength="12">
        </label>
        <button type="submit">Update password</button>
    </form>
</section>
