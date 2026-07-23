<?php
declare(strict_types=1);
/**
 * Slim public top nav for the utility card pages (submit / status / help), so
 * visitors can move between them and back home. Brand + tagline are config-driven.
 * Theme toggle is delegated (theme-init.js). No inline handlers (D5).
 *
 * @var string $company  brand name
 * @var string $navActive  one of 'submit' | 'status' | 'help' | '' (highlights the link)
 */
$company = $company ?? 'Support';
$navActive = $navActive ?? '';
$cls = static fn(string $n): string => $navActive === $n ? ' is-active' : '';
?>
<header class="pub-nav">
  <div class="pub-nav-inner">
    <a class="tf-brand" href="<?= e(url('')) ?>">
      <span class="tf-brand-icon">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </span>
      <span class="tf-brand-name"><?= e($company) ?></span>
    </a>
    <nav class="pub-nav-links">
      <a href="<?= e(url('submit')) ?>" class="pub-nav-link<?php echo $cls('submit'); ?>">Submit a request</a>
      <a href="<?= e(url('status')) ?>" class="pub-nav-link<?php echo $cls('status'); ?>">Check status</a>
      <a href="<?= e(url('help')) ?>" class="pub-nav-link<?php echo $cls('help'); ?>">Help Centre</a>
    </nav>
    <button type="button" class="theme-btn" data-action="toggle-theme" title="Toggle dark/light mode" aria-label="Toggle dark or light mode">
      <svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      <svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
    </button>
  </div>
</header>
