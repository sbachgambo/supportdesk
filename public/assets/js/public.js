/* P3A public.js (Phase 9) — submit + status forms on the public/widget surfaces.
 * Posts to /api with the stateless HMAC CSRF token (D6). No inline handlers;
 * rendering uses textContent so ticket data can never inject markup. */
(function () {
    'use strict';

    function container(el) { return el.closest('[data-form]'); }

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

    function setMsg(root, bind, text, isError) {
        var el = root.querySelector('[data-bind="' + bind + '"]');
        if (!el) { return; }
        el.textContent = text;
        el.classList.toggle('is-error', !!isError);
    }

    async function handleSubmit(form) {
        var root = container(form);
        var csrf = root.getAttribute('data-csrf');
        setMsg(root, 'submit-msg', 'Submitting…', false);
        var res = await call('submitTicket', fields(form), csrf);
        if (res && res.ok) {
            var id = res.data && res.data.ticket_id ? ' Your reference is ' + res.data.ticket_id + '.' : '';
            setMsg(root, 'submit-msg', (res.data && res.data.message ? res.data.message : 'Received.') + id, false);
            form.reset();
        } else {
            setMsg(root, 'submit-msg', (res && res.error) ? res.error : 'Could not submit.', true);
        }
    }

    async function handleStatus(form) {
        var root = container(form);
        var csrf = root.getAttribute('data-csrf');
        var result = root.querySelector('[data-bind="status-result"]');
        setMsg(root, 'status-msg', 'Looking up…', false);
        if (result) { result.hidden = true; result.textContent = ''; }

        var res = await call('checkTicketStatus', fields(form), csrf);
        if (res && res.ok && res.data && res.data.ticket) {
            setMsg(root, 'status-msg', '', false);
            renderTicket(result, res.data);
        } else {
            setMsg(root, 'status-msg', (res && res.error) ? res.error : 'Not found.', true);
        }
    }

    function renderTicket(container, data) {
        if (!container) { return; }
        container.textContent = '';
        var t = data.ticket;
        var h = document.createElement('h2');
        h.textContent = t.ticket_id + ' — ' + t.subject;
        var meta = document.createElement('p');
        meta.textContent = 'Status: ' + t.status + ' · Priority: ' + t.priority;
        container.appendChild(h);
        container.appendChild(meta);

        (data.messages || []).forEach(function (m) {
            var msg = document.createElement('div');
            msg.className = 'p3a-msg p3a-msg-' + m.from_type;
            var who = document.createElement('strong');
            who.textContent = (m.from_type === 'agent' ? 'Support' : (m.from_name || 'You')) + ': ';
            var body = document.createElement('span');
            body.textContent = m.text;              // textContent → no markup injection
            msg.appendChild(who);
            msg.appendChild(body);
            container.appendChild(msg);
        });
        container.hidden = false;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var action = form.getAttribute && form.getAttribute('data-action');
        if (action === 'submit-ticket') { e.preventDefault(); handleSubmit(form); }
        else if (action === 'check-status') { e.preventDefault(); handleStatus(form); }
    });
})();
