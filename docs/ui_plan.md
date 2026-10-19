# WhatsMine Public Website — Complete UI Redesign Plan

Date: 6 October 2026
Status: Proposed implementation specification; application redesign abhi implement nahi hua.
Language: Roman Urdu; sample website copy English hai aur localization se serve hogi.

## 1. Objective aur creative direction

Website ka first impression premium, memorable aur product-focused banana hai. Visitor ko pehli screen par samajh aaye: WhatsMine customer conversations, AI responses, marketing aur workflows ko ek business workspace mein organize karta hai. Visual excitement ke saath clear navigation, fast loading aur relevant information conversion ko support karegi; design customer retention ki guarantee nahi hai.

Creative direction: **Your business command center.** Signature visual ek detailed inbox/product stage hogi jahan connected channels, AI aur workflows ek coherent system ka experience dein. Deep forest backgrounds, warm ivory reading sections, confident typography aur selective amber/lime highlights use honge. Har page ka composition uske purpose ke mutabiq hoga.

Design principles:

- Hero mein readable value proposition, primary action aur product evidence together present hon.
- Product previews real implemented capabilities represent karein; demo content clearly sample ho.
- Animation user ko flow samjhaye, hierarchy establish kare aur interaction feedback de.
- Major sections mein varied layout ho: large product stage, editorial text, workflow strip, comparison table aur focused CTA.
- Layout whitespace, typography aur alignment se premium feel banaye; effects supporting layer hon.
- Light/dark section composition intentional ho. Public marketing palette client/admin theme tokens ko globally override na kare.

## 2. Scope aur page inventory

### 2.1 Core redesign pages — existing routes

| Page | Route | Existing file | Primary visitor goal |
|---|---|---|---|
| Home | `/` | `resources/js/Pages/Welcome.jsx` | Product samajhna aur demo/signup choose karna |
| Use Cases | `/use-cases` | `resources/js/Pages/marketing/UseCases.jsx` | Apne business ka relevant workflow dekhna |
| Integrations | `/integrations` | `resources/js/Pages/marketing/Integrations.jsx` | Required channels/services ki availability samajhna |
| Pricing | `/pricing` | `resources/js/Pages/marketing/Pricing.jsx` | Plans, billing aur limits compare karna |
| About | `/about` | `resources/js/Pages/marketing/About.jsx` | Product/company context aur trust assess karna |
| FAQ | `/faq` | `resources/js/Pages/marketing/Faq.jsx` | Setup, features aur commercial questions resolve karna |
| Contact | `/contact` | `resources/js/Pages/marketing/Contact.jsx` | Sales/setup enquiry submit karna |
| CMS / legal | `/p/{slug}` | `resources/js/Pages/marketing/CmsPage.jsx` | Published privacy/terms/other content read karna |

Core deliverable: seven named marketing pages plus one reusable CMS template. Privacy aur Terms pages tab show hon jab relevant CMS records published hon.

### 2.2 Proposed product detail pages — phase 2

These routes abhi existing public route inventory mein nahi hain. Product navigation initially homepage anchors use karegi. Detailed pages phase 2 mein content/backend route work ke saath add ki ja sakti hain.

| Proposed page | Proposed route | Specific content |
|---|---|---|
| Unified Inbox | `/features/inbox` | Channels, assignment, notes, labels, media aur handover |
| AI Chatbots | `/features/ai-chatbots` | KB grounding, playground, providers, memory aur controls |
| Automations | `/features/automations` | Triggers, graph builder, branching, waits aur integrations |
| Campaigns | `/features/campaigns` | WhatsApp/SMS/email, audience, personalization, scheduling |
| Social Publishing | `/features/social` | Connected accounts, composer, previews aur calendar |
| Ecommerce | `/features/ecommerce` | Store sync, order context aur template notifications |

Login/register/recovery UI business app entry points hain. Marketing header inko link karega; auth screens ka redesign separate phase hai. Client/admin application screens core public redesign scope mein include nahi hain.

