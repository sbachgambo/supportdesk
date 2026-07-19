<?php
declare(strict_types=1);
/**
 * Dashboard. Staff (agent/admin) get the ticket workbench: KPI cards, a filterable
 * paginated queue, a create form, and a detail modal (thread + reply/note + status/
 * priority/assignment + canned responses + attachments). Customers get their own
 * tickets. All data-* driven via dashboard.js → /api; no inline handlers (D5).
 *
 * @var string $email @var string $role
 */
$isStaff = in_array($role, ['agent', 'admin'], true);
?>
<?php if ($isStaff): ?>
<section class="p3a-dash" data-view="staff-dash">
    <div class="p3a-dash-head">
        <h1>Tickets</h1>
        <button type="button" data-action="new-ticket-open">+ New ticket</button>
    </div>

    <div class="p3a-cards">
        <article class="p3a-card"><h2>Open</h2><p class="p3a-metric" data-bind="kpi-open">—</p></article>
        <article class="p3a-card"><h2>Pending</h2><p class="p3a-metric" data-bind="kpi-pending">—</p></article>
        <article class="p3a-card"><h2>Resolved (24h)</h2><p class="p3a-metric" data-bind="kpi-resolved">—</p></article>
        <article class="p3a-card"><h2>SLA breaches</h2><p class="p3a-metric" data-bind="kpi-breaches">—</p></article>
    </div>

    <div class="p3a-tabs" role="tablist">
        <button type="button" class="is-active" data-action="filter-tickets" data-status="">All</button>
        <button type="button" data-action="filter-tickets" data-status="open">Open</button>
        <button type="button" data-action="filter-tickets" data-status="pending">Pending</button>
        <button type="button" data-action="filter-tickets" data-status="resolved">Resolved</button>
        <button type="button" data-action="filter-tickets" data-status="closed">Closed</button>
    </div>

    <table class="p3a-queue">
        <thead><tr><th>Ticket</th><th>Subject</th><th>Customer</th><th>Priority</th><th>Status</th><th>Assignee</th><th>Updated</th></tr></thead>
        <tbody data-region="ticket-rows"><tr><td colspan="7">Loading…</td></tr></tbody>
    </table>
    <div class="p3a-pager">
        <button type="button" data-action="page-prev">‹ Prev</button>
        <span data-bind="page-info">–</span>
        <button type="button" data-action="page-next">Next ›</button>
    </div>
</section>

<?php // ── ticket detail modal ── ?>
<div class="p3a-modal" data-region="detail-modal" hidden>
  <div class="p3a-modal-box p3a-modal-wide">
    <div class="p3a-modal-head">
        <h2 data-bind="detail-title">Ticket</h2>
        <button type="button" class="p3a-close" data-action="close-detail" aria-label="Close">×</button>
    </div>
    <div class="p3a-detail-meta">
        <div><span class="p3a-lbl">Customer</span> <span data-bind="detail-customer"></span></div>
        <div><span class="p3a-lbl">Priority</span>
            <select data-action="change-priority">
                <option value="urgent">Urgent</option><option value="high">High</option>
                <option value="normal">Normal</option><option value="low">Low</option>
            </select>
        </div>
        <div><span class="p3a-lbl">Status</span>
            <select data-action="change-status">
                <option value="open">Open</option><option value="pending">Pending</option>
                <option value="resolved">Resolved</option><option value="closed">Closed</option>
            </select>
        </div>
        <div><span class="p3a-lbl">Assignee</span>
            <select data-action="assign-ticket" data-region="assignee-select"><option value="">Unassigned</option></select>
        </div>
        <div data-bind="detail-sla" class="p3a-sla"></div>
    </div>

    <div class="p3a-thread" data-region="thread"></div>

    <div class="p3a-composer">
        <div class="p3a-composer-tools">
            <select data-action="apply-canned" data-region="canned-select"><option value="">Insert canned response…</option></select>
            <label class="p3a-attach">Attach
                <input type="file" data-action="upload-attachment" hidden>
            </label>
            <span data-bind="attach-msg"></span>
        </div>
        <textarea data-region="composer-text" rows="4" placeholder="Write a reply to the customer, or an internal note…"></textarea>
        <div class="p3a-composer-actions">
            <button type="button" data-action="send-reply">Send reply</button>
            <button type="button" class="p3a-note-btn" data-action="add-note">Add internal note</button>
        </div>
    </div>
  </div>
</div>

<?php // ── new ticket modal ── ?>
<div class="p3a-modal" data-region="new-modal" hidden>
  <div class="p3a-modal-box">
    <div class="p3a-modal-head"><h2>New ticket</h2><button type="button" class="p3a-close" data-action="new-ticket-close" aria-label="Close">×</button></div>
    <form data-action="create-ticket" class="p3a-newticket">
        <label>Subject<input type="text" name="subject" required maxlength="200"></label>
        <label>Customer name<input type="text" name="customer_name" maxlength="120"></label>
        <label>Customer email<input type="email" name="customer_email" required maxlength="254"></label>
        <label>Priority
            <select name="priority"><option value="urgent">Urgent</option><option value="high">High</option><option value="normal" selected>Normal</option><option value="low">Low</option></select>
        </label>
        <label>Description<textarea name="description" required rows="4" maxlength="5000"></textarea></label>
        <p class="p3a-form-msg" data-bind="new-msg"></p>
        <button type="submit">Create ticket</button>
    </form>
  </div>
</div>

<?php else: ?>
<section class="p3a-dash" data-view="customer-dash">
    <h1>Your tickets</h1>
    <p><a href="<?= e(url('submit')) ?>">Submit a new ticket</a></p>
    <table class="p3a-queue">
        <thead><tr><th>Ticket</th><th>Subject</th><th>Priority</th><th>Status</th><th>Updated</th></tr></thead>
        <tbody data-region="my-ticket-rows"><tr><td colspan="5">Loading…</td></tr></tbody>
    </table>
    <div class="p3a-modal" data-region="my-detail-modal" hidden>
      <div class="p3a-modal-box p3a-modal-wide">
        <div class="p3a-modal-head"><h2 data-bind="my-detail-title">Ticket</h2><button type="button" class="p3a-close" data-action="close-my-detail" aria-label="Close">×</button></div>
        <div class="p3a-thread" data-region="my-thread"></div>
        <div class="p3a-composer">
            <textarea data-region="my-composer-text" rows="3" placeholder="Add a reply…"></textarea>
            <button type="button" data-action="my-reply">Send reply</button>
        </div>
      </div>
    </div>
</section>
<?php endif; ?>
