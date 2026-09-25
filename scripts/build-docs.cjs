#!/usr/bin/env node
/**
 * Builds docs/index.html — a single, self-contained documentation page
 * from every markdown file in the docs/ folder.
 *
 * Usage: node scripts/build-docs.js
 */
const fs = require('fs');
const path = require('path');
const { marked } = require('marked');

const DOCS_DIR = path.join(__dirname, '..', 'docs');
const OUT_FILE = path.join(DOCS_DIR, 'index.html');

// Sidebar groups, in display order. Every docs/*.md must appear exactly once.
const GROUPS = [
    {
        title: 'Getting Started',
        items: [
            { file: 'README.md', label: 'Overview' },
            { file: 'checklist.md', label: 'Project Checklist' },
            { file: 'guide.md', label: 'Local Setup Guide' },
            { file: 'setting.md', label: 'Pusher Setup & Troubleshooting' },
        ],
    },
    {
        title: 'WhatsApp',
        items: [
            { file: 'whatsapp_coexistence.md', label: 'Coexistence Guide (Meta Reference)' },
            { file: 'whatsapp_coexistence_implementation.md', label: 'Coexistence Implementation' },
        ],
    },
    {
        title: 'Instagram',
        items: [
            { file: 'instagram.md', label: 'Module Deep Dive' },
            { file: 'instagram_setup_guide.md', label: 'Setup & Operations Guide (v3)' },
            { file: 'instagram_plan.md', label: 'Comment-Automation Plan' },
            { file: 'instagram_fix_guide.md', label: 'DM Fix Guide (Historical)' },
        ],
    },
    {
        title: 'Changelog & Notes',
        items: [
            { file: 'feature.md', label: 'New Features (Sept 4, 2026)' },
            { file: 'ai_inbox_features.md', label: 'AI Chatbot & Inbox Features (Sept 25, 2026)' },
            { file: 'context.md', label: 'Inbox Fix — Context' },
        ],
    },
];

