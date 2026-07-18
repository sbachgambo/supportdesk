<?php
declare(strict_types=1);
/** @var string $csrf @var ?string $error @var string $email */
?>
<section class="p3a-auth">
    <h1>Sign in</h1>
    <?php if (!empty($error)): ?>
        <p class="p3a-error-msg" role="alert"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('login')) ?>" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Email
            <input type="email" name="email" value="<?= e($email ?? '') ?>" required autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit">Sign in</button>
    </form>
    <p><a href="<?= e(url('forgot')) ?>">Forgot your password?</a></p>
</section>
