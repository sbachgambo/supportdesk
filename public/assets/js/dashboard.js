/* P3A dashboard.js — the single-page app (ported from the SupportDesk prototype).
 * Self-contained: its own /api wrapper (cookie session + CSRF meta), client-side view
 * router, and renderers. No inline handlers; all interaction is data-action delegated;
 * all dynamic text uses textContent so ticket/message content can't inject markup. */
(function () {
    'use strict';
    var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
    var assetBase = '/assets/';

    // ── tiny helpers ──────────────────────────────────────────────────────────
    function q(sel, root) { return (root || document).querySelector(sel); }
    function qa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
    function bind(key, val) { qa('[data-bind="' + key + '"]').forEach(function (n) { n.textContent = val; }); }
    function region(key) { return q('[data-region="' + key + '"]'); }
    function esc(s) { return String(s == null ? '' : s); }
    function shortDate(s) { return esc(s).replace('T', ' ').slice(0, 16); }
    function initials(name) { return (esc(name).split(' ').filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('') || '?').toUpperCase(); }

    async function api(action, payload) {
        var res = await fetch('/api', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: action, payload: payload || {}, csrf: CSRF }) });
        try { return await res.json(); } catch (e) { return { ok: false, error: 'Server error.' }; }
    }
    var toastTimer;
    function toast(msg, kind) {
        var t = region('toast'); if (!t) { return; }
        t.textContent = msg; t.className = 'toast show' + (kind ? ' ' + kind : '');
        clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.className = 'toast'; }, 2600);
    }

    // ── state ─────────────────────────────────────────────────────────────────
    var state = { view: 'dashboard', ticket: null, agents: [], companies: [], canned: [], page: {}, cache: {}, forcedPw: false, me: null, kbEditId: null, kbCurrent: null, categoriesAdmin: [], companiesAdmin: [], rulesAdmin: [], ruleEditId: null, entityKind: null, entityId: null };

    // ── boot ──────────────────────────────────────────────────────────────────
    async function boot() {
        var d = await api('getDashboardData');
        if (d && d.ok) { state.agents = d.data.agents || []; state.companies = d.data.companies || []; }
        var canned = await api('getCannedResponses');
        if (canned && canned.ok) { state.canned = canned.data.responses || []; }
        refreshCounts();
        refreshBadge();
        switchView('dashboard');
        // Force a password change if the account was created/reset with a temp password.
        var me = await api('getMe');
        if (me && me.ok && me.data) {
            state.me = me.data;
            if (me.data.must_change_pw) { openChangePw(true); }
        }
    }

    // ── change password (self-service + forced must_change_pw flow) ─────────────
    function openChangePw(forced) {
        state.forcedPw = !!forced;
        var m = region('changepw-modal');
        q('[data-bind="changepw-err"]').classList.remove('show');
        var form = q('form[data-action="change-pw"]'); if (form) { form.reset(); }
        bind('changepw-title', forced ? 'Choose a new password' : 'Change password');
        bind('changepw-lead', forced
            ? 'Your account was set up with a temporary password. Choose a new one to continue.'
            : 'Update the password for your account.');
        q('[data-bind="changepw-close"]').hidden = !!forced;
        m.hidden = false;
    }
    function closeChangePw() { if (!state.forcedPw) { region('changepw-modal').hidden = true; } }
    async function submitChangePw(form) {
        var err = q('[data-bind="changepw-err"]');
        err.classList.remove('show');
        var f = {}; new FormData(form).forEach(function (v, k) { f[k] = v; });
        if (f.new_password !== f.confirm_password) { err.textContent = 'New passwords do not match.'; err.classList.add('show'); return; }
        var r = await api('changeMyPassword', { current_password: f.current_password, new_password: f.new_password });
        if (r && r.ok) {
            state.forcedPw = false;
            region('changepw-modal').hidden = true;
            toast('Password updated', 'success');
        } else {
            err.textContent = (r && r.error) || 'Could not update password.'; err.classList.add('show');
        }
    }

    async function refreshCounts() {
        var d = await api('getDashboardData');
        if (d && d.ok) { bind('count-breaches', d.data.kpis.breaches); }
        var all = await api('getTicketsForView', { view: 'all' });
        var mine = await api('getTicketsForView', { view: 'mine' });
        var resolved = await api('getTicketsForView', { view: 'resolved' });
        bind('count-all', all.ok ? all.data.tickets.length : 0);
        bind('count-mine', mine.ok ? mine.data.tickets.length : 0);
        bind('count-resolved', resolved.ok ? resolved.data.tickets.length : 0);
    }

    // ── view router ───────────────────────────────────────────────────────────
    function switchView(view) {
        state.view = view;
        qa('[data-action="switch-view"]').forEach(function (n) { n.classList.toggle('active', n.getAttribute('data-view') === view); });
        if (view === 'dashboard') { renderDashboard(); }
        else if (view === 'kb') { renderKB(); }
        else if (view === 'reports') { renderReports(); }
        else if (view === 'admin') { renderAdmin(); }
        else { renderList(view); }
    }

    // ── dashboard ─────────────────────────────────────────────────────────────
    async function renderDashboard() {
        var c = region('content'); c.textContent = '';
        c.appendChild(header('Dashboard', 'Overview of your support queue', true));
        var d = await api('getDashboardData');
        var k = (d && d.ok) ? d.data.kpis : { open: 0, pending: 0, resolved_24h: 0, breaches: 0 };
        var grid = el('div', 'stats-grid');
        grid.appendChild(statCard('Open', (k.open + k.pending), 'Open & pending', '#EEF0FE', '#4057F5'));
        grid.appendChild(statCard('Resolved (24h)', k.resolved_24h, 'Last 24 hours', '#ECFDF3', '#17B26A'));
        grid.appendChild(statCard('SLA breaches', k.breaches, 'Still open', '#FEF3F2', '#F04438'));
        grid.appendChild(statCard('Avg first response', (d && d.ok && d.data.avg_response_hours != null) ? d.data.avg_response_hours + 'h' : '—', 'Across responded', '#FEF6EE', '#F79009'));
        c.appendChild(grid);
        // recent tickets
        var all = await api('getTicketsForView', { view: 'all' });
        var recent = (all.ok ? all.data.tickets : []).slice(0, 8);
        c.appendChild(ticketTable('Recent tickets', recent, false));
    }
    function statCard(label, value, note, bg, fg) {
        var card = el('div', 'stat-card');
        var head = el('div', 'stat-card-header');
        head.appendChild(el('span', 'stat-card-label', label));
        var icon = el('div', 'stat-card-icon'); icon.style.background = bg; icon.style.color = fg;
        icon.appendChild(dotSvg(fg));
        head.appendChild(icon);
        card.appendChild(head);
        card.appendChild(el('div', 'stat-card-value', value));
        card.appendChild(el('div', 'stat-card-note', note));
        return card;
    }
    function dotSvg(color) { var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2'); var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle'); c.setAttribute('cx', '12'); c.setAttribute('cy', '12'); c.setAttribute('r', '9'); s.appendChild(c); return s; }

    // ── ticket lists ──────────────────────────────────────────────────────────
    var VIEW_TITLES = { all: 'All Tickets', mine: 'My Tickets', breaches: 'SLA Breaches', resolved: 'Resolved' };
    async function renderList(view) {
        var c = region('content'); c.textContent = '';
        c.appendChild(header(VIEW_TITLES[view] || 'Tickets', '', true));
        var r = await api('getTicketsForView', { view: view });
        var rows = r.ok ? r.data.tickets : [];
        state.cache[view] = rows;
        if (!state.page[view]) { state.page[view] = 1; }
        c.appendChild(ticketTable(VIEW_TITLES[view] || 'Tickets', rows, true, view));
    }
    function ticketTable(title, rows, paginate, view) {
        var card = el('div', 'table-card');
        var head = el('div', 'table-header');
        head.appendChild(el('span', 'table-header-title', title));
        head.appendChild(el('span', 'table-header-count', rows.length + ' ticket' + (rows.length === 1 ? '' : 's')));
        card.appendChild(head);
        if (!rows.length) { card.appendChild(emptyState('No tickets here.')); return card; }

        var perPage = 10;
        var page = paginate ? (state.page[view] || 1) : 1;
        var slice = paginate ? rows.slice((page - 1) * perPage, page * perPage) : rows;

        var table = el('table');
        var thead = el('thead'); var htr = el('tr');
        ['Ticket', 'Subject', 'Customer', 'Priority', 'Status', 'SLA', 'Agent'].forEach(function (h) { htr.appendChild(el('th', null, h)); });
        thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        slice.forEach(function (t) { tb.appendChild(ticketRow(t)); });
        table.appendChild(tb); card.appendChild(table);

        if (paginate && rows.length > perPage) { card.appendChild(pager(rows.length, perPage, page, view)); }
        return card;
    }
    function ticketRow(t) {
        var tr = el('tr'); tr.setAttribute('data-action', 'open-ticket'); tr.setAttribute('data-ticket', t.ticket_id);
        tr.appendChild(el('td', null, '')).appendChild(el('span', 'ticket-id', t.ticket_id));
        var subCell = el('td');
        subCell.appendChild(el('div', 'ticket-subject', t.subject));
        tr.appendChild(subCell);
        tr.appendChild(el('td', null, t.customer_name || t.customer_email));
        var pc = el('td'); pc.appendChild(priorityTag(t.priority)); tr.appendChild(pc);
        var sc = el('td'); sc.appendChild(statusChip(t.status)); tr.appendChild(sc);
        var slc = el('td'); slc.appendChild(slaCell(t.sla_resolution_status)); tr.appendChild(slc);
        var ac = el('td');
        if (t.assigned_to) { ac.appendChild(el('span', 'mini-avatar', initials(t.assigned_to.split('@')[0]))); } else { ac.appendChild(el('span', 'ticket-customer', '—')); }
        tr.appendChild(ac);
        return tr;
    }
    function priorityTag(p) { var s = el('span', 'priority-tag'); s.appendChild(el('span', 'dot dot-' + p)); s.appendChild(document.createTextNode(p)); return s; }
    function statusChip(s) { return el('span', 'status-chip chip-' + s, s); }
    function slaCell(s) { var e = el('span', 'sla-cell sla-' + s, s); return e; }
    function pager(total, perPage, page, view) {
        var pages = Math.ceil(total / perPage);
        var wrap = el('div', 'pager');
        wrap.appendChild(el('span', 'pager-info', 'Showing ' + (((page - 1) * perPage) + 1) + '–' + Math.min(page * perPage, total) + ' of ' + total));
        var btns = el('div', 'pager-btns');
        var prev = el('button', 'pager-btn', '‹'); prev.disabled = page <= 1; prev.setAttribute('data-action', 'page'); prev.setAttribute('data-view', view); prev.setAttribute('data-page', page - 1); btns.appendChild(prev);
        for (var i = 1; i <= pages; i++) { var b = el('button', 'pager-btn' + (i === page ? ' active' : ''), String(i)); b.setAttribute('data-action', 'page'); b.setAttribute('data-view', view); b.setAttribute('data-page', i); btns.appendChild(b); }
        var next = el('button', 'pager-btn', '›'); next.disabled = page >= pages; next.setAttribute('data-action', 'page'); next.setAttribute('data-view', view); next.setAttribute('data-page', page + 1); btns.appendChild(next);
        wrap.appendChild(btns);
        return wrap;
    }

    // ── ticket detail ─────────────────────────────────────────────────────────
    async function openTicket(id) {
        var r = await api('getTicket', { ticket_id: id });
        if (!r || !r.ok) { toast('Could not open ticket', 'danger'); return; }
        state.ticket = r.data.ticket; var t = r.data.ticket;
        bind('d-id', t.ticket_id); bind('d-subject', t.subject);
        var st = region('ticket-modal');
        q('[data-bind="d-status"]', st).className = ''; q('[data-bind="d-status"]', st).appendChild ? null : null;
        replaceChip('d-status', statusChip(t.status));
        replaceChip('d-priority', priorityTag(t.priority));
        bind('d-customer', t.customer_name || '—'); bind('d-email', t.customer_email);
        bind('d-company', t.company || '—');
        bind('d-sla-resp', t.sla_response_status); bind('d-sla-res', t.sla_resolution_status);
        q('[data-action="change-status"]').value = t.status;
        q('[data-action="change-priority"]').value = t.priority;
        var asel = region('assignee-select'); asel.textContent = ''; asel.appendChild(opt('', 'Unassigned'));
        state.agents.forEach(function (a) { var o = opt(a.email, a.name); if (a.email === t.assigned_to) { o.selected = true; } asel.appendChild(o); });
        var csel = region('canned-select'); csel.textContent = ''; csel.appendChild(opt('', 'Canned response…'));
        state.canned.forEach(function (cn) { csel.appendChild(opt(cn.response_id, cn.title)); });
        // thread
        var thread = region('thread'); thread.textContent = '';
        r.data.messages.forEach(function (m) { thread.appendChild(messageEl(m)); });
        thread.scrollTop = thread.scrollHeight;
        // attachments
        var at = region('attachments'); at.textContent = '';
        if ((r.data.attachments || []).length) {
            at.appendChild(el('label', null, 'Attachments'));
            r.data.attachments.forEach(function (a) { var link = el('a', 'side-value', '📎 ' + a.original_name); link.href = '/download/' + a.id; link.style.display = 'block'; at.appendChild(link); });
        }
        region('ticket-modal').hidden = false;
    }
    function replaceChip(key, node) { var host = q('[data-bind="' + key + '"]'); if (!host) { return; } host.textContent = ''; host.appendChild(node); }
    function opt(v, t) { var o = document.createElement('option'); o.value = v; o.textContent = t; return o; }
    function messageEl(m) {
        var wrap = el('div', 'msg msg-' + m.from_type);
        var meta = el('div', 'msg-meta');
        meta.appendChild(el('span', null, (m.from_name || m.from_type) + ' · ' + shortDate(m.created_at)));
        if (Number(m.is_internal)) { meta.appendChild(el('span', 'msg-internal-tag', 'Internal')); }
        wrap.appendChild(meta);
        wrap.appendChild(el('div', 'msg-bubble', m.text));
        return wrap;
    }
    async function compose(internal) {
        if (!state.ticket) { return; }
        var box = region('composer'); var text = box.value.trim(); if (!text) { return; }
        var r = await api(internal ? 'addInternalNote' : 'sendReply', { ticket_id: state.ticket.ticket_id, text: text });
        if (r && r.ok) { box.value = ''; toast(internal ? 'Note added' : 'Reply sent', 'success'); openTicket(state.ticket.ticket_id); refreshCounts(); }
        else { toast((r && r.error) || 'Failed', 'danger'); }
    }
    async function changeField(action, key, value) {
        if (!state.ticket) { return; }
        var p = { ticket_id: state.ticket.ticket_id }; p[key] = value;
        var r = await api(action, p);
        if (r && r.ok) { toast('Updated', 'success'); openTicket(state.ticket.ticket_id); refreshCounts(); if (state.view !== 'dashboard') { renderList(state.view); } }
        else { toast((r && r.error) || 'Failed', 'danger'); }
    }
    async function applyCanned(id) { if (!id || !state.ticket) { return; } var r = await api('applyCannedResponse', { ticket_id: state.ticket.ticket_id, response_id: id }); if (r && r.ok) { region('composer').value = r.data.body; } }
    async function upload(input) {
        if (!input.files.length || !state.ticket) { return; }
        var fd = new FormData(); fd.append('file', input.files[0]); fd.append('ticket_id', state.ticket.ticket_id); fd.append('csrf', CSRF);
        var res = await fetch('/upload', { method: 'POST', body: fd }); var j = await res.json();
        toast(j.ok ? ('Attached ' + j.data.original_name) : (j.error || 'Upload failed'), j.ok ? 'success' : 'danger');
        if (j.ok) { openTicket(state.ticket.ticket_id); } input.value = '';
    }
    async function createTicket(form) {
        var payload = {}; new FormData(form).forEach(function (v, k) { payload[k] = v; });
        var r = await api('createTicket', payload);
        if (r && r.ok) { region('new-modal').hidden = true; form.reset(); toast('Ticket created', 'success'); refreshCounts(); switchView(state.view); }
        else { var errBox = q('[data-bind="new-err"]', region('new-modal')); errBox.textContent = (r && r.error) || 'Could not create'; errBox.classList.add('show'); }
    }

    // ── notifications ─────────────────────────────────────────────────────────
    async function refreshBadge() { var r = await api('getUnreadCount'); if (!r || !r.ok) { return; } var b = q('[data-bind="notif-badge"]'); if (b) { b.textContent = r.data.unread; b.hidden = r.data.unread === 0; } }
    async function toggleNotifs() {
        var p = region('notif-panel'); if (!p.hidden) { p.hidden = true; return; }
        var r = await api('getNotifications'); var list = region('notif-list'); list.textContent = '';
        if (r && r.ok && r.data.notifications.length) {
            r.data.notifications.forEach(function (n) {
                var item = el('div', 'notif-item' + (Number(n.is_read) ? ' read' : '')); item.setAttribute('data-action', 'read-notif'); item.setAttribute('data-notif', n.notif_id);
                item.appendChild(el('span', 'notif-dot'));
                var body = el('div'); body.appendChild(el('div', 'notif-msg', n.message)); body.appendChild(el('div', 'notif-time', shortDate(n.created_at))); item.appendChild(body);
                list.appendChild(item);
            });
        } else { list.appendChild(el('div', 'notif-empty', 'No notifications yet.')); }
        p.hidden = false;
    }

    // ── knowledge base ────────────────────────────────────────────────────────
    async function renderKB() {
        var c = region('content'); c.textContent = '';
        var h = header('Knowledge Base', 'Help articles for your team and customers', false);
        var nb = el('button', 'btn-new', '+ New article'); nb.setAttribute('data-action', 'new-kb'); h.appendChild(nb);
        c.appendChild(h);
        var r = await api('getKbArticles', {});
        var articles = (r && r.ok) ? r.data.articles : [];
        var grid = el('div', 'kb-grid');
        if (!articles.length) { grid.appendChild(emptyState('No articles yet. Click “New article” to write your first one.')); }
        articles.forEach(function (a) {
            var card = el('div', 'kb-card'); card.setAttribute('data-action', 'open-kb'); card.setAttribute('data-id', a.article_id);
            var top = el('div', 'kb-card-cat'); top.appendChild(el('span', 'ticket-customer', a.category || 'General')); top.appendChild(el('span', 'vis-chip vis-' + a.visibility, a.visibility)); card.appendChild(top);
            card.appendChild(el('div', 'kb-card-title', a.title));
            var meta = el('div', 'kb-card-meta'); meta.appendChild(el('span', null, a.author)); meta.appendChild(el('span', null, a.view_count + ' views')); card.appendChild(meta);
            grid.appendChild(card);
        });
        c.appendChild(grid);
    }
    async function openKb(id) {
        var r = await api('getKbArticle', { article_id: id });
        if (!r || !r.ok) { toast('Could not load article', 'danger'); return; }
        var a = r.data.article; state.kbCurrent = a;
        bind('kb-modal-title', a.title);
        var body = region('kb-modal-body'); body.textContent = '';
        var top = el('div', 'kb-card-cat'); top.appendChild(el('span', 'ticket-customer', a.category || 'General')); top.appendChild(el('span', 'vis-chip vis-' + a.visibility, a.visibility)); body.appendChild(top);
        body.appendChild(el('div', 'kb-body', a.body));
        var meta = el('div', 'kb-card-meta'); meta.appendChild(el('span', null, 'By ' + a.author)); meta.appendChild(el('span', null, a.view_count + ' views')); body.appendChild(meta);
        var actions = el('div', 'row-actions'); actions.style.marginTop = '16px';
        var edit = el('button', 'btn-mini', 'Edit'); edit.setAttribute('data-action', 'edit-kb'); actions.appendChild(edit);
        if (state.me && state.me.role === 'admin') {
            var del = el('button', 'btn-mini btn-danger', 'Delete'); del.setAttribute('data-action', 'delete-kb'); del.setAttribute('data-id', a.article_id); del.setAttribute('data-title', a.title); actions.appendChild(del);
        }
        body.appendChild(actions);
        region('kb-modal').hidden = false;
    }
    function kbEditor(a) {
        state.kbEditId = a ? a.article_id : null;
        bind('kb-modal-title', a ? 'Edit article' : 'New article');
        var body = region('kb-modal-body'); body.textContent = '';
        body.appendChild(field('Title', inp({ type: 'text', maxlength: '150', 'data-kb': 'title', value: a ? a.title : '' })));
        body.appendChild(field('Category', inp({ type: 'text', maxlength: '60', placeholder: 'e.g. Billing', 'data-kb': 'category', value: a ? (a.category || '') : '' })));
        body.appendChild(field('Visibility', sel([['internal', 'Internal — staff only'], ['public', 'Public — customers can see it']], 'data-kb', 'visibility', a ? a.visibility : 'internal')));
        var ta = document.createElement('textarea'); ta.setAttribute('data-kb', 'body'); ta.rows = 8; ta.value = a ? a.body : '';
        body.appendChild(field('Body', ta));
        var err = el('div', 'alert error'); err.setAttribute('data-bind', 'kb-err'); body.appendChild(err);
        var save = el('button', 'btn-submit', a ? 'Save changes' : 'Publish article'); save.setAttribute('data-action', 'save-kb'); body.appendChild(save);
        region('kb-modal').hidden = false;
    }
    async function saveKb() {
        var p = {}; qa('[data-kb]').forEach(function (i) { p[i.getAttribute('data-kb')] = i.value; });
        var err = q('[data-bind="kb-err"]'); if (err) { err.classList.remove('show'); }
        if (!p.title || !p.title.trim()) { if (err) { err.textContent = 'Title is required.'; err.classList.add('show'); } return; }
        if (!p.body || !p.body.trim()) { if (err) { err.textContent = 'Body is required.'; err.classList.add('show'); } return; }
        var editing = state.kbEditId;
        if (editing) { p.article_id = editing; }
        var r = await api(editing ? 'editKbArticle' : 'publishKbArticle', p);
        if (r && r.ok) { region('kb-modal').hidden = true; toast(editing ? 'Article updated' : 'Article published', 'success'); renderKB(); }
        else if (err) { err.textContent = (r && r.error) || 'Could not save the article.'; err.classList.add('show'); }
    }
    async function deleteKb(id, title) {
        if (!window.confirm('Delete article “' + title + '”? This cannot be undone.')) { return; }
        var r = await api('deleteKbArticle', { article_id: id });
        if (r && r.ok) { region('kb-modal').hidden = true; toast('Article deleted', 'success'); renderKB(); }
        else { toast((r && r.error) || 'Delete failed', 'danger'); }
    }

    // ── reports ───────────────────────────────────────────────────────────────
    var reportPeriod = 30;
    function loadChart() { return new Promise(function (res, rej) { if (window.Chart) { return res(); } var s = document.createElement('script'); s.src = assetBase + 'vendor/chart.min.js'; s.onload = res; s.onerror = rej; document.head.appendChild(s); }); }
    async function renderReports() {
        var c = region('content'); c.textContent = '';
        var h = header('Reports', 'Support performance analytics', false);
        var sw = el('div', 'period-switch');
        [7, 30, 90].forEach(function (p) { var b = el('button', 'period-btn' + (p === reportPeriod ? ' active' : ''), p + ' days'); b.setAttribute('data-action', 'report-period'); b.setAttribute('data-period', p); sw.appendChild(b); });
        h.appendChild(sw);
        c.appendChild(h);
        var r = await api('getReports', { period: reportPeriod });
        if (!r || !r.ok) { c.appendChild(emptyState('Could not load reports.')); return; }
        var d = r.data;
        var grid = el('div', 'stats-grid');
        grid.appendChild(statCard('Created', d.kpis.created, 'in period', '#EEF0FE', '#4057F5'));
        grid.appendChild(statCard('Resolved', d.kpis.resolved, 'in period', '#ECFDF3', '#17B26A'));
        grid.appendChild(statCard('Avg resolution', d.kpis.avg_resolution_hours != null ? d.kpis.avg_resolution_hours + 'h' : '—', 'hours', '#FEF6EE', '#F79009'));
        grid.appendChild(statCard('SLA compliance', d.kpis.sla_compliance_pct != null ? d.kpis.sla_compliance_pct + '%' : '—', 'resolution', '#EEF0FE', '#4057F5'));
        c.appendChild(grid);
        var row = el('div', 'charts-grid');
        row.appendChild(chartCard('Volume by day', 'vol')); row.appendChild(chartCard('By status', 'status'));
        c.appendChild(row);
        var row2 = el('div', 'charts-grid-row2');
        row2.appendChild(chartCard('By priority', 'prio')); row2.appendChild(chartCard('By channel', 'chan'));
        c.appendChild(row2);
        var exp = el('a', 'btn-secondary', 'Export CSV'); exp.href = '/export/tickets.csv?period=' + reportPeriod; c.appendChild(exp);
        try {
            await loadChart();
            drawLine('vol', d.volume.map(function (p) { return p.date.slice(5); }), d.volume.map(function (p) { return p.count; }));
            drawBar('status', d.distributions.status); drawBar('prio', d.distributions.priority); drawBar('chan', d.distributions.channel);
        } catch (e) { /* charts unavailable */ }
    }
    function chartCard(title, key) { var card = el('div', 'chart-card'); card.appendChild(el('div', 'chart-card-title', title)); var wrap = el('div', 'chart-wrap'); var cv = document.createElement('canvas'); cv.setAttribute('data-chart', key); wrap.appendChild(cv); card.appendChild(wrap); return card; }
    var charts = {};
    function theme() { var dark = document.documentElement.getAttribute('data-theme') === 'dark'; return { text: dark ? '#B4B9C8' : '#4B5060', grid: dark ? '#2B2F3E' : '#E3E6EF', palette: ['#4057F5', '#17B26A', '#F79009', '#F04438', '#7B8FFA', '#9499A8'] }; }
    function drawLine(key, labels, data) { var cv = q('[data-chart="' + key + '"]'); if (!cv) { return; } if (charts[key]) { charts[key].destroy(); } var t = theme(); charts[key] = new window.Chart(cv, { type: 'line', data: { labels: labels, datasets: [{ data: data, borderColor: '#4057F5', backgroundColor: 'rgba(64,87,245,.12)', fill: true, tension: .3 }] }, options: chartOpts(t) }); }
    function drawBar(key, dist) { var cv = q('[data-chart="' + key + '"]'); if (!cv) { return; } if (charts[key]) { charts[key].destroy(); } var t = theme(); charts[key] = new window.Chart(cv, { type: 'bar', data: { labels: Object.keys(dist), datasets: [{ data: Object.values(dist), backgroundColor: t.palette }] }, options: chartOpts(t) }); }
    function chartOpts(t) { return { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: t.text }, grid: { color: t.grid } }, y: { beginAtZero: true, ticks: { color: t.text, precision: 0 }, grid: { color: t.grid } } } }; }

    // ── admin (full: agents + categories + SLA + system + rules + backup) ─────
    var adminTab = 'agents';
    async function renderAdmin() {
        var c = region('content'); c.textContent = '';
        c.appendChild(header('Admin Panel', 'Manage your helpdesk', false));
        var tabs = el('div', 'tabs');
        [['agents', 'Agents'], ['categories', 'Categories'], ['companies', 'Companies'], ['sla', 'SLA Targets'], ['config', 'System'], ['rules', 'Routing Rules'], ['backup', 'Backup & Data']].forEach(function (pair) {
            var t = el('div', 'tab' + (pair[0] === adminTab ? ' active' : ''), pair[1]); t.setAttribute('data-action', 'admin-tab'); t.setAttribute('data-tab', pair[0]); tabs.appendChild(t);
        });
        c.appendChild(tabs);
        var body = el('div'); body.setAttribute('data-region', 'admin-body'); c.appendChild(body);
        if (adminTab === 'agents') { renderAgents(body); }
        else if (adminTab === 'categories') { renderCategories(body); }
        else if (adminTab === 'companies') { renderCompanies(body); }
        else if (adminTab === 'sla') { renderSla(body); }
        else if (adminTab === 'config') { renderConfig(body); }
        else if (adminTab === 'rules') { renderRules(body); }
        else { renderBackup(body); }
    }

    function field(labelText, input) { var f = el('div', 'field'); f.appendChild(el('label', null, labelText)); f.appendChild(input); return f; }
    function inp(attrs) { var i = document.createElement('input'); Object.keys(attrs || {}).forEach(function (k) { i.setAttribute(k, attrs[k]); }); return i; }
    function sel(options, dataAttr, dataVal, current) { var s = document.createElement('select'); if (dataAttr) { s.setAttribute(dataAttr, dataVal); } options.forEach(function (o) { var op = el('option', null, o[1]); op.value = o[0]; if (o[0] === current) { op.selected = true; } s.appendChild(op); }); return s; }

    // ── Agents: add + activate/deactivate + reset password + delete ───────────
    async function renderAgents(body) {
        var r = await api('listUsers', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }

        var form = el('div', 'admin-form');
        form.appendChild(el('div', 'admin-form-title', 'Add a team member'));
        var row = el('div', 'admin-form-row');
        row.appendChild(inp({ type: 'text', placeholder: 'Full name', 'data-newuser': 'name' }));
        row.appendChild(inp({ type: 'email', placeholder: 'Email address', 'data-newuser': 'email' }));
        row.appendChild(sel([['agent', 'Agent'], ['admin', 'Admin']], 'data-newuser', 'role', 'agent'));
        row.appendChild(inp({ type: 'password', placeholder: 'Temp password', 'data-newuser': 'password', autocomplete: 'new-password' }));
        var add = el('button', 'btn-submit', 'Add'); add.setAttribute('data-action', 'add-user'); row.appendChild(add);
        form.appendChild(row);
        form.appendChild(el('div', 'admin-form-hint', 'They will be required to change this password at first sign-in.'));
        body.appendChild(form);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Email', 'Role', 'Active', 'Actions'].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        r.data.users.forEach(function (u) {
            var tr = el('tr');
            tr.appendChild(el('td', null, u.name));
            tr.appendChild(el('td', null, u.email));
            var rc = el('td'); rc.appendChild(el('span', 'role-chip role-' + (u.role === 'admin' ? 'admin' : 'agent'), u.role)); tr.appendChild(rc);
            tr.appendChild(el('td', null, Number(u.active) ? 'Yes' : 'No'));
            var ac = el('td', 'row-actions');
            var tgl = el('button', 'btn-mini', Number(u.active) ? 'Deactivate' : 'Activate');
            tgl.setAttribute('data-action', 'toggle-user'); tgl.setAttribute('data-id', u.id); tgl.setAttribute('data-active', Number(u.active) ? '1' : '0'); ac.appendChild(tgl);
            var pw = el('button', 'btn-mini', 'Reset PW'); pw.setAttribute('data-action', 'reset-user-pw'); pw.setAttribute('data-id', u.id); pw.setAttribute('data-email', u.email); ac.appendChild(pw);
            var del = el('button', 'btn-mini btn-danger', 'Delete'); del.setAttribute('data-action', 'delete-user'); del.setAttribute('data-id', u.id); del.setAttribute('data-email', u.email); ac.appendChild(del);
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);
    }
    async function addUser() {
        var p = {}; qa('[data-newuser]').forEach(function (i) { p[i.getAttribute('data-newuser')] = i.value; });
        var r = await api('createUser', p);
        toast(r && r.ok ? 'Team member added' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); refreshCounts(); }
    }
    async function toggleUser(id, isActive) {
        var r = await api(isActive ? 'deactivateUser' : 'activateUser', { id: id });
        toast(r && r.ok ? 'Updated' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    async function resetUserPw(id, email) {
        var pw = window.prompt('New password for ' + email + ':');
        if (pw == null) { return; }
        var r = await api('adminResetPassword', { id: id, password: pw });
        toast(r && r.ok ? 'Password reset' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
    }
    async function deleteUser(id, email) {
        if (!window.confirm('Delete ' + email + '? This cannot be undone.')) { return; }
        var r = await api('deleteUser', { id: id });
        toast(r && r.ok ? 'Deleted' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }

    // ── Categories: add + toggle active + delete ──────────────────────────────
    async function renderCategories(body) {
        var r = await api('listCategories', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }
        var cats = r.data.categories || [];
        state.categoriesAdmin = cats;
        var tops = cats.filter(function (c) { return !c.parent_id; });

        var form = el('div', 'admin-form');
        form.appendChild(el('div', 'admin-form-title', 'Add a category'));
        var row = el('div', 'admin-form-row');
        row.appendChild(inp({ type: 'text', placeholder: 'Category name', 'data-newcat': 'name' }));
        row.appendChild(inp({ type: 'color', value: '#4057F5', 'data-newcat': 'color' }));
        var parentOpts = [['', 'Top level']].concat(tops.map(function (c) { return [c.category_id, c.name]; }));
        row.appendChild(sel(parentOpts, 'data-newcat', 'parent_id', ''));
        var add = el('button', 'btn-submit', 'Add'); add.setAttribute('data-action', 'add-category'); row.appendChild(add);
        form.appendChild(row);
        body.appendChild(form);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Parent', 'Colour', 'Active', 'Actions'].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var byId = {}; cats.forEach(function (c) { byId[c.category_id] = c; });
        // Order children directly under their parent so the hierarchy reads top-down.
        var ordered = [];
        cats.filter(function (c) { return !c.parent_id; }).forEach(function (p) {
            ordered.push(p);
            cats.forEach(function (c) { if (c.parent_id === p.category_id) { ordered.push(c); } });
        });
        cats.forEach(function (c) { if (ordered.indexOf(c) === -1) { ordered.push(c); } });
        var tb = el('tbody');
        ordered.forEach(function (c) {
            var tr = el('tr'); if (c.parent_id) { tr.className = 'cat-child'; }
            tr.appendChild(el('td', null, (c.parent_id ? '↳ ' : '') + c.name));
            tr.appendChild(el('td', null, c.parent_id && byId[c.parent_id] ? byId[c.parent_id].name : '—'));
            var cc = el('td'); var sw = el('span', 'cat-swatch'); sw.style.background = c.color || '#4057F5'; cc.appendChild(sw); cc.appendChild(el('span', null, ' ' + (c.color || ''))); tr.appendChild(cc);
            tr.appendChild(el('td', null, Number(c.active) ? 'Yes' : 'No'));
            var ac = el('td', 'row-actions');
            var edit = el('button', 'btn-mini', 'Edit'); edit.setAttribute('data-action', 'edit-category'); edit.setAttribute('data-id', c.category_id); ac.appendChild(edit);
            var tgl = el('button', 'btn-mini', Number(c.active) ? 'Disable' : 'Enable'); tgl.setAttribute('data-action', 'toggle-category'); tgl.setAttribute('data-id', c.category_id); tgl.setAttribute('data-active', Number(c.active) ? '1' : '0'); ac.appendChild(tgl);
            var del = el('button', 'btn-mini btn-danger', 'Delete'); del.setAttribute('data-action', 'delete-category'); del.setAttribute('data-id', c.category_id); del.setAttribute('data-name', c.name); ac.appendChild(del);
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);
    }
    async function addCategory() {
        var p = {}; qa('[data-newcat]').forEach(function (i) { p[i.getAttribute('data-newcat')] = i.value; });
        var r = await api('createCategory', p);
        toast(r && r.ok ? 'Category added' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    async function toggleCategory(id, isActive) {
        var r = await api('updateCategory', { category_id: id, active: isActive ? 0 : 1 });
        toast(r && r.ok ? 'Updated' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    async function deleteCategory(id, name) {
        if (!window.confirm('Delete category "' + name + '"?')) { return; }
        var r = await api('deleteCategory', { category_id: id });
        toast(r && r.ok ? 'Deleted' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    function editCategory(id) { var c = (state.categoriesAdmin || []).find(function (x) { return x.category_id === id; }); if (c) { entityEditor('category', c); } }

    // ── Companies (client/institution suggestion list) ────────────────────────
    async function renderCompanies(body) {
        var r = await api('listCompanies', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }
        state.companiesAdmin = r.data.companies || [];
        var form = el('div', 'admin-form');
        form.appendChild(el('div', 'admin-form-title', 'Add a company / institution'));
        var row = el('div', 'admin-form-row');
        row.appendChild(inp({ type: 'text', placeholder: 'Company name', 'data-newco': 'name' }));
        var add = el('button', 'btn-submit', 'Add'); add.setAttribute('data-action', 'add-company'); row.appendChild(add);
        form.appendChild(row);
        form.appendChild(el('div', 'admin-form-hint', 'These appear as type-or-pick suggestions in the Company field on ticket forms.'));
        body.appendChild(form);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Active', 'Actions'].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        if (!state.companiesAdmin.length) { var er = el('tr'); var td = el('td', null, 'No companies yet — add one above.'); td.setAttribute('colspan', '3'); td.style.color = 'var(--ink-muted)'; er.appendChild(td); tb.appendChild(er); }
        state.companiesAdmin.forEach(function (co) {
            var tr = el('tr');
            tr.appendChild(el('td', null, co.name));
            tr.appendChild(el('td', null, Number(co.active) ? 'Yes' : 'No'));
            var ac = el('td', 'row-actions');
            var edit = el('button', 'btn-mini', 'Edit'); edit.setAttribute('data-action', 'edit-company'); edit.setAttribute('data-id', co.company_id); ac.appendChild(edit);
            var tgl = el('button', 'btn-mini', Number(co.active) ? 'Disable' : 'Enable'); tgl.setAttribute('data-action', 'toggle-company'); tgl.setAttribute('data-id', co.company_id); tgl.setAttribute('data-active', Number(co.active) ? '1' : '0'); ac.appendChild(tgl);
            var del = el('button', 'btn-mini btn-danger', 'Delete'); del.setAttribute('data-action', 'delete-company'); del.setAttribute('data-id', co.company_id); del.setAttribute('data-name', co.name); ac.appendChild(del);
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);
    }
    async function addCompany() {
        var i = q('[data-newco="name"]'); var r = await api('createCompany', { name: i ? i.value : '' });
        toast(r && r.ok ? 'Company added' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    function editCompany(id) { var co = (state.companiesAdmin || []).find(function (x) { return x.company_id === id; }); if (co) { entityEditor('company', co); } }
    async function toggleCompany(id, isActive) {
        var r = await api('updateCompany', { company_id: id, active: isActive ? 0 : 1 });
        toast(r && r.ok ? 'Updated' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    async function deleteCompany(id, name) {
        if (!window.confirm('Delete company "' + name + '"?')) { return; }
        var r = await api('deleteCompany', { company_id: id });
        toast(r && r.ok ? 'Deleted' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }

    // ── shared edit modal for category / company ──────────────────────────────
    function entityEditor(kind, row) {
        state.entityKind = kind;
        state.entityId = kind === 'category' ? row.category_id : row.company_id;
        bind('entity-title', 'Edit ' + (kind === 'category' ? 'category' : 'company'));
        var body = region('entity-body'); body.textContent = '';
        body.appendChild(field('Name', inp({ type: 'text', maxlength: kind === 'category' ? '60' : '120', 'data-entity': 'name', value: row.name || '' })));
        if (kind === 'category') {
            body.appendChild(field('Colour', inp({ type: 'color', value: row.color || '#4057F5', 'data-entity': 'color' })));
            var hasChildren = (state.categoriesAdmin || []).some(function (c) { return c.parent_id === row.category_id; });
            if (hasChildren) {
                body.appendChild(el('div', 'admin-form-hint', 'This category has sub-categories, so it stays top-level.'));
            } else {
                var tops = (state.categoriesAdmin || []).filter(function (c) { return !c.parent_id && c.category_id !== row.category_id; });
                var opts = [['', 'Top level (no parent)']].concat(tops.map(function (c) { return [c.category_id, c.name]; }));
                body.appendChild(field('Parent category', sel(opts, 'data-entity', 'parent_id', row.parent_id || '')));
            }
        }
        var err = el('div', 'alert error'); err.setAttribute('data-bind', 'entity-err'); body.appendChild(err);
        var save = el('button', 'btn-submit', 'Save changes'); save.setAttribute('data-action', 'save-entity'); body.appendChild(save);
        region('entity-modal').hidden = false;
    }
    async function saveEntity() {
        var p = {}; qa('[data-entity]').forEach(function (i) { p[i.getAttribute('data-entity')] = i.value; });
        var err = q('[data-bind="entity-err"]'); if (err) { err.classList.remove('show'); }
        var action, payload;
        if (state.entityKind === 'category') {
            action = 'updateCategory';
            payload = { category_id: state.entityId, name: p.name, color: p.color };
            if ('parent_id' in p) { payload.parent_id = p.parent_id; } // only present when the selector was shown
        } else { action = 'updateCompany'; payload = { company_id: state.entityId, name: p.name }; }
        var r = await api(action, payload);
        if (r && r.ok) { region('entity-modal').hidden = true; toast('Saved', 'success'); renderAdmin(); }
        else if (err) { err.textContent = (r && r.error) || 'Could not save.'; err.classList.add('show'); }
    }

    // Fill the New-Ticket company datalist from the active companies loaded at boot.
    function populateCompanyList() {
        var dl = document.getElementById('company-list'); if (!dl) { return; }
        dl.textContent = '';
        (state.companies || []).forEach(function (co) { var o = document.createElement('option'); o.value = co.name; dl.appendChild(o); });
    }

    // ── Routing rules: add (one condition + one action) + toggle + delete ─────
    async function renderRules(body) {
        var r = await api('listRules', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }
        var rules = r.data.rules || [];
        state.rulesAdmin = rules; state.ruleEditId = null;

        var form = el('div', 'admin-form');
        var ftitle = el('div', 'admin-form-title', 'Add a routing rule'); ftitle.setAttribute('data-bind', 'rule-form-title'); form.appendChild(ftitle);
        form.appendChild(field('Rule name', inp({ type: 'text', placeholder: 'e.g. Urgent billing issues', 'data-newrule': 'name' })));
        var cRow = el('div', 'admin-form-row');
        cRow.appendChild(el('span', 'admin-form-lead', 'IF'));
        cRow.appendChild(sel([['subject', 'Subject'], ['description', 'Description'], ['priority', 'Priority'], ['category', 'Category'], ['customer_email', 'Customer email'], ['channel', 'Channel'], ['tags', 'Tags']], 'data-newrule', 'c_field', 'subject'));
        cRow.appendChild(sel([['contains', 'contains'], ['is', 'is'], ['starts_with', 'starts with'], ['not_contains', 'does not contain']], 'data-newrule', 'c_operator', 'contains'));
        cRow.appendChild(inp({ type: 'text', placeholder: 'value', 'data-newrule': 'c_value' }));
        form.appendChild(cRow);
        var aRow = el('div', 'admin-form-row');
        aRow.appendChild(el('span', 'admin-form-lead', 'THEN'));
        aRow.appendChild(sel([['set_priority', 'Set priority'], ['set_category', 'Set category'], ['assign_agent', 'Assign agent'], ['add_tag', 'Add tag']], 'data-newrule', 'a_type', 'set_priority'));
        aRow.appendChild(inp({ type: 'text', placeholder: 'value (e.g. urgent, CAT-001, agent email, tag)', 'data-newrule': 'a_value' }));
        form.appendChild(aRow);
        var add = el('button', 'btn-submit', 'Create rule'); add.setAttribute('data-action', 'add-rule'); add.setAttribute('data-bind', 'rule-save-btn'); add.style.marginTop = '12px'; form.appendChild(add);
        var cancel = el('button', 'btn-mini', 'Cancel edit'); cancel.setAttribute('data-action', 'cancel-rule-edit'); cancel.setAttribute('data-bind', 'rule-cancel'); cancel.style.cssText = 'margin:12px 0 0 8px;'; cancel.hidden = true; form.appendChild(cancel);
        body.appendChild(form);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Conditions', 'Actions', 'Enabled', 'Manage'].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        rules.forEach(function (rl) {
            var conds = safeParse(rl.conditions), acts = safeParse(rl.actions);
            var tr = el('tr');
            tr.appendChild(el('td', null, rl.name));
            tr.appendChild(el('td', 'rule-summary', conds.map(function (c) { return c.field + ' ' + c.operator + ' "' + c.value + '"'; }).join(' AND ')));
            tr.appendChild(el('td', 'rule-summary', acts.map(function (a) { return a.type + '=' + a.value; }).join(', ')));
            tr.appendChild(el('td', null, Number(rl.enabled) ? 'Yes' : 'No'));
            var ac = el('td', 'row-actions');
            var edit = el('button', 'btn-mini', 'Edit'); edit.setAttribute('data-action', 'edit-rule'); edit.setAttribute('data-id', rl.rule_id); ac.appendChild(edit);
            var tgl = el('button', 'btn-mini', Number(rl.enabled) ? 'Disable' : 'Enable'); tgl.setAttribute('data-action', 'toggle-rule'); tgl.setAttribute('data-id', rl.rule_id); tgl.setAttribute('data-enabled', Number(rl.enabled) ? '1' : '0'); ac.appendChild(tgl);
            var del = el('button', 'btn-mini btn-danger', 'Delete'); del.setAttribute('data-action', 'delete-rule'); del.setAttribute('data-id', rl.rule_id); del.setAttribute('data-name', rl.name); ac.appendChild(del);
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);
    }
    function safeParse(s) { try { var v = JSON.parse(s); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
    function ruleVal(k) { var i = q('[data-newrule="' + k + '"]'); return i ? i.value : ''; }
    function ruleSetVal(k, v) { var i = q('[data-newrule="' + k + '"]'); if (i) { i.value = v; } }
    function editRule(id) {
        var rl = (state.rulesAdmin || []).find(function (x) { return x.rule_id === id; });
        if (!rl) { return; }
        var c = safeParse(rl.conditions)[0] || {}, a = safeParse(rl.actions)[0] || {};
        ruleSetVal('name', rl.name);
        ruleSetVal('c_field', c.field || 'subject');
        ruleSetVal('c_operator', c.operator || 'contains');
        ruleSetVal('c_value', c.value || '');
        ruleSetVal('a_type', a.type || 'set_priority');
        ruleSetVal('a_value', a.value || '');
        state.ruleEditId = id;
        bind('rule-form-title', 'Edit routing rule');
        var btn = q('[data-bind="rule-save-btn"]'); if (btn) { btn.textContent = 'Save changes'; }
        var cancel = q('[data-bind="rule-cancel"]'); if (cancel) { cancel.hidden = false; }
        var f = q('[data-bind="rule-form-title"]'); if (f) { f.scrollIntoView({ block: 'center' }); }
    }
    function cancelRuleEdit() { state.ruleEditId = null; renderAdmin(); }
    async function addRule() {
        var editing = state.ruleEditId;
        var payload = {
            name: ruleVal('name'),
            conditions: [{ field: ruleVal('c_field'), operator: ruleVal('c_operator'), value: ruleVal('c_value') }],
            actions: [{ type: ruleVal('a_type'), value: ruleVal('a_value') }]
        };
        if (editing) {
            payload.rule_id = editing;
            var cur = (state.rulesAdmin || []).find(function (x) { return x.rule_id === editing; });
            payload.enabled = cur ? !!Number(cur.enabled) : true; // preserve enabled state across an edit
        } else {
            payload.enabled = true;
        }
        var r = await api(editing ? 'updateRule' : 'createRule', payload);
        toast(r && r.ok ? (editing ? 'Rule updated' : 'Rule created') : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { state.ruleEditId = null; renderAdmin(); }
    }
    async function toggleRule(id, isEnabled) {
        var r = await api('toggleRule', { rule_id: id, enabled: isEnabled ? 0 : 1 });
        toast(r && r.ok ? 'Updated' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    async function deleteRule(id, name) {
        if (!window.confirm('Delete rule "' + name + '"?')) { return; }
        var r = await api('deleteRule', { rule_id: id });
        toast(r && r.ok ? 'Deleted' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }

    // ── Backup & data (run backup + danger zone reset) ────────────────────────
    function renderBackup(body) {
        var card = el('div', 'table-card'); var inner = el('div'); inner.style.padding = '20px';
        inner.appendChild(el('div', 'admin-form-title', 'Backup'));
        inner.appendChild(el('p', 'admin-form-hint', 'Create an on-demand database backup. Backups are also produced automatically before any data reset.'));
        var bk = el('button', 'btn-submit', 'Run backup now'); bk.setAttribute('data-action', 'run-backup'); inner.appendChild(bk);
        card.appendChild(inner); body.appendChild(card);

        var danger = el('div', 'danger-zone');
        danger.appendChild(el('div', 'danger-title', 'Danger zone'));
        danger.appendChild(el('p', 'admin-form-hint', 'Permanently delete ALL tickets, messages, attachments and notifications. Users, categories, config, canned responses, routing rules, KB and the audit log are preserved. A backup is taken first.'));
        danger.appendChild(el('p', 'admin-form-hint', 'Type ' + '“' + 'RESET TICKET DATA' + '”' + ' to confirm:'));
        var drow = el('div', 'admin-form-row');
        drow.appendChild(inp({ type: 'text', placeholder: 'RESET TICKET DATA', 'data-reset': 'confirm' }));
        var rb = el('button', 'btn-mini btn-danger', 'Reset ticket data'); rb.setAttribute('data-action', 'reset-data'); drow.appendChild(rb);
        danger.appendChild(drow);
        body.appendChild(danger);
    }
    async function runBackup() {
        var r = await api('runBackup', {});
        toast(r && r.ok ? ('Backup created: ' + r.data.file) : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
    }
    async function resetData() {
        var i = q('[data-reset="confirm"]'); var phrase = i ? i.value : '';
        if (!window.confirm('This deletes all ticket data. Continue?')) { return; }
        var r = await api('resetTicketData', { confirm: phrase });
        toast(r && r.ok ? ('Reset complete — ' + r.data.deleted.tickets + ' tickets removed') : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { refreshCounts(); }
    }

    async function renderSla(body) {
        var r = await api('getSystemConfig', {}); var cfg = (r && r.ok) ? r.data.config : {};
        var card = el('div', 'table-card'); var inner = el('div'); inner.style.padding = '20px';
        var grid = el('div', 'sla-grid');
        grid.appendChild(el('div', 'sla-head', 'Tier')); grid.appendChild(el('div', 'sla-head', 'Response (min)')); grid.appendChild(el('div', 'sla-head', 'Resolution (min)'));
        ['urgent', 'high', 'normal', 'low'].forEach(function (tier) {
            grid.appendChild(el('div', 'sla-tier', tier));
            var i1 = document.createElement('input'); i1.type = 'number'; i1.value = cfg['sla_response_' + tier] || ''; i1.setAttribute('data-sla', 'sla_response_' + tier); grid.appendChild(i1);
            var i2 = document.createElement('input'); i2.type = 'number'; i2.value = cfg['sla_resolution_' + tier] || ''; i2.setAttribute('data-sla', 'sla_resolution_' + tier); grid.appendChild(i2);
        });
        inner.appendChild(grid);
        var save = el('button', 'btn-submit', 'Save SLA targets'); save.style.marginTop = '18px'; save.setAttribute('data-action', 'save-sla'); inner.appendChild(save);
        card.appendChild(inner); body.appendChild(card);
    }
    async function renderConfig(body) {
        var r = await api('getSystemConfig', {}); var cfg = (r && r.ok) ? r.data.config : {};
        var card = el('div', 'table-card'); var form = el('div', 'config-form'); form.style.padding = '20px';
        [['company_name', 'Company name'], ['support_email', 'Support email'], ['portal_title', 'Portal title'], ['portal_tagline', 'Portal tagline'], ['ticket_prefix', 'Ticket prefix']].forEach(function (pair) {
            var f = el('div', 'field'); f.appendChild(el('label', null, pair[1])); var i = document.createElement('input'); i.value = cfg[pair[0]] || ''; i.setAttribute('data-cfg', pair[0]); f.appendChild(i); form.appendChild(f);
        });
        // Security: require two-factor authentication for admin accounts.
        var mfaF = el('div', 'field'); mfaF.appendChild(el('label', null, 'Require 2FA for admins'));
        var mfaSel = sel([['1', 'On (recommended)'], ['0', 'Off']], 'data-cfg', 'require_admin_mfa', (cfg.require_admin_mfa === undefined ? '1' : String(cfg.require_admin_mfa)));
        mfaF.appendChild(mfaSel); mfaF.appendChild(el('div', 'admin-form-hint', 'When off, admins sign in with just their password. Turn on for stronger protection.')); form.appendChild(mfaF);
        var save = el('button', 'btn-submit', 'Save settings'); save.setAttribute('data-action', 'save-config'); form.appendChild(save);
        card.appendChild(form); body.appendChild(card);
    }
    async function saveSla() {
        var payload = {}; qa('[data-sla]').forEach(function (i) { payload[i.getAttribute('data-sla')] = i.value; });
        var r = await api('updateSlaTargets', payload); toast(r && r.ok ? 'SLA saved' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
    }
    async function saveConfig() {
        var payload = {}; qa('[data-cfg]').forEach(function (i) { payload[i.getAttribute('data-cfg')] = i.value; });
        var r = await api('updateConfig', payload); toast(r && r.ok ? 'Settings saved' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
    }

    // ── shared UI bits ────────────────────────────────────────────────────────
    function header(title, sub, showNew) {
        var h = el('div', 'page-header');
        var left = el('div'); left.appendChild(el('div', 'page-title', title)); if (sub) { left.appendChild(el('div', 'page-sub', sub)); }
        h.appendChild(left);
        if (showNew) { var b = el('button', 'btn-new', '+ New ticket'); b.setAttribute('data-action', 'open-new'); h.appendChild(b); }
        return h;
    }
    function emptyState(msg) { var e = el('div', 'empty-state'); e.appendChild(el('div', 'empty-state-title', msg)); return e; }

    // ── delegation ────────────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var m = e.target.classList && e.target.classList.contains('modal-overlay');
        if (m) {
            // A forced password change must not be dismissable by clicking the backdrop.
            if (e.target === region('changepw-modal') && state.forcedPw) { return; }
            e.target.hidden = true; return;
        }
        var t = e.target.closest('[data-action]'); if (!t) { return; }
        var a = t.getAttribute('data-action');
        if (a === 'switch-view') { switchView(t.getAttribute('data-view')); }
        else if (a === 'open-ticket') { openTicket(t.getAttribute('data-ticket')); }
        else if (a === 'close-ticket') { region('ticket-modal').hidden = true; }
        else if (a === 'send-reply') { compose(false); }
        else if (a === 'add-note') { compose(true); }
        else if (a === 'open-new') { q('[data-bind="new-err"]').classList.remove('show'); populateCompanyList(); region('new-modal').hidden = false; }
        else if (a === 'close-new') { region('new-modal').hidden = true; }
        else if (a === 'toggle-notifications') { toggleNotifs(); }
        else if (a === 'mark-all-notifs') { api('markAllNotificationsRead', {}).then(function () { refreshBadge(); toggleNotifs(); toggleNotifs(); }); }
        else if (a === 'read-notif') { api('markNotificationRead', { notif_id: t.getAttribute('data-notif') }).then(function () { t.classList.add('read'); refreshBadge(); }); }
        else if (a === 'page') { state.page[t.getAttribute('data-view')] = parseInt(t.getAttribute('data-page'), 10); renderList(t.getAttribute('data-view')); }
        else if (a === 'report-period') { reportPeriod = parseInt(t.getAttribute('data-period'), 10); renderReports(); }
        else if (a === 'admin-tab') { adminTab = t.getAttribute('data-tab'); renderAdmin(); }
        else if (a === 'save-sla') { saveSla(); }
        else if (a === 'save-config') { saveConfig(); }
        else if (a === 'add-user') { addUser(); }
        else if (a === 'toggle-user') { toggleUser(t.getAttribute('data-id'), t.getAttribute('data-active') === '1'); }
        else if (a === 'reset-user-pw') { resetUserPw(t.getAttribute('data-id'), t.getAttribute('data-email')); }
        else if (a === 'delete-user') { deleteUser(t.getAttribute('data-id'), t.getAttribute('data-email')); }
        else if (a === 'add-category') { addCategory(); }
        else if (a === 'toggle-category') { toggleCategory(t.getAttribute('data-id'), t.getAttribute('data-active') === '1'); }
        else if (a === 'delete-category') { deleteCategory(t.getAttribute('data-id'), t.getAttribute('data-name')); }
        else if (a === 'edit-category') { editCategory(t.getAttribute('data-id')); }
        else if (a === 'add-company') { addCompany(); }
        else if (a === 'edit-company') { editCompany(t.getAttribute('data-id')); }
        else if (a === 'toggle-company') { toggleCompany(t.getAttribute('data-id'), t.getAttribute('data-active') === '1'); }
        else if (a === 'delete-company') { deleteCompany(t.getAttribute('data-id'), t.getAttribute('data-name')); }
        else if (a === 'save-entity') { saveEntity(); }
        else if (a === 'close-entity') { region('entity-modal').hidden = true; }
        else if (a === 'add-rule') { addRule(); }
        else if (a === 'edit-rule') { editRule(t.getAttribute('data-id')); }
        else if (a === 'cancel-rule-edit') { cancelRuleEdit(); }
        else if (a === 'toggle-rule') { toggleRule(t.getAttribute('data-id'), t.getAttribute('data-enabled') === '1'); }
        else if (a === 'delete-rule') { deleteRule(t.getAttribute('data-id'), t.getAttribute('data-name')); }
        else if (a === 'run-backup') { runBackup(); }
        else if (a === 'reset-data') { resetData(); }
        else if (a === 'open-change-pw') { openChangePw(false); }
        else if (a === 'close-change-pw') { closeChangePw(); }
        else if (a === 'new-kb') { kbEditor(null); }
        else if (a === 'open-kb') { openKb(t.getAttribute('data-id')); }
        else if (a === 'edit-kb') { kbEditor(state.kbCurrent); }
        else if (a === 'delete-kb') { deleteKb(t.getAttribute('data-id'), t.getAttribute('data-title')); }
        else if (a === 'save-kb') { saveKb(); }
        else if (a === 'close-kb') { region('kb-modal').hidden = true; }
    });
    document.addEventListener('change', function (e) {
        var a = e.target.getAttribute && e.target.getAttribute('data-action'); if (!a) { return; }
        if (a === 'change-status') { changeField('changeStatus', 'status', e.target.value); }
        else if (a === 'change-priority') { changeField('changePriority', 'priority', e.target.value); }
        else if (a === 'assign-ticket') { changeField('assignTicket', 'assigned_to', e.target.value); }
        else if (a === 'apply-canned') { applyCanned(e.target.value); e.target.value = ''; }
        else if (a === 'upload-attachment') { upload(e.target); }
    });
    document.addEventListener('submit', function (e) {
        var a = e.target.getAttribute('data-action');
        if (a === 'create-ticket') { e.preventDefault(); createTicket(e.target); }
        else if (a === 'change-pw') { e.preventDefault(); submitChangePw(e.target); }
    });

    boot();
})();
