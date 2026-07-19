<?php
declare(strict_types=1);
/** Password-updated confirmation (prototype card design). @var string $company */
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
      <div class="pub-done-icon ok">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h2>Password updated!</h2>
      <p>Your password has been changed. For security, all your previous sessions have been signed out.</p>
      <a class="pub-btn-solid" href="<?= e(url('login')) ?>">Sign in →</a>
    </div>
  </div>
</div>
