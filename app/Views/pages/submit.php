<?php
declare(strict_types=1);
/**
 * Public submit form (Phase 9, re-skinned to the SupportDesk prototype card design).
 * Posts to /api submitTicket via public.js with the stateless HMAC CSRF token (D6).
 * Includes a honeypot ('website') hidden field. Works standalone and inside the
 * widget iframe. No inline handlers (D5); the theme toggle is delegated (theme-init).
 *
 * @var string $csrf @var array $categories @var bool $widget @var string $company
 */
$widget = $widget ?? false;
$company = $company ?? 'SupportDesk';
?>
<div class="pub-shell">
  <?php if (!$widget): ?>
    <button type="button" class="theme-btn pub-theme-btn" data-action="toggle-theme" title="Toggle dark/light mode" aria-label="Toggle dark or light mode">
      <svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      <svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </button>
  <?php endif; ?>

  <div class="pub-card" data-form="submit" data-csrf="<?= e($csrf) ?>">
    <div class="pub-logo">
      <div class="pub-logo-icon">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <span class="pub-logo-name"><?= e($company) ?></span>
    </div>

    <!-- FORM VIEW -->
    <div data-region="submit-form">
      <h1 class="pub-title">How can we help?</h1>
      <p class="pub-tagline">Submit a request and our team will get back to you shortly.</p>

      <div class="alert error" data-bind="submit-error" role="alert"></div>

      <form data-action="submit-ticket" autocomplete="on">
        <div class="pub-row2">
          <div class="field">
            <label>Your name <span class="pub-opt">(optional)</span></label>
            <input type="text" name="customer_name" placeholder="Jane Doe" maxlength="120">
          </div>
          <div class="field">
            <label>Email address *</label>
            <input type="email" name="customer_email" placeholder="jane@example.com" required maxlength="254">
          </div>
        </div>
        <div class="field">
          <label>Organization *</label>
          <select name="organization_id" required>
            <option value="" disabled selected>— Select your organization —</option>
            <?php foreach (($organizations ?? []) as $o): ?>
              <option value="<?= e($o['organization_id']) ?>"><?= e($o['name']) ?></option>
            <?php endforeach; ?>
            <option value="__general__">Other / not listed</option>
          </select>
        </div>
        <div class="pub-row2">
          <div class="field">
            <label>Category</label>
            <select name="category_id">
              <option value="">— Select —</option>
              <?php foreach (($categories ?? []) as $c): ?>
                <option value="<?= e($c['category_id']) ?>"><?= e(($c['parent_id'] ? '— ' : '') . $c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Priority</label>
            <select name="priority">
              <option value="low">Low — general question</option>
              <option value="normal" selected>Normal — standard request</option>
              <option value="high">High — significant impact</option>
              <?php /* 'urgent' is deliberately NOT offered to the public (priority ceiling, §3) */ ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label>Subject *</label>
          <input type="text" name="subject" placeholder="Brief summary of your request" required maxlength="200">
        </div>
        <div class="field">
          <label>Description *</label>
          <textarea name="description" placeholder="Tell us what happened, what you expected, and anything that might help us resolve it faster." required maxlength="5000" rows="5"></textarea>
        </div>

        <?php // Honeypot: real users never see or fill this; bots do. ?>
        <div class="p3a-hp" aria-hidden="true">
          <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <button type="submit" class="btn-primary" data-bind="submit-btn">Submit Request</button>
      </form>

      <?php if (!$widget): ?>
        <div class="pub-footer">Already have a ticket? <a href="<?= e(url('status')) ?>" target="_top">Check its status →</a></div>
      <?php endif; ?>
    </div>

    <!-- SUCCESS VIEW -->
    <div data-region="submit-done" hidden>
      <div class="pub-done">
        <div class="pub-done-icon ok">
          <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h2>Request submitted!</h2>
        <p>Your ticket has been created and assigned to our team.<br>Your reference number is:</p>
        <div class="pub-tid" data-bind="done-ticket-id">—</div>
        <div class="pub-save-note">Save this ID — you'll need it (with your email) to check the status of your request.</div>
        <div class="pub-done-actions">
          <?php if (!$widget): ?><a class="pub-btn-ghost" href="<?= e(url('status')) ?>" target="_top">Check status</a><?php endif; ?>
          <button type="button" class="pub-btn-solid" data-action="submit-another">Submit another request</button>
        </div>
      </div>
    </div>
  </div>
</div>