## 3. Information architecture aur shared navigation

Desktop header:

- Left: configured WhatsMine logo, home link.
- Center: Product menu, Use Cases, Integrations, Pricing, Resources menu.
- Product menu: six product links; phase 1 homepage section anchors.
- Resources: About, FAQ aur configured documentation/user guide links.
- Right: locale switch, Sign In, primary Get Started action.
- Sales/demo action Contact ko navigate kare. Dedicated booking integration tab add ho jab configured ho.
- Signed-in users ke liye primary destination appropriate dashboard ho; existing `canLogin`, `canRegister`, `auth` behavior preserve ho.

Header desktop height approximately 80px, compact scrolled height 64px. Top par hero-compatible surface; scroll ke baad solid/blurred surface with border. Menu click aur keyboard se open ho; hover supporting behavior ho. Focus/escape handling aur current page indicator defined hon.

Mobile: 64px header, logo aur menu button. Menu accessible overlay panel mein navigation, locale aur actions show kare. Open state page scrolling lock kare; close par focus original button ko return ho. Bottom sticky CTA sirf selected long pages par optional; form/pricing content obscure na kare.

Footer: concise product statement, Product/Solutions/Resources/Company groups, published legal links, configured contact details aur copyright. Newsletter form sirf backend subscription support available ho to. Social logos sirf valid configured URLs par link hon. Missing links empty placeholder ya `#` action na banein.

## 4. Exact color system

Marketing-specific CSS custom properties scoped shell par define hon: `--marketing-*`. Tailwind aliases zaroorat par scoped classes se reference karein.

| Token | Hex | Use |
|---|---|---|
| ink | `#10231A` | Light surface par headings/body |
| forest-950 | `#071A12` | Hero/final CTA deep background |
| forest-900 | `#102B1D` | Dark panels/footer |
| forest-800 | `#203E2A` | Raised dark surfaces |
| brand | `#467235` | Existing brand identity, primary light-surface action |
| brand-hover | `#38592A` | Primary hover/pressed |
| ivory | `#FAF9F3` | Main reading background |
| white | `#FFFFFF` | Cards/forms/product screens |
| sage | `#EDF2E8` | Soft feature backgrounds |
| lime | `#D6F279` | Dark-surface CTA and selected highlight |
| amber | `#FFBF00` | Sparing emphasis, connection/activity accents |
| text-muted | `#526258` | Secondary text on light backgrounds |
| text-on-dark | `#F3F7EF` | Dark section headings/body |
| muted-on-dark | `#B8C8BC` | Dark section secondary copy |
| border-light | `#DDE5DA` | Light card/table boundaries |
| border-dark | `#35503E` | Dark panel boundaries |
| success | `#207A47` | Success states with text/icon |
| error | `#B83232` | Form errors with explanatory text |
| ai-accent | `#9B8AFB` | Small AI-specific visual marker |

Application rules:

- Ivory/white cover most reading areas; deep forest hero/footer aur selected feature scenes anchor visual rhythm.
- Lime buttons use dark ink text. Brand-green buttons use white text, subject to contrast validation.
- Amber ko body copy ya long backgrounds ke liye use na karein; small visual highlights mein rakhein.
- Channel logos apne official recognizable colors retain karein; branding policy/assets verify karne honge.
- Translucent borders/gradients decorative hon; readable text stable contrasting surface par ho.
- Contrast implementation par measure ho: normal text 4.5:1 target, large text/UI boundaries 3:1 where applicable. Tokens alone compliance proof nahi hain.

## 5. Typography, spacing aur shape

Font plan: existing **Space Grotesk** display headings ke liye aur existing system sans stack body/UI ke liye. Initial implementation mein ek hi web font family load karke weight/size se hierarchy banayein. Bengali Anek Bangla behavior preserve ho. Latin typography assumptions translated languages par force na hon.

