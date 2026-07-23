/* landing.js — scroll-reveal for the marketing landing. Self-hosted (CSP script-src
 * 'self'). Reveals elements tagged .tf-reveal as they scroll into view, and fully
 * respects prefers-reduced-motion (shows everything immediately, no animation). */
(function () {
    'use strict';
    var els = document.querySelectorAll('.tf-reveal');
    if (!els.length) { return; }

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || !('IntersectionObserver' in window)) {
        for (var i = 0; i < els.length; i++) { els[i].classList.add('is-in'); }
        return;
    }

    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-in');
                io.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });

    els.forEach(function (el) { io.observe(el); });
})();
