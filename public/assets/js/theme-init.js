/* P3A theme-init — runs synchronously in <head> before paint to avoid a flash of the
   wrong theme, and registers the delegated dark/light toggle (data-action="toggle-theme").
   Self-hosted (CSP script-src 'self'). Shared persistence key: sd_theme (§12). */
(function () {
    var KEY = 'sd_theme';
    try {
        var saved = localStorage.getItem(KEY);
        var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-theme', saved || (systemDark ? 'dark' : 'light'));
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
    // Delegated toggle — works on login, dashboard, and public pages alike.
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-action="toggle-theme"]') : null;
        if (!el) { return; }
        e.preventDefault();
        var cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        var next = cur === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(KEY, next); } catch (e2) { /* private mode */ }
        document.documentElement.setAttribute('data-theme', next);
    });
})();
