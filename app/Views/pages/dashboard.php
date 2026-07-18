<?php
declare(strict_types=1);
/**
 * Dashboard shell (Phase 4) — role-branched. Panels are populated by app.js via the
 * /api gateway (getMe, getPortalData). Ticket lists, reports, and KB fill these in
 * later phases. No inline handlers; everything is data-* driven.
 *
 * @var string $email @var string $role
 */
$isStaff = in_array($role, ['agent', 'admin'], true);
?>
<section class="p3a-dash" data-view="dashboard">
    <h1>
        <?php if ($isStaff): ?>
            Agent dashboard
        <?php else: ?>
            Your support portal
        <?php endif; ?>
    </h1>
    <p class="p3a-greeting">Signed in as <strong data-bind="user-name"><?= e($email) ?></strong>
        (<span data-bind="user-role"><?= e($role) ?></span>).</p>

    <?php if ($isStaff): ?>
        <div class="p3a-cards" data-region="kpis">
            <article class="p3a-card"><h2>Open</h2><p class="p3a-metric" data-bind="kpi-open">—</p></article>
            <article class="p3a-card"><h2>Pending</h2><p class="p3a-metric" data-bind="kpi-pending">—</p></article>
            <article class="p3a-card"><h2>Resolved (24h)</h2><p class="p3a-metric" data-bind="kpi-resolved">—</p></article>
            <article class="p3a-card"><h2>SLA breaches</h2><p class="p3a-metric" data-bind="kpi-breaches">—</p></article>
        </div>
        <p class="p3a-hint">Ticket queues arrive in Phase 5.</p>
    <?php else: ?>
        <div class="p3a-portal" data-region="portal">
            <p>Welcome to <strong data-bind="company-name">support</strong>.</p>
            <p><a href="<?= e(url('submit')) ?>">Submit a new ticket</a> ·
               <a href="<?= e(url('status')) ?>">Check a ticket's status</a></p>
        </div>
    <?php endif; ?>
</section>
