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
    var state = { view: 'dashboard', ticket: null, agents: [], organizations: [], categories: [], products: [], canned: [], page: {}, cache: {}, forcedPw: false, me: null, kbEditId: null, kbCurrent: null, categoriesAdmin: [], organizationsAdmin: [], productsAdmin: [], rulesAdmin: [], ruleEditId: null, entityKind: null, entityId: null };

    // ── boot ──────────────────────────────────────────────────────────────────
    async function boot() {
        var d = await api('getDashboardData');
        if (d && d.ok) { state.agents = d.data.agents || []; state.organizations = d.data.organizations || []; state.categories = d.data.categories || []; state.products = d.data.products || []; }
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
    var listView = 'all';
    var listFilter = { q: '', product_id: '', category_id: '', priority: '', status: '', assigned_to: '' };
    async function renderList(view) {
        listView = view;
        var c = region('content'); c.textContent = '';
        c.appendChild(header(VIEW_TITLES[view] || 'Tickets', '', true));
        var r = await api('getTicketsForView', { view: view });
        state.cache[view] = r.ok ? r.data.tickets : [];
        if (!state.page[view]) { state.page[view] = 1; }
        c.appendChild(listFilterBar());
        var host = el('div'); host.setAttribute('data-region', 'list-table'); c.appendChild(host);
        drawList();
    }
    function listFilterBar() {
        var bar = el('div', 'list-filter-bar');
        bar.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;';
        var search = inp({ type: 'search', placeholder: 'Search subject, customer, id…', 'data-listfilter': 'q' });
        search.style.cssText = 'flex:1 1 200px;min-width:160px;'; if (listFilter.q) { search.value = listFilter.q; }
        bar.appendChild(search);
        bar.appendChild(filterSelect('product_id', 'All products', (state.products || []).map(function (p) { return [p.product_id, p.name]; })));
        bar.appendChild(filterSelect('category_id', 'All categories', (state.categories || []).map(function (c) { return [c.category_id, (c.parent_id ? '— ' : '') + c.name]; })));
        bar.appendChild(filterSelect('priority', 'All priorities', [['urgent', 'Urgent'], ['high', 'High'], ['normal', 'Normal'], ['low', 'Low']]));
        bar.appendChild(filterSelect('status', 'All statuses', [['open', 'Open'], ['pending', 'Pending'], ['resolved', 'Resolved'], ['closed', 'Closed']]));
        bar.appendChild(filterSelect('assigned_to', 'All agents', (state.agents || []).map(function (a) { return [a.email, a.name]; })));
        var clear = el('button', 'btn-mini', 'Clear'); clear.setAttribute('data-action', 'clear-list-filters'); bar.appendChild(clear);
        var exp = el('a', 'btn-secondary', 'Export CSV'); exp.setAttribute('data-bind', 'list-export'); exp.href = exportUrl(); bar.appendChild(exp);
        return bar;
    }
    function filterSelect(key, allLabel, pairs) {
        var s = document.createElement('select'); s.setAttribute('data-listfilter', key);
        s.appendChild(new Option(allLabel, ''));
        pairs.forEach(function (p) { var o = new Option(p[1], p[0]); if (listFilter[key] === p[0]) { o.selected = true; } s.appendChild(o); });
        return s;
    }
    function drawList() {
        var host = region('list-table'); if (!host) { return; }
        host.textContent = '';
        host.appendChild(ticketTable(VIEW_TITLES[listView] || 'Tickets', applyListFilters(state.cache[listView] || []), true, listView));
    }
    function applyListFilters(rows) {
        var f = listFilter; var qv = (f.q || '').toLowerCase();
        return rows.filter(function (t) {
            if (f.product_id && String(t.product_id || '') !== f.product_id) { return false; }
            if (f.category_id && String(t.category_id || '') !== f.category_id) { return false; }
            if (f.priority && t.priority !== f.priority) { return false; }
            if (f.status && t.status !== f.status) { return false; }
            if (f.assigned_to && String(t.assigned_to || '') !== f.assigned_to) { return false; }
            if (qv) {
                var hay = ((t.subject || '') + ' ' + (t.customer_name || '') + ' ' + (t.customer_email || '') + ' ' + (t.ticket_id || '')).toLowerCase();
                if (hay.indexOf(qv) === -1) { return false; }
            }
            return true;
        });
    }
    function exportUrl() {
        var p = ['period=90'];
        ['product_id', 'category_id', 'priority', 'status', 'assigned_to', 'q'].forEach(function (k) {
            if (listFilter[k]) { p.push(k + '=' + encodeURIComponent(listFilter[k])); }
        });
        return '/export/tickets.csv?' + p.join('&');
    }
    function onListFilterChange(key, value) {
        listFilter[key] = value; state.page[listView] = 1;
        drawList();
        var a = q('[data-bind="list-export"]'); if (a) { a.href = exportUrl(); }
    }
    function clearListFilters() {
        listFilter = { q: '', product_id: '', category_id: '', priority: '', status: '', assigned_to: '' };
        state.page[listView] = 1; renderList(listView);
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
        bind('d-organization', t.organization_name || '—');
        bind('d-product', t.product_name || '—');
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
    var reportDim = 'product';
    var DIM_LABELS = { product: 'Product / Project', category: 'Category', agent: 'Agent', status: 'Status', priority: 'Priority' };
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
        grid.appendChild(statCard('Customer rating', d.kpis.csat_avg != null ? d.kpis.csat_avg + ' / 5' : '—', (d.kpis.csat_count || 0) + ' rating' + (d.kpis.csat_count === 1 ? '' : 's'), '#FEF6EE', '#F79009'));
        c.appendChild(grid);
        var row = el('div', 'charts-grid');
        row.appendChild(chartCard('Volume by day', 'vol')); row.appendChild(chartCard('By status', 'status'));
        c.appendChild(row);
        var row2 = el('div', 'charts-grid-row2');
        row2.appendChild(chartCard('By priority', 'prio')); row2.appendChild(chartCard('By channel', 'chan'));
        c.appendChild(row2);
        var exp = el('a', 'btn-secondary', 'Export CSV'); exp.href = '/export/tickets.csv?period=' + reportPeriod; c.appendChild(exp);

        // Custom breakdown (grouped summary): tickets per product/category/agent/status/priority.
        var bd = el('div', 'table-card'); bd.style.marginTop = '18px';
        var bdHead = el('div', 'table-header');
        bdHead.appendChild(el('span', 'table-header-title', 'Breakdown'));
        var dimSw = el('div', 'period-switch');
        Object.keys(DIM_LABELS).forEach(function (dim) {
            var b = el('button', 'period-btn' + (dim === reportDim ? ' active' : ''), DIM_LABELS[dim]);
            b.setAttribute('data-action', 'report-dim'); b.setAttribute('data-dim', dim); dimSw.appendChild(b);
        });
        bdHead.appendChild(dimSw); bd.appendChild(bdHead);
        var bdBody = el('div'); bdBody.setAttribute('data-region', 'report-breakdown'); bd.appendChild(bdBody);
        c.appendChild(bd);
        drawBreakdown();

        try {
            await loadChart();
            drawLine('vol', d.volume.map(function (p) { return p.date.slice(5); }), d.volume.map(function (p) { return p.count; }));
            drawBar('status', d.distributions.status); drawBar('prio', d.distributions.priority); drawBar('chan', d.distributions.channel);
        } catch (e) { /* charts unavailable */ }
    }
    async function drawBreakdown() {
        var host = region('report-breakdown'); if (!host) { return; }
        host.textContent = '';
        var r = await api('getGroupedReport', { dimension: reportDim, period: reportPeriod });
        if (!r || !r.ok) { host.appendChild(emptyState('Could not load breakdown.')); return; }
        var groups = r.data.groups || [];
        if (!groups.length) { host.appendChild(emptyState('No tickets in this period.')); return; }
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        [DIM_LABELS[reportDim] || 'Group', 'Tickets', 'Resolved'].forEach(function (h) { htr.appendChild(el('th', null, h)); });
        thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        groups.forEach(function (g) {
            var tr = el('tr');
            tr.appendChild(el('td', null, g.label));
            tr.appendChild(el('td', null, String(g.total)));
            tr.appendChild(el('td', null, String(g.resolved)));
            tb.appendChild(tr);
        });
        table.appendChild(tb); host.appendChild(table);
    }
    function chartCard(title, key) { var card = el('div', 'chart-card'); card.appendChild(el('div', 'chart-card-title', title)); var wrap = el('div', 'chart-wrap'); var cv = document.createElement('canvas'); cv.setAttribute('data-chart', key); wrap.appendChild(cv); card.appendChild(wrap); return card; }
    var charts = {};
    function theme() { var dark = document.documentElement.getAttribute('data-theme') === 'dark'; return { text: dark ? '#B4B9C8' : '#4B5060', grid: dark ? '#2B2F3E' : '#E3E6EF', palette: ['#4057F5', '#17B26A', '#F79009', '#F04438', '#7B8FFA', '#9499A8'] }; }
    function drawLine(key, labels, data) { var cv = q('[data-chart="' + key + '"]'); if (!cv) { return; } if (charts[key]) { charts[key].destroy(); } var t = theme(); charts[key] = new window.Chart(cv, { type: 'line', data: { labels: labels, datasets: [{ data: data, borderColor: '#4057F5', backgroundColor: 'rgba(64,87,245,.12)', fill: true, tension: .3 }] }, options: chartOpts(t) }); }
    function drawBar(key, dist) { var cv = q('[data-chart="' + key + '"]'); if (!cv) { return; } if (charts[key]) { charts[key].destroy(); } var t = theme(); charts[key] = new window.Chart(cv, { type: 'bar', data: { labels: Object.keys(dist), datasets: [{ data: Object.values(dist), backgroundColor: t.palette }] }, options: chartOpts(t) }); }
    function chartOpts(t) { return { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { color: t.text }, grid: { color: t.grid } }, y: { beginAtZero: true, ticks: { color: t.text, precision: 0 }, grid: { color: t.grid } } } }; }

    // ── admin (full: agents + categories + SLA + system + rules + backup) ─────
    var adminTab = 'agents';
    function isSysAdmin() { return !!(state.me && state.me.role === 'admin'); }
    async function renderAdmin() {
        var c = region('content'); c.textContent = '';
        var sys = isSysAdmin();
        c.appendChild(header(sys ? 'Admin Panel' : 'Organization Admin', sys ? 'Manage your helpdesk' : 'Manage your organization’s team', false));
        // Org admins get only the Agents tab (their own org); system admins get everything.
        var tabDefs = sys
            ? [['agents', 'Agents'], ['organizations', 'Organizations'], ['products', 'Products / Projects'], ['categories', 'Categories'], ['sla', 'SLA Targets'], ['config', 'System'], ['rules', 'Routing Rules'], ['audit', 'Audit Log'], ['backup', 'Backup & Data']]
            : [['agents', 'Agents']];
        if (!sys) { adminTab = 'agents'; }
        var tabs = el('div', 'tabs');
        tabDefs.forEach(function (pair) {
            var t = el('div', 'tab' + (pair[0] === adminTab ? ' active' : ''), pair[1]); t.setAttribute('data-action', 'admin-tab'); t.setAttribute('data-tab', pair[0]); tabs.appendChild(t);
        });
        c.appendChild(tabs);
        var body = el('div'); body.setAttribute('data-region', 'admin-body'); c.appendChild(body);
        if (adminTab === 'agents') { renderAgents(body); }
        else if (adminTab === 'categories') { renderCategories(body); }
        else if (adminTab === 'organizations') { renderOrganizations(body); }
        else if (adminTab === 'products') { renderProducts(body); }
        else if (adminTab === 'sla') { renderSla(body); }
        else if (adminTab === 'config') { renderConfig(body); }
        else if (adminTab === 'rules') { renderRules(body); }
        else if (adminTab === 'audit') { renderAudit(body); }
        else { renderBackup(body); }
    }

    function field(labelText, input) { var f = el('div', 'field'); f.appendChild(el('label', null, labelText)); f.appendChild(input); return f; }
    function inp(attrs) { var i = document.createElement('input'); Object.keys(attrs || {}).forEach(function (k) { i.setAttribute(k, attrs[k]); }); return i; }
    function sel(options, dataAttr, dataVal, current) { var s = document.createElement('select'); if (dataAttr) { s.setAttribute(dataAttr, dataVal); } options.forEach(function (o) { var op = el('option', null, o[1]); op.value = o[0]; if (o[0] === current) { op.selected = true; } s.appendChild(op); }); return s; }

    // ── Agents: add + activate/deactivate + reset password + delete ───────────
    async function renderAgents(body) {
        var r = await api('listUsers', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }

        var sys = isSysAdmin();
        var form = el('div', 'admin-form');
        form.appendChild(el('div', 'admin-form-title', sys ? 'Add a team member' : 'Add an agent to your organization'));
        var row = el('div', 'admin-form-row');
        row.appendChild(inp({ type: 'text', placeholder: 'Full name', 'data-newuser': 'name' }));
        row.appendChild(inp({ type: 'email', placeholder: 'Email address', 'data-newuser': 'email' }));
        if (sys) {
            // System admin: choose role + organization. Org admin: always an agent in their own org.
            row.appendChild(sel([['agent', 'Agent'], ['org_admin', 'Organization Admin'], ['admin', 'System Admin']], 'data-newuser', 'role', 'agent'));
            var orgOpts = [['', 'No organization']].concat((state.organizations || []).map(function (o) { return [o.organization_id, o.name]; }));
            row.appendChild(sel(orgOpts, 'data-newuser', 'organization_id', ''));
        }
        row.appendChild(inp({ type: 'password', placeholder: 'Temp password', 'data-newuser': 'password', autocomplete: 'new-password' }));
        var add = el('button', 'btn-submit', 'Add'); add.setAttribute('data-action', 'add-user'); row.appendChild(add);
        form.appendChild(row);
        form.appendChild(el('div', 'admin-form-hint', 'They will be required to change this password at first sign-in.'));
        body.appendChild(form);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Email', 'Role', 'Organization', 'Active', 'Actions'].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        r.data.users.forEach(function (u) {
            var tr = el('tr');
            tr.appendChild(el('td', null, u.name));
            tr.appendChild(el('td', null, u.email));
            var roleLabel = u.role === 'org_admin' ? 'Org Admin' : (u.role === 'admin' ? 'System Admin' : 'Agent');
            var rc = el('td'); rc.appendChild(el('span', 'role-chip role-' + (u.role === 'agent' ? 'agent' : 'admin'), roleLabel)); tr.appendChild(rc);
            var oc = el('td');
            if (sys && (u.role === 'agent' || u.role === 'org_admin')) {
                var os = sel([['', '— none —']].concat((state.organizations || []).map(function (o) { return [o.organization_id, o.name]; })), 'data-orguser', String(u.id), u.organization_id || '');
                os.setAttribute('data-action', 'assign-user-org'); os.setAttribute('data-id', u.id); oc.appendChild(os);
            } else { oc.appendChild(el('span', 'muted', orgName(u.organization_id) || '—')); }
            tr.appendChild(oc);
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
    async function assignUserOrg(id, orgId) {
        var r = await api('updateUser', { id: id, organization_id: orgId });
        toast(r && r.ok ? 'Organization updated' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
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

    // ── Organizations (tenants) ───────────────────────────────────────────────
    async function renderOrganizations(body) {
        var r = await api('listOrganizations', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }
        state.organizationsAdmin = r.data.organizations || [];
        var form = el('div', 'admin-form');
        form.appendChild(el('div', 'admin-form-title', 'Add an organization'));
        var row = el('div', 'admin-form-row');
        row.appendChild(inp({ type: 'text', placeholder: 'Organization name', 'data-neworg': 'name' }));
        var add = el('button', 'btn-submit', 'Add'); add.setAttribute('data-action', 'add-organization'); row.appendChild(add);
        form.appendChild(row);
        form.appendChild(el('div', 'admin-form-hint', 'Clients pick their organization on the ticket form; tickets route to that organization’s agents. Assign each agent to an organization on the Agents tab.'));
        body.appendChild(form);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Active', 'Actions'].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        if (!state.organizationsAdmin.length) { var er = el('tr'); var td = el('td', null, 'No organizations yet — add one above.'); td.setAttribute('colspan', '3'); td.style.color = 'var(--ink-muted)'; er.appendChild(td); tb.appendChild(er); }
        state.organizationsAdmin.forEach(function (o) {
            var tr = el('tr');
            tr.appendChild(el('td', null, o.name));
            tr.appendChild(el('td', null, Number(o.active) ? 'Yes' : 'No'));
            var ac = el('td', 'row-actions');
            var edit = el('button', 'btn-mini', 'Edit'); edit.setAttribute('data-action', 'edit-organization'); edit.setAttribute('data-id', o.organization_id); ac.appendChild(edit);
            var tgl = el('button', 'btn-mini', Number(o.active) ? 'Disable' : 'Enable'); tgl.setAttribute('data-action', 'toggle-organization'); tgl.setAttribute('data-id', o.organization_id); tgl.setAttribute('data-active', Number(o.active) ? '1' : '0'); ac.appendChild(tgl);
            var del = el('button', 'btn-mini btn-danger', 'Delete'); del.setAttribute('data-action', 'delete-organization'); del.setAttribute('data-id', o.organization_id); del.setAttribute('data-name', o.name); ac.appendChild(del);
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);
    }
    async function addOrganization() {
        var i = q('[data-neworg="name"]'); var r = await api('createOrganization', { name: i ? i.value : '' });
        toast(r && r.ok ? 'Organization added' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    function editOrganization(id) { var o = (state.organizationsAdmin || []).find(function (x) { return x.organization_id === id; }); if (o) { entityEditor('organization', o); } }
    async function toggleOrganization(id, isActive) {
        var r = await api('updateOrganization', { organization_id: id, active: isActive ? 0 : 1 });
        toast(r && r.ok ? 'Updated' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    async function deleteOrganization(id, name) {
        if (!window.confirm('Delete organization "' + name + '"? Its agents and tickets keep working but become unassigned to it.')) { return; }
        var r = await api('deleteOrganization', { organization_id: id });
        toast(r && r.ok ? 'Deleted' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    function orgName(id) { var o = (state.organizationsAdmin || state.organizations || []).find(function (x) { return x.organization_id === id; }); return o ? o.name : '—'; }

    // ── Products / Projects (shared list) ─────────────────────────────────────
    async function renderProducts(body) {
        var r = await api('listProducts', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }
        state.productsAdmin = r.data.products || [];
        var form = el('div', 'admin-form');
        form.appendChild(el('div', 'admin-form-title', 'Add a product / project'));
        var row = el('div', 'admin-form-row');
        row.appendChild(inp({ type: 'text', placeholder: 'Product / project name', 'data-newprod': 'name' }));
        var add = el('button', 'btn-submit', 'Add'); add.setAttribute('data-action', 'add-product'); row.appendChild(add);
        form.appendChild(row);
        form.appendChild(el('div', 'admin-form-hint', 'Clients pick a product / project on the ticket form. Disable one to hide it from the forms while keeping its tickets and history.'));
        body.appendChild(form);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Active', 'Actions'].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        if (!state.productsAdmin.length) { var er = el('tr'); var td = el('td', null, 'No products / projects yet — add one above.'); td.setAttribute('colspan', '3'); td.style.color = 'var(--ink-muted)'; er.appendChild(td); tb.appendChild(er); }
        state.productsAdmin.forEach(function (p) {
            var tr = el('tr');
            tr.appendChild(el('td', null, p.name));
            tr.appendChild(el('td', null, Number(p.active) ? 'Yes' : 'No'));
            var ac = el('td', 'row-actions');
            var edit = el('button', 'btn-mini', 'Edit'); edit.setAttribute('data-action', 'edit-product'); edit.setAttribute('data-id', p.product_id); ac.appendChild(edit);
            var tgl = el('button', 'btn-mini', Number(p.active) ? 'Disable' : 'Enable'); tgl.setAttribute('data-action', 'toggle-product'); tgl.setAttribute('data-id', p.product_id); tgl.setAttribute('data-active', Number(p.active) ? '1' : '0'); ac.appendChild(tgl);
            var del = el('button', 'btn-mini btn-danger', 'Delete'); del.setAttribute('data-action', 'delete-product'); del.setAttribute('data-id', p.product_id); del.setAttribute('data-name', p.name); ac.appendChild(del);
            tr.appendChild(ac);
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);
    }
    async function addProduct() {
        var i = q('[data-newprod="name"]'); var r = await api('createProduct', { name: i ? i.value : '' });
        toast(r && r.ok ? 'Product / project added' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    function editProduct(id) { var p = (state.productsAdmin || []).find(function (x) { return x.product_id === id; }); if (p) { entityEditor('product', p); } }
    async function toggleProduct(id, isActive) {
        var r = await api('updateProduct', { product_id: id, active: isActive ? 0 : 1 });
        toast(r && r.ok ? 'Updated' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }
    async function deleteProduct(id, name) {
        if (!window.confirm('Delete product / project "' + name + '"? (Blocked if any ticket still uses it — disable it instead.)')) { return; }
        var r = await api('deleteProduct', { product_id: id });
        toast(r && r.ok ? 'Deleted' : ((r && r.error) || 'Failed'), r && r.ok ? 'success' : 'danger');
        if (r && r.ok) { renderAdmin(); }
    }

    // ── shared edit modal for category / organization ─────────────────────────
    function entityEditor(kind, row) {
        state.entityKind = kind;
        state.entityId = kind === 'category' ? row.category_id : (kind === 'product' ? row.product_id : row.organization_id);
        bind('entity-title', 'Edit ' + (kind === 'category' ? 'category' : (kind === 'product' ? 'product / project' : 'organization')));
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
        } else if (state.entityKind === 'product') {
            action = 'updateProduct'; payload = { product_id: state.entityId, name: p.name };
        } else { action = 'updateOrganization'; payload = { organization_id: state.entityId, name: p.name }; }
        var r = await api(action, payload);
        if (r && r.ok) { region('entity-modal').hidden = true; toast('Saved', 'success'); renderAdmin(); }
        else if (err) { err.textContent = (r && r.error) || 'Could not save.'; err.classList.add('show'); }
    }

    // Fill the New-Ticket organization + category dropdowns from the lists loaded at boot.
    function populateNewTicketSelects() {
        var orgSel = region('new-org');
        if (orgSel) {
            orgSel.textContent = '';
            orgSel.appendChild(new Option('General / none', ''));
            (state.organizations || []).forEach(function (o) { orgSel.appendChild(new Option(o.name, o.organization_id)); });
        }
        var prodSel = region('new-product');
        if (prodSel) {
            prodSel.textContent = '';
            prodSel.appendChild(new Option('— none —', ''));
            (state.products || []).forEach(function (p) { prodSel.appendChild(new Option(p.name, p.product_id)); });
        }
        var catSel = region('new-category');
        if (catSel) {
            catSel.textContent = '';
            catSel.appendChild(new Option('— none —', ''));
            (state.categories || []).forEach(function (c) { catSel.appendChild(new Option((c.parent_id ? '— ' : '') + c.name, c.category_id)); });
        }
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

    // ── Audit log viewer (admin) ──────────────────────────────────────────────
    var auditPage = 1, auditActor = '', auditAction = '';
    async function renderAudit(body) {
        var r = await api('listAuditLog', { page: auditPage, actor: auditActor, action: auditAction });
        if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }
        var d = r.data;

        var bar = el('div', 'admin-form-row'); bar.style.marginBottom = '12px';
        var actorIn = inp({ type: 'search', placeholder: 'Filter by actor (email)…', 'data-auditf': 'actor' }); actorIn.value = auditActor; bar.appendChild(actorIn);
        bar.appendChild(sel([['', 'All actions']].concat((d.actions || []).map(function (a) { return [a, a]; })), 'data-auditf', 'action', auditAction));
        var go = el('button', 'btn-mini', 'Apply'); go.setAttribute('data-action', 'audit-apply'); bar.appendChild(go);
        var vf = el('button', 'btn-mini', 'Verify chain'); vf.setAttribute('data-action', 'audit-verify'); bar.appendChild(vf);
        var note = el('span', 'admin-form-hint'); note.setAttribute('data-bind', 'audit-chain'); note.style.marginLeft = '8px'; bar.appendChild(note);
        body.appendChild(bar);

        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Time (UTC)', 'Actor', 'Action', 'Target', 'Details', 'IP'].forEach(function (h) { htr.appendChild(el('th', null, h)); });
        thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        if (!(d.rows || []).length) { var er = el('tr'); var td = el('td', null, 'No audit entries match.'); td.setAttribute('colspan', '6'); td.style.color = 'var(--ink-muted)'; er.appendChild(td); tb.appendChild(er); }
        (d.rows || []).forEach(function (a) {
            var tr = el('tr');
            tr.appendChild(el('td', null, shortDate(a.created_at)));
            tr.appendChild(el('td', null, a.actor));
            tr.appendChild(el('td', null, a.action));
            tr.appendChild(el('td', null, a.target || '—'));
            var dc = el('td', 'rule-summary', a.details || ''); dc.style.maxWidth = '260px'; tr.appendChild(dc);
            tr.appendChild(el('td', null, a.ip_address || '—'));
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);

        var pages = Math.max(1, Math.ceil(d.total / d.per_page));
        var pag = el('div', 'admin-form-row'); pag.style.marginTop = '10px';
        var prev = el('button', 'btn-mini', '‹ Newer'); prev.disabled = auditPage <= 1; prev.setAttribute('data-action', 'audit-page'); prev.setAttribute('data-page', String(auditPage - 1)); pag.appendChild(prev);
        pag.appendChild(el('span', 'admin-form-hint', 'Page ' + auditPage + ' of ' + pages + ' · ' + d.total + ' entries'));
        var next = el('button', 'btn-mini', 'Older ›'); next.disabled = auditPage >= pages; next.setAttribute('data-action', 'audit-page'); next.setAttribute('data-page', String(auditPage + 1)); pag.appendChild(next);
        body.appendChild(pag);
    }
    async function verifyAuditChain() {
        var note = q('[data-bind="audit-chain"]'); if (note) { note.textContent = 'Verifying…'; }
        var r = await api('listAuditLog', { page: 1, verify: 1 });
        if (!note) { return; }
        if (r && r.ok && r.data.chain_ok) { note.textContent = '✓ Chain intact — no tampering detected.'; note.style.color = 'var(--success, #17B26A)'; }
        else if (r && r.ok) { note.textContent = '⚠ Chain BROKEN at entry #' + r.data.chain_bad_id + ' — investigate!'; note.style.color = 'var(--danger, #F04438)'; }
        else { note.textContent = (r && r.error) || 'Verification failed.'; }
    }

    // ── Backup & data (run backup + danger zone reset) ────────────────────────
    function renderBackup(body) {
        var card = el('div', 'table-card'); var inner = el('div'); inner.style.padding = '20px';
        inner.appendChild(el('div', 'admin-form-title', 'Backup'));
        inner.appendChild(el('p', 'admin-form-hint', 'Create an on-demand database backup. Backups are also produced automatically before any data reset. Download copies regularly and keep them OFF this server.'));
        var bk = el('button', 'btn-submit', 'Run backup now'); bk.setAttribute('data-action', 'run-backup'); inner.appendChild(bk);
        var listHost = el('div'); listHost.setAttribute('data-region', 'backup-list'); listHost.style.marginTop = '16px'; inner.appendChild(listHost);
        card.appendChild(inner); body.appendChild(card);
        loadBackupList();

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
        if (r && r.ok) { loadBackupList(); }
    }
    async function loadBackupList() {
        var host = region('backup-list'); if (!host) { return; }
        var r = await api('listBackups', {});
        host.textContent = '';
        if (!r || !r.ok) { return; }
        var files = r.data.backups || [];
        if (!files.length) { host.appendChild(el('p', 'admin-form-hint', 'No backup files yet.')); return; }
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['File', 'Size', 'Created (UTC)', ''].forEach(function (h) { htr.appendChild(el('th', null, h)); });
        thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        files.forEach(function (f) {
            var tr = el('tr');
            tr.appendChild(el('td', null, f.name));
            tr.appendChild(el('td', null, (f.size_bytes / 1024).toFixed(1) + ' KB'));
            tr.appendChild(el('td', null, shortDate(f.modified_at)));
            var dc = el('td', 'row-actions');
            var dl = el('a', 'btn-mini', 'Download'); dl.href = '/admin/backup/download?f=' + encodeURIComponent(f.name); dc.appendChild(dl);
            tr.appendChild(dc);
            tb.appendChild(tr);
        });
        table.appendChild(tb); host.appendChild(table);
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

        // Business-hours clock: SLA minutes only count inside the working window.
        var bh = el('div'); bh.style.marginTop = '22px';
        bh.appendChild(el('div', 'admin-form-title', 'SLA clock'));
        var bhRow = el('div', 'admin-form-row');
        bhRow.appendChild(sel([['0', 'Calendar time (24/7)'], ['1', 'Business hours only']], 'data-slacfg', 'sla_business_hours_only', String(cfg.sla_business_hours_only || '0')));
        var st = inp({ type: 'time', 'data-slacfg': 'business_hours_start' }); st.value = cfg.business_hours_start || '08:00'; bhRow.appendChild(st);
        var en = inp({ type: 'time', 'data-slacfg': 'business_hours_end' }); en.value = cfg.business_hours_end || '17:00'; bhRow.appendChild(en);
        var dys = inp({ type: 'text', placeholder: '1,2,3,4,5', 'data-slacfg': 'business_days' }); dys.value = cfg.business_days || '1,2,3,4,5'; dys.style.maxWidth = '120px'; bhRow.appendChild(dys);
        bh.appendChild(bhRow);
        bh.appendChild(el('div', 'admin-form-hint', 'With "Business hours only", a ticket arriving outside the window starts its SLA clock at the next working period. Days: 1=Mon … 7=Sun, comma-separated. Existing tickets keep their deadlines; new tickets (and priority changes) use the new clock.'));
        inner.appendChild(bh);

        var save = el('button', 'btn-submit', 'Save SLA targets'); save.style.marginTop = '18px'; save.setAttribute('data-action', 'save-sla'); inner.appendChild(save);
        card.appendChild(inner); body.appendChild(card);
    }
    async function renderConfig(body) {
        var r = await api('getSystemConfig', {}); var cfg = (r && r.ok) ? r.data.config : {};
        var card = el('div', 'table-card'); var form = el('div', 'config-form'); form.style.padding = '20px';
        [['company_name', 'Company name'], ['support_email', 'Support email'], ['portal_title', 'Portal title'], ['portal_tagline', 'Portal tagline'], ['ticket_prefix', 'Ticket prefix'], ['auto_close_days', 'Auto-close resolved tickets after (days, 0 = off)']].forEach(function (pair) {
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
        var r = await api('updateSlaTargets', payload);
        if (!(r && r.ok)) { toast((r && r.error) || 'Failed', 'danger'); return; }
        var cfgPayload = {}; qa('[data-slacfg]').forEach(function (i) { cfgPayload[i.getAttribute('data-slacfg')] = i.value; });
        var r2 = await api('updateConfig', cfgPayload);
        toast(r2 && r2.ok ? 'SLA saved' : ((r2 && r2.error) || 'Failed'), r2 && r2.ok ? 'success' : 'danger');
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
        else if (a === 'open-new') { q('[data-bind="new-err"]').classList.remove('show'); populateNewTicketSelects(); region('new-modal').hidden = false; }
        else if (a === 'close-new') { region('new-modal').hidden = true; }
        else if (a === 'toggle-notifications') { toggleNotifs(); }
        else if (a === 'mark-all-notifs') { api('markAllNotificationsRead', {}).then(function () { refreshBadge(); toggleNotifs(); toggleNotifs(); }); }
        else if (a === 'read-notif') { api('markNotificationRead', { notif_id: t.getAttribute('data-notif') }).then(function () { t.classList.add('read'); refreshBadge(); }); }
        else if (a === 'page') { state.page[t.getAttribute('data-view')] = parseInt(t.getAttribute('data-page'), 10); renderList(t.getAttribute('data-view')); }
        else if (a === 'report-period') { reportPeriod = parseInt(t.getAttribute('data-period'), 10); renderReports(); }
        else if (a === 'admin-tab') { adminTab = t.getAttribute('data-tab'); renderAdmin(); }
        else if (a === 'clear-list-filters') { clearListFilters(); }
        else if (a === 'report-dim') { reportDim = t.getAttribute('data-dim'); qa('[data-action="report-dim"]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-dim') === reportDim); }); drawBreakdown(); }
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
        else if (a === 'add-organization') { addOrganization(); }
        else if (a === 'edit-organization') { editOrganization(t.getAttribute('data-id')); }
        else if (a === 'toggle-organization') { toggleOrganization(t.getAttribute('data-id'), t.getAttribute('data-active') === '1'); }
        else if (a === 'delete-organization') { deleteOrganization(t.getAttribute('data-id'), t.getAttribute('data-name')); }
        else if (a === 'add-product') { addProduct(); }
        else if (a === 'edit-product') { editProduct(t.getAttribute('data-id')); }
        else if (a === 'toggle-product') { toggleProduct(t.getAttribute('data-id'), t.getAttribute('data-active') === '1'); }
        else if (a === 'delete-product') { deleteProduct(t.getAttribute('data-id'), t.getAttribute('data-name')); }
        else if (a === 'save-entity') { saveEntity(); }
        else if (a === 'close-entity') { region('entity-modal').hidden = true; }
        else if (a === 'add-rule') { addRule(); }
        else if (a === 'edit-rule') { editRule(t.getAttribute('data-id')); }
        else if (a === 'cancel-rule-edit') { cancelRuleEdit(); }
        else if (a === 'toggle-rule') { toggleRule(t.getAttribute('data-id'), t.getAttribute('data-enabled') === '1'); }
        else if (a === 'delete-rule') { deleteRule(t.getAttribute('data-id'), t.getAttribute('data-name')); }
        else if (a === 'run-backup') { runBackup(); }
        else if (a === 'audit-apply') { auditActor = (q('[data-auditf="actor"]') || {}).value || ''; auditAction = (q('[data-auditf="action"]') || {}).value || ''; auditPage = 1; renderAdmin(); }
        else if (a === 'audit-page') { auditPage = Math.max(1, parseInt(t.getAttribute('data-page'), 10) || 1); renderAdmin(); }
        else if (a === 'audit-verify') { verifyAuditChain(); }
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
    document.addEventListener('input', function (e) {
        var lf = e.target.getAttribute && e.target.getAttribute('data-listfilter');
        if (lf) { onListFilterChange(lf, e.target.value); }
    });
    document.addEventListener('change', function (e) {
        var lf = e.target.getAttribute && e.target.getAttribute('data-listfilter');
        if (lf) { onListFilterChange(lf, e.target.value); return; }
        var a = e.target.getAttribute && e.target.getAttribute('data-action'); if (!a) { return; }
        if (a === 'change-status') { changeField('changeStatus', 'status', e.target.value); }
        else if (a === 'change-priority') { changeField('changePriority', 'priority', e.target.value); }
        else if (a === 'assign-ticket') { changeField('assignTicket', 'assigned_to', e.target.value); }
        else if (a === 'apply-canned') { applyCanned(e.target.value); e.target.value = ''; }
        else if (a === 'upload-attachment') { upload(e.target); }
        else if (a === 'assign-user-org') { assignUserOrg(e.target.getAttribute('data-id'), e.target.value); }
    });
    document.addEventListener('submit', function (e) {
        var a = e.target.getAttribute('data-action');
        if (a === 'create-ticket') { e.preventDefault(); createTicket(e.target); }
        else if (a === 'change-pw') { e.preventDefault(); submitChangePw(e.target); }
    });

    boot();
})();