| Role | Desktop | Mobile | Weight / line height |
|---|---|---|---|
| Hero H1 | Fluid 64–88px | Fluid 38–48px | 600, 1.04–1.12 |
| Page H1 | 56–72px | 36–44px | 600, 1.1 |
| Section H2 | 40–56px | 28–36px | 600, 1.15 |
| Card H3 | 22–28px | 20–24px | 600, 1.25 |
| Lead paragraph | 18–20px | 17–18px | 400, 1.6 |
| Body | 16px | 16px | 400, 1.6 |
| UI label | 14px | 14px | 500–600, 1.4 |
| Caption | 12–13px | 12–13px | 400–500, 1.5 |

Container max width: 1280px; large product stage up to 1440px. Horizontal gutter 20px mobile, 32px tablet, 48–64px desktop. Reading columns approximately 65–75 characters wide. Section spacing 96–128px desktop, 56–72px mobile. Base spacing scale 4/8/12/16/24/32/48/64/96/128px.

Radii: controls 10–12px, cards 16–20px, hero product stage 24–28px. Pill shapes badges/chips ke liye. Shadows low-opacity forest tint se use hon; contrast/hierarchy border aur surface se establish ho. Buttons height 48–52px, touch targets minimum 44px where practical.

## 6. Homepage — detailed content aur layout

### H1. Hero / first impression

Goal: visitor ko product proposition aur capability first viewport mein samajh aaye.

Copy draft: **Every conversation. One powerful workspace.** Supporting text: **Bring customer messaging, AI replies and business workflows together with WhatsMine.** Final copy localization/admin-content mapping ke waqt refine hogi.

Desktop: asymmetrical split; left 42% headline/copy/actions, right 58% large perspective product stage. Deep forest background, faint grid, one controlled light bloom. Stage preview actual inbox hierarchy ko reflect kare: channel list, conversation, AI draft, agent assignment aur sample order card.

Primary CTA Get Started → register if enabled. Secondary Explore Product → interactive preview/next product section. Talk to Us → Contact optional text action. Trial/free/no-card claims plan/configuration se derive hon; assume na karein.

Hero animation storyboard:

1. Text/actions initial readable render; brief opacity/translate entrance.
2. Product shell settles into position in roughly 700–1000ms.
3. Sample message arrives, AI response draft appears, assignment indicator updates.
4. Sequence approx 5–7 seconds; after first play calm resting state.
5. Tabs Support / Ecommerce / Marketing preview scenario change karein; replay explicit control ho.

Mobile: copy first, stacked preview second. Perspective/pointer tilt remove ho. Compact message cards retain meaning. Product controls accessible hon. Hero preview illustrative sample hai; live customer feed ka claim nahi.

### H2. Proof strip

Data: approved customer logos, testimonial attribution ya verified product/channel evidence. Customer logos absent hon to supported-channel strip show karein with correct label. Unsupported customer counts, ratings, revenue uplift aur success percentages publish na karein.

Design: ivory horizontal strip; readable static arrangement. Long list par gentle marquee optional with pause/reduced-motion behavior.

### H3. Connected channels

Headline draft: **Meet customers where they already talk.** Visual: channel badges smoothly converge into center inbox. Explain WhatsApp Cloud/QR, Instagram aur Messenger connection options; SMS/email capabilities ke exact send/receive coverage source verification ke baad wording decide karein.

Data: channel name, supported capability, setup link, connection requirement. Provider support aur external permission requirement accessible detail mein ho, implementation jargon primary copy mein nahi.

### H4. Unified inbox story

Ivory section: left three short chapters, right sticky inbox preview. Chapters: collect conversations → coordinate agents → respond with context. On desktop scroll progression selected chapter/product highlight update kare. Total pinned distance approximately 1–1.5 viewport heights, tuned during prototype.

Preview interactions: conversation selection, assignment highlight, internal note, label aur contact order context. Realistic sample content; buttons jo demo state change nahi karte decorative noninteractive elements hon. Mobile chapters vertically stacked, no pinned scrolling.

### H5. AI chatbot story

