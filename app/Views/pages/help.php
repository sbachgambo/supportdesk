<?php
declare(strict_types=1);
/**
 * Public Help Centre — browsable PUBLIC knowledge-base articles (server-rendered).
 * Internal articles are filtered out in the route; bodies are plain text rendered
 * through e() + nl2br (never raw HTML). No inline handlers (D5).
 *
 * @var string $company @var string $q
 * @var array|null $article  the single article being read, or null for the list
 * @var array $articles      public article rows (list mode)
 */
$company = $company ?? 'SupportDesk';
$q = $q ?? '';
$article = $article ?? null;
$articles = $articles ?? [];
?>
<?php $navActive = 'help'; include __DIR__ . '/../partials/public_nav.php'; ?>
<div class="pub-shell">
  <div class="pub-card" style="max-width:720px;">
    <div class="pub-logo">
      <div class="pub-logo-icon">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </div>
      <span class="pub-logo-name"><?= e($company) ?></span>
    </div>

    <?php if ($article !== null): ?>
      <p style="margin:0 0 14px;"><a href="<?= e(url('help')) ?>">&larr; All help articles</a></p>
      <h1 class="pub-title"><?= e($article['title']) ?></h1>
      <?php if (($article['category'] ?? '') !== ''): ?>
        <p class="pub-tagline"><?= e($article['category']) ?></p>
      <?php endif; ?>
      <div style="line-height:1.65;white-space:normal;">
        <?= nl2br(e((string) $article['body'])) ?>
      </div>
      <div class="pub-footer">Still stuck? <a href="<?= e(url('submit')) ?>">Submit a request &rarr;</a></div>

    <?php else: ?>
      <h1 class="pub-title">Help Centre</h1>
      <p class="pub-tagline">Find answers to common questions — or open a request if you're still stuck.</p>

      <form method="get" action="<?= e(url('help')) ?>">
        <div class="field">
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search help articles…" maxlength="100">
        </div>
        <button type="submit" class="btn-primary">Search</button>
      </form>

      <?php if ($articles === []): ?>
        <p style="margin-top:18px;color:var(--ink-muted,#667085);">
          <?= e($q === '' ? 'No help articles have been published yet.' : 'No articles match your search.') ?>
        </p>
      <?php else: ?>
        <?php
        // Group by category for a scannable list; uncategorised articles go last.
        $groups = [];
        foreach ($articles as $a) {
            $groups[(string) ($a['category'] ?: 'Other')][] = $a;
        }
        ?>
        <?php foreach ($groups as $cat => $items): ?>
          <h2 style="font-size:14px;text-transform:uppercase;letter-spacing:.05em;margin:22px 0 8px;color:var(--ink-muted,#667085);"><?= e($cat) ?></h2>
          <?php foreach ($items as $a): ?>
            <p style="margin:6px 0;">
              <a href="<?= e(url('help')) ?>?a=<?= e($a['article_id']) ?>"><?= e($a['title']) ?></a>
            </p>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="pub-footer">
        Can't find an answer? <a href="<?= e(url('submit')) ?>">Submit a request &rarr;</a>
        &nbsp;·&nbsp; <a href="<?= e(url('status')) ?>">Check ticket status</a>
      </div>
    <?php endif; ?>
  </div>
</div>
