/* P3A mfa.js (Phase 12) — drives the /mfa challenge/enrolment against /api.
 * Self-contained (the public 'main' layout doesn't load app.js). No inline handlers. */
(function () {
    'use strict';
    var root = document.querySelector('[data-view="mfa"]');
    if (!root) { return; }
    var csrf = root.getAttribute('data-csrf');

    async function call(action, payload) {
        var res = await fetch('/api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, payload: payload || {}, csrf: csrf })
        });
        try { return await res.json(); } catch (e) { return { ok: false, error: 'Server error.' }; }
    }
    function show(region, on) {
        var el = root.querySelector('[data-region="' + region + '"]');
        if (el) { el.hidden = !on; }
    }
    function msg(text, err) {
        var el = root.querySelector('[data-bind="mfa-msg"]');
        if (!el) { return; }
        el.textContent = text || '';
        el.classList.toggle('error', !!err);
        el.classList.toggle('info', !err && !!text);
        el.classList.toggle('show', !!text);
    }
    function fieldVal(region, name) {
        var el = root.querySelector('[data-region="' + region + '"] [name="' + name + '"]');
        return el ? el.value : '';
    }

    async function init() {
        var res = await call('getMfaStatus');
        if (res && res.ok && res.data.verified) { location.href = '/dashboard'; return; }
        if (res && res.ok && res.data.enabled) {
            show('mfa-challenge', true);
        } else {
            var enroll = await call('enrollTotp');
            if (enroll && enroll.ok) {
                root.querySelector('[data-bind="mfa-secret"]').textContent = enroll.data.secret;
                var link = root.querySelector('[data-bind="mfa-uri"]');
                link.setAttribute('href', enroll.data.uri);
                show('mfa-enroll', true);
            } else {
                msg((enroll && enroll.error) || 'Could not start enrolment.', true);
            }
        }
    }

    root.addEventListener('submit', async function (e) {
        var action = e.target.getAttribute('data-action');
        if (action === 'mfa-verify') {
            e.preventDefault();
            var res = await call('verifyMfa', { code: fieldVal('mfa-challenge', 'code') });
            if (res && res.ok) { location.href = '/dashboard'; } else { msg((res && res.error) || 'Invalid code.', true); }
        } else if (action === 'mfa-confirm') {
            e.preventDefault();
            var res2 = await call('confirmTotp', { code: fieldVal('mfa-enroll', 'code') });
            if (res2 && res2.ok) {
                show('mfa-enroll', false);
                var list = root.querySelector('[data-bind="mfa-backup-list"]');
                (res2.data.backup_codes || []).forEach(function (c) {
                    var li = document.createElement('li');
                    li.textContent = c;
                    list.appendChild(li);
                });
                show('mfa-backup', true);
                msg('');
            } else {
                msg((res2 && res2.error) || 'Invalid code.', true);
            }
        }
    });

    init();
})();
