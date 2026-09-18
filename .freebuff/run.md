# Preview Run Doc — WhatsMine (Instagram guides)

## What to preview

The user watches the **setup guide** in the Preview tab, not the Laravel app:

- `meta_setup_guide.html` — rich HTML guide (v1.7): setup steps, follow-gate loop,
  Instagram Login migration section, troubleshooting, changelog. Self-contained
  (inline CSS, no build step, no server, no network calls).

## How to run

**No artifacts and no server are needed.** Register the preview directly:

```
register_preview { htmlPath: "<workspace>/meta_setup_guide.html" }
```

The file lives in the repo root and is tracked in git — a fresh checkout of this
thread's worktree already has it.

## Notes

- The markdown guides (`instagram_setup_guide.md` v2, `instagram_fix_guide.md`,
  `instagram.md`) are companions; the HTML file is the one rendered in Preview.
- To view the full Laravel app instead, a real dev environment (PHP 8.2+, composer,
  MySQL, `php artisan serve`) is required — it has never been run in Preview and
  the guide preview is the user's established workflow.
- After any edit to `meta_setup_guide.html`, simply reload the preview; the page
  is static.
