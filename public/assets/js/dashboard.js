/* P3A dashboard.js — the ticket workbench (staff) + customer ticket list.
 * Uses window.P3A.call (app.js). No inline handlers; all rendering via textContent
 * so ticket/message content can never inject markup (D5, §10.2). */
(function () {
    'use strict';
    var P3A = window.P3A;
    if (!P3A) { return; }
    var staff = document.querySelector('[data-view="staff-dash"]');
    var cust = document.querySelector('[data-view="customer-dash"]');
    if (!staff && !cust) { return; }
    var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

    function q(sel) { return document.querySelector(sel); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
    function opt(v, t) { var o = document.createElement('option'); o.value = v; o.textContent = t; return o; }
    function pill(cls, text) { return el('span', 'p3a-pill p3a-' + cls, text); }
    function rowMsg(cols, text) { var tr = el('tr'); var td = el('td', null, text); td.setAttribute('colspan', String(cols)); tr.appendChild(td); return tr; }

    if (staff) { initStaff(); }
    if (cust) { initCustomer(); }

    // ══ STAFF WORKBENCH ══════════════════════════════════════════════════════
    function initStaff() {
        var state = { status: '', page: 1, perPage: 10, total: 0, ticket: null, agents: [] };

        async function loadKpis() {
            var res = await P3A.call('getDashboardData');
            if (res && res.ok) {
                P3A.bind('kpi-open', res.data.kpis.open);
                P3A.bind('kpi-pending', res.data.kpis.pending);
                P3A.bind('kpi-resolved', res.data.kpis.resolved_24h);
                P3A.bind('kpi-breaches', res.data.kpis.breaches);
                state.agents = res.data.agents || [];
            }
        }
        async function loadQueue() {
            var res = await P3A.call('getTickets', { page: state.page, status: state.status });
            var tbody = q('[data-region="ticket-rows"]');
            tbody.textContent = '';
            if (!res || !res.ok) { tbody.appendChild(rowMsg(7, 'Could not load tickets.')); return; }
            state.total = res.data.total;
            if (!res.data.rows.length) { tbody.appendChild(rowMsg(7, 'No tickets.')); }
            res.data.rows.forEach(function (t) { tbody.appendChild(ticketRow(t)); });
            var pages = Math.max(1, Math.ceil(state.total / state.perPage));
            P3A.bind('page-info', 'Page ' + state.page + ' / ' + pages + ' · ' + state.total + ' tickets');
        }
        function ticketRow(t) {
            var tr = el('tr');
            tr.setAttribute('data-action', 'open-ticket');
            tr.setAttribute('data-ticket', t.ticket_id);
            tr.appendChild(el('td', 'p3a-mono', t.ticket_id));
            tr.appendChild(el('td', null, t.subject));
            tr.appendChild(el('td', null, t.customer_email));
            var pd = el('td'); pd.appendChild(pill('prio-' + t.priority, t.priority)); tr.appendChild(pd);
            var sd = el('td'); sd.appendChild(pill('status-' + t.status, t.status)); tr.appendChild(sd);
            tr.appendChild(el('td', null, t.assigned_to || '—'));
            tr.appendChild(el('td', 'p3a-dim', (t.updated_at || '').replace('T', ' ').slice(0, 16)));
            if (t.sla_resolution_status === 'breached' || t.sla_response_status === 'breached') { tr.classList.add('is-breached'); }
            return tr;
        }

        async function open(id) {
            var res = await P3A.call('getTicket', { ticket_id: id });
            if (!res || !res.ok) { return; }
            state.ticket = res.data.ticket;
            var t = res.data.ticket;
            P3A.bind('detail-title', t.ticket_id + ' — ' + t.subject);
            P3A.bind('detail-customer', (t.customer_name ? t.customer_name + ' · ' : '') + t.customer_email);
            P3A.bind('detail-sla', 'SLA response: ' + t.sla_response_status + ' · resolution: ' + t.sla_resolution_status);
            q('[data-action="change-priority"]').value = t.priority;
            q('[data-action="change-status"]').value = t.status;
            var asel = q('[data-region="assignee-select"]'); asel.textContent = ''; asel.appendChild(opt('', 'Unassigned'));
            state.agents.forEach(function (a) { var o = opt(a.email, a.name); if (a.email === t.assigned_to) { o.selected = true; } asel.appendChild(o); });

            var thread = q('[data-region="thread"]'); thread.textContent = '';
            res.data.messages.forEach(function (m) {
                var d = el('div', 'p3a-msg p3a-msg-' + m.from_type + (Number(m.is_internal) ? ' is-internal' : ''));
                d.appendChild(el('div', 'p3a-msg-who', (m.from_name || m.from_type) + ' · ' + (m.created_at || '').replace('T', ' ').slice(0, 16) + (Number(m.is_internal) ? ' · internal note' : '')));
                d.appendChild(el('div', 'p3a-msg-body', m.text));
                thread.appendChild(d);
            });
            (res.data.attachments || []).forEach(function (a) {
                var link = el('a', 'p3a-attach-link', '📎 ' + a.original_name + (Number(a.is_internal) ? ' (internal)' : ''));
                link.href = '/download/' + a.id;
                thread.appendChild(link);
            });
            q('[data-region="thread"]').scrollTop = q('[data-region="thread"]').scrollHeight;
            q('[data-region="detail-modal"]').hidden = false;
        }

        async function compose(internal) {
            if (!state.ticket) { return; }
            var box = q('[data-region="composer-text"]');
            var text = box.value.trim();
            if (!text) { return; }
            var res = await P3A.call(internal ? 'addInternalNote' : 'sendReply', { ticket_id: state.ticket.ticket_id, text: text });
            if (res && res.ok) { box.value = ''; open(state.ticket.ticket_id); loadKpis(); loadQueue(); }
        }
        async function change(action, key, value) {
            if (!state.ticket) { return; }
            var p = { ticket_id: state.ticket.ticket_id }; p[key] = value;
            var res = await P3A.call(action, p);
            if (res && res.ok) { open(state.ticket.ticket_id); loadQueue(); loadKpis(); }
        }
        async function applyCanned(id) {
            if (!id || !state.ticket) { return; }
            var res = await P3A.call('applyCannedResponse', { ticket_id: state.ticket.ticket_id, response_id: id });
            if (res && res.ok) { q('[data-region="composer-text"]').value = res.data.body; }
        }
        async function upload(input) {
            if (!input.files.length || !state.ticket) { return; }
            var fd = new FormData();
            fd.append('file', input.files[0]);
            fd.append('ticket_id', state.ticket.ticket_id);
            fd.append('csrf', csrf);
            var res = await fetch('/upload', { method: 'POST', body: fd });
            var j = await res.json();
            P3A.bind('attach-msg', j.ok ? ('Attached ' + j.data.original_name) : (j.error || 'Upload failed'));
            if (j.ok) { open(state.ticket.ticket_id); }
            input.value = '';
        }
        async function create(form) {
            var payload = {};
            new FormData(form).forEach(function (v, k) { payload[k] = v; });
            var res = await P3A.call('createTicket', payload);
            if (res && res.ok) { q('[data-region="new-modal"]').hidden = true; form.reset(); loadKpis(); loadQueue(); }
            else { P3A.bind('new-msg', (res && res.error) || 'Could not create the ticket.'); }
        }
        async function loadCanned() {
            var res = await P3A.call('getCannedResponses');
            if (res && res.ok) { var sel = q('[data-region="canned-select"]'); res.data.responses.forEach(function (c) { sel.appendChild(opt(c.response_id, c.title)); }); }
        }

        document.addEventListener('click', function (e) {
            // Click on a modal backdrop (not its box) closes that modal.
            if (e.target.classList && e.target.classList.contains('p3a-modal')) { e.target.hidden = true; return; }
            var t = e.target.closest('[data-action]'); if (!t) { return; }
            var a = t.getAttribute('data-action');
            if (a === 'open-ticket') { open(t.getAttribute('data-ticket')); }
            else if (a === 'close-detail') { q('[data-region="detail-modal"]').hidden = true; }
            else if (a === 'filter-tickets') {
                state.status = t.getAttribute('data-status'); state.page = 1;
                document.querySelectorAll('[data-action="filter-tickets"]').forEach(function (b) { b.classList.toggle('is-active', b === t); });
                loadQueue();
            }
            else if (a === 'page-prev') { if (state.page > 1) { state.page--; loadQueue(); } }
            else if (a === 'page-next') { if (state.page * state.perPage < state.total) { state.page++; loadQueue(); } }
            else if (a === 'send-reply') { compose(false); }
            else if (a === 'add-note') { compose(true); }
            else if (a === 'new-ticket-open') { P3A.bind('new-msg', ''); q('[data-region="new-modal"]').hidden = false; }
            else if (a === 'new-ticket-close') { q('[data-region="new-modal"]').hidden = true; }
        });
        document.addEventListener('change', function (e) {
            var a = e.target.getAttribute && e.target.getAttribute('data-action'); if (!a) { return; }
            if (a === 'change-status') { change('changeStatus', 'status', e.target.value); }
            else if (a === 'change-priority') { change('changePriority', 'priority', e.target.value); }
            else if (a === 'assign-ticket') { change('assignTicket', 'assigned_to', e.target.value); }
            else if (a === 'apply-canned') { applyCanned(e.target.value); e.target.value = ''; }
            else if (a === 'upload-attachment') { upload(e.target); }
        });
        document.addEventListener('submit', function (e) {
            if (e.target.getAttribute('data-action') === 'create-ticket') { e.preventDefault(); create(e.target); }
        });

        loadKpis(); loadQueue(); loadCanned();
    }

    // ══ CUSTOMER VIEW ════════════════════════════════════════════════════════
    function initCustomer() {
        var current = null;
        async function load() {
            var res = await P3A.call('getMyTickets', {});
            var tbody = q('[data-region="my-ticket-rows"]');
            tbody.textContent = '';
            if (!res || !res.ok) { tbody.appendChild(rowMsg(5, 'Could not load.')); return; }
            if (!res.data.rows.length) { tbody.appendChild(rowMsg(5, 'You have no tickets yet.')); }
            res.data.rows.forEach(function (t) {
                var tr = el('tr'); tr.setAttribute('data-action', 'open-my'); tr.setAttribute('data-ticket', t.ticket_id);
                tr.appendChild(el('td', 'p3a-mono', t.ticket_id));
                tr.appendChild(el('td', null, t.subject));
                var pd = el('td'); pd.appendChild(pill('prio-' + t.priority, t.priority)); tr.appendChild(pd);
                var sd = el('td'); sd.appendChild(pill('status-' + t.status, t.status)); tr.appendChild(sd);
                tr.appendChild(el('td', 'p3a-dim', (t.updated_at || '').replace('T', ' ').slice(0, 16)));
                tbody.appendChild(tr);
            });
        }
        async function open(id) {
            var res = await P3A.call('getMyTicket', { ticket_id: id });
            if (!res || !res.ok) { return; }
            current = res.data.ticket;
            P3A.bind('my-detail-title', current.ticket_id + ' — ' + current.subject);
            var thread = q('[data-region="my-thread"]'); thread.textContent = '';
            res.data.messages.forEach(function (m) {
                var d = el('div', 'p3a-msg p3a-msg-' + m.from_type);
                d.appendChild(el('div', 'p3a-msg-who', (m.from_type === 'agent' ? 'Support' : (m.from_name || 'You')) + ' · ' + (m.created_at || '').replace('T', ' ').slice(0, 16)));
                d.appendChild(el('div', 'p3a-msg-body', m.text));
                thread.appendChild(d);
            });
            q('[data-region="my-detail-modal"]').hidden = false;
        }
        async function reply() {
            if (!current) { return; }
            var box = q('[data-region="my-composer-text"]');
            if (!box.value.trim()) { return; }
            var res = await P3A.call('replyToMyTicket', { ticket_id: current.ticket_id, text: box.value.trim() });
            if (res && res.ok) { box.value = ''; open(current.ticket_id); }
        }
        document.addEventListener('click', function (e) {
            if (e.target.classList && e.target.classList.contains('p3a-modal')) { e.target.hidden = true; return; }
            var t = e.target.closest('[data-action]'); if (!t) { return; }
            var a = t.getAttribute('data-action');
            if (a === 'open-my') { open(t.getAttribute('data-ticket')); }
            else if (a === 'close-my-detail') { q('[data-region="my-detail-modal"]').hidden = true; }
            else if (a === 'my-reply') { reply(); }
        });
        load();
    }
})();