Sage/white composition with question, knowledge-source snippet aur answer card. Show configure knowledge → test reply → connect chatbot. Explain KB grounding, tone/instructions, human handover aur optional memories with qualified wording.

Sample question-answer pair authored aur deterministic ho. Website demo paid AI calls automatically na kare. CTA Explore AI → anchor/detail page; real playground authenticated app mein rahe.

### H6. Automation story

Deep forest stage, readable SVG workflow: event → condition → approved template → wait → next action. Connection path animates once on entry. Visitors Welcome / Order Update / Lead Follow-up scenarios select kar saken.

Data: actual trigger/node names mapped to friendly labels, workflow context aur result explanation. Ecommerce scenarios mein repository rule ke mutabiq `send_template` use ho. Unknown/incomplete feature illustrative label se clearly distinguish ho.

### H7. Business workflows

Three editorial feature rows/cards: Campaigns, Ecommerce, Social Publishing. Har ek mein specific visual ho: audience/message preview, order timeline, publishing calendar. Show actual capabilities: channel/audience/content/schedule/review; store/order context; multi-account composer/calendar.

Cards Use Cases ya feature detail destination ko link karein. Automatic order/revenue benefit claims published evidence ke bina na hon.

### H8. Integrations preview

Selected services grouped: Messaging, Stores, AI, Social. Display 8–12 recognizable icons aur clear integration category. Full catalog CTA `/integrations`. Visual graph restrained ho; all icon/line motion viewport se exit par stop ho.

### H9. Trust aur operational controls

Verified capabilities ke concise blocks: team roles/assignment, workspace context, audit visibility, controlled AI settings. Compliance certifications, guaranteed uptime ya encryption claims documentation/implementation verify hone par hi publish hon.

### H10. Pricing preview

Backend-enabled featured plans se compact cards. Monthly/yearly toggle, actual price, trial if available aur key features. Full comparison CTA `/pricing`. No hardcoded fake plans. Missing plans → concise Contact CTA; `$0` fallback se misleading pricing na show ho.

### H11. Customer stories / FAQ

Approved testimonials hon to editorial quote cards. Otherwise testimonial block omit aur product workflow evidence retain ho. FAQ 5–6 useful questions: connection, AI setup, supported channels, team, billing aur trial. Answers source-grounded hon.

### H12. Final CTA

Forest background, large invitation **Bring your customer conversations together.** Get Started aur Talk to Us. Compact product outline/light motif hero ko visually echo kare. CTA/footer reading interference ke bina subtle ambient motion.

## 7. Use Cases page

Hero: **Built around the way your business works.** Ivory surface, large editorial headline, scenario selector aur contextual product visual.

Proposed categories:

| Scenario | Data/content | Product visualization | Action |
|---|---|---|---|
| Customer support | Shared inbox, agent assignment, notes, labels, handover | Support conversation timeline | Explore inbox |
| Ecommerce | Store sync, orders, template notifications, cart workflow | Order-to-message timeline | Explore ecommerce |
| Sales/lead follow-up | Contacts/segments, lead discovery, follow-up workflow | Contact profile and branch flow | Explore automations |
| Marketing teams | Campaign creation, personalization, scheduling | Audience + message preview | Explore campaigns |
| Social teams | Composer, connected networks, publishing calendar | Weekly calendar | Explore social |
| Instagram engagement | Keyword comments, DM flow, delivery | Comment-to-DM sequence | Explore Instagram workflow |

Each scenario detail: business problem, 3–4 workflow steps, relevant implemented features, setup requirements, CTA. Benefits qualitative hon unless verified numerical evidence ho. Tabs click se stable content change karein; browser history/anchor useful ho. Mobile horizontal tab list scrollable aur content stacked ho.

Footer se pehle relevant FAQ aur Get Started CTA. Avoid same generic feature card six baar duplicate karna.

## 8. Integrations page

Hero: **Connect the tools your business already uses.** Dark intro with network visual; catalog light surface par readable ho.

