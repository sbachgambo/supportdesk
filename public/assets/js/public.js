/* P3A public.js (Phase 9, re-skinned) — submit + status forms on the public/widget
 * surfaces. Posts to /api with the stateless HMAC CSRF token (D6). No inline handlers
 * (D5); all rendering uses textContent so ticket data can never inject markup. */
(function () {
    'use strict';

    function container(node) { return node.closest('[data-form]'); }
    function q(sel, root) { return (root || document).querySelector(sel); }
    function bindEl(root, key) { return q('[data-bind="' + key + '"]', root); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }

    async function call(action, payload, csrf) {
        var res = await fetch('/api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, payload: payload, csrf: csrf })
        });
        try { return await res.json(); }
        catch (e) { return { ok: false, error: 'Something went wrong. Please try again.' }; }
    }

    function fields(form) {
        var out = {};
        new FormData(form).forEach(function (v, k) { out[k] = v; });
        return out;
    }

    function showError(root, key, text) {
        var a = bindEl(root, key);
        if (!a) { return; }
        a.textContent = text || '';
        a.classList.toggle('show', !!text);
    }

    function busy(btn, on, label) {
        if (!btn) { return; }
        btn.disabled = on;
        if (on) { btn.textContent = ''; var s = el('span', 'pub-spinner'); btn.appendChild(s); btn.appendChild(document.createTextNode(' ' + (label || 'Working…'))); }
        else { btn.textContent = label || 'Submit'; }
    }

    function fmtDate(v) {
        if (!v) { return '—'; }
        var d = new Date(String(v).replace(' ', 'T') + (String(v).indexOf('T') === -1 ? 'Z' : ''));
        if (isNaN(d.getTime())) { return String(v).slice(0, 16).replace('T', ' '); }
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // ── Submit ────────────────────────────────────────────────────────────────
    async function handleSubmit(form) {
        var root = container(form);
        var csrf = root.getAttribute('data-csrf');
        showError(root, 'submit-error', '');
        var btn = bindEl(root, 'submit-btn');
        busy(btn, true, 'Submitting…');

        var res = await call('submitTicket', fields(form), csrf);
        busy(btn, false, 'Submit Request');

        if (res && res.ok) {
            var id = res.data && res.data.ticket_id ? res.data.ticket_id : '—';
            var tid = bindEl(root, 'done-ticket-id'); if (tid) { tid.textContent = id; }
            bindEl(root, 'submit-form').hidden = true;
            bindEl(root, 'submit-done').hidden = false;
            window.scrollTo(0, 0);
        } else {
            showError(root, 'submit-error', (res && res.error) ? res.error : 'Could not submit. Please try again.');
        }
    }

    function submitAnother(root) {
        var form = q('form[data-action="submit-ticket"]', root);
        if (form) { form.reset(); }
        showError(root, 'submit-error', '');
        bindEl(root, 'submit-done').hidden = true;
        bindEl(root, 'submit-form').hidden = false;
    }

    // ── Status ────────────────────────────────────────────────────────────────
    async function handleStatus(form) {
        var root = container(form);
        var csrf = root.getAttribute('data-csrf');
        var result = bindEl(root, 'status-result');
        showError(root, 'status-error', '');
        if (result) { result.hidden = true; result.textContent = ''; }
        var btn = bindEl(root, 'status-btn');
        busy(btn, true, 'Checking…');

        var res = await call('checkTicketStatus', fields(form), csrf);
        busy(btn, false, 'Check Status');

        if (res && res.ok && res.data && res.data.ticket) {
            renderTicket(result, res.data);
        } else {
            showError(root, 'status-error', (res && res.error) ? res.error : 'No ticket matches that ID and email.');
        }
    }

    var STATUS_LABELS = { open: 'Open', pending: 'In Progress', resolved: 'Resolved', closed: 'Closed' };

    function metaItem(label, value) {
        var item = el('div', 'pub-meta-item');
        item.appendChild(el('div', 'pub-meta-label', label));
        item.appendChild(el('div', 'pub-meta-value', value));
        return item;
    }

    function renderTicket(root, data) {
        if (!root) { return; }
        root.textContent = '';
        var t = data.ticket;

        var head = el('div', 'pub-res-head');
        head.appendChild(el('span', 'pub-res-id', t.ticket_id));
        head.appendChild(el('span', 'status-chip chip-' + (t.status || 'open'), STATUS_LABELS[t.status] || t.status));
        root.appendChild(head);

        root.appendChild(el('div', 'pub-res-subject', t.subject));

        var grid = el('div', 'pub-meta-grid');
        grid.appendChild(metaItem('Priority', t.priority));
        grid.appendChild(metaItem('Submitted', fmtDate(t.created_at)));
        if (t.resolved_at) { grid.appendChild(metaItem('Resolved', fmtDate(t.resolved_at))); }
        root.appendChild(grid);

        // Latest agent reply (customer-visible only; internal notes never returned).
        var replies = (data.messages || []).filter(function (m) { return m.from_type === 'agent'; });
        var last = replies.length ? replies[replies.length - 1] : null;
        if (last) {
            var block = el('div', 'pub-reply');
            var rh = el('div', 'pub-reply-head');
            rh.appendChild(el('span', 'pub-reply-from', 'Latest reply from ' + (last.from_name || 'Support')));
            rh.appendChild(el('span', 'pub-reply-time', fmtDate(last.created_at)));
            block.appendChild(rh);
            block.appendChild(el('div', 'pub-reply-text', last.text));
            root.appendChild(block);
        } else {
            root.appendChild(el('div', 'pub-no-reply', 'Our team has received your request and will reply soon.'));
        }
        root.hidden = false;
    }

    // ── delegation ──────────────────────────────────────────────────────────────
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var action = form.getAttribute && form.getAttribute('data-action');
        if (action === 'submit-ticket') { e.preventDefault(); handleSubmit(form); }
        else if (action === 'check-status') { e.preventDefault(); handleStatus(form); }
    });
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-action="submit-another"]') : null;
        if (t) { var root = container(t); if (root) { submitAnother(root); } }
    });
})();
