/* P3A app.js — the single frontend entrypoint for authenticated pages.
 *
 * D5: NO inline event handlers anywhere. All interactivity is delegated: elements
 * declare data-action="name" and one listener maps it to a handler in the registry.
 * This is what makes a strict `script-src 'self'` CSP genuinely protective.
 *
 * Everything server-bound goes through call(action, payload) → POST /api.
 */
(function () {
    'use strict';

    var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

    /** Call the /api gateway. Returns the parsed {ok,data|error} envelope. */
    async function call(action, payload) {
        var res = await fetch('/api', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, payload: payload || {}, csrf: csrf })
        });
        try { return await res.json(); }
        catch (e) { return { ok: false, error: 'Malformed server response.' }; }
    }

    // ── Theme (§12) ──────────────────────────────────────────────────────────
    var THEME_KEY = 'sd_theme';
    function currentTheme() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }
    function toggleTheme() {
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* private mode */ }
        document.documentElement.setAttribute('data-theme', next);
    }

    // ── Pagination (§12): 10/list, per-list state, clamp when data shrinks ────
    function createPaginator(items, perPage) {
        perPage = perPage || 10;
        var page = 1;
        return {
            setItems: function (next) { items = next || []; this.clamp(); },
            get pageCount() { return Math.max(1, Math.ceil(items.length / perPage)); },
            get page() { return page; },
            clamp: function () { page = Math.min(Math.max(1, page), this.pageCount); return page; },
            go: function (p) { page = p; return this.clamp(); },
            next: function () { return this.go(page + 1); },
            prev: function () { return this.go(page - 1); },
            slice: function () {
                this.clamp();
                var start = (page - 1) * perPage;
                return items.slice(start, start + perPage);
            }
        };
    }

    // ── Delegated event registry (D5) ────────────────────────────────────────
    var actions = {
        'toggle-theme': function () { toggleTheme(); }
    };
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-action]') : null;
        if (!el) return;
        var name = el.getAttribute('data-action');
        if (actions[name]) { e.preventDefault(); actions[name](el, e); }
    });

    /** Register more delegated actions from later phases. */
    function on(name, handler) { actions[name] = handler; }

    // ── Bindings helper: set text of [data-bind="key"] ───────────────────────
    function bind(key, value) {
        document.querySelectorAll('[data-bind="' + key + '"]').forEach(function (el) {
            el.textContent = value;
        });
    }

    // ── Boot ─────────────────────────────────────────────────────────────────
    async function boot() {
        var me = await call('getMe');
        if (me && me.ok && me.data && me.data.authenticated) {
            bind('user-name', me.data.name);
            bind('user-role', me.data.role);
        }
        if (document.body.getAttribute('data-role') === 'customer') {
            var portal = await call('getPortalData');
            if (portal && portal.ok && portal.data && portal.data.branding) {
                bind('company-name', portal.data.branding.company_name || 'support');
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Public surface for later phases.
    window.P3A = { call: call, on: on, bind: bind, createPaginator: createPaginator };
})();