Categories: Messaging, Social, Ecommerce, AI Providers, SMS, Payments, Productivity. Search text aur category filter. Integration card: icon, name, short purpose, support type, setup prerequisite aur detail disclosure. Phase 1 local catalog metadata se search/filter chale; backend secret/configuration data public props mein expose na ho.

Catalog examples source inventory se: WhatsApp, Instagram, Messenger; Facebook, LinkedIn, X, TikTok, YouTube; Shopify, WooCommerce, BigCommerce; OpenAI, Anthropic, Gemini, Ollama, OmniRoute; configured SMS/payment driver families; workflow Google integrations.

Support wording differentiate kare: Native connector / Provider driver / Workflow action. Public catalog presence ko workspace enabled/configured status na kahe. Provider-specific beta/limitations metadata ho to show karein. Search zero-results state helpful text aur clear filter reset de.

Detail panel data: supported capability, connection method, prerequisites, relevant setup docs, dependency on external approval/configuration. Primary CTA Get Started; technical setup docs secondary. No decorative outbound links to nonexistent integration pages.

## 9. Pricing page

Hero: **Choose the workspace that fits your team.** Light reading layout; green featured-plan accent. Minimal motion taake comparison easy rahe.

Content order:

1. Headline, billing toggle, applicable currency label.
2. Enabled plan cards with actual price, description, configured features aur trial.
3. Detailed feature/limit comparison, when backend exposes reliable data.
4. Usage/billing explanation; external provider charges only verified commercial policy ke mutabiq.
5. FAQ: plan change, cancellation, trial, limits, invoice/payment.
6. Contact for advice CTA; enterprise/custom plan offer sirf existing commercial support ho to.

Current `LandingController::plans()` props: `id`, `name`, `description`, `price_monthly`, `price_yearly`, `features`, `is_featured`, `trial_days`. Detailed limits aur currency full comparison ke liye explicitly expose/verify karne honge; private config ko blindly serialize na karein.

Yearly price ko annual total ya per-month equivalent clearly label karein. Discount percentage actual positive monthly/yearly amounts se derive ho, round carefully. Yearly unavailable ho to toggle that plan ke liye disabled/unavailable state de. Plan IDs actual checkout/auth journey se map hon.

Mobile cards single column; comparison accessible horizontal table ya category disclosures. Features/price motion low, layout shifting avoid ho. Missing-plan state aur failed-data handling specified hon.

## 10. About page

Hero: strong editorial message about organizing customer communication. Brand mission aur product philosophy authoritative configured content se aaye.

Sections: why product exists → what platform brings together → principles → actual team/company information if supplied → verified product evidence → Contact/Get Started.

Visual: large typographic statements, product collage, intentional green/ivory bands. People photography, office locations, founding dates, team members aur customer metrics fabricate na karein. Missing company details par honest product-focused content use ho.

Timeline sirf real dated milestones hon to. Animation heading reveals aur subtle collage movement tak limited. Mobile collage simple stacked imagery ho.

## 11. FAQ page

Hero concise; main content ivory surface. Categories: Getting Started, Channels, AI, Automations, Billing, Team/Workspaces, Integrations.

Search public approved Q/A text mein local filtering kare. Category filter aur accordion with stable IDs, keyboard controls, `aria-expanded` aur readable answer typography. Deep link to question optional. Multiple answers open reh sakte hain taake comparison easy ho.

Data: question, answer, category, optional documentation URL. Existing configured FAQs map hon; richer categories future metadata need karti hain. Empty search state reset/contact action de. Price/support-time answers dynamic configuration se aligned hon.

Mobile full-width disclosures. Motion 180–240ms height/opacity; reduced motion instant. FAQ structured data sirf actual rendered eligible content ko reflect kare.

## 12. Contact page

Two-column desktop: left purpose/support options and configured contact info; right clear form card. Mobile intro then form, secondary info below. Forest accent sidebar with ivory form area.

