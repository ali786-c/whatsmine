# Front Pages Refresh — WaGenius-Style Behavior & Animations

**Reference:** https://preview.codecanyon.net/item/wagenius-ai-whatsapp-crm-automation-platform/full_screen_preview/63012794
(demo source: `preview.wstacks.com/wagenius` — analyzed from its HTML/CSS/JS since the browser panel
blocked the host with `ERR_QUIC_PROTOCOL_ERROR`)

**Goal:** Give our marketing/front pages the same *feel* as the reference — motion on scroll, a
lively hero, marquees, sliders, a preloader — while keeping our own brand (green `#5a8b38` /
`#162610`), Inertia/React/Tailwind stack, and admin-editable landing content (`landing.*` keys).

---

## 1. What the reference does (evidence from its source)

| Behavior | Implementation in reference |
|---|---|
| Preloader | Full-screen `.preloader`, gradient-text loader (`@keyframes loader`), `fadeOut()` on `window.load` |
| Scroll reveal | **ScrollReveal.js**: `origin: top, distance: 60px, duration: 1500, delay: 100, reset: false`; hero title reveals from **bottom** (60ms); card grids get `interval: 100` stagger |
| Sticky header | Header gains `.fixed-header` after **300px** scroll |
| Scroll-to-top | Button appears after 300px, smooth jQuery animate to 0 |
| Brand marquee | Swiper: `loop: true, speed: 8000, autoplay: { delay: 0 }` — infinite logo ticker |
| Testimonials | Swiper autoplay slider (not a static grid) |
| Counters | Hero stats: **AI Response 92% / Message-Day 2.4M / Revenue Lift +380%** (stat-num/stat-lbl markup) |
| Chat mockup | "One CRM Two Channels" live WhatsApp/Telegram chat phone mockup section |
| FAQ | Accordion + "Chat with Us" WhatsApp CTA card |
| Pricing | Monthly/Yearly toggle + full comparison table (Yes/No rows) |
| Theme toggle | Dark/light switch, persisted in localStorage, respects `prefers-color-scheme` |
| Mobile nav | Offcanvas slide-in menu with overlay |
| Keyframes | `loader`, `pulse`, `shimmer`, `slide-down` |
| Toasts | iziToast |

## 2. Current state of our front pages

- `resources/js/Pages/Welcome.jsx` (1041 lines): Hero, Metrics, TrustedBy, Channels,
  Problem/Solution, Features, HowItWorks, Integrations strip, Why, Security, Pricing
  (monthly/yearly toggle ✅), Testimonials (**static grid**), FAQ (accordion, **no height
  transition**), CTA. All content driven by `landing.*` settings — must stay that way.
- `resources/js/Layouts/LandingLayout.jsx`: header is `sticky top-0` already (no scroll state),
  mobile menu is a plain dropdown (no offcanvas), **no scroll-to-top**, footer static.
- Marketing sub-pages: `marketing/Pricing`, `marketing/Faq`, `marketing/UseCases`,
  `marketing/About`, `marketing/Integrations`.
- **Missing vs reference:** preloader, scroll-reveal, counters, marquee, testimonial slider,
  header scroll state, scroll-to-top, chat-mockup hero visual, stagger/entrance motion anywhere.

## 3. Design decisions (lean-first)

- **No new animation framework.** A tiny `Reveal` primitive built on `IntersectionObserver`
  reproduces ScrollReveal's behavior (distance/delay/interval/once) in ~60 lines, ships zero
  dependencies, and respects `prefers-reduced-motion`. (framer-motion rejected: ~50KB gz for
  what we need; AOS rejected: DOM-mutation based, awkward in React.)
- **Marquee + shimmer via pure CSS** (`@keyframes` + duplicated track), no JS library.
- **Testimonial slider via CSS scroll-snap** + prev/next buttons + autoplay — native touch
  swipe on mobile, no Swiper dependency.
- **Counters** = same `Reveal` primitive + rAF count-up hook; parse the suffix from the string
  (`+380%`, `2.4M`) so admin-entered metric values keep working.
- Everything honors `prefers-reduced-motion: reduce` (reference does not; we will).
- Landing content keys (`landing.*`) and i18n (`react-i18next`) stay the source of truth —
  animations attach to existing elements, no content moves into code.

## 4. Work packages

