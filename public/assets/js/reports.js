/* P3A reports.js (Phase 8) — populates KPI cards + Chart.js charts from getReports.
 * Chart.js is self-hosted and loaded on demand (CSP script-src 'self'). Period
 * switcher and export are data-* driven — no inline handlers (D5). */
(function () {
    'use strict';
    var P3A = window.P3A;
    if (!P3A) { return; }

    var assetBase = document.body.getAttribute('data-asset-base') || '/assets/';
    var period = 30;
    var charts = {};

    function loadChartJs() {
        return new Promise(function (resolve, reject) {
            if (window.Chart) { return resolve(); }
            var s = document.createElement('script');
            s.src = assetBase + 'vendor/chart.min.js';
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('chart lib failed')); };
            document.head.appendChild(s);
        });
    }

    function themeColors() {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            text: dark ? '#e5e7eb' : '#1f2937',
            grid: dark ? '#2a3346' : '#e5e7eb',
            palette: ['#4057f5', '#12b76a', '#f79009', '#f04438', '#7c8cff', '#9aa4b2']
        };
    }

    function drawBar(key, labels, data) {
        var c = themeColors();
        var canvas = document.querySelector('[data-chart="' + key + '"]');
        if (!canvas) { return; }
        if (charts[key]) { charts[key].destroy(); }
        charts[key] = new window.Chart(canvas, {
            type: key === 'volume' ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: key === 'volume' ? 'rgba(64,87,245,0.15)' : c.palette,
                    borderColor: '#4057f5',
                    borderWidth: key === 'volume' ? 2 : 0,
                    fill: key === 'volume',
                    tension: 0.25
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: c.text }, grid: { color: c.grid } },
                    y: { beginAtZero: true, ticks: { color: c.text, precision: 0 }, grid: { color: c.grid } }
                }
            }
        });
    }

    function setText(bind, value) {
        var el = document.querySelector('[data-bind="' + bind + '"]');
        if (el) { el.textContent = value; }
    }

    function distToArrays(dist) {
        return { labels: Object.keys(dist), data: Object.keys(dist).map(function (k) { return dist[k]; }) };
    }

    async function render() {
        var res = await P3A.call('getReports', { period: period });
        if (!res || !res.ok) { return; }
        var d = res.data;
        setText('kpi-created', d.kpis.created);
        setText('kpi-resolved', d.kpis.resolved);
        setText('kpi-avg', d.kpis.avg_resolution_hours == null ? '—' : d.kpis.avg_resolution_hours);
        setText('kpi-sla', d.kpis.sla_compliance_pct == null ? '—' : d.kpis.sla_compliance_pct + '%');

        var link = document.querySelector('[data-bind="export-link"]');
        if (link) { link.setAttribute('href', assetBase.replace(/assets\/$/, '') + 'export/tickets.csv?period=' + period); }

        try {
            await loadChartJs();
            drawBar('volume', d.volume.map(function (p) { return p.date.slice(5); }), d.volume.map(function (p) { return p.count; }));
            var s = distToArrays(d.distributions.status); drawBar('status', s.labels, s.data);
            var p = distToArrays(d.distributions.priority); drawBar('priority', p.labels, p.data);
            var ch = distToArrays(d.distributions.channel); drawBar('channel', ch.labels, ch.data);
        } catch (e) { /* charts unavailable — KPI cards still populated */ }
    }

    P3A.on('report-period', function (el) {
        period = parseInt(el.getAttribute('data-period'), 10) || 30;
        document.querySelectorAll('[data-action="report-period"]').forEach(function (b) {
            b.classList.toggle('is-active', b === el);
        });
        render();
    });

    if (document.querySelector('[data-view="reports"]')) {
        render();
    }
})();