Existing form fields preserve: `name`, `email`, `subject`, `message`; submit POST `contact.store` via Inertia `useForm`. Demo/sales CTA subject prefill kar sakti hai existing validation ke andar. Extra company/phone/budget fields backend validation/storage update ke bina introduce na karein.

States: idle, field error, submitting, success, request failure. Persistent visible labels, suitable autocomplete, disabled submit while processing, inline errors plus accessible status announcement. Success sirf confirmed backend response par show ho. Failed submission entered data retain kare.

Public email configured source se ho; brand-name-based invented email fallback remove/avoid karein. Response time, office hours, live chat availability aur booking links verified settings se show hon. No claim of demo booking when form only sends enquiry.

## 13. CMS / legal page template

Clean editorial document layout: breadcrumb, title, optional verified updated date, readable content column, desktop table of contents for long documents. Legal text exact published source se preserve ho; marketing redesign policy substance change na kare.

Tables, lists, links, long words aur embeds responsive styling receive karein. CMS content rendering existing sanitation/trust mechanism preserve kare; arbitrary rich content ke liye explicit sanitization assessment ho. Empty/unpublished pages existing access behavior follow karein.

Low motion; reading focus. Optional Contact support link. TOC links actual headings par target hon and sticky header offsets consider karein.

## 14. Proposed feature-detail page template

Phase 2 structure: feature-specific hero → interactive product demonstration → 3 deep capability sections → setup overview → relevant integrations → practical example → related FAQ → CTA.

Each feature page ka distinct product visual ho. Inbox conversation stage; AI source-to-answer; automation graph; campaign wizard preview; social calendar; ecommerce order timeline. Shared shell/typography retain ho, homepage content verbatim repeat na ho.

## 15. Motion specification

| Motion type | Tool | Proposed timing | Behavior |
|---|---|---|---|
| Buttons/focus/hover | CSS or Motion | 120–180ms | Subtle color/lift; keyboard focus equally clear |
| Menus/accordions | Motion | 180–240ms | Short entrance/exit; preserve focus |
| Section entrance | Motion | 400–600ms | 12–24px translate, small stagger; once per entry policy |
| Hero product sequence | GSAP | 5–7s narrative | One initial play, pause/replay control |
| Inbox scroll story | GSAP ScrollTrigger | Scroll-position driven | Desktop selective pin, mobile stacked |
| SVG workflow | GSAP/CSS | 1–2s | Path and active-node progression |
| Ambient light | CSS | 10–16s optional | Low amplitude, stop offscreen |

Animation ownership: Motion handles React interactive state/layout; GSAP handles specific product-story elements/timelines. Same DOM property ko both systems control nahi karein. Effects page unmount par cleanup/revert hon, ScrollTriggers destroy hon, resize/locale changes correctly handle hon.

No loading animation visitor ko content se block kare. Text never depends on animation completion to become usable. No scroll-jacking. Reduced-motion mode removes parallax/pins/tilt and uses static narrative states. Hover effects touch screens par essential action hide na karein. Pointer effects requestAnimationFrame/throttled values se bounded hon.

## 16. Technology decisions

| Technology | Decision | Responsibility |
|---|---|---|
| Laravel + Inertia | Keep existing | Routes, public settings, plan/contact/CMS data |
| React | Keep existing | Shared marketing components and demo state |
| Tailwind + scoped CSS variables | Keep/extend | Marketing token system and responsiveness |
| Motion for React (`motion`) | Proposed new dependency | Interactive component animation |
| GSAP + ScrollTrigger (`gsap`) | Proposed new dependency | Coordinated hero/scroll storytelling |
| SVG | Core visual format | Workflow/connection diagrams and crisp icons |
| Existing Lucide/brand icons | Reuse | Consistent UI and channel identification |
| Headless UI | Reuse where suitable | Accessible menu/dialog primitives |
| Three.js | Conditional phase 2 | Only approved meaningful 3D scene |
| Smooth-scroll library | Not part of initial stack | Native scrolling sufficient for proposed flows |
| External UI template packs | Optional reference only | Custom components follow chosen brand/spec |

