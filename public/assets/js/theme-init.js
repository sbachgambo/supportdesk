/* P3A theme-init — runs synchronously in <head> before paint to avoid a flash of
   the wrong theme. Self-hosted (CSP script-src 'self'). Public pages default light;
   the dashboard defaults to the system preference. Shared key: sd_theme (§12). */
(function () {
    try {
        var KEY = 'sd_theme';
        var saved = localStorage.getItem(KEY);
        var systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var isDashboard = document.documentElement.getAttribute('data-app') === '1'
            || document.body && document.body.getAttribute('data-role');
        // On the dashboard, honour the system preference when nothing is saved.
        var theme = saved || (systemDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
