# WhatsMine homepage UI/UX consistency plan

## Diagnosis

The prototype currently has good individual visuals, but they do not yet feel like one product story. The main causes are different visual languages competing, repeated capabilities, abrupt section scale changes, inconsistent text hierarchy, too many motion systems, and screenshots being presented with inconsistent framing.

The fix is a single system and a deliberate story order, not more isolated effects.

## Experience goal

Within the first screen, a visitor should understand that WhatsMine brings customer conversations, AI, automations and campaigns into one team workspace. Later sections should answer: what is it, how does it work, what can the team do, can it fit the existing stack, and what should happen next.

## Information architecture

1. Hero — one promise, one supporting sentence and two CTAs. Keep the robot as the brand character and use UI proof as a supporting panel.
2. Channel proof strip — compact supported channel icons, not a second hero.
3. Unified Inbox — explain receive, assign, reply and handover with the existing inbox preview.
4. Product walkthrough — keep the four-card Dashboard, Inbox, Automation Builder and Message Templates stack. This is the only long pinned scroll animation.
5. Business outcomes — use the new assets for collaboration, campaign reporting, contacts and Instagram automation. One image and one message per row.
6. Ecommerce and operations — use the order-confirmation workflow image only if the same story is not already repeated in the stack.
7. Integrations — replace the large continuously rotating orbit with a calm connection diagram showing six to eight priority integrations first.
8. Trust and proof — use real product facts only. Until real customer numbers exist, show capability proof instead of invented reviews.
9. Plans — equal-height plan cards, aligned CTAs and clearly marked illustrative pricing.
10. FAQ and final CTA — four high-intent answers followed by one clear action.

## Design tokens

### Color

The homepage should feel light first. Dark green is reserved for contrast sections and product framing, not used as the default background.

- ivory-50 (#FCFDF9): main page background.
- white (#FFFFFF): product panels, cards and navigation.
- ink-900 (#17352A): headings, navigation and primary text.
- ink-600 (#587064): body copy and supporting labels.
- emerald-600 (#218A5B): primary action, success states and active controls.
- emerald-100 (#DDF4E7): soft success panels and message highlights.
- lime-300 (#D6F279): high-attention accent for the primary CTA, progress and small highlights.
- sky-100 (#EAF5FA): occasional channel or integration background.
- lavender-100 (#F1EEFA): occasional AI or automation background.
- sand-100 (#FFF5DF): occasional campaign or ecommerce background.
- line-200 (#E2EAE2): borders and separators.
- forest-950 (#0D281C): hero, product stack, final CTA and footer only.

Use one soft tint per section, with white cards above it. Keep emerald as the main product color and lime as a small accent. Sky, lavender and sand are supporting tints only; they should never become competing brand colors. Reserve red, orange and purple chart colors for the embedded product screenshots, where they communicate data meaning.

### Layout

- Content rail: 1120px maximum.
- Text measure: 440–560px.
- Desktop side padding: 40px; tablet: 28px; mobile: 20px.
- Section padding: 96px desktop, 64px tablet, 48px mobile.
- Grid gaps: 24px cards and 56–88px feature rows.
- Radius family: 12px controls, 18px cards, 24px feature panels.

### Type

- Display heading: 56–72px desktop, 38–44px mobile.
- Section heading: 40–52px desktop, 32–36px mobile.
- Body: 15–17px with 1.65–1.8 line height.
- Eyebrow: 9–11px uppercase with 1.6–2px letter spacing.
- Use one heading font and one body font throughout.

## Reusable component rules

Standardize SectionHeader, PrimaryButton, SecondaryButton, ProductFrame, FeatureRow, ProofStrip, PlanCard and FAQItem. Every product screenshot uses the same browser bar, border, shadow, caption and expand interaction. Do not show raw screenshots in one section and framed mockups in another.

## Picture-folder usage

- automation.png: primary automation proof.
- stat screen.png: primary campaign reporting proof.
- contact.png: contacts and segmentation proof.
- instgarm comment.png: social automation proof.
- f92239e4-3544-4f9d-861d-dd46d4f1c1c7.png: team collaboration image.
- Smiling Shopper with Chat Support.png: customer-support outcome image.
- templete UI.png: templates proof only when it is not repeated in the card stack.
- campign.png and templete.png: secondary detail or lightbox assets.

One section gets one primary visual. Secondary visuals are revealed by click or placed in a small supporting card.

## Motion strategy

- Hero: one entrance sequence and subtle robot float.
- Inbox: no continuous animation; allow interaction inside the preview.
- Product stack: pinned scroll; incoming card slides from below while the outgoing card scales to 0.9 and rotates 3 degrees.
- Feature rows: one reveal on entry and a restrained image hover scale.
- Integrations: static connection lines with a slow pulse, or rotation paused by default.
- CTA: one subtle line-draw effect only after the rest of the page is stable.

Respect reduced-motion preferences and the pause-motion control. Avoid simultaneous orbit rotation, floating chips, chart animation and scroll pinning in the same viewport.

## Responsive behavior

Desktop uses two-column hero and feature rows, readable screenshots and a pinned stack. Tablet reduces decorative elements and turns the integration diagram into a grid when labels collide. Mobile uses copy, visual, benefits and action in a single column; the robot loses crowded callouts, the stack becomes normal cards, and screenshots remain readable inside a framed viewport.

## Copy rules

- One promise per section.
- Avoid repeating connected, conversation, workspace and next step in every heading.
- Keep headings below two lines on desktop and three on mobile.
- Use benefit language followed by evidence.
- Use specific CTAs such as Explore the inbox, See automation in action and Connect your channels.
- Mark prototype-only prices, demo data and preview interactions clearly.

## Implementation phases

### Phase A — foundation

Freeze the current prototype as a reference, define CSS variables and component classes, remove duplicate markup and stale anchors, and inventory every image.

### Phase B — structure

Reorder sections, apply one content rail and section rhythm, frame all screenshots consistently, and remove duplicate capability explanations.

### Phase C — visual system

Apply the color system, type scale, radii, borders, buttons, image crops and light/dark section rhythm.

### Phase D — motion

Keep the product stack as the major scroll animation, then add entry reveals and hover states. Test reverse scroll, keyboard focus, reduced motion and pause controls.

### Phase E — QA

Review at 1440px, 1280px, 1024px, 768px, 390px and 320px. Check overflow, clipped text, broken anchors, sticky overlap, alt text, lazy loading and expand states. The page must remain understandable with motion disabled.

## Acceptance criteria

- A visitor can explain the product after the hero and inbox sections.
- No capability is described in more than one primary section.
- Sections share the same rail, type scale, button system, image frame and spacing rhythm.
- Only one major scroll animation is active at a time.
- Mobile is deliberate, not a squeezed desktop layout.
- Screenshots remain readable and human images retain their focal point.
- Every CTA has a clear destination or a prototype explanation.
