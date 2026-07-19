<?php
declare(strict_types=1);
/**
 * Dashboard SPA shell (ported layout). Topbar + sidebar + content area filled by
 * dashboard.js via /api (client-side view switching, no page reloads). Auth is the
 * hardened httpOnly session cookie; the CSRF token is in the <meta> for /api calls.
 *
 * @var string $company @var string $email @var string $name @var string $role @var bool $isAdmin
 */
$name = $name ?? $email;
$parts = array_slice(array_filter(explode(' ', trim((string) $name))), 0, 2);
$initials = strtoupper(implode('', array_map(static fn($w) => mb_substr((string) $w, 0, 1), $parts))) ?: 'A';
$logoSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
$themeToggle = '<button type="button" class="theme-btn" data-action="toggle-theme" title="Toggle dark/light mode">'
  . '<svg class="icon-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>'
  . '<svg class="icon-sun" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg></button>';
?>
<div class="app-shell" data-view="app">
  <div class="topbar">
    <div class="brand-logo">
      <div class="brand-logo-icon"><?= raw($logoSvg) ?></div>
      <span class="brand-logo-name"><?= e($company ?? 'SupportDesk') ?></span>
    </div>
    <div class="topbar-actions">
      <?= raw($themeToggle) ?>
      <div class="notif-wrap">
        <button type="button" class="bell-btn" data-action="toggle-notifications" title="Notifications">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <span class="notif-badge" data-bind="notif-badge" hidden>0</span>
        </button>
        <div class="notif-panel" data-region="notif-panel" hidden>
          <div class="notif-head">
            <span class="notif-head-title">Notifications</span>
            <button type="button" class="notif-mark-all" data-action="mark-all-notifs">Mark all read</button>
          </div>
          <div class="notif-list" data-region="notif-list"><div class="notif-empty">No notifications yet.</div></div>
        </div>
      </div>
      <div class="avatar-btn"><?= e($initials) ?></div>
    </div>
  </div>

  <div class="main-layout">
    <div class="sidebar">
      <div class="nav-section-label">Tickets</div>
      <div class="nav-item active" data-action="switch-view" data-view="dashboard">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Dashboard
      </div>
      <div class="nav-item" data-action="switch-view" data-view="all">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        All Tickets <span class="nav-count muted" data-bind="count-all">0</span>
      </div>
      <div class="nav-item" data-action="switch-view" data-view="mine">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        My Tickets <span class="nav-count accent" data-bind="count-mine">0</span>
      </div>
      <div class="nav-item" data-action="switch-view" data-view="breaches">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        SLA Breaches <span class="nav-count" data-bind="count-breaches">0</span>
      </div>
      <div class="nav-item" data-action="switch-view" data-view="resolved">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        Resolved <span class="nav-count muted" data-bind="count-resolved">0</span>
      </div>

      <div class="nav-section-label">Insights</div>
      <div class="nav-item" data-action="switch-view" data-view="kb">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        Knowledge Base
      </div>
      <div class="nav-item" data-action="switch-view" data-view="reports">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Reports
      </div>

      <?php if (!empty($isAdmin)): ?>
        <div class="nav-section-label">Administration</div>
        <div class="nav-item" data-action="switch-view" data-view="admin">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Admin Panel
        </div>
      <?php endif; ?>

      <div class="sidebar-footer">
        <div class="agent-info">
          <div class="avatar-btn" style="width:32px;height:32px;font-size:12px;"><?= e($initials) ?></div>
          <div class="agent-details">
            <div class="agent-name"><?= e($name) ?></div>
            <div class="agent-role"><?= e(ucfirst((string) $role)) ?></div>
          </div>
          <button type="button" class="signout-btn" data-action="open-change-pw" title="Change password">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </button>
          <form method="post" action="<?= e(url('logout')) ?>" style="margin:0;">
            <?= csrf_field() ?>
            <button type="submit" class="signout-btn" title="Sign out">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </button>
          </form>
        </div>
      </div>
    </div>

    <div class="content" data-region="content"><!-- views injected by dashboard.js --></div>
  </div>
</div>

