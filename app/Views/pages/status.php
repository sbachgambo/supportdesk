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
<?php $navActive = 'status'; include __DIR__ . '/../partials/public_nav.php'; ?>
<div class="pub-shell">
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

    <div class="pub-footer">Need to open a new request? <a href="<?= e(url('submit')) ?>" target="_top">Submit a request →</a>
      &nbsp;·&nbsp; <a href="<?= e(url('help')) ?>" target="_top">Help Centre</a></div>
  </div>
</div>
