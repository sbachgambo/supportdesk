<?php
declare(strict_types=1);
/**
 * Choose-a-new-password (re-skinned to the prototype card design). Server-rendered
 * POST to /reset with the public CSRF token; the reset identity is held in a
 * short-lived signed cookie (no token in the URL, §10.9). Policy min is 12 chars
 * (§10.3). No inline handlers (D5); theme toggle delegated.
 *
 * @var string $csrf @var ?string $error @var string $company
 */
$company = $company ?? 'SupportDesk';
?>
<div class="pub-shell center">
  <button type="button" class="theme-btn pub-theme-btn" data-action="toggle-theme" title="Toggle dark/light mode" aria-label="Toggle dark or light mode">
    <svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
  </button>

  <div class="pub-card narrow">
    <div class="pub-logo center">
      <div class="pub-logo-icon">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <span class="pub-logo-name"><?= e($company) ?></span>
    </div>

    <h1 class="pub-title center">Choose a new password</h1>
    <p class="pub-tagline center">At least 12 characters. Longer passphrases are stronger — no other rules.</p>

    <?php if (!empty($error)): ?>
      <div class="alert error show" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('reset')) ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label>New password <span class="pub-opt">(min 12 characters)</span></label>
        <input type="password" name="password" required autofocus minlength="12" autocomplete="new-password">
      </div>
      <button type="submit" class="btn-primary">Update password</button>
    </form>

    <div class="pub-footer"><a href="<?= e(url('login')) ?>">← Back to sign in</a></div>
  </div>
</div>