Motion aur GSAP package versions existing React/Vite setup ke against installation time verify hon; current plan versions pin nahi karta. Three.js initial implementation requirement nahi hai. SVG/CSS product stage hero ka first prototype banayegi; extra 3D complexity prototype evidence ke baad decide ho.

Official references checked for earlier stack proposal:

- Motion scroll: https://motion.dev/docs/react-scroll-animations
- Motion reduced-motion configuration: https://motion.dev/docs/react-motion-config
- GSAP ScrollTrigger: https://gsap.com/docs/v3/Plugins/ScrollTrigger/
- Optional Three.js responsiveness: https://threejs.org/manual/pages/responsive.html

## 17. Component and source organization

Proposed marketing component families:

- Shell: MarketingHeader, MarketingFooter, MarketingContainer, MarketingSection.
- Typography/actions: MarketingHeading, MarketingButton, Eyebrow, InlineLink.
- Product previews: HeroProductStage, InboxDemo, AiAnswerDemo, WorkflowDemo, OrderTimelineDemo, SocialCalendarDemo.
- Commercial/content: PlanCard, PlanComparison, IntegrationCard, ScenarioSelector, TestimonialQuote, FaqGroup.
- Feedback: EmptyContent, SearchEmptyState, FormStatus.
- Motion hooks: reduced-motion preference, viewport/pointer capability, timeline lifecycle.

Existing `Components/marketing` aur `Components/motion` ko inspect/refactor karke duplication control karein. Existing custom CSS class conflicts audit hon. Plan mock data separately authored constants mein ho; public content configuration aur billing props alag data layers hon.

Suggested style boundary: marketing root class + scoped CSS file imported by marketing layout. App-wide `app.css` changes minimum and justified hon. Existing client/admin palette/components independently operate karein.

## 18. Data contract and content ownership

| Content | Source | Rule |
|---|---|---|
| Logo/app name/public links | Inertia branding/shared props | Configured values first |
| Public hero/sections/FAQ/testimonials | `landing` public settings | Existing admin editing preserve; new keys map explicitly |
| Plans | `LandingController::plans()` | Dynamic enabled plans, real values |
| CMS body | Published CmsPage record | Preserve content/access behavior |
| Contact submission | Existing ContactController | Existing validation/action preserved |
| Product preview | Authored sample fixtures | Clearly illustrative, no real customer data |
| Integration catalog | Reviewed public metadata | Capability type and requirements accurate |
| Locale strings | Existing i18n workflow | New copy localizable, RTL-aware |
| Proof/ratings/logos | Supplied verified assets/content | Omit unverified claims |

Current admin landing settings contain existing public content structure. Implementation se pehle key-by-key content mapping banegi: reuse key / new key / deprecated key. Redesign admin-managed copy ko silently hardcoded text se replace na kare. Richer FAQ/catalog schemas optional backend extensions hain; explicitly implemented and validated hon.

Empty content policy: missing testimonials hide block; missing logos use accurately labeled channel evidence; missing plans use Contact fallback; missing links remove action; missing company facts use product explanation. Optional sections remove hone par layout spacing coherent rahe.

## 19. Responsive and accessible behavior

Design/reference widths: 360, 390, 768, 1024, 1440, 1920px. Layout breakpoints practical content needs se tune hon; 1024px par all wide scenes automatically desktop pin assume na karein. Full story pins only adequate width/height and reduced-motion off par.

- Mobile content order meaning preserve kare: heading → explanation → visual → action.
- Large preview screens scale readability lose karein to simplified compact demo render ho.
- Keyboard navigation menus, tabs, accordions, forms aur demo controls cover kare.
- Focus indicators visible; overlay focus containment and return defined.
- Color-only status avoid ho; icon/text labels accompany states.
- Decorative SVG hidden from assistive technology; meaningful visual text summary available.
- Locale expansion/RTL mirrored spacing and diagrams reviewed hon; logos/text direction intentionally handled ho.
- 200% zoom par content/action accessible; horizontal page overflow absent.

