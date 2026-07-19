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
    var state = { view: 'dashboard', ticket: null, agents: [], canned: [], page: {}, cache: {} };

    // ── boot ──────────────────────────────────────────────────────────────────
    async function boot() {
        var d = await api('getDashboardData');
        if (d && d.ok) { state.agents = d.data.agents || []; }
        var canned = await api('getCannedResponses');
        if (canned && canned.ok) { state.canned = canned.data.responses || []; }
        refreshCounts();
        refreshBadge();
        switchView('dashboard');
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
        c.appendChild(header('Knowledge Base', 'Help articles for your team and customers', false));
        var r = await api('getKbArticles', {});
        var articles = r.ok ? r.data.articles : [];
        var grid = el('div', 'kb-grid');
        if (!articles.length) { grid.appendChild(emptyState('No articles yet.')); }
        articles.forEach(function (a) {
            var card = el('div', 'kb-card');
            var top = el('div', 'kb-card-cat'); top.appendChild(el('span', 'ticket-customer', a.category || 'General')); top.appendChild(el('span', 'vis-chip vis-' + a.visibility, a.visibility)); card.appendChild(top);
            card.appendChild(el('div', 'kb-card-title', a.title));
            var meta = el('div', 'kb-card-meta'); meta.appendChild(el('span', null, a.author)); meta.appendChild(el('span', null, a.view_count + ' views')); card.appendChild(meta);
            grid.appendChild(card);
        });
        c.appendChild(grid);
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

    // ── admin (basic: agents + SLA + config) ─────────────────────────────────
    var adminTab = 'agents';
    async function renderAdmin() {
        var c = region('content'); c.textContent = '';
        c.appendChild(header('Admin Panel', 'Manage your helpdesk', false));
        var tabs = el('div', 'tabs');
        [['agents', 'Agents'], ['sla', 'SLA Targets'], ['config', 'System']].forEach(function (pair) {
            var t = el('div', 'tab' + (pair[0] === adminTab ? ' active' : ''), pair[1]); t.setAttribute('data-action', 'admin-tab'); t.setAttribute('data-tab', pair[0]); tabs.appendChild(t);
        });
        c.appendChild(tabs);
        var body = el('div'); body.setAttribute('data-region', 'admin-body'); c.appendChild(body);
        if (adminTab === 'agents') { renderAgents(body); }
        else if (adminTab === 'sla') { renderSla(body); }
        else { renderConfig(body); }
    }
    async function renderAgents(body) {
        var r = await api('listUsers', {}); if (!r || !r.ok) { body.appendChild(emptyState('Could not load.')); return; }
        var card = el('div', 'table-card');
        var table = el('table'); var thead = el('thead'); var htr = el('tr');
        ['Name', 'Email', 'Role', 'Active', ''].forEach(function (h) { htr.appendChild(el('th', null, h)); }); thead.appendChild(htr); table.appendChild(thead);
        var tb = el('tbody');
        r.data.users.forEach(function (u) {
            var tr = el('tr');
            tr.appendChild(el('td', null, u.name));
            tr.appendChild(el('td', null, u.email));
            var rc = el('td'); rc.appendChild(el('span', 'role-chip role-' + (u.role === 'admin' ? 'admin' : 'agent'), u.role)); tr.appendChild(rc);
            tr.appendChild(el('td', null, Number(u.active) ? 'Yes' : 'No'));
            tr.appendChild(el('td', null, ''));
            tb.appendChild(tr);
        });
        table.appendChild(tb); card.appendChild(table); body.appendChild(card);
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
        if (m) { e.target.hidden = true; return; }
        var t = e.target.closest('[data-action]'); if (!t) { return; }
        var a = t.getAttribute('data-action');
        if (a === 'switch-view') { switchView(t.getAttribute('data-view')); }
        else if (a === 'open-ticket') { openTicket(t.getAttribute('data-ticket')); }
        else if (a === 'close-ticket') { region('ticket-modal').hidden = true; }
        else if (a === 'send-reply') { compose(false); }
        else if (a === 'add-note') { compose(true); }
        else if (a === 'open-new') { q('[data-bind="new-err"]').classList.remove('show'); region('new-modal').hidden = false; }
        else if (a === 'close-new') { region('new-modal').hidden = true; }
        else if (a === 'toggle-notifications') { toggleNotifs(); }
        else if (a === 'mark-all-notifs') { api('markAllNotificationsRead', {}).then(function () { refreshBadge(); toggleNotifs(); toggleNotifs(); }); }
        else if (a === 'read-notif') { api('markNotificationRead', { notif_id: t.getAttribute('data-notif') }).then(function () { t.classList.add('read'); refreshBadge(); }); }
        else if (a === 'page') { state.page[t.getAttribute('data-view')] = parseInt(t.getAttribute('data-page'), 10); renderList(t.getAttribute('data-view')); }
        else if (a === 'report-period') { reportPeriod = parseInt(t.getAttribute('data-period'), 10); renderReports(); }
        else if (a === 'admin-tab') { adminTab = t.getAttribute('data-tab'); renderAdmin(); }
        else if (a === 'save-sla') { saveSla(); }
        else if (a === 'save-config') { saveConfig(); }
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
        if (e.target.getAttribute('data-action') === 'create-ticket') { e.preventDefault(); createTicket(e.target); }
    });

    boot();
})();
