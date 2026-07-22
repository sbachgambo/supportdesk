<?php
declare(strict_types=1);
/**
 * MFA challenge / enrolment (D8), re-skinned to the prototype card design. mfa.js drives
 * it against the /api actions reachable while the session is unverified. Enrolled → code
 * challenge; not enrolled (admin first login) → TOTP enrolment with backup codes.
 * No inline handlers (D5); theme toggle delegated.
 *
 * @var string $csrf @var string $company
 */
$company = $company ?? 'SupportDesk';
?>
<div class="pub-shell center">
  <button type="button" class="theme-btn pub-theme-btn" data-action="toggle-theme" title="Toggle dark/light mode" aria-label="Toggle dark or light mode">
    <svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
  </button>

  <div class="pub-card narrow" data-view="mfa" data-csrf="<?= e($csrf) ?>" data-manage="<?= !empty($manage) ? '1' : '0' ?>">
    <div class="pub-logo center">
      <div class="pub-logo-icon">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <span class="pub-logo-name"><?= e($company) ?></span>
    </div>

    <h1 class="pub-title center">Two-factor verification</h1>

    <div class="alert error" data-bind="mfa-msg" role="alert"></div>

    <div data-region="mfa-challenge" hidden>
      <p class="pub-tagline center">Enter the 6-digit code from your authenticator app.</p>
      <form data-action="mfa-verify">
        <div class="field">
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9A-Za-z]*" placeholder="123456" required autofocus>
        </div>
        <button type="submit" class="btn-primary">Verify</button>
      </form>
    </div>

    <div data-region="mfa-enroll" hidden>
      <p class="pub-tagline center">Multi-factor authentication is required. Scan this secret into your authenticator app (or enter it manually), then confirm a code.</p>
      <div class="mfa-secret" data-bind="mfa-secret"></div>
      <p style="text-align:center;margin:8px 0 18px;"><a data-bind="mfa-uri" href="#" rel="noopener">Open in authenticator app</a></p>
      <form data-action="mfa-confirm">
        <div class="field">
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
        </div>
        <button type="submit" class="btn-primary">Confirm &amp; enable</button>
      </form>
    </div>

    <div data-region="mfa-manage" hidden>
      <p class="pub-tagline center">Two-factor authentication is <strong>on</strong> for your account.
        Backup codes remaining: <strong data-bind="mfa-backup-count">–</strong>.</p>
      <button type="button" class="btn-primary" data-action="mfa-disable" style="background:var(--danger,#F04438);">Turn off two-factor authentication</button>
      <p style="text-align:center;margin-top:14px;"><a href="<?= e(url('dashboard')) ?>">&larr; Back to dashboard</a></p>
    </div>

    <div data-region="mfa-backup" hidden>
      <div class="pub-done">
        <div class="pub-done-icon ok">
          <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2>Save your backup codes</h2>
        <p>Each can be used once if you lose your device. Store them somewhere safe.</p>
      </div>
      <ul class="mfa-backup-list" data-bind="mfa-backup-list"></ul>
      <a class="pub-btn-solid" href="<?= e(url('dashboard')) ?>" style="width:100%;margin-top:8px;">Continue to dashboard →</a>
    </div>
  </div>
</div>
