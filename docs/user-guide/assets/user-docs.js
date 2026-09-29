/* ============================================================
   WhatsMine User Guide — Shared behaviour
   Dark mode, mobile nav, active link, TOC scroll-spy, search
   ============================================================ */
(function () {
    'use strict';

    /* ── Theme ───────────────────────────────────────────── */
    var THEME_KEY = 'user-docs-theme';

    function applyTheme(t) {
        document.documentElement.classList.toggle('dark', t === 'dark');
        var btn = document.querySelector('.theme-toggle');
        if (btn) btn.textContent = t === 'dark' ? '☀️' : '🌙';
    }

    function storedTheme() {
        try { return localStorage.getItem(THEME_KEY); } catch (e) { return null; }
    }

    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    applyTheme(storedTheme() || (prefersDark ? 'dark' : 'light'));

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.theme-toggle');
        if (!btn) return;
        var isDark = document.documentElement.classList.contains('dark');
        var next = isDark ? 'light' : 'dark';
        applyTheme(next);
        try { localStorage.setItem(THEME_KEY, next); } catch (err) {}
    });

    /* ── Mobile sidebar ──────────────────────────────────── */
    document.addEventListener('click', function (e) {
        if (e.target.closest('.menu-toggle')) {
            var sb = document.querySelector('.docs-sidebar');
            if (sb) sb.classList.toggle('open');
        }
        // Close sidebar when a nav link is tapped on mobile
        if (e.target.closest('.docs-sidebar a.nav-link') && window.innerWidth <= 860) {
            var sb2 = document.querySelector('.docs-sidebar');
            if (sb2) sb2.classList.remove('open');
        }
    });

    /* ── Active sidebar link ─────────────────────────────── */
    (function () {
        var page = (location.pathname.split('/').pop() || 'index.html').split('#')[0];
        var links = document.querySelectorAll('.docs-sidebar a.nav-link');
        for (var i = 0; i < links.length; i++) {
            var href = links[i].getAttribute('href') || '';
            if (href === page || (page === '' && href === 'index.html')) {
                links[i].classList.add('active');
            }
        }
    })();

    /* ── TOC scroll-spy ──────────────────────────────────── */
    (function () {
        var toc = document.querySelector('.docs-toc');
        if (!toc) return;
        var heads = Array.prototype.slice.call(document.querySelectorAll('.docs-content h2[id]'));
        if (!heads.length) return;
        var tocLinks = {};
        Array.prototype.forEach.call(toc.querySelectorAll('a'), function (a) {
            var id = (a.getAttribute('href') || '').replace(/^#/, '');
            tocLinks[id] = a;
        });
        function onScroll() {
            var current = heads[0];
            for (var i = 0; i < heads.length; i++) {
                if (heads[i].getBoundingClientRect().top <= 120) current = heads[i];
            }
            Array.prototype.forEach.call(toc.querySelectorAll('a'), function (a) { a.classList.remove('active'); });
            if (current && tocLinks[current.id]) tocLinks[current.id].classList.add('active');
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    })();

    /* ── Search (client-side, over search-index.js) ──────── */
    (function () {
        var boxes = document.querySelectorAll('[data-docs-search]');
        if (!boxes.length) return;

        var INDEX = (window.DOCS_SEARCH_INDEX || []);
        var entries = [];
        INDEX.forEach(function (page) {
            (page.sections || []).forEach(function (sec) {
                entries.push({
                    title: sec.t,
                    page: page.title,
                    file: page.file,
                    text: sec.b,
                });
            });
        });

        function snippet(text, q) {
            var idx = text.toLowerCase().indexOf(q);
            if (idx < 0) return text.slice(0, 90);
            var start = Math.max(0, idx - 30);
            return (start > 0 ? '…' : '') + text.slice(start, idx + q.length + 60) + '…';
        }

        function search(q) {
            q = q.trim().toLowerCase();
            if (q.length < 2) return [];
            var words = q.split(/\s+/);
            var scored = [];
            for (var i = 0; i < entries.length; i++) {
                var e = entries[i];
                var hay = (e.title + ' ' + e.text).toLowerCase();
                var score = 0, ok = true;
                for (var w = 0; w < words.length; w++) {
                    if (hay.indexOf(words[w]) < 0) { ok = false; break; }
                    score += (e.title.toLowerCase().indexOf(words[w]) >= 0 ? 2 : 0) + 1;
                }
                if (ok) scored.push({ e: e, score: score });
            }
            scored.sort(function (a, b) { return b.score - a.score; });
            return scored.slice(0, 8).map(function (s) { return s.e; });
        }

        Array.prototype.forEach.call(boxes, function (box) {
            var input = box.querySelector('input');
            var panel = box.querySelector('.search-results');
            if (!input || !panel) return;

            function render(q) {
                var results = search(q);
                if (!q.trim() || q.trim().length < 2) { panel.classList.remove('open'); panel.innerHTML = ''; return; }
                if (!results.length) {
                    panel.innerHTML = '<div class="no-results">No results for “' + q.replace(/</g, '&lt;') + '”</div>';
                } else {
                    panel.innerHTML = results.map(function (r) {
                        return '<a href="' + r.file + '">'
                            + '<div class="result-title">' + r.title.replace(/</g, '&lt;') + '</div>'
                            + '<div class="result-snippet">' + snippet(r.text, q.trim().toLowerCase()).replace(/</g, '&lt;') + '</div>'
                            + '</a>';
                    }).join('');
                }
                panel.classList.add('open');
            }

            input.addEventListener('input', function () { render(input.value); });
            input.addEventListener('focus', function () { if (input.value) render(input.value); });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { panel.classList.remove('open'); input.blur(); }
                if (e.key === 'Enter') {
                    var first = panel.querySelector('a');
                    if (first) window.location.href = first.getAttribute('href');
                }
            });
            document.addEventListener('click', function (e) {
                if (!box.contains(e.target)) panel.classList.remove('open');
            });
        });
    })();
})();
