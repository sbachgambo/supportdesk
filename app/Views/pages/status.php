<?php
declare(strict_types=1);
/**
 * Public status lookup (Phase 9, re-skinned to the SupportDesk prototype card design).
 * Ticket id AND matching email both required. Wrong-email and unknown-id return a
 * byte-identical generic error (server-side). Internal notes/system messages are never
 * returned (anonymous visibility). No inline handlers (D5); theme toggle delegated.
 *
 * @var string $csrf @var string $company
 */
$company = $company ?? 'SupportDesk';
?>
<div class="pub-shell">
  <button type="button" class="theme-btn pub-theme-btn" data-action="toggle-theme" title="Toggle dark/light mode" aria-label="Toggle dark or light mode">
    <svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
  </button>

  <div class="pub-card narrow" data-form="status" data-csrf="<?= e($csrf) ?>">
    <div class="pub-logo">
      <div class="pub-logo-icon">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <span class="pub-logo-name"><?= e($company) ?></span>
    </div>

    <h1 class="pub-title">Check ticket status</h1>
    <p class="pub-tagline">Enter your ticket ID and the email address you used when submitting the request.</p>

    <div class="alert error" data-bind="status-error" role="alert"></div>

    <form data-action="check-status">
      <div class="field">
        <label>Ticket ID</label>
        <input type="text" name="ticket_id" placeholder="e.g. TKT-2026-0001" autocomplete="off" required>
      </div>
      <div class="field">
        <label>Email address</label>
        <input type="email" name="email" placeholder="you@example.com" required maxlength="254">
      </div>
      <button type="submit" class="btn-primary" data-bind="status-btn">Check Status</button>
    </form>

    <div class="pub-result" data-bind="status-result" hidden></div>

    <div class="pub-footer">Need to open a new request? <a href="<?= e(url('submit')) ?>" target="_top">Submit a request →</a></div>
  </div>
</div>
