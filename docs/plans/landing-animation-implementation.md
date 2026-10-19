# Implementation Plan — Landing Animation Refresh (in OUR system)

Companion to `docs/plans/landing-animation-refresh.md` (the *what*). This doc is the *how*:
exact files, APIs, and patterns, verified against the current codebase.

**Stack facts (verified):**
- Tailwind **v3.2** + `tailwind.config.js` — keyframes go in config or plain CSS, both work.
- CSS entry: `resources/css/app.css` (imported by `resources/js/app.jsx`), already has
  `@layer components` (L28) and existing `@keyframes` (L109+) — new keyframes live here.
- No animation library anywhere in `resources/js/` today — clean slate.
- `Components/` has no `motion/` dir yet; `Pages/marketing/*.jsx` (7 files) all wrap
  `LandingLayout` the same way as `Welcome.jsx`.
- Landing content keys are admin-managed via `LandingPageController::defaults()`; JSX reads
  `landing['landing.<key>']` with `s(key, def)` fallbacks — **no schema changes needed** for
  WP1–WP4 except one optional toggle (noted below).

---

## Files to create (4 + 1 optional)

```
resources/js/Components/motion/Reveal.jsx       # scroll-reveal wrapper (core primitive)
resources/js/Components/motion/useCountUp.js    # rAF counter hook
resources/js/Components/motion/Marquee.jsx      # infinite CSS ticker
resources/js/Components/motion/ScrollTop.jsx    # floating back-to-top button
resources/js/Components/marketing/LiveChatMock.jsx  # WP3.7 only (ship last)
```

All new files are pure client components — no Inertia/controller changes for them.

---

## 1. `Reveal.jsx` — the ScrollReveal replacement

API (matches reference behavior: `origin/distance/duration/delay/interval/once`):

```jsx
import { useEffect, useRef, useState } from 'react';

export default function Reveal({
    as: Tag = 'div',
    origin = 'bottom',      // top | bottom | left | right
    distance = 60,          // px
    duration = 900,         // ms (reference: 1500; ours tuned shorter — see note)
    delay = 0,              // ms
    interval = 0,           // ms — stagger multiplier from `index` prop
    index = 0,              // stagger index
    once = true,
    className = '',
    children,
    ...rest
}) {
    const ref = useRef(null);
    // `hidden` starts false → SSR/no-JS renders fully visible (SEO-safe).
    const [hidden, setHidden] = useState(false);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        setHidden(true);
        const io = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    setVisible(true);
                    if (once) io.disconnect();
                } else if (!once) setVisible(false);
            },
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
        );
        io.observe(el);
        return () => io.disconnect();
    }, [once]);

    const transform = {
        top:    `translateY(-${distance}px)`,
        bottom: `translateY(${distance}px)`,
        left:   `translateX(-${distance}px)`,
        right:  `translateX(${distance}px)`,
    }[origin];

    return (
        <Tag
            ref={ref}
            className={className}
            style={hidden && !visible ? {
                opacity: 0,
                transform,
                transition: `opacity ${duration}ms cubic-bezier(.22,1,.36,1) ${delay + index * interval}ms, transform ${duration}ms cubic-bezier(.22,1,.36,1) ${delay + index * interval}ms`,
                willChange: 'opacity, transform',
            } : hidden ? {
                opacity: 1,
                transform: 'none',
                transition: `opacity ${duration}ms cubic-bezier(.22,1,.36,1) ${delay + index * interval}ms, transform ${duration}ms cubic-bezier(.22,1,.36,1) ${delay + index * interval}ms`,
            } : undefined}
            {...rest}
        >
            {children}
        </Tag>
    );
}
```

**Why `hidden` starts `false`:** crawlers and no-JS see static content; the observer flips
`hidden → true` only after mount and only when motion is allowed. First-paint flash risk is one
frame, invisible in practice.

