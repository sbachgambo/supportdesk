<?php
declare(strict_types=1);
/**
 * Login — split-screen brand design (ported). Server-side auth: the form POSTs to
 * /login (public CSRF token), which sets the httpOnly session cookie and redirects.
 *
 * @var string $csrf @var ?string $error @var string $email @var string $company @var string $tagline
 */
$company = $company ?? 'SupportDesk';
?>
<div class="login-shell">
  <button type="button" class="theme-btn login-theme-btn" data-action="toggle-theme" title="Toggle dark/light mode">
    <svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
  </button>

  <div class="login-brand">
    <div class="brand-logo">
      <div class="brand-logo-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <span class="brand-logo-name"><?= e($company) ?></span>
    </div>
    <div class="brand-copy">
      <h1>Support that just <em>works</em>.</h1>
      <p>A unified helpdesk that keeps your team on top of every customer request — with SLAs, priorities and analytics built in.</p>
    </div>
    <div class="brand-features">
      <div class="brand-feature"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Real-time SLA monitoring</div>
      <div class="brand-feature"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Threaded conversations &amp; internal notes</div>
      <div class="brand-feature"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Multi-channel intake &amp; audit logging</div>
    </div>
  </div>

  <div class="login-form-side">
    <div class="login-card">
      <h2>Welcome back</h2>
      <p class="sub">Sign in to your <?= e($company) ?> account</p>

      <div class="alert error <?= !empty($error) ? 'show' : '' ?>">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?= e($error ?? '') ?></span>
      </div>

      <form method="post" action="<?= e(url('login')) ?>" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <div class="field">
          <label>Email address</label>
          <input type="email" name="email" value="<?= e($email ?? '') ?>" placeholder="you@company.com" autocomplete="email" required autofocus>
        </div>
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn-primary">Sign in</button>
      </form>

      <div style="text-align:center;margin-top:16px;font-size:13px;">
        <a href="<?= e(url('forgot')) ?>" style="font-weight:600;text-decoration:none;">Forgot password?</a>
      </div>
    </div>
  </div>
</div>
