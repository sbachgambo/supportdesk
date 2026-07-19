<?php
declare(strict_types=1);
/** Invalid/expired reset link (prototype card design). @var string $company */
$company = $company ?? 'SupportDesk';
?>
<div class="pub-shell center">
  <div class="pub-card narrow">
    <div class="pub-logo center">
      <div class="pub-logo-icon">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <span class="pub-logo-name"><?= e($company) ?></span>
    </div>
    <div class="pub-done">
      <div class="pub-done-icon bad">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="var(--danger)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <h2>Link invalid or expired</h2>
      <p>This password-reset link is invalid, has expired, or has already been used. Use <strong>Forgot password?</strong> on the sign-in page to request a new one.</p>
      <a class="pub-btn-solid" href="<?= e(url('forgot')) ?>">Request a new link →</a>
    </div>
  </div>
</div>