## 20. Performance, SEO and measurement targets

Targets measured on representative production build/device/network, guarantees nahi:

- Core Web Vitals goals: LCP ≤2.5s, INP ≤200ms, CLS ≤0.1 where realistically achievable.
- Hero copy/CTA first render mein usable; product animation loading unko block na kare.
- Optimize hero assets, reserve dimensions, lazy-load lower visuals, stop offscreen effects.
- Route-split heavy demo/motion modules where useful; animation budget bundle analysis ke baad record ho.
- Use transform/opacity for most motion; large continuous blur/filter repaint avoid ho.
- Font preload selectively, font-display swap, limited weights.
- Semantic headings, unique page titles/descriptions, valid canonical policy, sitemap/CMS links.
- Structured data verified company/product/FAQ facts se generated ho; hardcoded invented ratings remove hon.

Optional privacy-respecting analytics events after actual tracking system approval/configuration: primary CTA, demo scenario selection, pricing toggle, plan selection, integration search and confirmed contact submit. Typed messages, personal form values aur credentials event payloads mein na bhejein.

## 21. Implementation sequence and deliverables

### Phase A — design foundation

Deliverables: token sheet, content/source map, navigation, all core page wireframes, hero/inbox motion storyboard. This document baseline specification hai; visuals prototype se refine honge.

### Phase B — visual prototype

Hero aur one inbox/story section build karein. Desktop/mobile, static/reduced-motion aur interaction states review hon. Product realism, visual hierarchy, contrast aur performance prototype decision points hain.

### Phase C — homepage and shared shell

Header/footer, reusable marketing components aur complete homepage implement ho. Dynamic settings/plans/auth/locale compatibility verify ho. Animations incremental add hon, content usable base render se start ho.

### Phase D — remaining core pages

Use Cases, Integrations, Pricing, About, FAQ, Contact aur CMS template. Each page gets purpose-specific layout and real data handling. Main forms/routing/billing links regressions check hon.

### Phase E — polish and verification

Responsive/device checks, keyboard/reduced motion, RTL/translation, SEO and production build. Necessary tests actual behavior verify karein: form errors/success, pricing selection, filters, menu focus and reduced-motion lifecycle. Decorative implementation-mirroring tests avoid hon.

Frontend source touch hone par `.agents/AGENTS.md` standing workflow follow ho: `npm run build`, source plus `public/build` same-task commit/push. Existing unrelated work preserve karna hai. This documentation-only task application frontend/build/deployment change nahi karti.

### Phase F — optional expansion

Six product detail pages, verified customer stories, extra interactive scenes aur justified 3D. New public routes sitemap/navigation mein integrate hon. Broader auth redesign separately scoped ho.

## 22. Acceptance checklist

- All seven core marketing routes aur published CMS pages cohesive redesigned shell use karein.
- Hero clearly communicates product and has functioning primary/secondary actions.
- Product scenes implemented features accurately demonstrate karein.
- Each page has distinct content hierarchy, useful data and intentional CTA.
- Dynamic plans, landing settings, Contact form, auth destinations and CMS content work karein.
- Missing content/provider configuration graceful state show kare.
- No invented proof, pricing, testimonials, unsupported guarantee or dead links.
- Mobile/RTL/keyboard/reduced-motion layouts verified hon.
- Motion cleanly tears down and no offscreen infinite workload remain ho.
- Production build and appropriate behavior checks pass hon.

## 23. Open content inputs and working defaults

Plan proceed karne ke defaults: current WhatsMine identity retain, English copy through localization, existing routes keep, custom SVG product demos, Motion+GSAP stack, Contact as sales destination, real configured pricing.

Further useful inputs: approved company copy, real customer logos/testimonials, final brand assets, sales contact/support commitments aur preferred visual references. Missing optional inputs development block nahi karte; corresponding sections honest fallback policy follow karti hain. Commercial facts and legal copy explicitly sourced honi chahiye.