<?php // ── ticket detail modal ── ?>
<div class="modal-overlay" data-region="ticket-modal" hidden>
  <div class="modal modal-wide">
    <div class="modal-header">
      <div style="min-width:0;">
        <div class="detail-chips">
          <span class="ticket-id" data-bind="d-id"></span>
          <span data-bind="d-status"></span>
          <span data-bind="d-priority"></span>
        </div>
        <div class="modal-title" data-bind="d-subject" style="margin-top:6px;"></div>
      </div>
      <button type="button" class="modal-close" data-action="close-ticket">&times;</button>
    </div>
    <div class="detail-grid">
      <div class="detail-main">
        <div class="thread" data-region="thread"></div>
        <div class="composer">
          <textarea data-region="composer" placeholder="Write a reply to the customer, or an internal note…"></textarea>
          <div class="composer-bar">
            <select data-action="apply-canned" data-region="canned-select"><option value="">Canned response…</option></select>
            <label class="btn-xs" style="cursor:pointer;">Attach<input type="file" data-action="upload-attachment" hidden></label>
            <span class="spacer"></span>
            <button type="button" class="btn-note" data-action="add-note">Internal note</button>
            <button type="button" class="btn-reply" data-action="send-reply">Reply</button>
          </div>
        </div>
      </div>
      <div class="detail-side">
        <div class="side-block"><label>Customer</label><div class="side-value" data-bind="d-customer"></div><div class="side-sub" data-bind="d-email"></div></div>
        <div class="side-block"><label>Status</label>
          <select class="side-select" data-action="change-status"><option value="open">Open</option><option value="pending">Pending</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select>
        </div>
        <div class="side-block"><label>Priority</label>
          <select class="side-select" data-action="change-priority"><option value="urgent">Urgent</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select>
        </div>
        <div class="side-block"><label>Assigned to</label>
          <select class="side-select" data-action="assign-ticket" data-region="assignee-select"><option value="">Unassigned</option></select>
        </div>
        <div class="side-block"><label>SLA</label>
          <div class="sla-row"><span>Response</span><span data-bind="d-sla-resp"></span></div>
          <div class="sla-row"><span>Resolution</span><span data-bind="d-sla-res"></span></div>
        </div>
        <div class="side-block" data-region="attachments"></div>
      </div>
    </div>
  </div>
</div>

<?php // ── new ticket modal ── ?>
<div class="modal-overlay" data-region="new-modal" hidden>
  <div class="modal">
    <div class="modal-header"><div class="modal-title">New ticket</div><button type="button" class="modal-close" data-action="close-new">&times;</button></div>
    <div class="modal-body">
      <form data-action="create-ticket">
        <div class="field"><label>Subject</label><input type="text" name="subject" required maxlength="200"></div>
        <div class="field"><label>Customer name</label><input type="text" name="customer_name" maxlength="120"></div>
        <div class="field"><label>Customer email</label><input type="email" name="customer_email" required maxlength="254"></div>
        <div class="field"><label>Priority</label><select name="priority"><option value="urgent">Urgent</option><option value="high">High</option><option value="normal" selected>Normal</option><option value="low">Low</option></select></div>
        <div class="field"><label>Description</label><textarea name="description" required rows="4" maxlength="5000"></textarea></div>
        <div class="alert error" data-bind="new-err"></div>
        <button type="submit" class="btn-submit">Create ticket</button>
      </form>
    </div>
  </div>
</div>

<?php // ── knowledge-base modal (view / create / edit) ── ?>
<div class="modal-overlay" data-region="kb-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" data-bind="kb-modal-title">Article</div>
      <button type="button" class="modal-close" data-action="close-kb">&times;</button>
    </div>
    <div class="modal-body" data-region="kb-modal-body"></div>
  </div>
</div>

<?php // ── change-password modal (also used to force a change when must_change_pw) ── ?>
<div class="modal-overlay" data-region="changepw-modal" hidden>
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title" data-bind="changepw-title">Change password</div>
      <button type="button" class="modal-close" data-action="close-change-pw" data-bind="changepw-close">&times;</button>
    </div>
    <div class="modal-body">
      <p class="page-sub" data-bind="changepw-lead" style="margin-bottom:14px;">Update the password for your account.</p>
      <form data-action="change-pw">
        <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="field"><label>New password <span class="pub-opt">(min 12 characters)</span></label><input type="password" name="new_password" required minlength="12" autocomplete="new-password"></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></div>
        <div class="alert error" data-bind="changepw-err"></div>
        <button type="submit" class="btn-submit">Update password</button>
      </form>
    </div>
  </div>
</div>

<div class="toast" data-region="toast"></div>