**Usage pattern in Welcome.jsx** (grid items pass `index` for the reference's `interval: 100`):

```jsx
<Reveal interval={100} index={idx} origin="bottom" distance={40}>
    <div className="group ...existing card markup unchanged... />
</Reveal>
```

Section headers: `<Reveal origin="bottom" delay={0}>`; hero: staged children with
`delay = idx * 80`.

---

## 2. `useCountUp.js`

```js
import { useEffect, useRef, useState } from 'react';

// Animates when `active` flips true. Parses trailing suffix from the target string
// so admin-entered values like "+380%", "2.4M", "92%" all work.
export function useCountUp(target, active, duration = 1400) {
    const [display, setDisplay] = useState(target);
    const raf = useRef(null);

    useEffect(() => {
        if (!active) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const m = String(target).match(/^([^\d\-]*)(-?[\d.,]+)(.*)$/);
        if (!m) return;                       // not a plain number → show as-is
        const [, prefix, numStr, suffix] = m;
        const end = parseFloat(numStr.replace(/,/g, ''));
        if (!Number.isFinite(end)) return;
        const decimals = (numStr.split('.')[1] || '').length;
        const start = performance.now();

        const tick = (now) => {
            const p = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - p, 3);           // ease-out-cubic
            setDisplay(prefix + (end * eased).toFixed(decimals) + suffix);
            if (p < 1) raf.current = requestAnimationFrame(tick);
        };
        raf.current = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf.current);
    }, [active, target, duration]);

    return display;
}
```

Wire it with `Reveal`'s observer: add an optional `onVisible` callback prop to `Reveal`
(one line inside the intersect branch) so metrics sections do
`const v = useCountUpState()` without a second observer.

---

## 3. `Marquee.jsx` + CSS

```jsx
export default function Marquee({ speed = 40, pauseOnHover = true, className = '', children }) {
    return (
        <div className={`wm-marquee ${pauseOnHover ? 'wm-marquee--pause' : ''} ${className}`}>
            <div className="wm-marquee__track" style={{ animationDuration: `${speed}s` }}>
                <div className="wm-marquee__group">{children}</div>
                <div className="wm-marquee__group" aria-hidden="true">{children}</div>
            </div>
        </div>
    );
}
```

CSS in `resources/css/app.css` (new `@layer utilities` block — Tailwind v3 has no built-in
marquee):

```css
.wm-marquee { overflow: hidden; -webkit-mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent); mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent); }
.wm-marquee__track { display: flex; width: max-content; animation: wm-marquee linear infinite; }
.wm-marquee__group { display: flex; align-items: center; gap: 3rem; padding-right: 3rem; flex-shrink: 0; }
.wm-marquee--pause:hover .wm-marquee__track { animation-play-state: paused; }
@keyframes wm-marquee { from { transform: translateX(0); } to { transform: translateX(-50%); } }
@media (prefers-reduced-motion: reduce) { .wm-marquee__track { animation: none; } }
```

Applied to: `TrustedBySection` (brands) — replaces the static flex-wrap row. Testimonials use
scroll-snap instead (below).

---

## 4. `ScrollTop.jsx`

Reference behavior verbatim: show after `scrollTop > 300`, smooth-scroll to 0 (native
`window.scrollTo({ behavior: 'smooth' })` — no jQuery). Fixed bottom-right, `z-40`, fades via
`opacity/translate` transition. Mounted once in `LandingLayout` above `<Footer>`.

---

## 5. `LandingLayout.jsx` edits (3 surgical changes)

1. **Header scroll state** — add `useEffect` scroll listener with a `scrolled` state
   (rAF-throttled, threshold **100px** since our header is always sticky unlike reference's
   300px-then-fixed). Apply to the existing `<header className="sticky top-0 z-50 ...">`:
   `scrolled` adds `shadow-lg backdrop-blur-md bg-[#162610]/90` + shrinks vertical padding
   (`py-3 → py-2` via transition).
2. **Mobile menu → offcanvas** — keep `mobileOpen` state; change the dropdown panel markup to
   a fixed right drawer: `translate-x-full → translate-x-0` transition (300ms), overlay div
   with `bg-black/50` fade, close on overlay click. Existing links/close logic untouched.
3. **Mount `<ScrollTop />`.**

No prop/API changes → other pages using `LandingLayout` inherit all three for free.

---

## 6. `Welcome.jsx` edits (per-section, all additive)

| Section | Change |
|---|---|
| Page root | Preloader component (inline ~25 lines): `useState(loaded)` + `useEffect` on `window.load` **or** 700ms timeout whichever first; `sessionStorage.getItem('wm-loaded')` skips it on repeat visits; renders brand mark + pulsing gradient text; `opacity-0 pointer-events-none` then unmount. Add to `app.css`: reuse existing `ai-glow-pulse` keyframe for the loader. |
| `HeroSection` | Wrap badge/title/subtitle/CTAs/trust in `<Reveal origin="bottom" delay={i*80}>` each. Background grid gets a slow `transform: scale()` pulse (existing radial gradient, 20s, CSS-only). |
| `MetricsSection` | Each stat: `Reveal` + `useCountUp(m.value, visible)` — value string drives prefix/suffix parsing. |
| `TrustedBySection` | Static flex row → `<Marquee speed={35}>` around the brand spans (keep `stats_*_label` keys). |
| `FeaturesSection` | Each card wrapped: `<Reveal interval={100} index={idx}>` (reference's exact stagger). |
| `HowItWorksSection` | Steps: `<Reveal delay={idx * 150}>`; connector line: CSS `transform: scaleX(0) → 1` on reveal (add `origin="left"` Reveal around it). |
| `TestimonialsSection` | Grid → scroll-snap carousel: `flex overflow-x-auto snap-x snap-mandatory scrollbar-hide` + 3 dots + prev/next buttons; autoplay via `scrollBy` every 5s (paused on hover/reduced-motion). Fallback: under reduced-motion keep grid. Markup stays component-internal. |
| `FaqSection` | Height animation via `grid-template-rows: 0fr → 1fr` trick on the answer wrapper + `transition: grid-template-rows 300ms ease` (replaces the `open === idx &&` mount/unmount). Chevron rotate already exists. |
| `PricingSection` | Cards: `<Reveal interval={100} index={idx}>`. Toggle pill: add sliding highlight (absolute-positioned span with `translate-x` transition). |
| `CtaSection` | `Reveal`; primary button shimmer: pseudo-element sweep (`@keyframes wm-shimmer`) on hover — demo has `shimmer` keyframe. |
| `Channels / Why / Security / ProblemSolution / IntegrationsStrip` | `<Reveal interval={100} index={idx}>` on cards only — headers get plain `Reveal`. |
| **NEW `LiveChatMock`** | **Ship last.** Static CSS phone mockup: two stacked chat bubbles (WhatsApp green + Messenger blue) with a typing-dots animation, messages revealed in a 4s CSS loop (`animation-delay` per bubble, `animation: infinite`). No JS. Section gated by existing pattern `s('livemock_enabled') !== '1' && return null` — add key `'landing.livemock_enabled' => '1'` to `LandingPageController::defaults()` (one line; seeder auto-picks it up on fresh installs, and `s()` fallback `'0'`-safe behavior means old DBs simply keep it hidden until admin enables... **decision: default `'1'` in defaults() + graceful hide when key absent** so existing installs stay unchanged until reseeded/admin-saved). |

`LandingPageController::defaults()` needs exactly **one new line** (`livemock_enabled`) —
verified the pattern at L40-46.

---

## 7. Marketing sub-pages (WP4)

`marketing/Faq.jsx` gets the same FAQ grid-rows accordion (extract `FaqAccordion` into
`Components/marketing/FaqAccordion.jsx` and import from both `Welcome.jsx` and `marketing/Faq.jsx`
— avoids duplicating the animated version). Other 5 pages: wrap section headers + card grids in
`Reveal` only (~5 lines each). Skip on `CmsPage.jsx`/`Contact.jsx` (contact form UX stays static).

---

## 8. Order of work + verification gates

```
1. app.css keyframes + tailwind config (if needed)      → npm run build passes
2. Reveal.jsx + useCountUp.js                          → isolated: Reveal visible w/o JS
3. LandingLayout edits (header/offcanvas/ScrollTop)     → build + visual check
4. Welcome.jsx: preloader, hero, metrics, TrustedBy     → build + preview /
5. Welcome.jsx: card staggers, FAQ, testimonials, CTA   → preview / (all sections)
6. Marketing pages + FaqAccordion extraction            → preview /faq /pricing
7. LiveChatMock + defaults() line                       → preview + admin landing toggles
8. FINAL: npm run build (committed artifacts refreshed)
   /c/php83/php.exe artisan test tests/Feature/LandingPageToggleTest.php  (must stay green)
   /c/php83/php.exe artisan test tests/Feature/ProductionHardening/QrMediaSecurityTest.php
   Browser: /, /pricing, /faq, /use-cases, /about, /integrations @ 1440px + 375px
   DevTools reduced-motion emulation → page fully static
   Lighthouse a11y on / → no regressions (focus, contrast, CLS from preloader)
```

**Risks:**
- Preloader CLS → overlay is `position: fixed; inset: 0`, body never blocked from rendering.
- Marquee perf → CSS transform only, `will-change` off (single layer), pause on hover.
- Reduced-motion → every animation has an opt-out; `Reveal` never hides content.
- `npm run build` artifacts are committed in this repo (established in the docs-website task) —
  step 8 is mandatory before any commit.