### WP1 — Animation primitives (new: `resources/js/Components/motion/`)
1. `Reveal.jsx` — wrapper: `as`, `origin` (top/bottom/left/right), `distance` (px), `duration`,
   `delay`, `interval` (stagger index multiplier), `once=true`; adds `opacity/transform` classes
   only when JS + reduced-motion allows (SSR-safe: visible by default so no-JS/SEO is unaffected).
2. `useCountUp.js` — rAF counter, triggers on reveal, formats with prefix/suffix/commafier.
3. `ScrollTop.jsx` — floating button, visible after 300px, smooth scroll.
4. `Marquee.jsx` — infinite CSS ticker (pause on hover), clones children to fill the track.

### WP2 — Layout behaviors (`LandingLayout.jsx`)
1. Header scroll state: after 60–100px add backdrop-blur + shadow + slightly reduced height
   (reference waits 300px because its hero is tall; our header is always-sticky, so a smaller
   threshold reads better — verify visually).
2. Mobile menu → offcanvas slide-in from right with overlay (matches reference behavior).
3. Mount `ScrollTop`.
4. Footer link columns: subtle fade-in on reveal.

### WP3 — `Welcome.jsx` section upgrades
1. **Preloader** — brand mark + gradient text pulse, session-once (`sessionStorage`), max ~600ms,
   fades out on mount/load; skipped under reduced-motion.
2. **Hero** — staged entrance (badge → title → subtitle → CTAs → trust badges, 80ms stagger),
   add animated **stat counters** row using existing `metrics` keys (count-up on load), subtle
   floating/shimmer on the background grid.
3. **TrustedBy** → infinite logo marquee (keeps `stats_*_label` keys, becomes horizontal loop).
4. **Metrics band** → count-up on scroll into view.
5. **Features / Why / Security / Channels cards** → staggered reveal (interval 100ms) + keep the
   existing hover-glow.
6. **HowItWorks** → steps reveal sequentially; connector line draws in (CSS `scaleX` transition).
7. **NEW "Live inbox" mockup section** (the reference's signature "One CRM Two Channels") —
   CSS-only phone/chat mockup: messages pop in on a loop with a typing-indicator dots animation,
   showing our real channels (WhatsApp/Messenger/Instagram — we have 3+, better than their 2).
   Placed after `HowItWorksSection`. Renders with static fallback content, admin-toggleable via
   a new `livemock_enabled` landing key (default off until copy is reviewed? → default on, copy
   hardcoded-then-keyed like other sections).
8. **Testimonials** → scroll-snap carousel with autoplay + dots (grid fallback under
   reduced-motion).
9. **FAQ** → smooth height/opacity accordion (grid-template-rows 0fr→1fr trick, no JS measuring).
10. **CTA** → reveal + shimmer sweep across the primary button.
11. **Pricing** → cards stagger in; toggle already exists (add sliding-pill transition).

### WP4 — Marketing sub-pages
Apply `Reveal` + shared section headers to `marketing/Pricing|Faq|UseCases|About|Integrations`
so the whole front area feels consistent (light-touch: reveal + FAQ accordion only).

### WP5 — Verification
1. `npm run build` (committed build artifacts must be regenerated).
2. Browser preview: `/`, `/pricing`, `/faq`, `/use-cases`, `/about`, `/integrations` —
   desktop + 375px mobile: reveal timing, marquee smoothness, carousel swipe, offcanvas menu,
   scroll-top, preloader.
3. Reduced-motion emulation (DevTools) — page must be fully static & readable.
4. Lighthouse/a11y pass on `/`: no CLS from preloader, focus states intact, contrast unchanged.
5. LandingPageToggleTest + existing suite still green (`/c/php83/php.exe artisan test`).

## 5. Explicit non-goals

- No re-theming (keep green brand; reference's orange `#ff7d0e` is not being adopted).
- No backend/CMS schema changes beyond one optional `landing.*` toggle key.
- No blog section (we have no public blog posts on the landing route).
- No new npm dependencies.

## 6. Order & risk

WP1 → WP2 → WP3 → WP5(partial build check) → WP4 → WP5 full.
Highest-risk item: WP3.7 (live mockup) — ship last, it's the only purely new section.
Everything else is additive class-level motion with static fallbacks, so regression risk is low.