function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderDoc(file) {
    const raw = fs.readFileSync(path.join(DOCS_DIR, file), 'utf8');
    const lines = raw.split('\n');

    // First # heading becomes the section title; the rest is rendered as markdown.
    let title = file.replace(/\.md$/, '');
    let body = raw;
    if (lines[0] && lines[0].startsWith('# ')) {
        title = lines[0].replace(/^#\s+/, '').trim();
        body = lines.slice(1).join('\n').replace(/^\s*\n+/, '');
    }

    const html = marked.parse(body, { gfm: true, breaks: false });

    return {
        file,
        title,
        html,
        excerpt: body.replace(/[#>*`\[\]\|\-]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160),
    };
}

function build() {
    const docs = {};
    for (const group of GROUPS) {
        for (const item of group.items) {
            docs[item.file] = renderDoc(item.file);
        }
    }

    const nav = GROUPS.map((group) => {
        const links = group.items.map((item) => {
            const d = docs[item.file];
            return `<a class="nav-link" data-target="doc-${esc(item.file.replace(/[^a-z0-9]/gi, '_'))}" href="#${esc(item.file.replace(/\.md$/, ''))}">
                <span class="nav-label">${esc(item.label)}</span>
                <span class="nav-file">${esc(item.file)}</span>
            </a>`;
        }).join('\n');
        return `<div class="nav-group"><div class="nav-group-title">${esc(group.title)}</div>${links}</div>`;
    }).join('\n');

    const sectionList = GROUPS.flatMap((g) => g.items).map((item) => {
        const d = docs[item.file];
        const id = item.file.replace(/\.md$/, '');
        return `<section class="doc-section" id="doc-${esc(item.file.replace(/[^a-z0-9]/gi, '_'))}" data-title="${esc(d.title)} ${esc(item.label)} ${esc(d.excerpt)}">
            <header class="doc-header"><span class="doc-file">${esc(item.file)}</span><h1>${esc(d.title)}</h1></header>
            <div class="doc-body">${d.html}</div>
        </section>`;
    });
    const sections = sectionList.join('\n');

    const page = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WhatsMine — Documentation</title>
<style>
:root {
    --bg: #f8fafc; --bg-panel: #ffffff; --bg-sidebar: #0f172a; --bg-sidebar-hover: #1e293b;
    --text: #0f172a; --text-muted: #64748b; --text-sidebar: #cbd5e1; --text-sidebar-active: #ffffff;
    --accent: #16a34a; --accent-soft: #dcfce7; --border: #e2e8f0; --code-bg: #f1f5f9;
    --table-head: #f1f5f9; --note-bg: #eff6ff; --note-border: #3b82f6;
    --warn-bg: #fffbeb; --warn-border: #f59e0b; --danger-bg: #fef2f2; --danger-border: #ef4444;
}
html.dark {
    --bg: #0b1120; --bg-panel: #111827; --bg-sidebar: #0b1120; --bg-sidebar-hover: #1e293b;
    --text: #e2e8f0; --text-muted: #94a3b8; --text-sidebar: #94a3b8; --text-sidebar-active: #ffffff;
    --accent: #22c55e; --accent-soft: #14532d; --border: #1f2937; --code-bg: #1f2937;
    --table-head: #1f2937; --note-bg: #172554; --note-border: #3b82f6;
    --warn-bg: #451a03; --warn-border: #f59e0b; --danger-bg: #450a0a; --danger-border: #ef4444;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--bg); color: var(--text); line-height: 1.65; }
.layout { display: flex; min-height: 100vh; }
.sidebar { width: 300px; flex-shrink: 0; background: var(--bg-sidebar); color: var(--text-sidebar); position: sticky; top: 0; height: 100vh; overflow-y: auto; padding: 20px 14px; }
.brand { display: flex; align-items: center; gap: 10px; padding: 4px 10px 18px; }
.brand-badge { width: 34px; height: 34px; border-radius: 9px; background: var(--accent); display: grid; place-items: center; color: #fff; font-weight: 800; font-size: 15px; }
.brand-name { font-weight: 700; color: #fff; font-size: 15px; }
.brand-sub { font-size: 11px; color: var(--text-muted); }
.search-wrap { position: relative; margin: 0 4px 14px; }
.search-wrap svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: var(--text-muted); }
#search { width: 100%; background: var(--bg-sidebar-hover); border: 1px solid transparent; border-radius: 8px; color: #fff; padding: 8px 10px 8px 30px; font-size: 13px; outline: none; }
#search:focus { border-color: var(--accent); }
#search::placeholder { color: var(--text-muted); }
.nav-group-title { font-size: 10.5px; text-transform: uppercase; letter-spacing: .12em; color: var(--text-muted); padding: 16px 10px 6px; font-weight: 700; }
.nav-link { display: block; padding: 7px 10px; border-radius: 8px; color: var(--text-sidebar); text-decoration: none; font-size: 13.5px; margin: 1px 0; }
.nav-link:hover { background: var(--bg-sidebar-hover); color: var(--text-sidebar-active); }
.nav-link.active { background: var(--accent); color: #fff; }
.nav-link.active .nav-file { color: rgba(255,255,255,.7); }
.nav-file { display: block; font-size: 10.5px; color: var(--text-muted); font-family: ui-monospace, monospace; }
.nav-link.active .nav-label { font-weight: 600; }
.main { flex: 1; min-width: 0; }
.topbar { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 32px; background: color-mix(in srgb, var(--bg-panel) 88%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border); }
.crumb { font-size: 13px; color: var(--text-muted); }
.crumb b { color: var(--text); }
.topbar-actions { display: flex; gap: 8px; }
.btn { border: 1px solid var(--border); background: var(--bg-panel); color: var(--text); border-radius: 8px; padding: 6px 12px; font-size: 13px; cursor: pointer; }
.btn:hover { border-color: var(--accent); color: var(--accent); }
.content { max-width: 880px; margin: 0 auto; padding: 36px 32px 90px; }
.doc-section { display: none; background: var(--bg-panel); border: 1px solid var(--border); border-radius: 16px; padding: 34px 40px 42px; }
.doc-section.visible { display: block; }
.doc-header { border-bottom: 1px solid var(--border); padding-bottom: 16px; margin-bottom: 24px; }
.doc-file { font-family: ui-monospace, monospace; font-size: 11.5px; color: var(--accent); background: var(--accent-soft); padding: 2px 8px; border-radius: 99px; }
.doc-header h1 { margin: 10px 0 0; font-size: 26px; line-height: 1.25; }
.doc-body h1 { font-size: 19px; margin: 30px 0 10px; padding-top: 12px; border-top: 1px solid var(--border); }
.doc-body h2 { font-size: 20px; margin: 34px 0 12px; padding-top: 14px; border-top: 1px solid var(--border); }
.doc-body h3 { font-size: 16.5px; margin: 26px 0 10px; }
.doc-body h4 { font-size: 14.5px; margin: 20px 0 8px; }
.doc-body p { margin: 10px 0; }
.doc-body a { color: var(--accent); }
.doc-body ul, .doc-body ol { padding-left: 24px; margin: 10px 0; }
.doc-body li { margin: 4px 0; }
.doc-body code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .875em; background: var(--code-bg); padding: .15em .4em; border-radius: 5px; }
.doc-body pre { background: var(--code-bg); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; overflow-x: auto; font-size: 13px; line-height: 1.55; }
.doc-body pre code { background: transparent; padding: 0; font-size: 13px; }
.doc-body table { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 13.5px; display: block; overflow-x: auto; }
.doc-body th { background: var(--table-head); text-align: left; font-weight: 700; }
.doc-body th, .doc-body td { border: 1px solid var(--border); padding: 8px 12px; vertical-align: top; }
.doc-body blockquote { margin: 14px 0; padding: 10px 16px; border-left: 4px solid var(--note-border); background: var(--note-bg); border-radius: 0 10px 10px 0; }
.doc-body blockquote p { margin: 4px 0; }
.doc-body hr { border: 0; border-top: 1px solid var(--border); margin: 28px 0; }
.markdown-alert { border-radius: 0 10px 10px 0; padding: 12px 16px; margin: 14px 0; border-left: 4px solid var(--note-border); background: var(--note-bg); }
.markdown-alert-title { font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
.markdown-alert.caution, .markdown-alert.warning { border-left-color: var(--warn-border); background: var(--warn-bg); }
.markdown-alert.danger { border-left-color: var(--danger-border); background: var(--danger-bg); }
.no-results { display: none; text-align: center; color: var(--text-muted); padding: 60px 0; }
@media (max-width: 900px) {
    .layout { flex-direction: column; }
    .sidebar { position: relative; width: 100%; height: auto; }
    .content { padding: 20px 14px 60px; }
    .doc-section { padding: 22px 18px; }
    .topbar { padding: 10px 14px; }
}
@media print {
    .sidebar, .topbar, .btn { display: none !important; }
    .doc-section { display: block !important; border: 0; padding: 0; margin-bottom: 40px; }
}
</style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-badge">W</div>
            <div><div class="brand-name">WhatsMine Docs</div><div class="brand-sub">User &amp; Developer Guide</div></div>
        </div>
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input id="search" type="search" placeholder="Search documentation…" autocomplete="off">
        </div>
        <nav id="nav">${nav}</nav>
    </aside>
    <main class="main">
        <div class="topbar">
            <div class="crumb">Documentation / <b id="crumb-current">Overview</b></div>
            <div class="topbar-actions">
                <button class="btn" id="print-btn" type="button">Print / PDF</button>
                <button class="btn" id="theme-btn" type="button" aria-label="Toggle dark mode">◐ Theme</button>
            </div>
        </div>
        <div class="content">
            <div class="no-results" id="no-results">No sections match your search.</div>
            ${sections}
    </main>
</div>
<script>
(function () {
    var links = Array.prototype.slice.call(document.querySelectorAll('.nav-link'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('.doc-section'));
    var crumb = document.getElementById('crumb-current');
    var activeId = 'doc-README_md';

    function show(id, push) {
        activeId = id;
        var target = null;
        sections.forEach(function (s) { s.classList.toggle('visible', s.id === id); if (s.id === id) target = s; });
        links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('data-target') === id); });
        if (target) {
            crumb.textContent = target.querySelector('h1').textContent;
            if (push) history.replaceState(null, '', '#' + id.replace(/^doc-/, '').replace(/_/g, '-').toLowerCase());
            window.scrollTo({ top: 0 });
        }
    }

    function fromHash() {
        var h = decodeURIComponent(location.hash.replace('#', ''));
        if (!h) return 'doc-README_md';
        var id = 'doc-' + h.replace(/[^a-z0-9]/gi, '_');
        return sections.some(function (s) { return s.id === id; }) ? id : 'doc-README_md';
    }

    links.forEach(function (l) {
        l.addEventListener('click', function (e) {
            e.preventDefault();
            show(l.getAttribute('data-target'), true);
        });
    });

    document.getElementById('search').addEventListener('input', function (e) {
        var q = e.target.value.trim().toLowerCase();
        var any = false;
        if (!q) {
            // Search cleared — return to the single active section.
            sections.forEach(function (s) { s.classList.toggle('visible', s.id === activeId); });
            any = true;
        } else {
            sections.forEach(function (s) {
                var hay = (s.getAttribute('data-title') + ' ' + s.textContent).toLowerCase();
                var match = hay.indexOf(q) !== -1;
                s.classList.toggle('visible', match);
                if (match) any = true;
            });
        }
        document.getElementById('no-results').style.display = any ? 'none' : 'block';
    });

    document.getElementById('theme-btn').addEventListener('click', function () {
        document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('docs-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
    });
    try { if (localStorage.getItem('docs-theme') === 'dark') document.documentElement.classList.add('dark'); } catch (e) {}

    document.getElementById('print-btn').addEventListener('click', function () { window.print(); });

    window.addEventListener('hashchange', function () { show(fromHash(), false); });
    show(fromHash(), false);
})();
</script>
</body>
</html>`;

    fs.writeFileSync(OUT_FILE, page, 'utf8');
    const kb = (fs.statSync(OUT_FILE).size / 1024).toFixed(1);
    console.log('Built ' + path.relative(process.cwd(), OUT_FILE) + ' (' + kb + ' KB, ' + sectionList.length + ' sections)');
}

build();
