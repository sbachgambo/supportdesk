<?php
declare(strict_types=1);
/**
 * Reports dashboard (Phase 8). Chart.js is self-hosted; reports.js populates the
 * KPI cards and charts from the /api getReports action. Period switcher and CSV
 * export are data-* driven (no inline handlers).
 *
 * @var string $role
 */
?>
<section class="p3a-reports" data-view="reports">
    <div class="p3a-reports-head">
        <h1>Reports</h1>
        <div class="p3a-period" role="group" aria-label="Report period">
            <button type="button" data-action="report-period" data-period="7">7 days</button>
            <button type="button" data-action="report-period" data-period="30" class="is-active">30 days</button>
            <button type="button" data-action="report-period" data-period="90">90 days</button>
            <a class="p3a-export" data-bind="export-link" href="<?= e(url('export/tickets.csv?period=30')) ?>">Export CSV</a>
        </div>
    </div>

    <div class="p3a-cards">
        <article class="p3a-card"><h2>Created</h2><p class="p3a-metric" data-bind="kpi-created">—</p></article>
        <article class="p3a-card"><h2>Resolved</h2><p class="p3a-metric" data-bind="kpi-resolved">—</p></article>
        <article class="p3a-card"><h2>Avg resolution (hrs)</h2><p class="p3a-metric" data-bind="kpi-avg">—</p></article>
        <article class="p3a-card"><h2>SLA compliance</h2><p class="p3a-metric" data-bind="kpi-sla">—</p></article>
    </div>

    <div class="p3a-charts">
        <figure class="p3a-chart"><figcaption>Volume by day</figcaption><canvas data-chart="volume" height="120"></canvas></figure>
        <figure class="p3a-chart"><figcaption>By status</figcaption><canvas data-chart="status" height="160"></canvas></figure>
        <figure class="p3a-chart"><figcaption>By priority</figcaption><canvas data-chart="priority" height="160"></canvas></figure>
        <figure class="p3a-chart"><figcaption>By channel</figcaption><canvas data-chart="channel" height="160"></canvas></figure>
    </div>
</section>
