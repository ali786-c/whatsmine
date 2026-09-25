# Project Documentation

Sab documentation is folder me hai. **Aage se har nayi guide/plan/changelog yahi add hogi.**

> **HTML version:** `docs/index.html` — saari docs ek professional HTML page me (sidebar, search, dark mode, print/PDF). Rebuild: `npm run docs` (nayi .md add karne ke baad `scripts/build-docs.cjs` ke `GROUPS` me uska entry add karna zaroori hai).

## Index

| File | Kya hai |
|---|---|
| `instagram_setup_guide.md` | **Asli Instagram setup guide (v3)** — Meta dashboard config, webhook registration, supervisor/worker setup, 2-step button funnel + uncapped gate loop ke sath, aur issues #1–#32 ka changelog (har bug ka root cause + fix, features bhi) |
| `instagram_fix_guide.md` | Instagram fixes ka purana reference |
| `instagram_plan.md` | Instagram module ka original plan |
| `instagram.md` | Instagram module overview |
| `whatsapp_coexistence.md` | WhatsApp co-existence design |
| `whatsapp_coexistence_implementation.md` | WhatsApp co-existence implementation notes |
| `checklist.md` | Setup checklist |
| `context.md` | Project context |
| `feature.md` | Feature notes |
| `ai_inbox_features.md` | **AI Chatbot & Inbox Features (Sept 25, 2026)** — chatbot KB grounding, playground memory, quoted replies, media/voice notes, `microphone=(self)` header, aaPanel voice-note server requirements |
| `guide.md` | General guide |
| `setting.md` | Settings notes |

## Convention

- **Naya issue fix hua** → `instagram_setup_guide.md` (ya relevant guide) ke changelog me number ke sath add karo
- **Nayi feature/guide** → nayi file is folder me + upar index me row add karo
- Server-side deploy commands har guide me copy-paste ready rakho
