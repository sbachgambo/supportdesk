<?php
declare(strict_types=1);
/**
 * Public marketing landing page (standalone-website hero). Brand name + tagline are
 * config-driven (company_name / portal_tagline) so a rename re-brands the whole page.
 * Bare layout; theme toggle is delegated (theme-init.js). No inline handlers (D5);
 * all dynamic values escaped via e().
 *
 * @var string $company @var string $tagline
 */
$company = $company ?? 'Support';
$tagline = $tagline ?? 'How can we help you today?';
$year = gmdate('Y');
?>
<div class="tf-page">

  <header class="tf-nav">
    <div class="tf-nav-inner">
      <a class="tf-brand" href="<?= e(url('')) ?>">
        <span class="tf-brand-icon">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </span>
        <span class="tf-brand-name"><?= e($company) ?></span>
      </a>
      <nav class="tf-nav-links">
        <a href="#features">Features</a>
        <a href="<?= e(url('help')) ?>">Help Centre</a>
        <a href="<?= e(url('status')) ?>">Check status</a>
      </nav>
      <div class="tf-nav-actions">
        <button type="button" class="theme-btn" data-action="toggle-theme" title="Toggle dark/light mode" aria-label="Toggle dark or light mode">
          <svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
          <svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
        <a class="tf-btn tf-btn-ghost" href="<?= e(url('login')) ?>">Sign in</a>
        <a class="tf-btn tf-btn-solid" href="<?= e(url('submit')) ?>">Submit a request</a>
      </div>
    </div>
  </header>

  <section class="tf-hero">
    <div class="tf-hero-copy">
      <span class="tf-eyebrow">Support that just works</span>
      <h1 class="tf-hero-title">Every customer request,<br><span class="tf-grad">handled with flow.</span></h1>
      <p class="tf-hero-sub">Turn every message into a tracked, resolved ticket — with SLA timers, a self-service help centre, and reporting your team will actually use. Fast for customers, calm for your agents.</p>
      <div class="tf-hero-cta">
        <a class="tf-btn tf-btn-solid tf-btn-lg" href="<?= e(url('submit')) ?>">Submit a request &rarr;</a>
        <a class="tf-btn tf-btn-ghost tf-btn-lg" href="<?= e(url('status')) ?>">Check ticket status</a>
      </div>
      <p class="tf-hero-note">No account needed to submit &middot; Track your ticket any time</p>
    </div>

    <div class="tf-hero-visual" aria-hidden="true">
      <div class="tf-mock tf-mock-back">
        <div class="tf-mock-stat"><span class="tf-mock-stat-num">98%</span><span class="tf-mock-stat-label">SLA met this week</span></div>
      </div>
      <div class="tf-mock tf-mock-front">
        <div class="tf-mock-head">
          <span class="tf-mock-id">TKT-<?= e($year) ?>-0042</span>
          <span class="tf-mock-chip">Open</span>
        </div>
        <div class="tf-mock-title">Payment not reflecting on my invoice</div>
        <div class="tf-mock-meta"><span class="tf-mock-dot"></span> High priority &middot; Billing</div>
        <div class="tf-mock-bar"><span style="width:72%"></span></div>
        <div class="tf-mock-foot">
          <span class="tf-mock-avatar">SS</span>
          <span>Sarah replied &middot; SLA 2h 14m left</span>
        </div>
      </div>
    </div>
  </section>

  <section class="tf-features" id="features">
    <div class="tf-section-head tf-reveal">
      <h2>Everything a support team needs</h2>
      <p>From first message to resolved — in one secure place.</p>
    </div>
    <div class="tf-feature-grid tf-reveal">
      <?php
      $features = [
          ['M9 12l2 2 4-4', 'Ticket tracking', 'Every request gets a reference id, a status, and a full conversation thread — nothing slips through.'],
          ['M12 6v6l4 2', 'SLA timers', 'Response and resolution deadlines per priority, with optional business-hours clocks so weekends never count against you.'],
          ['M4 19.5A2.5 2.5 0 0 1 6.5 17H20 M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z', 'Help Centre', 'Publish answers to common questions so customers can self-serve — and cut your ticket volume.'],
          ['M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M23 21v-2a4 4 0 0 0-3-3.87', 'Multi-team', 'Organizations keep each team’s tickets and agents cleanly separated, with a super-admin owner on top.'],
          ['M3 3v18h18 M18 9l-5 5-3-3-4 4', 'Reports & insights', 'Volume, resolution times, SLA compliance, customer ratings and breakdowns by product, agent or category.'],
          ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z', 'Secure by design', 'Two-factor auth, a tamper-evident audit log, encrypted backups and strict access control — built in, not bolted on.'],
      ];
      foreach ($features as [$path, $title, $text]): ?>
        <div class="tf-feature">
          <div class="tf-feature-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="<?= e($path) ?>"/></svg>
          </div>
          <h3><?= e($title) ?></h3>
          <p><?= e($text) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="tf-showcase">
    <div class="tf-showcase-media tf-reveal">
      <img src="<?= e(asset('img/showcase-team.jpg')) ?>" alt="A support team collaborating around a laptop" width="1400" height="934" loading="lazy">
    </div>
    <div class="tf-showcase-copy tf-reveal tf-reveal-2">
      <h2>Built for real support teams</h2>
      <p>Whether you're a two-person shop or several teams under one roof, <?= e($company) ?> keeps every request organised, routed to the right people, and moving toward resolved.</p>
      <ul class="tf-checks">
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> Auto-assignment to the least-busy agent</li>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> Separate queues per team or organisation</li>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> Canned replies, internal notes &amp; attachments</li>
        <li><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg> Email updates so customers are never left guessing</li>
      </ul>
      <a class="tf-btn tf-btn-solid" href="<?= e(url('login')) ?>">Staff sign in &rarr;</a>
    </div>
  </section>

  <section class="tf-cta">
    <div class="tf-cta-inner tf-reveal">
      <h2><?= e($tagline) ?></h2>
      <p>Submit a request and our team will take it from there.</p>
      <a class="tf-btn tf-btn-solid tf-btn-lg" href="<?= e(url('submit')) ?>">Submit a request &rarr;</a>
    </div>
  </section>

  <footer class="tf-footer">
    <div class="tf-footer-inner">
      <a class="tf-brand" href="<?= e(url('')) ?>">
        <span class="tf-brand-icon">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </span>
        <span class="tf-brand-name"><?= e($company) ?></span>
      </a>
      <nav class="tf-footer-links">
        <a href="<?= e(url('submit')) ?>">Submit a request</a>
        <a href="<?= e(url('status')) ?>">Check status</a>
        <a href="<?= e(url('help')) ?>">Help Centre</a>
        <a href="<?= e(url('login')) ?>">Staff sign in</a>
      </nav>
      <span class="tf-footer-copy">&copy; <?= e($year) ?> <?= e($company) ?></span>
    </div>
  </footer>

</div>
