# WhatsMine — Complete Features, UI aur Function Reference

Review date: 6 October 2026. Language: Roman Urdu with exact code names.

## 1. Is document ka scope

Yeh current repository ka source-based reference hai: React pages, layouts, shared controls, Laravel routes, controllers, services, jobs aur scheduler ko inspect karke banaya gaya hai. Feature ka code mein hona aur production par successfully configured hona alag cheezen hain. Live provider accounts, payment transactions, browser rendering, microphone, webhook delivery aur deployed workers is review mein execute nahi kiye gaye.

Main sections user-facing functionality aur important backend behavior explain karte hain. End par generated indexes current UI pages, route declarations, controller actions aur service/job methods ke exact names dete hain. Index mein kisi function ka listed hona uske har branch ki runtime verification ka claim nahi hai. Historical plans ko implemented feature ka proof nahi maana gaya.

## 2. Product aur architecture

WhatsMine ek multi-tenant customer messaging aur marketing SaaS hai. Business apne contacts manage karta hai, connected channels se messages receive/send karta hai, agents aur AI ko conversations assign karta hai, campaigns chalata hai, social posts publish karta hai aur event-based workflows banata hai.

| Layer | Current implementation | Role |
|---|---|---|
| Backend | Laravel 12, PHP requirement ^8.2 | Authentication, authorization, validation, records, integrations |
| Frontend | React 19, Inertia 2, Vite 6 | Server-supplied page props se interactive screens |
| Styling | Tailwind 3, custom CSS | Theme, responsive layout, reusable visual tokens |
| Visual workflows | @xyflow/react | Nodes, edges, zoom, controls aur minimap |
| Charts | Recharts | Dashboard aur report visualizations |
| Realtime | Laravel Echo, Pusher/Reverb integration | Messages, typing aur notification updates |
| Background work | Laravel jobs aur scheduler | Campaigns, AI indexing, social publishing, sync |
| API | Sanctum aur ability middleware | External/mobile clients ka scoped access |

Important relationships:

- Client organization ke users aur workspaces hote hain. Client ki active plan subscription feature capacity affect karti hai.
- User ka current workspace active data context hota hai; kaafi controllers `current_workspace_id ?? workspace_id` use karte hain.
- Workspace ke contacts, channel accounts, conversations, campaigns, automations, social accounts aur AI resources hote hain.
- Conversation ek contact/channel context mein messages, assignment, status aur internal notes rakhti hai.
- Module service providers apne routes aur services register karte hain. Module folder presence aur registered routes navigation ko affect kar sakte hain.
- `EnsureClientScope` request par client ID set karta hai; complete isolation ke liye controllers ki workspace/ownership checks bhi zaroori hain. Sirf middleware ka naam isolation ka proof nahi hai.

Sources: `composer.json`, `package.json`, `app/Modules`, `app/Models`, `app/Http/Middleware`, `resources/js/app.jsx`.

## 3. UI structure aur design system

### 3.1 Standard client shell

`ClientLayout.jsx` standard pages ka shell hai: desktop par fixed 256px sidebar, 56px sticky topbar aur responsive content padding. Topbar mein page title, global search, workspace switch, onboarding progress, notifications, locale, theme aur account menu hota hai.

`useClientNav.jsx` client navigation ka single source hai; inbox bhi isi ko consume karta hai. Groups daily operations se account settings tak ordered hain: dashboard, inbox, social, WhatsApp tools, contacts, broadcasting, automations, ecommerce, AI, leads, Instagram, reports, support, billing, developer tools aur account settings.

Sidebar groups collapsible hain; active group forced-open rehta hai aur route change par active link visible hota hai. Mobile par sidebar overlay drawer banta hai aur floating menu button se open hota hai. Team aur audit items client administrator role ke mutabiq appear karte hain. Help links branding configuration par depend karte hain.

### 3.2 Specialized layouts

- `InboxLayout.jsx`: full viewport height, contained scrolling aur shared sidebar; standard topbar content automatically render nahi karta.
- `AdminLayout.jsx`: permission-filtered admin navigation, account controls aur command palette.
- `LandingLayout.jsx`: public navigation, responsive menu, scroll-reactive header aur marketing footer.
- `AuthLayout.jsx`: login/register/recovery screens ka shell; admin variant bhi hai.
- `InstallLayout.jsx`: installation steps ka shell.

### 3.3 Visual conventions

Space Grotesk default font hai. Bengali locale mein Anek Bangla apply hota hai. Brand forest green `#467235`, secondary dark green `#283F24`, accent amber `#FFBF00`, pale yellow `#FFF78D` aur destructive coral colors defined hain. Content mein light neutral/white cards, dark neutral surfaces, soft borders, rounded corners aur subtle shadows use hote hain.

Shared UI folder mein Button, Input, Select, Checkbox, Toggle, Badge, Card, Tabs, Modal, Drawer, Dropdown, Tooltip, Pagination, DatePicker aur Skeleton hain. Button variants primary/secondary/ghost/danger/outline hain; sm/md/lg sizes aur focus/disabled states defined hain. Input label, hint aur error state support karta hai. Shared modal Headless UI Dialog/Transition use karta hai.

AI buttons ke liye animated gradient/glow CSS hai. Public pages mein reveal, marquee, shimmer, counters aur chat mock visuals hain. CSS ke relevant motion blocks reduced-motion preference handle karte hain. Har custom page/control ki accessibility ya responsive quality independently verified nahi hai.

### 3.4 Shared UI behavior

- Theme localStorage mein persist hota hai aur `<html>` par `dark` class apply hoti hai; authenticated toggle server ko bhi update karta hai.
- Locale switch translations, document language aur RTL direction ko affect karta hai.
- Global search Ctrl/Cmd+K se open, Escape se close, arrow keys se selection aur Enter se navigation karta hai; search debounce 200ms aur minimum query length 2 hai.
- Sonner toasts success/error aur realtime notifications show karte hain.
- Usage banner 80% ya zyada usage par appear hota hai; upgrade modal limit-reached flows support karta hai.
- Impersonation banner admin ko client context se wapas jane ka action deta hai.
- Demo notice aur `EnsureNotDemoMode` write restrictions ke related mechanisms hain; visible banner alone enforcement nahi hai.
- Global ErrorBoundary render failure par recoverable fallback provide karta hai.

## 4. Authentication, profile aur onboarding

Registration, password login, forgot/reset password, email verification/resend, password confirmation aur logout routes hain. Password-reset aur magic-link send endpoints throttled hain. Magic link passwordless email-token flow deta hai. Socialite provider redirect/callback aur Firebase login endpoint bhi available hain; configured providers aur enabled settings actual availability determine karte hain.

Two-factor authentication mein enable/disable, challenge verification aur recovery-code regeneration actions hain. Profile screen user details/password aur account deletion manage karti hai. Sessions screen session revocation action expose karti hai. Public invitation link user ko invite accept karne deta hai; client admins invitation send/revoke kar sakte hain.

Onboarding wizard aur dashboard next-step prompts email verification, plan selection, channel connection, contact import, first campaign, chatbot aur social account setup ki progress guide karte hain. Topbar incomplete onboarding ka percentage dikhata hai.

Sources: `routes/auth.php`, `routes/client.php`, `app/Http/Controllers/Auth`, `app/Services/OnboardingService.php`, `resources/js/Pages/Auth`, `resources/js/Pages/Profile`, `resources/js/Pages/client/Onboarding`.

## 5. Workspaces, team aur client settings

Workspace list, create aur switch actions available hain. Switch ke baad active workspace data context change hota hai. Team management member create/update/delete aur invitations cover karta hai. Client-administrator-only comments/navigation ko controller authorization ke saath samajhna chahiye.

Settings pages general settings, notification preferences aur data export expose karti hain. Audit log client activity ka review area hai. Media library upload/list/delete aur reusable media selection support karti hai. Data export queue job workspace data ka archive banata hai; export service contacts, conversations, messages, AI runs, campaigns/recipients, automations/runs, audit log aur social posts ke CSVs generate karta hai.

Sources: `WorkspaceController`, `Client/TeamController`, `Client/SettingsController`, `Client/Settings/DataExportController`, `GenerateWorkspaceExportJob`, `WorkspaceExportService`.

## 6. Dashboard

Dashboard operational overview hai: KPI tiles, date-range controls, channel-wise messages, conversations, AI token usage, contacts growth, channel mix, automation runs aur social posts ke charts hain. Recent conversations/campaigns, agent leaderboard, team, workspaces, plan aur quick links bhi appear karte hain. Empty data aur onboarding states ke separate components hain.

Dashboard numbers server-provided analytics se aate hain; marketing-page metrics ko real workspace analytics ke saath mix nahi karna chahiye.

Sources: `resources/js/Pages/client/Dashboard.jsx`, `Client/DashboardController`, `AnalyticsService`, `Components/Dashboard`, `Components/Charts`.

## 7. Contacts aur segments

Contacts screen searchable paginated table, avatars, phone/email, tags, row selection, view/delete aur bulk actions provide karti hai. Individual contact profile edit aur associated conversation/order context ka entry point hai. Avatar upload/delete ke dedicated routes hain.

Import mein regular import endpoint aur spreadsheet-style bulk import UI hai; ExcelJS aur Handsontable dependencies is workflow ko support karti hain. Bulk-store, bulk-delete aur export routes hain. Contacts ko direct entry, import, inbound conversations, ecommerce customer sync aur scraped leads se populate kiya ja sakta hai.

Segments create/edit/delete kiye ja sakte hain. Segment contacts view mein contacts attach/detach kiye ja sakte hain. `SegmentResolver` campaign audience resolution ka part hai. Contact tags classification aur automation triggers ko support karte hain; conversation labels alag inbox classification mechanism hain.

Sources: `app/Modules/Shared/routes/web.php`, `ContactController`, `SegmentController`, `ContactService`, `SegmentResolver`, `resources/js/Pages/Contacts`.

## 8. Unified inbox

### 8.1 Listing aur conversation navigation

Folder filters All, Mine, Unassigned, Resolved aur Snoozed hain. Channel choices WhatsApp Cloud, WhatsApp QR, Instagram, Messenger, SMS aur email UI mein represented hain. Har channel ke receive/send behavior ka provider implementation aur configuration alag verify karna hota hai; filter option hona full transport coverage ka proof nahi hai.

Conversation cards contact identity, channel, latest message, timestamp, status aur unread count dikhati hain. Contact avatar/profile navigation aur new conversation modal available hain. Start conversation ke liye contact search, available channel accounts aur templates fetch karne ke routes hain.

### 8.2 Conversation workspace

Thread UI mein folders, conversation list, center message/composer aur desktop contact detail panel hain. Contact detail panel smaller sizes par hide hota hai; doosre fixed-width panels ki mobile usability browser mein check honi chahiye.

Main actions:

- Message reply, template selection, media upload/preview aur protected media retrieval.
- Quoted replies aur voice-note recording/preview; microphone browser permission aur suitable deployment context required hain.
- Emoji/media controls aur canned replies; slash-prefix typing matching canned shortcuts dikhati hai.
- Internal notes tab customer messages se alag team notes maintain karti hai.
- Conversation assignment to team member, status change: open/pending/resolved/snoozed.
- Bot/human handover; assigned agent aur handling mode contact panel mein represented hain.
- Attach/detach colored labels aur label management.
- AI draft generation agent composer ke liye; AI monthly limit route par applied hai.
- Ecommerce product share aur contact order context.
- Realtime incoming-message updates, typing presence aur unread indicators.

### 8.3 Backend path

Provider webhook se normalized contact/conversation/message records bante hain; jobs provider processing ko request se separate karte hain. Shared channel manager outbound transport select karta hai. Messenger/Instagram webhook processing job transient errors par retries aur exponential backoff use karta hai. Message/assignment events realtime aur automation listeners ko drive kar sakte hain.

Sources: `app/Modules/Inbox/routes/web.php`, `InboxController`, `InternalNoteController`, `AiDraftController`, `HandoverService`, `ChannelManager`, `resources/js/Pages/Inbox/Show.jsx`.

## 9. Channel setup aur WhatsApp Cloud API

Unified Channel Setup screen connection management aur chatbot assignment ka main area hai. WhatsApp setup GET route isi screen par redirect hota hai. Meta embedded signup WABA/phone linkage ka entry point hai. WABA webhook re-registration, phone sync, phone status refresh, display-name change, default template seeding aur disconnect actions available hain.

Instagram login OAuth/manual-token paths aur Messenger embedded signup routes bhi hain. Channel account ko chatbot assign ya disconnect kiya ja sakta hai. Credentials, webhook URLs, permissions aur public callback reachability external setup requirements hain.

WhatsApp template editor create/update/delete, Meta sync, default reseed, media upload aur preview support karta hai. Template validation aur Cloud API clients backend par hain; remote approval/status local CRUD se alag hota hai. Auto-replies CRUD keyword/reply configuration provide karta hai. Website chat widget create/edit/delete aur public JavaScript embed endpoint deta hai.

Coexistence ke liye `SyncCoexistenceDataJob` aur implementation docs hain; yeh Cloud account ke compatible connection/sync path se related hai, QR session transport se alag feature hai.

Sources: `app/Modules/Whatsapp`, `InboxSetupController`, `resources/js/Pages/Whatsapp`, `docs/whatsapp_coexistence_implementation.md`.

## 10. WhatsApp QR connection

QR module session create/list/show, QR polling, status polling aur logout/delete expose karta hai. `QrSessionManager` external session service se communicate karta hai; connected session ko shared `whatsapp_qr` channel account aur `baileys` provider se link karta hai. Per-session webhook secret store hota hai aur QR driver outbound operations handle karta hai.

Is module ka code available hona external QR service ke running hone ka proof nahi hai. Cloud templates/Meta signup aur QR authentication ko separate configuration flows samajhna zaroori hai.

Sources: `app/Modules/WhatsappQR`, `resources/js/Pages/WhatsappQR`.

## 11. Campaign broadcasting

Campaign creation ka actual five-step flow: **Channel → Audience → Content → Schedule → Review**. Channels WhatsApp, SMS aur email hain. Audience selection mein segments/tags/CSV-related paths aur live audience preview hain. WhatsApp content phone account, approved template, language aur parameter bindings se linked hota hai. Personalizer contact tokens ko content mein resolve karta hai.

Campaign list/detail/edit, draft save, test-send, launch, pause aur delete actions hain. Scheduled campaign scheduler se launch hoti hai. Backend audience resolve karta hai, recipients create karta hai, chunk jobs dispatch karta hai, individual messages send karta hai aur finalization/status totals maintain karta hai. Recipient insert uniqueness campaign/contact level par re-launch duplication ko guard karti hai. Empty audience launch ko failed state deta hai.

Email campaigns mein visual email editor, reusable block/template support, AI email generation aur subject improvement endpoints hain. Client email-server configuration create/update/delete/test actions rakhti hai. Yeh admin transactional email-system configuration se alag area hai.

SMS gateway configuration workspace overrides ke saath hai. Driver files: Twilio, Nexmo, MessageBird, Infobip, ClickSend, Plivo, Telnyx, Amazon SNS, Fast2Sms, BulkSmsBd, MimSms, SmsDotBd, SmsBd aur ReveSms. Actual provider use credentials, destination support aur driver behavior par depend karta hai.

Sources: `app/Modules/Broadcasting`, `CampaignForm.jsx`, `Components/EmailEditor`, `CampaignPersonalizer`, `LaunchCampaignJob`, `DispatchCampaignChunkJob`, `SendCampaignMessageJob`, `FinalizeCampaignJob`.

## 12. AI chatbots, knowledge bases aur memories

### 12.1 Chatbot configuration

Chatbot create/edit/delete aur playground chat available hain. Fields include knowledge base, system prompt, tone, context chunk count, history limit, token budget, temperature, fallback reply, handover reply, channels aur enabled state. Ollama-related context/keep-alive options bhi controller validation mein hain. Knowledge-base ownership workspace ke against check hoti hai.

Playground supplied chat history ke saath synthetic conversation use karta hai; real inbox DB conversation se recent history read karta hai. Current runner real history ko maximum 6 turns aur 500 characters per turn tak cap karta hai, chahe configuration form higher history value accept kare.

### 12.2 Grounded reply pipeline

1. Enabled bot aur inbound conversation resolve hoti hai.
2. EmojiEngine message semantics, keywords aur sentiment/urgency signals derive karta hai.
3. Knowledge-base query embedding try hoti hai.
4. Hybrid keyword + embedding retrieval aur reciprocal-rank fusion relevant chunks select karta hai.
5. Context trimmer prompt budget ke andar KB context fit karta hai.
6. Core rules, global rules, bot instructions/tone, recent history, conversation summary aur learned memories combine hote hain.
7. LLM reply generate hoti hai; runner mein context-present hedging ke liye grounding retry logic aur fallback handling hai.
8. Background reply job transport aur usage/logging flow ka part hai.

Yeh retrieval/guardrails factual correctness ki guarantee nahi hain; model responses aur memory quality ko test karna hota hai.

### 12.3 Knowledge bases

KB create/list/show, source documents add, document reindex/delete routes hain. IndexDocumentJob extraction → chunks → optional embeddings → indexed status ka workflow hai. File/URL/FAQ-related source handling hai; PDF parsing aur HTML-to-Markdown dependencies present hain. FAQ-shaped content ka specialized chunking hai.

Embedding-capable providers current manager mein OpenAI, Gemini aur Ollama hain. Anthropic aur OmniRoute ko automatically native embedding provider assume nahi kiya gaya. Missing embedding capability par indexing plain text ke saath complete ho sakti hai; transient embedding API error queue retries ko allow karta hai. Document indexed status alone vector retrieval readiness ka proof nahi hai.

### 12.4 Providers aur routing

Provider implementations OpenAI, Anthropic, Gemini, Ollama aur OmniRoute hain. Workspace provider settings aur system default credentials resolve kiye jaate hain. Dead-upstream guard aur diagnostics troubleshooting support karte hain. API keys, model availability aur network access working AI ke requirements hain.

### 12.5 Self-learning

Memories screen list/manual-add/delete aur learning toggle actions expose karti hai. Memory workspace-wide ya chatbot-specific ho sakti hai. Runner useful recent memories aur rolling conversation summary inject karta hai. Current constants: extraction consideration every 20 outbound messages, minimum message length 20 characters, injected memories maximum 15, each line maximum 160 characters, extraction window 30 messages. Nightly decay job stale memories/cap hygiene handle karta hai.

Sources: `app/Modules/AI`, especially `ChatbotRunner`, `AiMemoryService`, `AiSystemPrompt`, `LlmManager`, `IndexDocumentJob`, `GenerateAiReplyJob`, `resources/js/Pages/AI`.

## 13. General automation builder

Automation list/create/edit/delete, activate-related saved configuration, templates, AI workflow generation, run history, webhook token generation aur builder dry-run routes hain. React Flow canvas nodes/edges, palette, configuration, save, test, zoom aur minimap provide karta hai.

### 13.1 Trigger types

`contact.created`, `contact.tag_added`, `message.received`, `campaign.sent`, `form.submitted`, `webhook.received`, `order.placed`, `order.fulfilled`, `order.cancelled`, `cart.abandoned`, `customer.created`.

Trigger context contact, message, tag, campaign, form, order/cart ya webhook tokens carry kar sakta hai. Token fields trigger-specific hain; recipes ke liye `docs/automation_triggers.md` relevant detailed reference hai.

### 13.2 Runtime node handlers

| Group | Node types in engine dispatch | Purpose |
|---|---|---|
| Send | send_whatsapp, send_template, send_sms, send_email, send_media, send_sequence | Channel content aur ordered sends |
| Interactive | quick_replies, list_message, send_poll, ask_question | Choice/reply collect karna aur branching |
| Logic | wait, condition, run_subflow | Delay, conditional path, reusable workflow |
| Contact | add_tag, remove_tag, update_contact, assign_agent | CRM state aur assignment |
| Campaign | add_to_campaign | Recipient ko campaign flow mein add karna |
| AI | ai_reply, run_chatbot | Generated/bot reply actions |
| Engagement | cta_button, send_location, book_appointment, google_meet, whatsapp_form | CTA/location/form/calendar-related actions |
| Commerce | whatsapp_catalog, woocommerce_product, shopify_product | Catalog/product share |
| External | webhook, google_sheets, google_docs, google_forms | External API/resource actions |

Handler existence ka matlab har provider/node combination supported nahi hai. Specific node configuration, credentials aur transport response runtime outcome decide karte hain.

### 13.3 Execution aur testing

Active automation trigger se pending run banta hai aur `ExecuteAutomationRunJob` automation queue par dispatch hota hai. Engine trigger ke outgoing edge se graph walk karta hai, current node/context/logs persist karta hai aur wait/reply nodes par resume state rakhta hai. Interactive reply ID matching sourceHandle edge choose kar sakta hai. Runtime mein cycle guard aur 100-step bound hai.

Builder Test real delivery nahi karta: sample contact/context par condition evaluate karke side-effect nodes ka preview trace banata hai. Current test bound 60 steps hai. Dry-run pass hona provider credentials ya successful message send ka proof nahi hai.

Repository rule: ecommerce order notifications mein `send_template` use karna hai, plain `send_whatsapp` nahi. Approved templates order confirmed/cancelled/shipped flows ke liye required project convention hain.

Sources: `AutomationEngine`, `WorkflowGenerator`, `AutomationTemplateRepository`, `resources/js/Pages/Automation`, `.agents/AGENTS.md`.

## 14. Instagram comment automation aur DM flows

Instagram module general automation builder se alag specialized comment-to-DM funnel rakhta hai. Account setup/status, recent posts, comment automation create/edit/toggle/delete, templates/gallery, visual DM flows aur funnel logs available hain.

Comment webhook account/post/keyword context resolve karta hai; own comments aur reply-to-comment events ke skip logic hain. Participant state repeated processing/delivery ko manage karti hai. Private reply user engagement ke initial step ko drive karti hai; CTA response, follow-gate prompt aur delivery keyword funnel ko continue karte hain. Delivery text/link/template configuration se related hai aur inbox mirroring team visibility deta hai.

Follow check API se true/false/null aa sakta hai; inspected implementation unknown/null result ko fail-open treat karti hai. Isliye unknown follow state ko verified follower ka reliable business proof nahi samajhna chahiye. Current implementation behavior ko historical documentation/comments ke against preference di gayi hai.

DM FlowEngine send-message/template, wait-reply, condition aur edge-based progression use karta hai. Timeout-resume scheduler waits continue karta hai. OAuth token refresh background task hai. Logs aur diagnose/register-webhook commands operational troubleshooting ke tools hain.

Sources: `app/Modules/Instagram`, especially `CommentFunnelService`, `FlowEngine`, `KeywordMatcher`, `PrivateReplyService`, `LeadDeliveryService`, `InboxMirrorService`; `resources/js/Pages/Instagram`.

## 15. Social media publishing

Supported network driver files Facebook, Instagram, LinkedIn, X/Twitter, TikTok aur YouTube ke liye hain. Accounts OAuth connect/callback/disconnect se manage hote hain.

Composer selected accounts, text, media, per-network limits aur native-looking previews show karta hai. Timezone-aware scheduling aur publish workflow hai. Posts list/edit/delete, publish-now/cancel aur calendar views hain. AI-generate, AI-plan aur bulk-store endpoints content aur multiple posts prepare karne ke liye hain.

Scheduled dispatcher due posts queue karta hai, publisher network driver choose karta hai, publish job result/state manage karta hai aur token-refresh job expiring credentials refresh karta hai. Network-specific format, media requirements, OAuth permissions aur app approvals actual publishing determine karte hain.

Sources: `app/Modules/Social`, `SocialPublisher`, `SocialPostController`, `resources/js/Pages/Social`.

## 16. Ecommerce

Stores Shopify, WooCommerce aur BigCommerce connector paths rakhte hain. Manual credential connection aur platform-specific OAuth routes available hain. Store create/test/sync/delete, products browse/search aur orders list/detail/refresh/fulfill actions hain. Contact ke orders inbox/profile context mein retrieve kiye ja sakte hain.

Background jobs webhook registration, product/customer sync, order backfill, incoming webhook processing, abandoned-cart checks aur ecommerce messaging handle karte hain. PayloadNormalizer platform data ko common representation mein convert karta hai. ContactEnricher customer details aur ContactCapacity plan capacity concerns handle karte hain. Store URL guard connection URLs validate karne ke related service hai.

Store WhatsApp automations configuration modal aur ecommerce template seeding support order/customer messaging workflows. Order/cart events general automation triggers bhi drive karte hain. Fulfillment action platform-specific capabilities aur remote response par depend karti hai.

Sources: `app/Modules/Ecommerce`, `resources/js/Pages/Ecommerce`, `WhatsappAutomationsModal.jsx`.

## 17. Lead discovery

Lead scraper Google Places-related service se business leads collect karta hai. UI previous scrape jobs aur paginated results show karti hai. Scrape request lead-credit limit ke saath queue hoti hai. Selected leads push-to-contacts action se CRM mein transfer hote hain; inspected controller phone/email dono absent hon to lead skip karta hai aur already-pushed state maintain karta hai. Lead delete endpoint workspace ownership check karta hai.

Sources: `GooglePlacesScraper`, `ScrapeLeadsJob`, `LeadController`, `resources/js/Pages/Leads/Index.jsx`.

## 18. Reports aur exports

Reports inbox operations, campaign performance, automation runs, AI usage aur social results cover karte hain. Dashboard chart components line/bar/donut/funnel visualization support karte hain. Campaign reports per-campaign page par hain; campaign report overview route campaign list ko redirect hota hai.

CSV export endpoints contacts, campaign recipients, conversations, AI runs aur audit log ke liye hain. Weekly digest command workspace owners ko scheduled performance email bhejne ka path hai. Full workspace export ZIP alag asynchronous settings workflow hai.

Sources: `routes/reports.php`, `Client/Reports`, `AnalyticsService`, `WorkspaceExportService`, `SendWeeklyDigestCommand`.

## 19. Subscription, billing aur limits

Client pricing/subscription/billing pages plan choose/change, coupon validation, checkout, invoice download aur subscription cancellation actions expose karti hain. Admin plans feature flags/limits aur subscription assignments manage karta hai. Coupons, tax rates, currencies, payments aur refund paths separate admin features hain.

Billing service implementations Stripe, PayPal, Paddle, Razorpay, Paystack, Mollie, MercadoPago, Xendit, Tap, Paymob, MyFatoorah, Square aur Cashfree ke liye hain. Registry/configuration enabled options decide karti hai; sab gateways ka renewal/refund/change-plan behavior identical nahi hai. Cashfree code mein in-place plan change unsupported hai aur new mandate requirement noted hai.

UsageMeter monthly workspace usage rakhta hai. EnforceLimit missing/null limit ko unlimited treat karta hai; exceeded JSON request par HTTP 402 aur `upgrade_required` response deta hai. UI upgrade banner/modal related experience provide karte hain. Individual routes aur services par limits applied hain; har write endpoint uniformly limited hone ka claim nahi hai.

Scheduler subscription sync, trial expiry, Tap/Paymob/MyFatoorah recurring charging aur trial-ending notification paths run karta hai.

Sources: `app/Services/Billing`, `CheckoutController`, `Client/SubscriptionController`, `EnforceLimit`, `UsageMeter`, `config/billing.php`.

## 20. Notifications aur support

In-app notifications list/recent/unread count, mark-read/mark-all-read/delete aur preference updates support karti hain. Echo private user channel events unread count/toasts update karte hain. Browser notification handling, Web Push subscriptions aur optional OneSignal integration present hain. Configured app keys, browser permissions aur service-worker deployment working push ke prerequisites hain.

Support tickets client list/create/show/reply aur admin ticket management/reply workflows provide karte hain. Help Docs/User Guide external links branding settings se enabled ho sakte hain.

Sources: `Client/NotificationController`, `WebPushController`, `OneSignalService`, `WebPushService`, `hooks/useOneSignal.js`, `public/sw.js`, client/admin Support pages.

## 21. Admin panel — operational controls

| Area | Available purpose/actions |
|---|---|
| Dashboard | Platform overview |
| Clients | Organization CRUD/export, users manage, plan assign, impersonate, branding |
| Plans | Create/edit/delete, duplicate, reorder, limits/features |
| Subscriptions | Review/export, user search, subscription creation |
| Payments | Transactions review aur refund endpoint |
| Gateways | Credentials/configuration and enabled provider management |
| Coupons/tax/currencies | Commercial configuration |
| Roles/permissions/admins | Admin access management |
| Locales/translations | Language CRUD/default, bulk edits aur auto-translate endpoint |
| Email system | SMTP CRUD/activate/test, email-template edit/test |
| Integrations | Provider configuration, connection tests aur audit visibility |
| System settings | Platform-wide configuration |
| Landing/CMS | Public content aur marketing configuration |
| Queue | Background queue operations/visibility |
| Cron setup | Scheduler setup guidance aur heartbeat visibility |
| Pusher settings | Realtime service configuration |
| License | License management/update-related services |
| Audit log | Admin activity visibility |
| AI dashboard | Platform AI overview/configuration-related UI |
| Support | Ticket handling |

Navigation permissions aur route permission middleware dono inspect karne chahiye. Hidden sidebar item alone endpoint authorization nahi hai. Admin guard/client guard alag login/logout context rakhte hain. Impersonation client context temporarily open karke return action provide karti hai.

Sources: `routes/admin.php`, `app/Http/Controllers/Admin`, `resources/js/Pages/Admin`, `AdminLayout.jsx`.

## 22. Integration credentials aur developer APIs

CredentialResolver workspace override → system default → unavailable pattern use karta hai, with provider-specific credential objects. LLM aur SMS workspace overrides alag config tables se aa sakte hain; Facebook/Instagram OAuth shared Meta app config se resolve hota hai. Admin integration connection tests aur audit tools configuration troubleshooting support karte hain.

API tokens, API docs aur outbound webhook management client developer section mein hain. Token abilities endpoint access narrow karti hain. V1 API route families contacts, segments, campaigns/recipients, messages, conversations, AI KB/chatbots, automations, social, analytics, workspace/account/subscription, notifications aur audit resources include karti hain; exact registered declarations appendix mein hain.

Mobile auth/inbox/conversation controller paths bhi present hain. API message endpoint throttling aur relevant plan-limit middleware route-specific hain. Outbound webhook endpoints CRUD, secret rotation, test delivery aur delivery log view support karte hain. Dispatch services/jobs external callback delivery handle karte hain. Incoming provider webhooks aur outgoing customer-defined webhooks separate flows hain.

Sources: `routes/api.php`, `routes/webhooks.php`, `routes/channels.php`, `Api/V1`, `Client/WebhookEndpointController`, `WebhookDispatchService`, `WebhookIdempotencyService`.

## 23. Public website, CMS, installer aur licensing

Public landing includes hero, unified inbox showcase, metrics, trusted brands, channels, problem/solution, how-it-works, integrations, benefits, security, pricing, testimonials, FAQ aur CTA. Optional live chat mock config se enable hota hai. Separate About, Use Cases, Integrations, Pricing, FAQ, Contact aur CMS page screens hain. SEO metadata/structured data components hain; promotional figures runtime analytics ka substitute nahi hain.

Installation components requirements, license, database, app settings, admin aur seed steps expose karte hain. InstallerService aur EnvWriter setup persist karte hain. EnsureInstalled/EnsureLicensed middleware app access gating ke related controls hain. LicenseManager aur Updater activation/update operations ka backend provide karte hain. Is documentation task mein installation, updates ya license actions execute nahi kiye gaye.

Sources: `Welcome.jsx`, `Layouts/LandingLayout.jsx`, `Pages/marketing`, `Pages/Install`, `Components/Install`, `app/Services/Install`, `app/Services/License`.

## 24. Background processes aur deployment dependencies

| Task | Schedule in current code | User-visible dependency |
|---|---|---|
| Scheduler heartbeat | Every minute | Cron Setup health indication |
| Scheduled campaigns | Every minute | Timed campaign launch |
| Scheduled social posts | Every minute | Timed publishing |
| Instagram wait resumption | Every minute | DM flow timeout progression |
| WhatsApp template sync | Daily | Updated Meta template state |
| Social token refresh | Daily 02:00 | Connected social account continuity |
| Instagram login refresh | Daily 02:30 | Instagram credential continuity |
| AI memory decay | Daily 03:30 | Learned-memory hygiene |
| Usage meter pruning | Monthly day 1, 00:05 | Older period cleanup; current month retained |
| Webhook idempotency pruning | Weekly | Old duplicate-event record cleanup |
| Billing sync/trial expiry/recurring commands | Hourly | Subscription lifecycle |
| Trial-ending notice | Daily 09:00 | Reminder emails |
| Weekly digest | Monday 09:00 | Performance emails |

Schedule times above source values hain; actual timezone Laravel deployment configuration determine karti hai. Campaign, WhatsApp, inbox, AI, automation, ecommerce, social, Instagram, lead, webhook aur export jobs ko functioning queue workers chahiye. UI save successful ho sakta hai jab downstream worker disabled ho; queued state ko completed delivery assume nahi karna chahiye.

## 25. Practical workflows

### Customer support

Channel connect → inbound webhook → normalized message/contact/conversation → inbox update → bot or agent assignment → reply → provider result/status → reports/notifications.

### Marketing campaign

Contacts import → tags/segment prepare → channel/audience/content/schedule/review → test-send → launch/scheduler → recipient/chunk/message jobs → delivery results → campaign report/export.

### Grounded chatbot

Provider configure → KB create → sources add/index → bot instructions and KB attach → playground test → channel assignment → real conversations → AI runs/usage → optional learned memories.

### Ecommerce messaging

Store connect/test → product/customer/order sync and webhook registration → event processing → contact/order context → approved template notification or automation → inbox/order reporting.

### Instagram lead funnel

Instagram account connect → post/keyword automation configure → comment receive → initial private reply → user CTA/reply → optional follow gate → delivery keyword → content delivery → funnel logs/inbox mirror.

## 26. Review limits aur maintenance notes

- Yeh source inspection hai; complete runtime QA, security audit ya provider compliance verification nahi.
- Some React pages inline controls/styles use karti hain aur kuch shared components; future visual changes dono paths cover karein.
- Sidebar ke standard aur inbox versions same navigation hook use karte hain, lekin topbar/notification behavior identical nahi.
- Inbox fixed-width panels, theme consistency aur RTL layouts ko real viewport testing chahiye.
- AI indexed document, dry-run workflow, configured gateway aur saved social account ko successful external operation ka proof na samjhein.
- Secrets/environment values is document mein copy nahi kiye gaye.
- Documentation source `docs/README.md` ke convention ke mutabiq `docs/` mein hai.
- Frontend changes ke liye repository standing workflow build/commit/push hai; is task mein application frontend source change nahi ki gayi.
- Exact inventories neeche source snapshot hain; code changes ke baad regenerate/update karna hoga.

## 27. Exact source inventories

Neeche ke appendices mechanically extracted hain. Paths source jump ke liye hain; line numbers snapshot ke waqt ke hain. Controller action registry public methods list karti hai. Service/job registry private/public helpers bhi list karti hai taake implementation ka function-level navigation available rahe. Vendor, generated frontend bundles aur tests is index ka part nahi hain.

### A. UI pages

- `resources/js/Pages/Admin/Admins/Index.jsx`
- `resources/js/Pages/Admin/AI/Dashboard.jsx`
- `resources/js/Pages/Admin/AuditLog/Index.jsx`
- `resources/js/Pages/Admin/Auth/Login.jsx`
- `resources/js/Pages/Admin/Clients/Index.jsx`
- `resources/js/Pages/Admin/CmsPages/Index.jsx`
- `resources/js/Pages/Admin/Coupons/Index.jsx`
- `resources/js/Pages/Admin/CronSetup/Index.jsx`
- `resources/js/Pages/Admin/Currencies/Index.jsx`
- `resources/js/Pages/Admin/Dashboard.jsx`
- `resources/js/Pages/Admin/EmailSystem/Index.jsx`
- `resources/js/Pages/Admin/Integrations/AuditLog.jsx`
- `resources/js/Pages/Admin/Integrations/Edit.jsx`
- `resources/js/Pages/Admin/Integrations/Index.jsx`
- `resources/js/Pages/Admin/LandingPage/Index.jsx`
- `resources/js/Pages/Admin/License/Index.jsx`
- `resources/js/Pages/Admin/Locales/Index.jsx`
- `resources/js/Pages/Admin/PaymentGateways/Index.jsx`
- `resources/js/Pages/Admin/Payments/Index.jsx`
- `resources/js/Pages/Admin/Plans/Edit.jsx`
- `resources/js/Pages/Admin/Plans/Index.jsx`
- `resources/js/Pages/Admin/Plans/PlanFeatures.jsx`
- `resources/js/Pages/Admin/Plans/PlanForm.jsx`
- `resources/js/Pages/Admin/Plans/PlanLimits.jsx`
- `resources/js/Pages/Admin/Plans/PlanModal.jsx`
- `resources/js/Pages/Admin/Plans/PlanTable.jsx`
- `resources/js/Pages/Admin/PusherSettings/Index.jsx`
- `resources/js/Pages/Admin/Queue/Index.jsx`
- `resources/js/Pages/Admin/RolesPermissions/Index.jsx`
- `resources/js/Pages/Admin/Settings/Index.jsx`
- `resources/js/Pages/Admin/Subscriptions/Index.jsx`
- `resources/js/Pages/Admin/Support/Create.jsx`
- `resources/js/Pages/Admin/Support/Index.jsx`
- `resources/js/Pages/Admin/Support/Show.jsx`
- `resources/js/Pages/Admin/TaxRates/Index.jsx`
- `resources/js/Pages/AI/Chatbots/Index.jsx`
- `resources/js/Pages/AI/KnowledgeBases/Index.jsx`
- `resources/js/Pages/AI/KnowledgeBases/Show.jsx`
- `resources/js/Pages/AI/Memories/Index.jsx`
- `resources/js/Pages/AI/Providers/Index.jsx`
- `resources/js/Pages/Auth/AcceptInvitation.jsx`
- `resources/js/Pages/Auth/ConfirmPassword.jsx`
- `resources/js/Pages/Auth/ForgotPassword.jsx`
- `resources/js/Pages/Auth/Login.jsx`
- `resources/js/Pages/Auth/MagicLink.jsx`
- `resources/js/Pages/Auth/Register.jsx`
- `resources/js/Pages/Auth/ResetPassword.jsx`
- `resources/js/Pages/Auth/TwoFactorChallenge.jsx`
- `resources/js/Pages/Auth/VerifyEmail.jsx`
- `resources/js/Pages/Automation/AutomationTemplateModal.jsx`
- `resources/js/Pages/Automation/Builder.jsx`
- `resources/js/Pages/Automation/Index.jsx`
- `resources/js/Pages/Automation/Runs.jsx`
- `resources/js/Pages/Broadcasting/Campaigns/CampaignForm.jsx`
- `resources/js/Pages/Broadcasting/Campaigns/Edit.jsx`
- `resources/js/Pages/Broadcasting/Campaigns/Index.jsx`
- `resources/js/Pages/Broadcasting/Campaigns/Show.jsx`
- `resources/js/Pages/Broadcasting/Campaigns/Wizard.jsx`
- `resources/js/Pages/Broadcasting/EmailServer/Index.jsx`
- `resources/js/Pages/Broadcasting/SmsProviders/Index.jsx`
- `resources/js/Pages/client/Api/Docs.jsx`
- `resources/js/Pages/client/Api/Tokens.jsx`
- `resources/js/Pages/client/AuditLog/Index.jsx`
- `resources/js/Pages/client/Billing/Index.jsx`
- `resources/js/Pages/client/Checkout/Sdk.jsx`
- `resources/js/Pages/client/Dashboard.jsx`
- `resources/js/Pages/client/Media/Index.jsx`
- `resources/js/Pages/client/Notifications/Index.jsx`
- `resources/js/Pages/client/Onboarding/Wizard.jsx`
- `resources/js/Pages/client/Pricing.jsx`
- `resources/js/Pages/client/Reports/Ai/Index.jsx`
- `resources/js/Pages/client/Reports/Automation/Index.jsx`
- `resources/js/Pages/client/Reports/Campaign/Show.jsx`
- `resources/js/Pages/client/Reports/Inbox/Index.jsx`
- `resources/js/Pages/client/Reports/Social/Index.jsx`
- `resources/js/Pages/client/Settings/DataExport.jsx`
- `resources/js/Pages/client/Settings/Index.jsx`
- `resources/js/Pages/client/Settings/Notifications.jsx`
- `resources/js/Pages/client/Subscription/Show.jsx`
- `resources/js/Pages/client/Support/Create.jsx`
- `resources/js/Pages/client/Support/Index.jsx`
- `resources/js/Pages/client/Support/Show.jsx`
- `resources/js/Pages/client/Team/Index.jsx`
- `resources/js/Pages/client/Webhooks/Deliveries.jsx`
- `resources/js/Pages/client/Webhooks/Index.jsx`
- `resources/js/Pages/client/Workspaces/Index.jsx`
- `resources/js/Pages/Contacts/BulkImport.jsx`
- `resources/js/Pages/Contacts/Index.jsx`
- `resources/js/Pages/Contacts/SegmentContacts.jsx`
- `resources/js/Pages/Contacts/Segments.jsx`
- `resources/js/Pages/Contacts/Show.jsx`
- `resources/js/Pages/Ecommerce/Orders/Index.jsx`
- `resources/js/Pages/Ecommerce/Orders/Show.jsx`
- `resources/js/Pages/Ecommerce/Products/Index.jsx`
- `resources/js/Pages/Ecommerce/Stores/Index.jsx`
- `resources/js/Pages/Ecommerce/Stores/WhatsappAutomationsModal.jsx`
- `resources/js/Pages/Inbox/CannedReplies/Index.jsx`
- `resources/js/Pages/Inbox/Index.jsx`
- `resources/js/Pages/Inbox/Labels/Index.jsx`
- `resources/js/Pages/Inbox/Setup.jsx`
- `resources/js/Pages/Inbox/Show.jsx`
- `resources/js/Pages/Instagram/Automations/Edit.jsx`
- `resources/js/Pages/Instagram/Automations/Index.jsx`
- `resources/js/Pages/Instagram/Flows/Builder.jsx`
- `resources/js/Pages/Instagram/Flows/Index.jsx`
- `resources/js/Pages/Instagram/Logs/Index.jsx`
- `resources/js/Pages/Instagram/Setup.jsx`
- `resources/js/Pages/Instagram/Templates/Edit.jsx`
- `resources/js/Pages/Instagram/Templates/Index.jsx`
- `resources/js/Pages/Install/Setup.jsx`
- `resources/js/Pages/Leads/Index.jsx`
- `resources/js/Pages/License/Activate.jsx`
- `resources/js/Pages/marketing/About.jsx`
- `resources/js/Pages/marketing/CmsPage.jsx`
- `resources/js/Pages/marketing/Contact.jsx`
- `resources/js/Pages/marketing/Faq.jsx`
- `resources/js/Pages/marketing/Integrations.jsx`
- `resources/js/Pages/marketing/Pricing.jsx`
- `resources/js/Pages/marketing/UseCases.jsx`
- `resources/js/Pages/Profile/Edit.jsx`
- `resources/js/Pages/Profile/Partials/DeleteUserForm.jsx`
- `resources/js/Pages/Profile/Partials/UpdatePasswordForm.jsx`
- `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.jsx`
- `resources/js/Pages/Profile/Sessions.jsx`
- `resources/js/Pages/Profile/TwoFactor.jsx`
- `resources/js/Pages/Social/Accounts/Index.jsx`
- `resources/js/Pages/Social/Calendar.jsx`
- `resources/js/Pages/Social/Composer.jsx`
- `resources/js/Pages/Social/Posts/AiPlannerModal.jsx`
- `resources/js/Pages/Social/Posts/Edit.jsx`
- `resources/js/Pages/Social/Posts/Index.jsx`
- `resources/js/Pages/Welcome.jsx`
- `resources/js/Pages/Whatsapp/AutoReplies/Index.jsx`
- `resources/js/Pages/Whatsapp/Setup.jsx`
- `resources/js/Pages/Whatsapp/Templates/Editor.jsx`
- `resources/js/Pages/Whatsapp/Templates/Index.jsx`
- `resources/js/Pages/Whatsapp/Widget/Create.jsx`
- `resources/js/Pages/Whatsapp/Widget/Edit.jsx`
- `resources/js/Pages/Whatsapp/Widget/Index.jsx`
- `resources/js/Pages/Whatsapp/Widget/Partials/WidgetForm.jsx`
- `resources/js/Pages/WhatsappQR/Index.jsx`
- `resources/js/Pages/WhatsappQR/Show.jsx`

### B. Route declarations


#### app/Modules/AI/routes/web.php

```php
// line 11
Route::get('/providers', [AiProviderController::class, 'index'])->name('providers.index');
// line 12
Route::put('/providers/{provider}', [AiProviderController::class, 'update'])->name('providers.update');
// line 15
Route::get('/knowledge-bases', [AiKnowledgeBaseController::class, 'index'])->name('knowledge-bases.index');
// line 16
Route::post('/knowledge-bases', [AiKnowledgeBaseController::class, 'store'])->name('knowledge-bases.store')->middleware('limit:knowledge_bases,knowledge_bases');
// line 17
Route::get('/knowledge-bases/{kb}', [AiKnowledgeBaseController::class, 'show'])->name('knowledge-bases.show');
// line 18
Route::post('/knowledge-bases/{kb}/documents', [AiKnowledgeBaseController::class, 'addDocument'])->name('knowledge-bases.documents.add');
// line 19
Route::post('/documents/{document}/reindex', [AiKnowledgeBaseController::class, 'reindex'])->name('documents.reindex');
// line 20
Route::delete('/documents/{document}', [AiKnowledgeBaseController::class, 'destroyDocument'])->name('documents.destroy');
// line 23
Route::get('/chatbots', [AiChatbotController::class, 'index'])->name('chatbots.index');
// line 24
Route::post('/chatbots', [AiChatbotController::class, 'store'])->name('chatbots.store');
// line 25
Route::put('/chatbots/{chatbot}', [AiChatbotController::class, 'update'])->name('chatbots.update');
// line 26
Route::delete('/chatbots/{chatbot}', [AiChatbotController::class, 'destroy'])->name('chatbots.destroy');
// line 27
Route::post('/chatbots/{chatbot}/playground', [AiChatbotController::class, 'playground'])->name('chatbots.playground')->middleware('limit:ai_tokens_per_month,ai_tokens');
// line 30
Route::get('/memories', [AiMemoryController::class, 'index'])->name('memories.index');
// line 31
Route::post('/memories', [AiMemoryController::class, 'store'])->name('memories.store');
// line 32
Route::delete('/memories/{memory}', [AiMemoryController::class, 'destroy'])->name('memories.destroy');
// line 33
Route::post('/memories/toggle-learning', [AiMemoryController::class, 'toggle'])->name('memories.toggle');
```

#### app/Modules/Automation/routes/web.php

```php
// line 7
Route::get('/', [AutomationController::class, 'index'])->name('index');
// line 8
Route::post('/', [AutomationController::class, 'store'])->name('store');
// line 9
Route::post('/generate', [AutomationController::class, 'generate'])->name('generate');
// line 10
Route::post('/template', [AutomationController::class, 'storeFromTemplate'])->name('template');
// line 11
Route::get('/{automation}/edit', [AutomationController::class, 'edit'])->name('edit');
// line 12
Route::put('/{automation}', [AutomationController::class, 'update'])->name('update');
// line 13
Route::delete('/{automation}', [AutomationController::class, 'destroy'])->name('destroy');
// line 14
Route::get('/{automation}/runs', [AutomationController::class, 'runs'])->name('runs');
// line 15
Route::post('/{automation}/test', [AutomationController::class, 'test'])->name('test');
// line 16
Route::post('/{automation}/token', [AutomationController::class, 'generateToken'])->name('generate-token');
```

#### app/Modules/Broadcasting/routes/web.php

```php
// line 10
Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
// line 11
Route::post('/campaigns/generate-email', [EmailAiController::class, 'generate'])->name('campaigns.generate-email');
// line 12
Route::post('/campaigns/improve-subject', [EmailAiController::class, 'improveSubject'])->name('campaigns.improve-subject');
// line 13
Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
// line 14
Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
// line 15
Route::post('/campaigns/draft', [CampaignController::class, 'storeDraft'])->name('campaigns.store-draft');
// line 16
Route::post('/campaigns/audience-preview', [CampaignController::class, 'audiencePreview'])->name('campaigns.audience-preview');
// line 17
Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
// line 18
Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
// line 19
Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
// line 20
Route::post('/campaigns/{campaign}/test-send', [CampaignController::class, 'testSend'])->name('campaigns.test-send');
// line 21
Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch')->middleware('limit:campaigns_per_month,campaigns');
// line 22
Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
// line 23
Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');
// line 25
Route::get('/sms-gateways', [SmsProviderController::class, 'index'])->name('sms-gateways.index');
// line 26
Route::put('/sms-gateways/{provider}', [SmsProviderController::class, 'update'])->name('sms-gateways.update');
// line 27
Route::delete('/sms-gateways/{provider}', [SmsProviderController::class, 'destroy'])->name('sms-gateways.destroy');
// line 29
Route::get('/email-server', [EmailServerController::class, 'index'])->name('email-server.index');
// line 30
Route::post('/email-server', [EmailServerController::class, 'store'])->name('email-server.store');
// line 31
Route::put('/email-server', [EmailServerController::class, 'update'])->name('email-server.update');
// line 32
Route::delete('/email-server', [EmailServerController::class, 'destroy'])->name('email-server.destroy');
// line 33
Route::post('/email-server/test', [EmailServerController::class, 'testEmail'])->name('email-server.test');
```

#### app/Modules/Ecommerce/routes/web.php

```php
// line 12
Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
// line 13
Route::post('/stores', [StoreController::class, 'store'])->name('stores.store');
// line 14
Route::post('/stores/{store}/test', [StoreController::class, 'test'])->name('stores.test');
// line 15
Route::post('/stores/{store}/sync', [StoreController::class, 'sync'])->name('stores.sync');
// line 16
Route::delete('/stores/{store}', [StoreController::class, 'destroy'])->name('stores.destroy');
// line 19
Route::get('/oauth/{platform}/connect', [EcommerceOAuthController::class, 'connect'])->name('oauth.connect');
// line 20
Route::get('/oauth/shopify/callback', [EcommerceOAuthController::class, 'shopifyCallback'])->name('oauth.shopify.callback');
// line 21
Route::get('/oauth/bigcommerce/callback', [EcommerceOAuthController::class, 'bigcommerceCallback'])->name('oauth.bigcommerce.callback');
// line 22
Route::get('/oauth/woocommerce/return', [EcommerceOAuthController::class, 'woocommerceReturn'])->name('oauth.woocommerce.return');
// line 25
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
// line 26
Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
// line 29
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
// line 30
Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
// line 31
Route::post('/orders/{order}/refresh', [OrderController::class, 'refresh'])->name('orders.refresh');
// line 32
Route::post('/orders/{order}/fulfill', [OrderController::class, 'fulfill'])->name('orders.fulfill');
// line 35
Route::get('/contacts/{contact}/orders', [OrderContextController::class, 'index'])->name('contacts.orders');
```

#### app/Modules/Inbox/routes/web.php

```php
// line 12
Route::get('/', [InboxController::class, 'index'])->name('index');
// line 13
Route::get('/contacts/search', [InboxController::class, 'contactSearch'])->name('contacts.search');
// line 14
Route::get('/channel-accounts', [InboxController::class, 'channelAccounts'])->name('channel-accounts');
// line 15
Route::get('/templates', [InboxController::class, 'templates'])->name('templates');
// line 16
Route::post('/conversations/{conversation}/upload-media', [InboxController::class, 'uploadMedia'])->name('upload-media');
// line 17
Route::get('/conversations/{conversation}/messages/{message}/media', [InboxController::class, 'serveMedia'])->name('message-media');
// line 18
Route::post('/conversations/start', [InboxController::class, 'startConversation'])->name('start');
// line 19
Route::get('/conversations/{conversation}', [InboxController::class, 'show'])->name('show');
// line 20
Route::post('/conversations/{conversation}/reply', [InboxController::class, 'reply'])->name('reply')->middleware('limit:whatsapp_messages_per_month,whatsapp_messages');
// line 21
Route::post('/conversations/{conversation}/share-product', [InboxController::class, 'shareProduct'])->name('share-product')->middleware('limit:whatsapp_messages_per_month,whatsapp_messages');
// line 22
Route::post('/conversations/{conversation}/assign', [InboxController::class, 'assign'])->name('assign');
// line 23
Route::post('/conversations/{conversation}/status', [InboxController::class, 'updateStatus'])->name('status');
// line 24
Route::post('/conversations/{conversation}/typing', [InboxController::class, 'typing'])->name('typing');
// line 25
Route::get('/conversations/{conversation}/notes', [InternalNoteController::class, 'index'])->name('notes.index');
// line 26
Route::post('/conversations/{conversation}/notes', [InternalNoteController::class, 'store'])->name('notes.store');
// line 27
Route::post('/conversations/{conversation}/handover', [InboxController::class, 'handover'])->name('handover');
// line 30
Route::post('/conversations/{conversation}/ai-draft', [AiDraftController::class, 'store'])->name('ai-draft.store')->middleware('limit:ai_tokens_per_month,ai_tokens');
// line 33
Route::get('/canned-replies', [CannedReplyController::class, 'index'])->name('canned-replies.index');
// line 34
Route::get('/canned-replies/list', [CannedReplyController::class, 'list'])->name('canned-replies.list');
// line 35
Route::post('/canned-replies', [CannedReplyController::class, 'store'])->name('canned-replies.store');
// line 36
Route::put('/canned-replies/{cannedReply}', [CannedReplyController::class, 'update'])->name('canned-replies.update');
// line 37
Route::delete('/canned-replies/{cannedReply}', [CannedReplyController::class, 'destroy'])->name('canned-replies.destroy');
// line 40
Route::get('/labels', [LabelController::class, 'index'])->name('labels.index');
// line 41
Route::post('/labels', [LabelController::class, 'store'])->name('labels.store');
// line 42
Route::put('/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
// line 43
Route::delete('/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
// line 44
Route::post('/conversations/{conversation}/labels', [LabelController::class, 'attach'])->name('labels.attach');
// line 45
Route::delete('/conversations/{conversation}/labels/{label}', [LabelController::class, 'detach'])->name('labels.detach');
// line 48
Route::get('/setup', [InboxSetupController::class, 'index'])->name('setup');
// line 49
Route::post('/setup/embedded-signup/messenger', [InboxSetupController::class, 'embeddedSignupMessenger'])->name('setup.embedded-signup.messenger');
// line 51
Route::post('/setup/instagram-login/connect', [InboxSetupController::class, 'instagramLoginConnect'])->name('setup.instagram-login.connect');
// line 52
Route::post('/setup/instagram-login/token', [InboxSetupController::class, 'instagramManualToken'])->name('setup.instagram-login.token');
// line 53
Route::patch('/setup/{channelAccount}/chatbot', [InboxSetupController::class, 'assignChatbot'])->name('setup.assign-chatbot');
// line 54
Route::delete('/setup/{channelAccount}', [InboxSetupController::class, 'destroy'])->name('setup.destroy');
```

#### app/Modules/Instagram/routes/web.php

```php
// line 14
Route::get('/setup', [ConnectController::class, 'index'])->name('setup');
// line 15
Route::get('/setup/connect-instagram-login', [ConnectController::class, 'connectInstagramLogin'])->name('connect-instagram-login');
// line 16
Route::get('/setup/status', [ConnectController::class, 'status'])->name('setup.status');
// line 18
Route::get('/automations', [AutomationController::class, 'index'])->name('automations.index');
// line 19
Route::get('/automations/create', [AutomationController::class, 'create'])->name('automations.create');
// line 20
Route::get('/automations/recent-posts', [AutomationController::class, 'recentPosts'])->name('automations.recent-posts');
// line 21
Route::get('/automations/{automation}/edit', [AutomationController::class, 'edit'])->name('automations.edit');
// line 22
Route::post('/automations', [AutomationController::class, 'store'])->name('automations.store');
// line 23
Route::put('/automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
// line 24
Route::patch('/automations/{automation}/toggle', [AutomationController::class, 'toggle'])->name('automations.toggle');
// line 25
Route::delete('/automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
// line 27
Route::get('/logs', [LogsController::class, 'index'])->name('logs.index');
// line 30
Route::get('/flows', [FlowController::class, 'index'])->name('flows.index');
// line 31
Route::get('/flows/create', [FlowController::class, 'create'])->name('flows.create');
// line 32
Route::get('/flows/{flow}/edit', [FlowController::class, 'edit'])->name('flows.edit');
// line 33
Route::post('/flows', [FlowController::class, 'store'])->name('flows.store');
// line 34
Route::put('/flows/{flow}', [FlowController::class, 'update'])->name('flows.update');
// line 35
Route::patch('/flows/{flow}/toggle', [FlowController::class, 'toggle'])->name('flows.toggle');
// line 36
Route::delete('/flows/{flow}', [FlowController::class, 'destroy'])->name('flows.destroy');
// line 40
Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
// line 41
Route::get('/templates/gallery', [TemplateController::class, 'gallery'])->name('templates.gallery');
// line 42
Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
// line 43
Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
// line 44
Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
// line 45
Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
// line 46
Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
```

#### app/Modules/Leads/routes/web.php

```php
// line 7
Route::get('/', [LeadController::class, 'index'])->name('index');
// line 8
Route::post('/scrape', [LeadController::class, 'scrape'])->name('scrape')->middleware('limit:lead_credits_per_month,lead_credits');
// line 9
Route::post('/push-to-contacts', [LeadController::class, 'pushToContacts'])->name('push-to-contacts');
// line 10
Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
```

#### app/Modules/Shared/routes/web.php

```php
// line 9
Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
// line 10
Route::get('/contacts/bulk-import', [ContactController::class, 'bulkImport'])->name('contacts.bulk-import');
// line 11
Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
// line 12
Route::post('/contacts/bulk', [ContactController::class, 'bulkStore'])->name('contacts.bulk-store');
// line 13
Route::post('/contacts/import', [ContactController::class, 'import'])->name('contacts.import');
// line 14
Route::delete('/contacts', [ContactController::class, 'bulkDestroy'])->name('contacts.bulk-destroy');
// line 15
Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');
// line 16
Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
// line 17
Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
// line 18
Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
// line 19
Route::post('/contacts/{contact}/avatar', [ContactController::class, 'uploadAvatar'])->name('contacts.avatar.upload');
// line 20
Route::delete('/contacts/{contact}/avatar', [ContactController::class, 'deleteAvatar'])->name('contacts.avatar.delete');
// line 23
Route::get('/segments', [SegmentController::class, 'index'])->name('segments.index');
// line 24
Route::post('/segments', [SegmentController::class, 'store'])->name('segments.store');
// line 25
Route::put('/segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
// line 26
Route::delete('/segments/{segment}', [SegmentController::class, 'destroy'])->name('segments.destroy');
// line 27
Route::get('/segments/{segment}/contacts', [SegmentController::class, 'manageContacts'])->name('segments.contacts');
// line 28
Route::post('/segments/{segment}/contacts', [SegmentController::class, 'attachContacts'])->name('segments.contacts.attach');
// line 29
Route::delete('/segments/{segment}/contacts/{contact}', [SegmentController::class, 'detachContact'])->name('segments.contacts.detach');
```

#### app/Modules/Social/routes/web.php

```php
// line 8
Route::get('/accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
// line 9
Route::get('/accounts/connect/{network}', [SocialAccountController::class, 'connect'])->name('accounts.connect');
// line 10
Route::get('/accounts/callback/{network}', [SocialAccountController::class, 'callback'])->name('oauth.callback');
// line 11
Route::delete('/accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('accounts.disconnect');
// line 13
Route::get('/posts', [SocialPostController::class, 'index'])->name('posts.index');
// line 14
Route::get('/composer', [SocialPostController::class, 'composer'])->name('composer');
// line 15
Route::post('/posts', [SocialPostController::class, 'store'])->name('posts.store')->middleware('limit:social_posts_per_month,social_posts');
// line 16
Route::get('/posts/{post}/edit', [SocialPostController::class, 'edit'])->name('posts.edit');
// line 17
Route::put('/posts/{post}', [SocialPostController::class, 'update'])->name('posts.update');
// line 18
Route::delete('/posts/{post}', [SocialPostController::class, 'destroy'])->name('posts.destroy');
// line 19
Route::post('/posts/{post}/publish-now', [SocialPostController::class, 'publishNow'])->name('posts.publish-now');
// line 20
Route::post('/posts/{post}/cancel', [SocialPostController::class, 'cancel'])->name('posts.cancel');
// line 21
Route::post('/ai-generate', [SocialPostController::class, 'aiGenerate'])->name('ai-generate');
// line 22
Route::post('/ai-plan',    [SocialPostController::class, 'aiPlan'])->name('ai-plan');
// line 23
Route::post('/posts/bulk', [SocialPostController::class, 'bulkStore'])->name('posts.bulk')->middleware('limit:social_posts_per_month,social_posts');
// line 24
Route::get('/calendar', [SocialPostController::class, 'calendar'])->name('calendar');
```

#### app/Modules/Whatsapp/routes/web.php

```php
// line 12
Route::get('/widgets/whatsapp/{key}.js', [WhatsappWidgetController::class, 'embed'])->name('whatsapp.widget.embed');
// line 17
Route::get('/setup', fn () => redirect()->route('client.inbox.setup'))->name('setup');
// line 18
Route::post('/setup/embedded-signup', [WhatsappEmbeddedSignupController::class, 'store'])->name('setup.embedded-signup');
// line 19
Route::post('/setup/{waba}/reregister-webhook', [WhatsappEmbeddedSignupController::class, 'reregisterWebhook'])->name('setup.reregister-webhook');
// line 20
Route::delete('/setup/{waba}', [WhatsappSetupController::class, 'destroy'])->name('setup.destroy');
// line 21
Route::post('/setup/{waba}/sync-phone-numbers', [WhatsappSetupController::class, 'syncPhoneNumbers'])->name('setup.sync-phone-numbers');
// line 22
Route::post('/setup/{waba}/seed-templates', [WhatsappSetupController::class, 'seedTemplates'])->name('setup.seed-templates');
// line 23
Route::post('/setup/{waba}/phone/{phoneNumberId}/refresh-status', [WhatsappSetupController::class, 'refreshPhoneStatus'])->name('setup.refresh-phone-status');
// line 24
Route::post('/setup/{waba}/phone/{phoneNumberId}/change-name', [WhatsappSetupController::class, 'changeDisplayName'])->name('setup.change-display-name');
// line 27
Route::get('/templates', [WhatsappTemplateController::class, 'index'])->name('templates.index');
// line 28
Route::get('/templates/create', [WhatsappTemplateController::class, 'create'])->name('templates.create');
// line 29
Route::post('/templates', [WhatsappTemplateController::class, 'store'])->name('templates.store');
// line 30
Route::post('/templates/sync', [WhatsappTemplateController::class, 'sync'])->name('templates.sync');
// line 31
Route::post('/templates/reseed-defaults', [WhatsappTemplateController::class, 'reseedDefaults'])->name('templates.reseed-defaults');
// line 32
Route::post('/templates/upload-media', [WhatsappTemplateController::class, 'uploadMedia'])->name('templates.upload-media');
// line 33
Route::get('/templates/{template}/edit', [WhatsappTemplateController::class, 'edit'])->name('templates.edit');
// line 34
Route::put('/templates/{template}', [WhatsappTemplateController::class, 'update'])->name('templates.update');
// line 35
Route::delete('/templates/{template}', [WhatsappTemplateController::class, 'destroy'])->name('templates.destroy');
// line 38
Route::get('/auto-replies', [WhatsappAutoReplyController::class, 'index'])->name('auto-replies.index');
// line 39
Route::post('/auto-replies', [WhatsappAutoReplyController::class, 'store'])->name('auto-replies.store');
// line 40
Route::put('/auto-replies/{autoReply}', [WhatsappAutoReplyController::class, 'update'])->name('auto-replies.update');
// line 41
Route::delete('/auto-replies/{autoReply}', [WhatsappAutoReplyController::class, 'destroy'])->name('auto-replies.destroy');
// line 44
Route::get('/widget', [WhatsappWidgetController::class, 'index'])->name('widget.index');
// line 45
Route::get('/widgets/create', [WhatsappWidgetController::class, 'create'])->name('widgets.create');
// line 46
Route::post('/widgets', [WhatsappWidgetController::class, 'store'])->name('widgets.store');
// line 47
Route::get('/widgets/{widget}/edit', [WhatsappWidgetController::class, 'edit'])->name('widgets.edit');
// line 48
Route::put('/widgets/{widget}', [WhatsappWidgetController::class, 'update'])->name('widgets.update');
// line 49
Route::delete('/widgets/{widget}', [WhatsappWidgetController::class, 'destroy'])->name('widgets.destroy');
```

#### app/Modules/WhatsappQR/routes/web.php

```php
// line 7
Route::get('/', [WhatsappQRController::class, 'index'])->name('index');
// line 8
Route::get('/{session}', [WhatsappQRController::class, 'show'])->name('show');
// line 9
Route::post('/', [WhatsappQRController::class, 'store'])->name('store');
// line 10
Route::get('/{session}/qr', [WhatsappQRController::class, 'qr'])->name('qr');
// line 11
Route::get('/{session}/status', [WhatsappQRController::class, 'status'])->name('status');
// line 12
Route::delete('/{session}', [WhatsappQRController::class, 'destroy'])->name('destroy');
```

#### routes/admin.php

```php
// line 36
Route::get('/', fn () => redirect()->route('admin.dashboard'));
// line 37
Route::get('/dashboard', DashboardController::class)->name('dashboard');
// line 40
Route::get('/search', [AdminSearchController::class, 'search'])->name('search');
// line 43
Route::get('/clients', [ClientController::class, 'index'])->name('clients.index')->middleware('permission:view_clients');
// line 44
Route::get('/clients/export', [ClientController::class, 'export'])->name('clients.export')->middleware('permission:view_clients');
// line 45
Route::post('/clients', [ClientController::class, 'store'])->name('clients.store')->middleware('permission:create_clients');
// line 46
Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update')->middleware('permission:update_clients');
// line 47
Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy')->middleware('permission:delete_clients');
// line 48
Route::get('/clients/{client}/users', [ClientController::class, 'users'])->name('clients.users.index')->middleware('permission:view_clients');
// line 49
Route::post('/clients/{client}/users', [ClientController::class, 'storeUser'])->name('clients.users.store')->middleware('permission:update_clients');
// line 50
Route::put('/clients/{client}/users/{user}', [ClientController::class, 'updateUser'])->name('clients.users.update')->middleware('permission:update_clients');
// line 51
Route::delete('/clients/{client}/users/{user}', [ClientController::class, 'destroyUser'])->name('clients.users.destroy')->middleware('permission:update_clients');
// line 52
Route::post('/clients/{client}/assign-plan', [ClientController::class, 'assignPlan'])->name('clients.assign-plan')->middleware('permission:view_clients');
// line 53
Route::post('/clients/{client}/impersonate', [ClientController::class, 'impersonate'])->name('clients.impersonate')->middleware('permission:view_clients');
// line 54
Route::post('/clients/{client}/branding', [ClientBrandingController::class, 'update'])->name('clients.branding')->middleware('permission:update_clients');
// line 57
Route::get('/plans', [PlanController::class, 'index'])->name('plans.index')->middleware('permission:view_plans');
// line 58
Route::post('/plans', [PlanController::class, 'store'])->name('plans.store')->middleware('permission:create_plans');
// line 59
Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit')->middleware('permission:update_plans');
// line 60
Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('plans.update')->middleware('permission:update_plans');
// line 61
Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy')->middleware('permission:delete_plans');
// line 62
Route::post('/plans/{plan}/duplicate', [PlanController::class, 'duplicate'])->name('plans.duplicate')->middleware('permission:create_plans');
// line 63
Route::post('/plans/reorder', [PlanController::class, 'reorder'])->name('plans.reorder')->middleware('permission:update_plans');
// line 66
Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index')->middleware('permission:view_subscriptions');
// line 67
Route::get('/subscriptions/export', [SubscriptionController::class, 'export'])->name('subscriptions.export')->middleware('permission:view_subscriptions');
// line 68
Route::get('/subscriptions/user-search', [SubscriptionController::class, 'userSearch'])->name('subscriptions.user-search')->middleware('permission:manage_subscriptions');
// line 69
Route::post('/subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store')->middleware('permission:manage_subscriptions');
// line 70
Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index')->middleware('permission:view_payment_gateways');
// line 71
Route::post('/payments/{transaction}/refund', [TransactionController::class, 'refund'])->name('payments.refund')->middleware('permission:view_payment_gateways');
// line 72
Route::get('/payment-gateways', [PaymentGatewayConfigController::class, 'index'])->name('payment-gateways.index')->middleware('permission:view_payment_gateways');
// line 73
Route::get('/payment-gateways/{gateway}', [PaymentGatewayConfigController::class, 'show'])->name('payment-gateways.show')->middleware('permission:manage_payment_gateways');
// line 74
Route::put('/payment-gateways/{gateway}', [PaymentGatewayConfigController::class, 'update'])->name('payment-gateways.update')->middleware('permission:manage_payment_gateways');
// line 77
Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index')->middleware('permission:view_plans');
// line 78
Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store')->middleware('permission:create_plans');
// line 79
Route::put('/coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update')->middleware('permission:create_plans');
// line 80
Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy')->middleware('permission:delete_plans');
// line 83
Route::get('/tax-rates', [TaxRateController::class, 'index'])->name('tax-rates.index')->middleware('permission:view_plans');
// line 84
Route::post('/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store')->middleware('permission:create_plans');
// line 85
Route::put('/tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update')->middleware('permission:create_plans');
// line 86
Route::delete('/tax-rates/{taxRate}', [TaxRateController::class, 'destroy'])->name('tax-rates.destroy')->middleware('permission:delete_plans');
// line 89
Route::get('/locales', [LocaleController::class, 'index'])->name('locales.index')->middleware('permission:view_languages');
// line 90
Route::post('/locales', [LocaleController::class, 'store'])->name('locales.store')->middleware('permission:manage_languages');
// line 91
Route::put('/locales/{locale}', [LocaleController::class, 'update'])->name('locales.update')->middleware('permission:manage_languages');
// line 92
Route::post('/locales/{locale}/set-default', [LocaleController::class, 'setDefault'])->name('locales.set-default')->middleware('permission:manage_languages');
// line 93
Route::delete('/locales/{locale}', [LocaleController::class, 'destroy'])->name('locales.destroy')->middleware('permission:manage_languages');
// line 96
Route::put('/translations', [TranslationController::class, 'update'])->name('translations.update')->middleware('permission:manage_languages');
// line 97
Route::post('/translations/bulk', [TranslationController::class, 'bulkUpdate'])->name('translations.bulk')->middleware('permission:manage_languages');
// line 98
Route::post('/translations/auto-translate', [TranslationController::class, 'autoTranslateMissing'])->name('translations.auto-translate')->middleware('permission:manage_languages');
// line 101
Route::get('/currencies', [CurrencyController::class, 'index'])->name('currencies.index')->middleware('permission:view_currencies');
// line 102
Route::post('/currencies', [CurrencyController::class, 'store'])->name('currencies.store')->middleware('permission:manage_currencies');
// line 103
Route::put('/currencies/{currency}', [CurrencyController::class, 'update'])->name('currencies.update')->middleware('permission:manage_currencies');
// line 106
Route::get('/email-system', [EmailSystemController::class, 'index'])->name('email-system.index')->middleware('permission:view_email_settings');
// line 107
Route::put('/email-templates/{template}', [EmailSystemController::class, 'updateTemplate'])->name('email-templates.update')->middleware('permission:manage_email_settings');
// line 108
Route::post('/smtp-configurations', [EmailSystemController::class, 'storeSmtp'])->name('smtp-configurations.store')->middleware('permission:manage_email_settings');
// line 109
Route::put('/smtp-configurations/{smtpConfiguration}', [EmailSystemController::class, 'updateSmtp'])->name('smtp-configurations.update')->middleware('permission:manage_email_settings');
// line 110
Route::delete('/smtp-configurations/{smtpConfiguration}', [EmailSystemController::class, 'destroySmtp'])->name('smtp-configurations.destroy')->middleware('permission:manage_email_settings');
// line 111
Route::post('/smtp-configurations/{smtpConfiguration}/activate', [EmailSystemController::class, 'activateSmtp'])->name('smtp-configurations.activate')->middleware('permission:manage_email_settings');
// line 112
Route::post('/smtp-configurations/test', [EmailSystemController::class, 'testEmail'])->name('smtp-configurations.test')->middleware('permission:manage_email_settings');
// line 113
Route::post('/email-templates/{template}/test', [EmailSystemController::class, 'testTemplate'])->name('email-templates.test')->middleware('permission:manage_email_settings');
// line 116
Route::get('/settings', [SystemSettingsController::class, 'index'])->name('settings.index')->middleware('permission:view_settings');
// line 117
Route::put('/settings', [SystemSettingsController::class, 'update'])->name('settings.update')->middleware('permission:manage_settings');
// line 118
Route::put('/settings/general', [SystemSettingsController::class, 'updateGeneral'])->name('settings.general.update')->middleware('permission:manage_settings');
// line 119
Route::post('/settings/logo', [SystemSettingsController::class, 'uploadLogo'])->name('settings.logo.upload')->middleware('permission:manage_settings');
// line 120
Route::delete('/settings/logo', [SystemSettingsController::class, 'deleteLogo'])->name('settings.logo.delete')->middleware('permission:manage_settings');
// line 121
Route::post('/settings/favicon', [SystemSettingsController::class, 'uploadFavicon'])->name('settings.favicon.upload')->middleware('permission:manage_settings');
// line 122
Route::delete('/settings/favicon', [SystemSettingsController::class, 'deleteFavicon'])->name('settings.favicon.delete')->middleware('permission:manage_settings');
// line 123
Route::put('/settings/firebase', [SystemSettingsController::class, 'updateFirebase'])->name('settings.firebase.update')->middleware('permission:manage_settings');
// line 124
Route::put('/settings/system-ai', [SystemSettingsController::class, 'updateSystemAi'])->name('settings.system-ai.update')->middleware('permission:manage_settings');
// line 125
Route::put('/settings/omniroute', [SystemSettingsController::class, 'updateOmniroute'])->name('settings.omniroute.update')->middleware('permission:manage_settings');
// line 126
Route::post('/settings/omniroute/test-model', [SystemSettingsController::class, 'omnirouteTestModel'])->name('settings.omniroute.test-model')->middleware('permission:manage_settings');
// line 127
Route::post('/settings/omniroute/models', [SystemSettingsController::class, 'omnirouteModels'])->name('settings.omniroute.models')->middleware('permission:manage_settings');
// line 128
Route::get('/settings/omniroute/diagnose', [SystemSettingsController::class, 'omnirouteDiagnose'])->name('settings.omniroute.diagnose')->middleware('permission:manage_settings');
// line 131
Route::get('/license', [LicenseController::class, 'index'])->name('license.index')->middleware('permission:view_settings');
// line 132
Route::post('/license/check-update', [LicenseController::class, 'checkUpdate'])->name('license.check-update')->middleware('permission:view_settings');
// line 133
Route::post('/license/apply-update', [LicenseController::class, 'applyUpdate'])->name('license.apply-update')->middleware('permission:manage_settings');
// line 134
Route::post('/license/activate', [LicenseController::class, 'activate'])->name('license.activate')->middleware('permission:manage_settings');
// line 135
Route::post('/license/deactivate', [LicenseController::class, 'deactivate'])->name('license.deactivate')->middleware('permission:manage_settings');
// line 138
Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit-log.index')->middleware('permission:view_settings');
// line 141
Route::get('/roles-permissions', [RolesPermissionsController::class, 'index'])->name('roles-permissions.index')->middleware('permission:view_admin_roles,manage_admin_roles');
// line 142
Route::post('/roles', [RoleController::class, 'store'])->name('roles.store')->middleware('permission:manage_admin_roles');
// line 143
Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update')->middleware('permission:manage_admin_roles');
// line 144
Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy')->middleware('permission:manage_admin_roles');
// line 145
Route::post('/permissions', [PermissionController::class, 'store'])->name('permissions.store')->middleware('permission:manage_admin_roles');
// line 146
Route::put('/permissions/{permission}', [PermissionController::class, 'update'])->name('permissions.update')->middleware('permission:manage_admin_roles');
// line 147
Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy'])->name('permissions.destroy')->middleware('permission:manage_admin_roles');
// line 150
Route::get('/landing-page', [LandingPageController::class, 'index'])->name('landing-page.index')->middleware('permission:view_settings');
// line 151
Route::put('/landing-page', [LandingPageController::class, 'update'])->name('landing-page.update')->middleware('permission:manage_settings');
// line 154
Route::get('/cms-pages', [CmsPageController::class, 'index'])->name('cms-pages.index')->middleware('permission:view_settings');
// line 155
Route::post('/cms-pages', [CmsPageController::class, 'store'])->name('cms-pages.store')->middleware('permission:manage_settings');
// line 156
Route::put('/cms-pages/{cmsPage}', [CmsPageController::class, 'update'])->name('cms-pages.update')->middleware('permission:manage_settings');
// line 157
Route::delete('/cms-pages/{cmsPage}', [CmsPageController::class, 'destroy'])->name('cms-pages.destroy')->middleware('permission:manage_settings');
// line 160
Route::get('/support', [SupportTicketController::class, 'index'])->name('support.index')->middleware('permission:view_settings');
// line 161
Route::get('/support/create', [SupportTicketController::class, 'create'])->name('support.create')->middleware('permission:manage_settings');
// line 162
Route::post('/support', [SupportTicketController::class, 'store'])->name('support.store')->middleware('permission:manage_settings');
// line 163
Route::get('/support/{supportTicket}', [SupportTicketController::class, 'show'])->name('support.show')->middleware('permission:view_settings');
// line 164
Route::post('/support/{supportTicket}/reply', [SupportTicketController::class, 'reply'])->name('support.reply')->middleware('permission:view_settings');
// line 165
Route::post('/support/{supportTicket}/status', [SupportTicketController::class, 'updateStatus'])->name('support.status')->middleware('permission:manage_settings');
// line 168
Route::get('/cron-setup', [CronSetupController::class, 'index'])->name('cron-setup.index')->middleware('permission:view_settings');
// line 171
Route::get('/queue', [QueueController::class, 'index'])->name('queue.index')->middleware('permission:view_settings');
// line 172
Route::post('/queue/{id}/retry', [QueueController::class, 'retryFailed'])->name('queue.retry')->middleware('permission:manage_settings');
// line 173
Route::delete('/queue/{id}', [QueueController::class, 'deleteFailed'])->name('queue.delete-failed')->middleware('permission:manage_settings');
// line 174
Route::post('/queue/retry-all', [QueueController::class, 'retryAll'])->name('queue.retry-all')->middleware('permission:manage_settings');
// line 175
Route::post('/queue/flush', [QueueController::class, 'flushFailed'])->name('queue.flush')->middleware('permission:manage_settings');
// line 178
Route::get('/pusher-settings', [PusherSettingsController::class, 'index'])->name('pusher-settings.index')->middleware('permission:manage_settings');
// line 179
Route::put('/pusher-settings', [PusherSettingsController::class, 'update'])->name('pusher-settings.update')->middleware('permission:manage_settings');
// line 180
Route::post('/pusher-settings/test', [PusherSettingsController::class, 'test'])->name('pusher-settings.test')->middleware('permission:manage_settings');
// line 183
Route::get('/integrations', [IntegrationConfigController::class, 'index'])->name('integrations.index')->middleware('permission:manage_integrations');
// line 184
Route::get('/integrations/audit-log', [IntegrationConfigController::class, 'auditLogIndex'])->name('integrations.audit-log')->middleware('permission:manage_integrations');
// line 185
Route::get('/integrations/{provider}', [IntegrationConfigController::class, 'edit'])->name('integrations.edit')->middleware('permission:manage_integrations');
// line 186
Route::put('/integrations/{provider}', [IntegrationConfigController::class, 'update'])->name('integrations.update')->middleware('permission:manage_integrations');
// line 187
Route::post('/integrations/{provider}/test', [IntegrationConfigController::class, 'test'])->name('integrations.test')->middleware('permission:manage_integrations');
// line 188
Route::post('/integrations/{provider}/toggle', [IntegrationConfigController::class, 'toggle'])->name('integrations.toggle')->middleware('permission:manage_integrations');
// line 189
Route::post('/integrations/{provider}/rotate', [IntegrationConfigController::class, 'rotate'])->name('integrations.rotate')->middleware('permission:manage_integrations');
// line 190
Route::post('/integrations/{provider}/set-default', [IntegrationConfigController::class, 'setDefault'])->name('integrations.set-default')->middleware('permission:manage_integrations');
// line 193
Route::get('/ai', [AiDashboardController::class, 'index'])->name('ai.index')->middleware('permission:view_settings');
// line 196
Route::get('/admins', [AdminUserController::class, 'index'])->name('admins.index')->middleware('permission:view_admins');
// line 197
Route::post('/admins', [AdminUserController::class, 'store'])->name('admins.store')->middleware('permission:create_admins');
// line 198
Route::put('/admins/{adminUser}', [AdminUserController::class, 'update'])->name('admins.update')->middleware('permission:update_admins');
// line 199
Route::delete('/admins/{adminUser}', [AdminUserController::class, 'destroy'])->name('admins.destroy')->middleware('permission:delete_admins');
// line 200
Route::post('/admins/{adminUser}/toggle-status', [AdminUserController::class, 'toggleStatus'])->name('admins.toggle-status')->middleware('permission:update_admins');
```

#### routes/api.php

```php
// line 37
Route::post('/login', [MobileAuthController::class, 'login']);
// line 41
Route::post('/logout', [MobileAuthController::class, 'logout']);
// line 42
Route::get('/me', [MobileAuthController::class, 'me']);
// line 43
Route::post('/profile', [MobileAuthController::class, 'updateProfile'])->middleware('demo');
// line 51
Route::get('/conversations', [MobileConversationController::class, 'index']);
// line 52
Route::get('/conversations/{uuid}', [MobileConversationController::class, 'show']);
// line 53
Route::get('/conversations/{uuid}/messages', [MobileConversationController::class, 'messages']);
// line 54
Route::post('/conversations', [MobileConversationController::class, 'start']);
// line 55
Route::post('/conversations/{uuid}/reply', [MobileConversationController::class, 'reply']);
// line 56
Route::patch('/conversations/{uuid}/assign', [MobileConversationController::class, 'assign']);
// line 57
Route::patch('/conversations/{uuid}/status', [MobileConversationController::class, 'updateStatus']);
// line 58
Route::post('/conversations/{uuid}/typing', [MobileConversationController::class, 'typing']);
// line 59
Route::post('/conversations/{uuid}/handover', [MobileConversationController::class, 'handover']);
// line 62
Route::get('/conversations/{uuid}/notes', [MobileConversationController::class, 'notes']);
// line 63
Route::post('/conversations/{uuid}/notes', [MobileConversationController::class, 'storeNote']);
// line 66
Route::post('/conversations/{uuid}/labels', [MobileConversationController::class, 'attachLabel']);
// line 67
Route::delete('/conversations/{uuid}/labels/{labelId}', [MobileConversationController::class, 'detachLabel']);
// line 70
Route::get('/inbox/setup', [MobileInboxController::class, 'setup']);
// line 71
Route::get('/inbox/templates', [MobileInboxController::class, 'templates']);
// line 72
Route::get('/inbox/labels', [MobileInboxController::class, 'labels']);
// line 73
Route::get('/inbox/canned-replies', [MobileInboxController::class, 'cannedReplies']);
// line 76
Route::get('/contacts/search', [MobileInboxController::class, 'contactSearch']);
// line 77
Route::get('/contacts/{id}', [MobileInboxController::class, 'contact']);
// line 83
Route::get('/me', [MeController::class, 'show']);
// line 84
Route::patch('/me', [MeController::class, 'update']);
// line 85
Route::get('/workspaces', [WorkspaceApiController::class, 'index']);
// line 86
Route::get('/subscription', [SubscriptionApiController::class, 'show']);
// line 87
Route::get('/usage', [SubscriptionApiController::class, 'usage']);
// line 88
Route::get('/audit-log', [AuditLogApiController::class, 'index']);
// line 89
Route::get('/notifications', [NotificationApiController::class, 'index']);
// line 90
Route::post('/notifications/{notification}/read', [NotificationApiController::class, 'markRead']);
// line 91
Route::get('/tokens', [TokenController::class, 'index']);
// line 92
Route::post('/tokens', [TokenController::class, 'store']);
// line 93
Route::delete('/tokens/{tokenId}', [TokenController::class, 'destroy']);
// line 94
Route::get('/token-scopes', [TokenController::class, 'scopes']);
// line 97
Route::get('/contacts', [ContactApiController::class, 'index'])
// line 99
Route::post('/contacts', [ContactApiController::class, 'store'])
// line 101
Route::get('/contacts/{id}', [ContactApiController::class, 'show'])
// line 103
Route::patch('/contacts/{id}', [ContactApiController::class, 'update'])
// line 105
Route::delete('/contacts/{id}', [ContactApiController::class, 'destroy'])
// line 109
Route::get('/segments', [SegmentApiController::class, 'index'])
// line 111
Route::post('/segments', [SegmentApiController::class, 'store'])
// line 113
Route::get('/segments/{id}/contacts', [SegmentApiController::class, 'contacts'])
// line 117
Route::get('/campaigns', [CampaignApiController::class, 'index'])
// line 119
Route::post('/campaigns', [CampaignApiController::class, 'store'])
// line 121
Route::get('/campaigns/{id}', [CampaignApiController::class, 'show'])
// line 123
Route::patch('/campaigns/{id}', [CampaignApiController::class, 'update'])
// line 125
Route::post('/campaigns/{id}/launch', [CampaignApiController::class, 'launch'])
// line 127
Route::post('/campaigns/{id}/pause', [CampaignApiController::class, 'pause'])
// line 129
Route::get('/campaigns/{id}/recipients', [CampaignApiController::class, 'recipients'])
// line 133
Route::post('/messages/send', [MessageApiController::class, 'send'])
// line 137
Route::get('/conversations', [ConversationApiController::class, 'index'])
// line 139
Route::get('/conversations/{id}/messages', [ConversationApiController::class, 'messages'])
// line 143
Route::get('/webhooks', [OutboundWebhookApiController::class, 'index'])
// line 145
Route::post('/webhooks', [OutboundWebhookApiController::class, 'store'])
// line 147
Route::delete('/webhooks/{id}', [OutboundWebhookApiController::class, 'destroy'])
// line 151
Route::get('/ai/chatbots', [AiChatbotApiController::class, 'index'])
// line 153
Route::post('/ai/chatbots/{id}/chat', [AiChatbotApiController::class, 'chat'])
// line 157
Route::get('/ai/knowledge-bases', [AiKnowledgeBaseApiController::class, 'index'])
// line 159
Route::post('/ai/knowledge-bases', [AiKnowledgeBaseApiController::class, 'store'])
// line 161
Route::get('/ai/knowledge-bases/{id}', [AiKnowledgeBaseApiController::class, 'show'])
// line 163
Route::post('/ai/knowledge-bases/{id}/documents', [AiKnowledgeBaseApiController::class, 'addDocument'])
// line 165
Route::delete('/ai/knowledge-bases/{kbId}/documents/{docId}', [AiKnowledgeBaseApiController::class, 'destroyDocument'])
// line 169
Route::get('/automations', [AutomationApiController::class, 'index'])
// line 171
Route::post('/automations/{id}/trigger', [AutomationApiController::class, 'trigger'])
// line 175
Route::get('/social/accounts', [SocialPostApiController::class, 'accounts'])
// line 177
Route::post('/social/posts', [SocialPostApiController::class, 'store'])
// line 181
Route::get('/analytics/messages', [AnalyticsApiController::class, 'messages'])
// line 183
Route::get('/analytics/ai-usage', [AnalyticsApiController::class, 'aiUsage'])
// line 185
Route::get('/analytics/campaign/{campaignId}/funnel', [AnalyticsApiController::class, 'campaignFunnel'])
// line 187
Route::get('/analytics/conversations', [AnalyticsApiController::class, 'conversations'])
```

#### routes/auth.php

```php
// line 26
Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
// line 27
Route::post('register', [RegisteredUserController::class, 'store']);
// line 29
Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
// line 30
Route::post('login', [AuthenticatedSessionController::class, 'store']);
// line 32
Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
// line 33
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
// line 35
Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
// line 36
Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.store');
// line 39
Route::get('magic-link', [MagicLinkController::class, 'show'])->name('auth.magic-link');
// line 40
Route::post('magic-link', [MagicLinkController::class, 'send'])->middleware('throttle:5,1')->name('auth.magic-link.send');
// line 41
Route::get('magic-link/{token}', [MagicLinkController::class, 'verify'])->name('auth.magic-link.verify');
// line 44
Route::get('two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('auth.two-factor.challenge');
// line 45
Route::post('two-factor-challenge', [TwoFactorController::class, 'verify'])->name('auth.two-factor.verify');
// line 54
Route::get('verify-email', EmailVerificationPromptController::class)->name('verification.notice');
// line 55
Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
// line 58
Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
// line 62
Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
// line 63
Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
// line 65
Route::put('password', [PasswordController::class, 'update'])->name('password.update');
// line 66
Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
// line 74
Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('auth.social.redirect');
// line 75
Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('auth.social.callback');
// line 85
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('auth.invitations.show');
// line 86
Route::post('invitations/{token}', [InvitationController::class, 'accept'])->name('auth.invitations.accept');
```

#### routes/client.php

```php
// line 30
Route::get('/dashboard', ClientDashboardController::class)->name('dashboard');
// line 33
Route::get('/subscription', [ClientSubscriptionController::class, 'show'])->name('subscription.show');
// line 34
Route::post('/subscription/change-plan', [ClientSubscriptionController::class, 'changePlan'])->name('subscription.change-plan');
// line 35
Route::get('/subscription/invoice/{transaction}', [ClientSubscriptionController::class, 'invoiceDownload'])->name('subscription.invoice');
// line 36
Route::delete('/subscription', [ClientSubscriptionController::class, 'destroy'])->name('subscription.destroy');
// line 39
Route::post('/coupon/check', [ClientSubscriptionController::class, 'couponCheck'])->name('coupon.check');
// line 42
Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
// line 43
Route::get('/pricing', [PricingController::class, 'index'])->name('pricing');
// line 44
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
// line 47
Route::get('/team', [TeamController::class, 'index'])->name('team.index');
// line 48
Route::post('/team', [TeamController::class, 'store'])->name('team.store');
// line 49
Route::put('/team/{member}', [TeamController::class, 'update'])->name('team.update');
// line 50
Route::delete('/team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
// line 53
Route::get('/audit-log', [ClientAuditLogController::class, 'index'])->name('audit-log.index');
// line 56
Route::get('/settings', [ClientSettingsController::class, 'index'])->name('settings.index');
// line 57
Route::put('/settings', [ClientSettingsController::class, 'update'])->name('settings.update');
// line 58
Route::get('/settings/notifications', [ClientSettingsController::class, 'notifications'])->name('settings.notifications');
// line 59
Route::get('/settings/data-export', [DataExportController::class, 'index'])->name('settings.data-export');
// line 60
Route::post('/settings/data-export', [DataExportController::class, 'store'])->name('settings.data-export.store');
// line 63
Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
// line 64
Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');
// line 65
Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
// line 68
Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
// line 69
Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
// line 70
Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
// line 73
Route::get('/profile/two-factor', [TwoFactorController::class, 'show'])->name('profile.2fa');
// line 74
Route::post('/profile/two-factor/enable', [TwoFactorController::class, 'enable'])->name('profile.2fa.enable');
// line 75
Route::post('/profile/two-factor/disable', [TwoFactorController::class, 'disable'])->name('profile.2fa.disable');
// line 76
Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateCodes'])->name('profile.2fa.recovery-codes');
// line 79
Route::get('/profile/sessions', [SessionController::class, 'index'])->name('profile.sessions');
// line 80
Route::delete('/profile/sessions', [SessionController::class, 'destroy'])->name('profile.sessions.destroy');
// line 83
Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
// line 84
Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
// line 87
Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
// line 88
Route::get('/api-docs', fn () => Inertia::render('client/Api/Docs'))->name('api-docs');
// line 91
Route::get('/media', [MediaController::class, 'index'])->name('media.index');
// line 92
Route::post('/media', [MediaController::class, 'store'])->name('media.store');
// line 93
Route::delete('/media/{medium}', [MediaController::class, 'destroy'])->name('media.destroy');
// line 96
Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
// line 97
Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
// line 100
Route::get('/search', [SearchController::class, 'search'])->name('search');
// line 103
Route::get('/support', [SupportTicketController::class, 'index'])->name('support.index');
// line 104
Route::get('/support/create', [SupportTicketController::class, 'create'])->name('support.create');
// line 105
Route::post('/support', [SupportTicketController::class, 'store'])->name('support.store');
// line 106
Route::get('/support/{supportTicket}', [SupportTicketController::class, 'show'])->name('support.show');
// line 107
Route::post('/support/{supportTicket}/reply', [SupportTicketController::class, 'reply'])->name('support.reply');
// line 110
Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
// line 111
Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
// line 112
Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
// line 113
Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
// line 114
Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
// line 115
Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
// line 116
Route::post('/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notification-preferences.update');
// line 119
Route::get('/webhooks', [WebhookEndpointController::class, 'index'])->name('webhooks.index');
// line 120
Route::post('/webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
// line 121
Route::put('/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
// line 122
Route::delete('/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
// line 123
Route::post('/webhooks/{webhookEndpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])->name('webhooks.rotate-secret');
// line 124
Route::post('/webhooks/{webhookEndpoint}/test', [WebhookEndpointController::class, 'testDelivery'])->name('webhooks.test');
// line 125
Route::get('/webhooks/{webhookEndpoint}/deliveries', [WebhookEndpointController::class, 'deliveries'])->name('webhooks.deliveries');
// line 128
Route::post('/push/subscribe', [WebPushController::class, 'subscribe'])->name('push.subscribe');
// line 129
Route::post('/push/unsubscribe', [WebPushController::class, 'unsubscribe'])->name('push.unsubscribe');
```

#### routes/reports.php

```php
// line 14
Route::get('/campaigns', function () {
// line 17
Route::get('/campaigns/{campaign}', CampaignReportController::class)->name('campaigns.show');
// line 18
Route::get('/ai', AiReportController::class)->name('ai.index');
// line 19
Route::get('/inbox', InboxReportController::class)->name('inbox.index');
// line 20
Route::get('/automations', AutomationReportController::class)->name('automations.index');
// line 21
Route::get('/social', SocialReportController::class)->name('social.index');
// line 26
Route::get('contacts', [ExportController::class, 'contacts'])->name('contacts');
// line 27
Route::get('campaign-recipients/{campaign}', [ExportController::class, 'campaignRecipients'])->name('campaign-recipients');
// line 28
Route::get('conversations', [ExportController::class, 'conversations'])->name('conversations');
// line 29
Route::get('ai-runs', [ExportController::class, 'aiRuns'])->name('ai-runs');
// line 30
Route::get('audit-log', [ExportController::class, 'auditLog'])->name('audit-log');
```

#### routes/web.php

```php
// line 19
Route::get('/', [LandingController::class, 'index'])->name('home');
// line 25
Route::put('/locale', [LocaleController::class, 'update'])->name('locale.update');
// line 26
Route::get('/i18n/{locale}', [I18nController::class, 'show'])->name('i18n.show');
// line 27
Route::put('/currency', [CurrencyController::class, 'update'])->name('currency.update');
// line 28
Route::post('/theme/update', [ThemeController::class, 'update'])->name('theme.update');
// line 31
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
// line 32
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');
// line 35
Route::get('/pricing', [LandingController::class, 'pricing'])->name('pricing');
// line 36
Route::get('/faq', [LandingController::class, 'faq'])->name('faq');
// line 37
Route::get('/use-cases', [LandingController::class, 'useCases'])->name('use-cases');
// line 38
Route::get('/about', [LandingController::class, 'about'])->name('about');
// line 39
Route::get('/integrations', [LandingController::class, 'integrations'])->name('integrations');
// line 42
Route::get('/p/{slug}', [CmsPageController::class, 'show'])->name('cms-page.show');
// line 45
Route::get('/sitemap.xml', function () {
// line 72
Route::get('/robots.txt', function () {
// line 83
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe'])->name('webhooks.stripe');
// line 84
Route::post('/webhooks/paypal', [WebhookController::class, 'paypal'])->name('webhooks.paypal');
// line 85
Route::post('/webhooks/paddle', [WebhookController::class, 'paddle'])->name('webhooks.paddle');
// line 86
Route::post('/webhooks/razorpay', [WebhookController::class, 'razorpay'])->name('webhooks.razorpay');
// line 87
Route::post('/webhooks/cashfree', [WebhookController::class, 'cashfree'])->name('webhooks.cashfree');
// line 88
Route::post('/webhooks/tap', [WebhookController::class, 'tap'])->name('webhooks.tap');
// line 89
Route::post('/webhooks/paystack', [WebhookController::class, 'paystack'])->name('webhooks.paystack');
// line 90
Route::post('/webhooks/xendit', [WebhookController::class, 'xendit'])->name('webhooks.xendit');
// line 91
Route::post('/webhooks/paymob', [WebhookController::class, 'paymob'])->name('webhooks.paymob');
// line 92
Route::post('/webhooks/myfatoorah', [WebhookController::class, 'myfatoorah'])->name('webhooks.myfatoorah');
// line 93
Route::post('/webhooks/mollie', [WebhookController::class, 'mollie'])->name('webhooks.mollie');
// line 94
Route::post('/webhooks/square', [WebhookController::class, 'square'])->name('webhooks.square');
// line 95
Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago'])->name('webhooks.mercadopago');
// line 108
Route::get('/healthz/db', function () use ($guardHealthz) {
// line 121
Route::get('/healthz/redis', function () use ($guardHealthz) {
// line 134
Route::get('/healthz/queue', function () use ($guardHealthz) {
```

#### routes/webhooks.php

```php
// line 15
Route::get('/global', [WhatsappWebhookController::class, 'verifyGlobal'])->name('global.verify');
// line 16
Route::post('/global', [WhatsappWebhookController::class, 'receiveGlobal'])->name('global.receive');
// line 17
Route::get('/{token}', [WhatsappWebhookController::class, 'verify'])->name('verify');
// line 18
Route::post('/{token}', [WhatsappWebhookController::class, 'receive'])->name('receive');
// line 22
Route::get('/{token}', [MetaWebhookController::class, 'verify'])->name('verify');
// line 23
Route::post('/{token}', [MetaWebhookController::class, 'receive'])->name('receive');
// line 26
Route::post('webhooks/sms/{provider}', [SmsStatusWebhookController::class, 'handle'])
// line 30
Route::post('webhooks/automation/{trigger_token}', [AutomationWebhookController::class, 'receive'])
// line 33
Route::post('webhooks/ecommerce/shopify/{store}', [EcommerceWebhookController::class, 'shopify'])
// line 35
Route::post('webhooks/ecommerce/woocommerce/{store}', [EcommerceWebhookController::class, 'woocommerce'])
// line 37
Route::post('webhooks/ecommerce/bigcommerce/{store}', [EcommerceWebhookController::class, 'bigcommerce'])
// line 40
Route::post('webhooks/ecommerce/woo-auth', [EcommerceOAuthController::class, 'woocommerceCallback'])
// line 43
Route::get('track/email/{token}/open.gif', [EmailTrackingController::class, 'open'])
// line 46
Route::get('track/email/{token}/click', [EmailTrackingController::class, 'click'])
// line 49
Route::match(['get', 'post'], 'track/email/{token}/unsubscribe', [EmailTrackingController::class, 'unsubscribe'])
// line 53
Route::post('webhooks/qr/{sessionId}/sync-status', [QrWebhookController::class, 'syncStatus'])
// line 55
Route::post('webhooks/qr/{sessionId}', [QrWebhookController::class, 'receive'])
```

### C. Controller public action registry

- `app/Http/Controllers/Admin/AdminSearchController.php`: search (L15)
- `app/Http/Controllers/Admin/AdminUserController.php`: index (L17), store (L51), update (L76), destroy (L97), toggleStatus (L115)
- `app/Http/Controllers/Admin/AiDashboardController.php`: index (L18)
- `app/Http/Controllers/Admin/AuditLogController.php`: index (L13)
- `app/Http/Controllers/Admin/Auth/AdminLoginController.php`: create (L15), store (L23), destroy (L30)
- `app/Http/Controllers/Admin/ClientBrandingController.php`: __construct (L14), update (L16)
- `app/Http/Controllers/Admin/ClientController.php`: __construct (L22), index (L24), store (L73), update (L100), destroy (L122), users (L134), storeUser (L150), updateUser (L190), destroyUser (L232), assignPlan (L258), impersonate (L297), export (L334)
- `app/Http/Controllers/Admin/CmsPageController.php`: index (L14), store (L21), update (L38), destroy (L55)
- `app/Http/Controllers/Admin/CouponController.php`: index (L15), store (L24), update (L45), destroy (L66)
- `app/Http/Controllers/Admin/CronSetupController.php`: index (L20)
- `app/Http/Controllers/Admin/CurrencyController.php`: index (L13), store (L21), update (L46)
- `app/Http/Controllers/Admin/DashboardController.php`: __invoke (L24)
- `app/Http/Controllers/Admin/EmailSystemController.php`: __construct (L18), index (L22), storeSmtp (L54), updateSmtp (L85), updateTemplate (L119), destroySmtp (L133), activateSmtp (L139), testTemplate (L149), testEmail (L175)
- `app/Http/Controllers/Admin/ImpersonationController.php`: __construct (L13), stop (L17)
- `app/Http/Controllers/Admin/LandingPageController.php`: defaults (L19), index (L304), update (L316), getPublicSettings (L336)
- `app/Http/Controllers/Admin/LicenseController.php`: __construct (L17), index (L19), checkUpdate (L34), applyUpdate (L40), activate (L45), deactivate (L64)
- `app/Http/Controllers/Admin/LocaleController.php`: __construct (L14), index (L18), store (L83), update (L106), setDefault (L123), destroy (L135)
- `app/Http/Controllers/Admin/PaymentController.php`: index (L13)
- `app/Http/Controllers/Admin/PaymentGatewayConfigController.php`: index (L36), show (L61), update (L96)
- `app/Http/Controllers/Admin/PermissionController.php`: store (L12), update (L26), destroy (L40)
- `app/Http/Controllers/Admin/PlanController.php`: defaultLimits (L16), index (L63), store (L74), edit (L85), update (L94), destroy (L102), duplicate (L109), reorder (L122)
- `app/Http/Controllers/Admin/PusherSettingsController.php`: index (L17), update (L36), test (L63)
- `app/Http/Controllers/Admin/QueueController.php`: index (L12), retryFailed (L64), deleteFailed (L74), retryAll (L84), flushFailed (L94)
- `app/Http/Controllers/Admin/RoleController.php`: store (L13), update (L37), destroy (L66)
- `app/Http/Controllers/Admin/RolesPermissionsController.php`: index (L14)
- `app/Http/Controllers/Admin/SubscriptionController.php`: __construct (L21), index (L23), userSearch (L63), store (L87), export (L135)
- `app/Http/Controllers/Admin/SupportTicketController.php`: index (L19), create (L43), store (L52), show (L99), reply (L108), updateStatus (L144)
- `app/Http/Controllers/Admin/SystemSettingsController.php`: index (L17), updateGeneral (L86), uploadLogo (L102), deleteLogo (L122), uploadFavicon (L130), deleteFavicon (L150), updateFirebase (L158), updateSystemAi (L175), updateOmniroute (L192), omnirouteModels (L220), omnirouteTestModel (L250), omnirouteDiagnose (L311), update (L316)
- `app/Http/Controllers/Admin/TaxRateController.php`: index (L14), store (L23), update (L40), destroy (L57)
- `app/Http/Controllers/Admin/TransactionController.php`: __construct (L15), index (L19), refund (L30)
- `app/Http/Controllers/Admin/TranslationController.php`: __construct (L14), update (L18), bulkUpdate (L34), autoTranslateMissing (L53)
- `app/Http/Controllers/Api/V1/AiChatbotApiController.php`: __construct (L12), index (L17), chat (L37)
- `app/Http/Controllers/Api/V1/AiKnowledgeBaseApiController.php`: __construct (L14), index (L19), store (L33), show (L50), addDocument (L68), destroyDocument (L103)
- `app/Http/Controllers/Api/V1/AnalyticsApiController.php`: __construct (L13), messages (L19), aiUsage (L32), campaignFunnel (L49), conversations (L67)
- `app/Http/Controllers/Api/V1/AuditLogApiController.php`: index (L12)
- `app/Http/Controllers/Api/V1/AutomationApiController.php`: index (L17), trigger (L38)
- `app/Http/Controllers/Api/V1/CampaignApiController.php`: index (L20), store (L38), show (L68), launch (L86), pause (L123), recipients (L143), update (L161)
- `app/Http/Controllers/Api/V1/ContactApiController.php`: index (L17), store (L60), show (L88), update (L104), destroy (L136)
- `app/Http/Controllers/Api/V1/ConversationApiController.php`: index (L17), messages (L42)
- `app/Http/Controllers/Api/V1/MeController.php`: show (L11), update (L26)
- `app/Http/Controllers/Api/V1/MessageApiController.php`: send (L18)
- `app/Http/Controllers/Api/V1/MobileAuthController.php`: __construct (L16), login (L22), logout (L55), me (L66), updateProfile (L79)
- `app/Http/Controllers/Api/V1/MobileConversationController.php`: __construct (L24), index (L33), show (L75), messages (L108), reply (L131), assign (L248), updateStatus (L271), typing (L291), handover (L306), notes (L325), storeNote (L349), attachLabel (L374), detachLabel (L392), start (L407)
- `app/Http/Controllers/Api/V1/MobileInboxController.php`: setup (L21), templates (L64), labels (L87), cannedReplies (L98), contactSearch (L118), contact (L148)
- `app/Http/Controllers/Api/V1/NotificationApiController.php`: index (L11), markRead (L18)
- `app/Http/Controllers/Api/V1/OutboundWebhookApiController.php`: index (L23), store (L36), destroy (L61)
- `app/Http/Controllers/Api/V1/SegmentApiController.php`: index (L17), store (L29), contacts (L53)
- `app/Http/Controllers/Api/V1/SocialPostApiController.php`: accounts (L16), store (L36)
- `app/Http/Controllers/Api/V1/SubscriptionApiController.php`: show (L13), usage (L41)
- `app/Http/Controllers/Api/V1/TokenController.php`: store (L17), index (L46), destroy (L63), scopes (L77)
- `app/Http/Controllers/Api/V1/WorkspaceApiController.php`: index (L11)
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`: create (L18), store (L60), destroy (L144)
- `app/Http/Controllers/Auth/ConfirmablePasswordController.php`: show (L18), store (L26)
- `app/Http/Controllers/Auth/EmailVerificationNotificationController.php`: store (L14)
- `app/Http/Controllers/Auth/EmailVerificationPromptController.php`: __invoke (L16)
- `app/Http/Controllers/Auth/FirebaseLoginController.php`: login (L20)
- `app/Http/Controllers/Auth/InvitationController.php`: show (L21), accept (L39)
- `app/Http/Controllers/Auth/MagicLinkController.php`: show (L20), send (L28), verify (L64)
- `app/Http/Controllers/Auth/NewPasswordController.php`: create (L22), store (L35)
- `app/Http/Controllers/Auth/PasswordController.php`: update (L16)
- `app/Http/Controllers/Auth/PasswordResetLinkController.php`: create (L18), store (L30)
- `app/Http/Controllers/Auth/RegisteredUserController.php`: create (L26), store (L39)
- `app/Http/Controllers/Auth/SessionController.php`: index (L17), destroy (L41)
- `app/Http/Controllers/Auth/SocialLoginController.php`: redirect (L22), callback (L32)
- `app/Http/Controllers/Auth/TwoFactorController.php`: __construct (L16), show (L21), enable (L52), disable (L77), regenerateCodes (L93), challenge (L107), verify (L119)
- `app/Http/Controllers/Auth/VerifyEmailController.php`: __invoke (L15)
- `app/Http/Controllers/CheckoutController.php`: __construct (L15), store (L22)
- `app/Http/Controllers/Client/ApiTokenController.php`: index (L12)
- `app/Http/Controllers/Client/AuditLogController.php`: index (L14)
- `app/Http/Controllers/Client/BillingController.php`: __construct (L16), index (L57)
- `app/Http/Controllers/Client/DashboardController.php`: __invoke (L25)
- `app/Http/Controllers/Client/InvitationController.php`: __construct (L14), store (L19), destroy (L68)
- `app/Http/Controllers/Client/MediaController.php`: __construct (L16), index (L18), store (L45), destroy (L75)
- `app/Http/Controllers/Client/NotificationController.php`: index (L18), recent (L41), unreadCount (L53), markRead (L63), markAllRead (L74), destroy (L84), updatePreferences (L94)
- `app/Http/Controllers/Client/OnboardingController.php`: __construct (L14), show (L16), complete (L25)
- `app/Http/Controllers/Client/Reports/AiReportController.php`: __invoke (L14)
- `app/Http/Controllers/Client/Reports/AutomationReportController.php`: __invoke (L14)
- `app/Http/Controllers/Client/Reports/CampaignReportController.php`: __invoke (L15)
- `app/Http/Controllers/Client/Reports/ExportController.php`: contacts (L18), campaignRecipients (L44), conversations (L69), aiRuns (L95), auditLog (L121)
- `app/Http/Controllers/Client/Reports/InboxReportController.php`: __invoke (L14)
- `app/Http/Controllers/Client/Reports/SocialReportController.php`: __invoke (L15)
- `app/Http/Controllers/Client/SearchController.php`: search (L11)
- `app/Http/Controllers/Client/Settings/DataExportController.php`: index (L14), store (L21)
- `app/Http/Controllers/Client/SettingsController.php`: index (L17), notifications (L56), update (L69)
- `app/Http/Controllers/Client/SubscriptionController.php`: __construct (L22), show (L27), changePlan (L103), couponCheck (L142), invoiceDownload (L170), destroy (L194)
- `app/Http/Controllers/Client/SupportTicketController.php`: index (L18), create (L40), store (L45), show (L104), reply (L116)
- `app/Http/Controllers/Client/TeamController.php`: index (L16), store (L58), update (L91), destroy (L123)
- `app/Http/Controllers/Client/WebhookEndpointController.php`: __construct (L16), index (L18), store (L45), update (L64), rotateSecret (L82), destroy (L92), testDelivery (L101), deliveries (L112)
- `app/Http/Controllers/Client/WebPushController.php`: subscribe (L12), unsubscribe (L36)
- `app/Http/Controllers/CmsPageController.php`: show (L11)
- `app/Http/Controllers/ContactController.php`: show (L15), store (L23)
- `app/Http/Controllers/CurrencyController.php`: update (L15)
- `app/Http/Controllers/I18nController.php`: __construct (L12), show (L19)
- `app/Http/Controllers/Install/InstallController.php`: __construct (L18), show (L24), testDatabase (L50), activateLicense (L68), run (L90)
- `app/Http/Controllers/LandingController.php`: index (L47), pricing (L61), faq (L74), useCases (L86), about (L98), integrations (L110)
- `app/Http/Controllers/LicenseController.php`: __construct (L20), show (L22), activate (L40)
- `app/Http/Controllers/LocaleController.php`: update (L15)
- `app/Http/Controllers/PricingController.php`: __construct (L15), index (L20)
- `app/Http/Controllers/ProfileController.php`: edit (L19), update (L30), destroy (L46)
- `app/Http/Controllers/ThemeController.php`: update (L14)
- `app/Http/Controllers/WebhookController.php`: __construct (L31), stripe (L38), paypal (L56), paddle (L74), razorpay (L92), cashfree (L110), tap (L128), paystack (L146), xendit (L164), paymob (L182), myfatoorah (L200), mollie (L218), square (L236), mercadopago (L254)
- `app/Http/Controllers/Webhooks/AutomationWebhookController.php`: receive (L13)
- `app/Http/Controllers/WorkspaceController.php`: index (L17), switch (L33), store (L52)
- `app/Modules/AI/Http/Controllers/AiChatbotController.php`: index (L24), store (L36), update (L48), destroy (L81), playground (L89)
- `app/Modules/AI/Http/Controllers/AiKnowledgeBaseController.php`: __construct (L17), index (L19), show (L29), store (L37), addDocument (L46), reindex (L93), destroyDocument (L108)
- `app/Modules/AI/Http/Controllers/AiMemoryController.php`: index (L25), store (L52), destroy (L71), toggle (L80)
- `app/Modules/AI/Http/Controllers/AiProviderController.php`: index (L17), update (L42)
- `app/Modules/Automation/Http/Controllers/AutomationController.php`: index (L30), store (L45), storeFromTemplate (L60), edit (L87), update (L136), destroy (L152), runs (L160), generateToken (L170), test (L183), generate (L213)
- `app/Modules/Broadcasting/Http/Controllers/CampaignController.php`: index (L28), create (L44), store (L49), storeDraft (L73), edit (L124), update (L138), show (L149), launch (L181), pause (L213), destroy (L222), audiencePreview (L235), testSend (L290)
- `app/Modules/Broadcasting/Http/Controllers/EmailAiController.php`: improveSubject (L12), generate (L88)
- `app/Modules/Broadcasting/Http/Controllers/EmailServerController.php`: __construct (L16), index (L20), store (L41), update (L65), destroy (L101), testEmail (L109)
- `app/Modules/Broadcasting/Http/Controllers/EmailTrackingController.php`: open (L36), click (L69), unsubscribe (L119)
- `app/Modules/Broadcasting/Http/Controllers/SmsProviderController.php`: index (L103), update (L123), destroy (L164)
- `app/Modules/Broadcasting/Http/Controllers/SmsStatusWebhookController.php`: __construct (L22), handle (L24)
- `app/Modules/Ecommerce/Http/Controllers/EcommerceOAuthController.php`: __construct (L19), connect (L38), shopifyCallback (L87), bigcommerceCallback (L115), woocommerceReturn (L142), woocommerceCallback (L151)
- `app/Modules/Ecommerce/Http/Controllers/EcommerceWebhookController.php`: shopify (L18), woocommerce (L38), bigcommerce (L58)
- `app/Modules/Ecommerce/Http/Controllers/OrderContextController.php`: index (L16)
- `app/Modules/Ecommerce/Http/Controllers/OrderController.php`: index (L27), show (L77), refresh (L108), fulfill (L154)
- `app/Modules/Ecommerce/Http/Controllers/ProductController.php`: index (L18), search (L68)
- `app/Modules/Ecommerce/Http/Controllers/StoreController.php`: index (L41), store (L92), test (L117), sync (L125), destroy (L134)
- `app/Modules/Inbox/Http/Controllers/AiDraftController.php`: store (L31)
- `app/Modules/Inbox/Http/Controllers/CannedReplyController.php`: index (L21), store (L30), update (L44), destroy (L59), list (L68)
- `app/Modules/Inbox/Http/Controllers/InboxController.php`: __construct (L34), index (L45), show (L79), reply (L156), shareProduct (L408), assign (L552), typing (L570), updateStatus (L580), handover (L594), serveMedia (L620), uploadMedia (L1006), templates (L1040), contactSearch (L1053), channelAccounts (L1076), startConversation (L1088)
- `app/Modules/Inbox/Http/Controllers/InboxSetupController.php`: index (L21), embeddedSignupMessenger (L110), instagramLoginConnect (L472), instagramManualToken (L514), assignChatbot (L634), destroy (L666)
- `app/Modules/Inbox/Http/Controllers/InternalNoteController.php`: index (L16), store (L25)
- `app/Modules/Inbox/Http/Controllers/LabelController.php`: index (L22), store (L31), update (L45), destroy (L60), attach (L68), detach (L82)
- `app/Modules/Inbox/Http/Controllers/MetaWebhookController.php`: verify (L19), receive (L33)
- `app/Modules/Instagram/Http/Controllers/AutomationController.php`: index (L22), create (L40), recentPosts (L93), edit (L129), store (L136), update (L146), toggle (L156), destroy (L165)
- `app/Modules/Instagram/Http/Controllers/ConnectController.php`: index (L18), connectInstagramLogin (L50), disconnect (L192), status (L203)
- `app/Modules/Instagram/Http/Controllers/FlowController.php`: index (L24), create (L49), edit (L58), store (L74), update (L86), toggle (L95), destroy (L110)
- `app/Modules/Instagram/Http/Controllers/InstagramWebhookController.php`: verify (L31), receive (L45)
- `app/Modules/Instagram/Http/Controllers/LogsController.php`: index (L13)
- `app/Modules/Instagram/Http/Controllers/TemplateController.php`: gallery (L22), create (L30), edit (L38), index (L47), store (L56), update (L77), destroy (L86)
- `app/Modules/Integrations/Http/Controllers/IntegrationConfigController.php`: index (L18), edit (L45), update (L78), test (L200), toggle (L219), setDefault (L243), rotate (L265), auditLogIndex (L281)
- `app/Modules/Leads/Http/Controllers/LeadController.php`: index (L22), scrape (L38), pushToContacts (L58), destroy (L86)
- `app/Modules/Shared/Http/Controllers/ContactController.php`: __construct (L25), index (L30), bulkImport (L58), show (L79), store (L94), update (L124), destroy (L157), uploadAvatar (L172), deleteAvatar (L192), import (L205), bulkStore (L237), bulkDestroy (L278), export (L303)
- `app/Modules/Shared/Http/Controllers/SegmentController.php`: __construct (L16), index (L18), store (L26), update (L44), destroy (L60), manageContacts (L69), attachContacts (L100), detachContact (L116)
- `app/Modules/Social/Http/Controllers/SocialAccountController.php`: __construct (L25), index (L42), connect (L50), callback (L69), disconnect (L188)
- `app/Modules/Social/Http/Controllers/SocialPostController.php`: index (L24), composer (L61), calendar (L69), store (L131), edit (L184), update (L198), publishNow (L228), cancel (L240), destroy (L250), aiPlan (L259), bulkStore (L374), aiGenerate (L430)
- `app/Modules/Whatsapp/Http/Controllers/WhatsappAutoReplyController.php`: index (L15), store (L23), update (L44), destroy (L64)
- `app/Modules/Whatsapp/Http/Controllers/WhatsappEmbeddedSignupController.php`: store (L21), reregisterWebhook (L256)
- `app/Modules/Whatsapp/Http/Controllers/WhatsappSetupController.php`: seedTemplates (L20), syncPhoneNumbers (L29), destroy (L67), refreshPhoneStatus (L89), changeDisplayName (L126)
- `app/Modules/Whatsapp/Http/Controllers/WhatsappTemplateController.php`: index (L24), create (L59), store (L80), edit (L144), update (L166), destroy (L221), uploadMedia (L265), reseedDefaults (L315), sync (L332)
- `app/Modules/Whatsapp/Http/Controllers/WhatsappWebhookController.php`: verifyGlobal (L33), receiveGlobal (L50), verify (L103), receive (L119)
- `app/Modules/Whatsapp/Http/Controllers/WhatsappWidgetController.php`: index (L14), create (L22), edit (L27), store (L34), update (L55), destroy (L77), embed (L86)
- `app/Modules/WhatsappQR/Http/Controllers/QrWebhookController.php`: receive (L41), syncStatus (L95)
- `app/Modules/WhatsappQR/Http/Controllers/WhatsappQRController.php`: __construct (L19), index (L26), show (L44), store (L59), qr (L105), status (L128), destroy (L149)

### D. Services, jobs, commands aur middleware function registry

- `app/Console/Commands/AiDiagnoseCommand.php`: handle (L23)
- `app/Console/Commands/BillingSyncCommand.php`: handle (L15)
- `app/Console/Commands/ChargeRecurringMyFatoorahCommand.php`: handle (L24)
- `app/Console/Commands/ChargeRecurringPaymobCommand.php`: handle (L24)
- `app/Console/Commands/ChargeRecurringTapCommand.php`: handle (L25)
- `app/Console/Commands/DbBackupCommand.php`: handle (L13)
- `app/Console/Commands/ExpireTrialsCommand.php`: handle (L17)
- `app/Console/Commands/I18nScanCommand.php`: handle (L18)
- `app/Console/Commands/I18nSeedDefaultsCommand.php`: handle (L15)
- `app/Console/Commands/InboxDuplicateReportCommand.php`: handle (L30)
- `app/Console/Commands/InstagramDiagnoseCommand.php`: handle (L30), checkInstagramLoginAccount (L433)
- `app/Console/Commands/InstagramFunnelCommand.php`: handle (L23)
- `app/Console/Commands/InstagramRegisterWebhookCommand.php`: handle (L28), subscribeAccounts (L81)
- `app/Console/Commands/MessengerProfileTestCommand.php`: handle (L29)
- `app/Console/Commands/MessengerSubscriptionCheckCommand.php`: handle (L34)
- `app/Console/Commands/NotifyTrialEndingCommand.php`: handle (L15)
- `app/Console/Commands/SaasInstallCommand.php`: handle (L14), createSuperAdmin (L71)
- `app/Console/Commands/SaasMakeModuleCommand.php`: handle (L13), boot (L55)
- `app/Console/Commands/SeedEcommerceTemplatesCommand.php`: handle (L28)
- `app/Console/Commands/SendWeeklyDigestCommand.php`: handle (L23), buildStats (L63)
- `app/Console/Commands/WebpushVapidCommand.php`: handle (L14)
- `app/Console/Commands/WhatsappReseedTemplatesCommand.php`: handle (L17)
- `app/Console/Commands/WhatsappWebhookRegisterCommand.php`: handle (L19)
- `app/Http/Middleware/BroadcastingAuthDebug.php`: handle (L22)
- `app/Http/Middleware/CheckApiAbility.php`: handle (L15)
- `app/Http/Middleware/EnforceLimit.php`: handle (L26)
- `app/Http/Middleware/EnsureAdminRole.php`: handle (L14)
- `app/Http/Middleware/EnsureClientScope.php`: handle (L15)
- `app/Http/Middleware/EnsureInstalled.php`: handle (L16)
- `app/Http/Middleware/EnsureLicensed.php`: __construct (L18), handle (L20)
- `app/Http/Middleware/EnsureNotDemoMode.php`: handle (L37)
- `app/Http/Middleware/EnsureSuperAdmin.php`: handle (L14)
- `app/Http/Middleware/EnsureUserRole.php`: handle (L16)
- `app/Http/Middleware/HandleInertiaRequests.php`: version (L33), share (L43), firebasePublicConfig (L87), oneSignalPublicConfig (L104), pusherPublicConfig (L118), brandingShare (L142), workspaceUsage (L172), appShare (L207), metaAppId (L369), assetUrl (L378)
- `app/Http/Middleware/RedirectIfAdminAuthenticated.php`: handle (L12)
- `app/Http/Middleware/RequestIdMiddleware.php`: handle (L17)
- `app/Http/Middleware/RequirePermission.php`: handle (L16)
- `app/Http/Middleware/SecureHeaders.php`: handle (L12), buildCsp (L44), viteDevSources (L78), thirdPartyScriptSources (L89), thirdPartyStyleSources (L104), connectSources (L113), metaFrameSources (L143), metaSdkEnabled (L152)
- `app/Http/Middleware/SetLocale.php`: handle (L16)
- `app/Jobs/DispatchWebhookJob.php`: __construct (L24), handle (L30), scheduleRetry (L75), backoff (L83)
- `app/Jobs/GenerateWorkspaceExportJob.php`: __construct (L21), handle (L23)
- `app/Modules/AI/Jobs/DecayAiMemoriesJob.php`: __construct (L25), handle (L30), failed (L35)
- `app/Modules/AI/Jobs/ExtractConversationLearningJob.php`: __construct (L26), handle (L31), failed (L36)
- `app/Modules/AI/Jobs/GenerateAiReplyJob.php`: __construct (L34), handle (L39)
- `app/Modules/AI/Jobs/IndexDocumentJob.php`: __construct (L29), handle (L31), embedProviderAvailable (L109), extractText (L125), fetchUrl (L137), readFile (L164), formatFaq (L206), processSitemap (L247), chunkFaq (L305), chunk (L332)
- `app/Modules/AI/Services/AiDiagnostic.php`: run (L38), settings (L64), codeMarkers (L82), probes (L109), probe (L140), workspaceLayer (L211), kbLayer (L260), kbRetrievalProbe (L349), hybridVia (L401), diagnose (L413), defaultDiagnosis (L419)
- `app/Modules/AI/Services/AiMemoryService.php`: __construct (L43), injectBlock (L56), summaryBlock (L96), extractForConversation (L123), extractionPrompt (L185), callLlm (L208), parseExtraction (L231), storeMemory (L267), decayAndPrune (L302), decay (L308), prune (L318), learningEnabled (L340)
- `app/Modules/AI/Services/AiSystemPrompt.php`: build (L30), buildFromParts (L42), coreGuardrails (L61), toneLayer (L116), adminRules (L124), normalize (L131)
- `app/Modules/AI/Services/ChatbotRunner.php`: __construct (L76), run (L86), cleanReply (L257), fallbackFor (L286), keywordChunksForDiagnostic (L298), fuseForDiagnostic (L312), keywordChunks (L329), expandKeywords (L406), orderSummary (L425), productSummary (L472), retrieveHybrid (L540), fuseRrf (L566), groundedRetry (L604), looksHedged (L649), improveForApi (L677), runForApi (L707)
- `app/Modules/AI/Services/EmbeddingStore.php`: storeEmbedding (L23), search (L41), qdrantEnabled (L58), qdrantClient (L63), qdrantUpsert (L77), qdrantSearch (L98), ensureQdrantCollection (L141), mysqlSearch (L158), unpackEmbedding (L177), cosine (L187)
- `app/Modules/AI/Services/EmojiEngine.php`: __construct (L162), normalize (L183), analyze (L212), classifyLabel (L306), promptLayer (L329), expandKeywords (L370), clampReply (L392), metaFor (L418)
- `app/Modules/AI/Services/KbContextTrimmer.php`: __construct (L21), fit (L27)
- `app/Modules/AI/Services/Llm/AnthropicProvider.php`: __construct (L11), chat (L16), embed (L63)
- `app/Modules/AI/Services/Llm/DeadUpstreamGuard.php`: inspect (L47), isCannedGreeting (L74), isSimpleGreeting (L87), lastUserMessage (L102), isUpstreamErrorBody (L114)
- `app/Modules/AI/Services/Llm/GeminiProvider.php`: __construct (L11), chat (L17), embed (L65)
- `app/Modules/AI/Services/Llm/LlmManager.php`: forWorkspace (L31), forWorkspaceEmbed (L86), build (L130), systemOmniroute (L154)
- `app/Modules/AI/Services/Llm/LlmProviderInterface.php`: chat (L8), embed (L11)
- `app/Modules/AI/Services/Llm/LlmResponse.php`: __construct (L7)
- `app/Modules/AI/Services/Llm/OllamaProvider.php`: __construct (L12), chat (L21), embed (L69)
- `app/Modules/AI/Services/Llm/OmniRouteProvider.php`: __construct (L22), fetchModels (L36), chat (L53), embed (L108)
- `app/Modules/AI/Services/Llm/OpenAiProvider.php`: __construct (L11), chat (L18), embed (L59)
- `app/Modules/AI/Services/LlmGateway.php`: chat (L22), embed (L85), retryViaAutocomplete (L112), omnirouteKey (L131), omnirouteBase (L136)
- `app/Modules/Automation/Jobs/ExecuteAutomationRunJob.php`: __construct (L19), handle (L21)
- `app/Modules/Automation/Services/AutomationEngine.php`: __construct (L54), triggerForContact (L60), resumeAwaitingReplies (L90), executeRun (L123), testRun (L240), sampleContact (L326), defaultTestContext (L341), previewNode (L360), snippet (L415), executeNode (L422), executeSendWhatsapp (L479), executeSendSms (L500), executeSendEmail (L512), executeAiReply (L534), executeAddToCampaign (L591), renderTokens (L628), resolveOrCreateConversation (L648), executeWait (L666), executeTagAction (L709), executeUpdateContact (L732), executeWebhook (L786), decodeJsonField (L824), executeCondition (L838), evaluateCondition (L850), conditionResult (L885), executeSendTemplate (L896), executeSendMedia (L944), executeSendSequence (L974), executeQuickReplies (L1007), executeListMessage (L1032), executeAskQuestion (L1061), parkAwaitingInteractiveChoice (L1096), executeRunSubflow (L1115), executeAssignAgent (L1146), executeCtaButton (L1181), executeSendLocation (L1208), executeSendPoll (L1230), executeRunChatbot (L1265), executeBookAppointment (L1304), executeGoogleMeet (L1351), executeWhatsappForm (L1399), executeWhatsappCatalog (L1434), executeSendProduct (L1452), executeGoogleSheets (L1498), executeGoogleDocs (L1536), executeGoogleForms (L1569), pickChannel (L1632), resolveChannelTarget (L1648), dispatchMessage (L1685), dispatchSms (L1734), sendWhatsappPayload (L1789), sendTextViaChannel (L1795), buttonInteractive (L1801), listInteractive (L1812), toList (L1836), toLines (L1849), parseRows (L1864), parseSteps (L1905), parseReplacements (L1961), parseDateTime (L1997)
- `app/Modules/Automation/Services/AutomationTemplateRepository.php`: getTemplates (L10)
- `app/Modules/Automation/Services/WorkflowGenerator.php`: __construct (L34), generate (L41), systemPrompt (L58), decode (L120), normalise (L151), uniqueId (L238), ensureConnectivity (L253), layout (L288)
- `app/Modules/Broadcasting/Jobs/DispatchCampaignChunkJob.php`: __construct (L18), handle (L23)
- `app/Modules/Broadcasting/Jobs/FinalizeCampaignJob.php`: __construct (L28), handle (L33)
- `app/Modules/Broadcasting/Jobs/LaunchCampaignJob.php`: __construct (L25), handle (L27), resolveAudience (L115), resolveSegment (L156), resolveTag (L176), resolveAllContacts (L189), resolveCsv (L211), coerceBool (L294)
- `app/Modules/Broadcasting/Jobs/LaunchScheduledCampaignsJob.php`: handle (L15)
- `app/Modules/Broadcasting/Jobs/SendCampaignMessageJob.php`: __construct (L39), handle (L44), isOptedOut (L143), sendWhatsApp (L156), buildTemplateDefinition (L235), resolveHeaderMediaIds (L277), uploadHeaderMedia (L313), guessMimeFromUrl (L342), sendSms (L359), sendEmail (L386), wrapLinksForClickTracking (L481), buildUnsubscribeFooter (L512), syncToInbox (L528), resolveChannelAccount (L583), summariseTemplateForInbox (L619)
- `app/Modules/Broadcasting/Services/CampaignPersonalizer.php`: renderText (L24), renderTemplateComponents (L78), renderParameter (L94), availableContactTokens (L132)
- `app/Modules/Broadcasting/Services/Sms/AmazonSnsDriver.php`: __construct (L14), send (L31), status (L67)
- `app/Modules/Broadcasting/Services/Sms/BulkSmsBdDriver.php`: __construct (L9), send (L14), status (L36)
- `app/Modules/Broadcasting/Services/Sms/ClickSendDriver.php`: __construct (L9), send (L15), status (L39)
- `app/Modules/Broadcasting/Services/Sms/Fast2SmsDriver.php`: __construct (L11), send (L17), status (L51)
- `app/Modules/Broadcasting/Services/Sms/InfobipDriver.php`: __construct (L11), send (L21), status (L40)
- `app/Modules/Broadcasting/Services/Sms/MessageBirdDriver.php`: __construct (L9), send (L14), status (L31)
- `app/Modules/Broadcasting/Services/Sms/MimSmsDriver.php`: __construct (L10), send (L15), status (L37)
- `app/Modules/Broadcasting/Services/Sms/NexmoDriver.php`: __construct (L9), send (L15), status (L35)
- `app/Modules/Broadcasting/Services/Sms/PlivoDriver.php`: __construct (L9), send (L15), status (L32)
- `app/Modules/Broadcasting/Services/Sms/ReveSmsDriver.php`: __construct (L9), send (L15), status (L33)
- `app/Modules/Broadcasting/Services/Sms/SmsBdDriver.php`: __construct (L9), send (L14), status (L30)
- `app/Modules/Broadcasting/Services/Sms/SmsDotBdDriver.php`: __construct (L10), send (L15), status (L34)
- `app/Modules/Broadcasting/Services/Sms/SmsDriverInterface.php`: send (L7), status (L9)
- `app/Modules/Broadcasting/Services/Sms/SmsDriverManager.php`: forWorkspace (L12), build (L35)
- `app/Modules/Broadcasting/Services/Sms/SmsSendResult.php`: __construct (L7)
- `app/Modules/Broadcasting/Services/Sms/SmsStatus.php`: __construct (L7)
- `app/Modules/Broadcasting/Services/Sms/TelnyxDriver.php`: __construct (L9), send (L15), status (L38)
- `app/Modules/Broadcasting/Services/Sms/TwilioDriver.php`: __construct (L9), send (L15), status (L33)
- `app/Modules/Ecommerce/Jobs/BackfillStoreOrdersJob.php`: __construct (L30), handle (L35), resolveContact (L87)
- `app/Modules/Ecommerce/Jobs/CheckAbandonedCartJob.php`: __construct (L21), handle (L23)
- `app/Modules/Ecommerce/Jobs/ProcessEcommerceMessagingJob.php`: __construct (L22), handle (L30), sendTemplate (L84)
- `app/Modules/Ecommerce/Jobs/ProcessEcommerceWebhookJob.php`: __construct (L34), failed (L40), handle (L49), resolveContact (L110), handleOrder (L128), upsertProduct (L167), handleCart (L178)
- `app/Modules/Ecommerce/Jobs/RegisterStoreWebhooksJob.php`: __construct (L17), handle (L19)
- `app/Modules/Ecommerce/Jobs/SyncStoreCustomersJob.php`: __construct (L31), handle (L36), mapCustomer (L102), phone (L126)
- `app/Modules/Ecommerce/Jobs/SyncStoreProductsJob.php`: __construct (L26), handle (L31)
- `app/Modules/Ecommerce/Services/Clients/BigCommerceClient.php`: __construct (L21), http (L27), testConnection (L36), registerWebhooks (L59), deregisterWebhooks (L81), fetchCustomers (L99), fetchOrder (L117), fetchOrders (L129), fetchRecentOrdersForContact (L148), fetchProducts (L158), fulfillOrder (L176), hydrateWebhook (L196), hydrateOrder (L213), hydrateCart (L228), hydrateCustomer (L240), hydrateProduct (L251), eventFromStatus (L266), fetchOrderProducts (L278), createCartRecoveryUrl (L285)
- `app/Modules/Ecommerce/Services/Clients/EcommerceClientInterface.php`: testConnection (L10), registerWebhooks (L17), deregisterWebhooks (L24), fetchCustomers (L31), fetchOrder (L36), fetchOrders (L43), fetchProducts (L52), fetchRecentOrdersForContact (L59), fulfillOrder (L66)
- `app/Modules/Ecommerce/Services/Clients/ShopifyClient.php`: __construct (L20), http (L25), testConnection (L33), registerWebhooks (L60), deregisterWebhooks (L84), fetchCustomers (L102), fetchOrder (L120), fetchOrders (L127), fetchProducts (L156), reshapeProduct (L216), fetchRecentOrdersForContact (L237), fulfillOrder (L251), nextPageInfo (L292)
- `app/Modules/Ecommerce/Services/Clients/StoreClientFactory.php`: for (L11)
- `app/Modules/Ecommerce/Services/Clients/WooClient.php`: __construct (L14), http (L20), testConnection (L30), registerWebhooks (L45), deregisterWebhooks (L67), fetchCustomers (L85), fetchOrder (L101), fetchOrders (L108), fetchRecentOrdersForContact (L124), fulfillOrder (L134), fetchProducts (L155)
- `app/Modules/Ecommerce/Services/ContactCapacity.php`: remaining (L18)
- `app/Modules/Ecommerce/Services/ContactEnricher.php`: enrich (L16), markAsCustomer (L58), tag (L76), platformLabel (L86)
- `app/Modules/Ecommerce/Services/Credentials/StoreCredentials.php`: __construct (L13), get (L15), accessToken (L21), consumerKey (L27), consumerSecret (L32)
- `app/Modules/Ecommerce/Services/EcommerceTemplateSeederService.php`: seedAndSubmit (L15)
- `app/Modules/Ecommerce/Services/EcommerceTemplateVariables.php`: variableMap (L29), bodyValues (L61), sendComponents (L105), defaultBaseUrl (L134), resolveContext (L153), bodyVariableCount (L174), urlButtonParameters (L191), storeUrl (L221), url (L232)
- `app/Modules/Ecommerce/Services/OAuth/EcommerceOAuthManager.php`: __construct (L23), shopifyAuthUrl (L27), shopifyVerifyHmac (L50), shopifyExchange (L72), wooAuthUrl (L97), bigcommerceExchange (L113)
- `app/Modules/Ecommerce/Services/PayloadNormalizer.php`: normalize (L24), bigcommerce (L38), bigcommerceOrder (L49), bigcommerceCart (L90), bigcommerceCustomer (L129), shopify (L146), productEvent (L162), mapProduct (L180), shopifyProduct (L190), wooProduct (L214), bigcommerceProduct (L229), shopifyOrder (L244), shopifyCheckout (L283), woocommerce (L316), shopifyLineItems (L374), wooLineItems (L387), orderContext (L402), date (L421), phone (L437)
- `app/Modules/Ecommerce/Services/StoreConnectionTester.php`: test (L13)
- `app/Modules/Ecommerce/Services/StoreConnector.php`: __construct (L18), connect (L24), normalizeDomain (L66)
- `app/Modules/Ecommerce/Services/StoreUrlGuard.php`: validate (L21), validateShopify (L31), validateBigCommerce (L38), validateWoo (L45), resolveHost (L74), isPublicIp (L101)
- `app/Modules/Inbox/Jobs/ProcessInboundInboxMessageJob.php`: backoff (L27), __construct (L32), handle (L37)
- `app/Modules/Inbox/Services/HandoverService.php`: __construct (L35), announce (L49), attachWaitingLabel (L71), removeWaitingLabel (L91), sendHandoverReply (L107), defaultReply (L149)
- `app/Modules/Inbox/Services/InstagramDriver.php`: __construct (L25), send (L27), limitText (L132), postMessage (L154), receiveWebhook (L276), processWebhookPayload (L281), normalizePostbackToMessage (L367), verifyCreds (L388), processInboundMessage (L393), processEchoMessage (L488), resolveInstagramContact (L574), fetchSenderProfile (L637)
- `app/Modules/Inbox/Services/MessengerDriver.php`: __construct (L25), send (L27), postMessage (L64), receiveWebhook (L81), processWebhookPayload (L86), verifyCreds (L159), processEchoEvent (L173), echoFromOwnApp (L298), processInboundMessage (L309), resolveMessengerContact (L385), fetchSenderProfile (L443)
- `app/Modules/Instagram/Jobs/CheckFunnelTimeoutsJob.php`: handle (L20)
- `app/Modules/Instagram/Jobs/ProcessInstagramCommentJob.php`: backoff (L20), __construct (L25), handle (L30)
- `app/Modules/Instagram/Jobs/ProcessInstagramDmJob.php`: backoff (L34), __construct (L39), handle (L44), forwardToInbox (L63)
- `app/Modules/Instagram/Jobs/RefreshInstagramLoginTokensJob.php`: handle (L39)
- `app/Modules/Instagram/Jobs/ResumeFlowWaitsJob.php`: handle (L24)
- `app/Modules/Instagram/Services/CommentFunnelService.php`: __construct (L26), handleComment (L39), resumePrivateReply (L143), sendReply (L161), handleDmReply (L213), sendFollowUp (L416), sendGateTemplate (L439), ctaQuickReply (L493), followGateQuickReplies (L506), pickAutomation (L527), triggerMatches (L555), renderReply (L581), log (L607)
- `app/Modules/Instagram/Services/FlowEngine.php`: __construct (L34), startFromComment (L43), handleInbound (L96), resumeTimedOutWaits (L160), runFrom (L209), execSendMessage (L282), sendTemplate (L321), sendTemplateDefinition (L338), execWaitReply (L373), execCondition (L388), followEdge (L415), nextNode (L434), edgeByHandle (L441), nodeById (L455), firstSendNodeId (L466), complete (L497), renderVars (L509)
- `app/Modules/Instagram/Services/FlowKeywordMatcher.php`: matches (L16)
- `app/Modules/Instagram/Services/FunnelTimeoutService.php`: expireStaleParticipants (L16), retryStrandedPrivateReplies (L37), expireUnsentPrivateReplies (L96), closeStaleFollowUps (L129)
- `app/Modules/Instagram/Services/InboxMirrorService.php`: enabled (L25), mirrorOutbound (L37), mirrorInbound (L76), resolveThread (L120), logMirrorFailure (L170)
- `app/Modules/Instagram/Services/InstagramAuthService.php`: authorizeUrl (L28), exchangeCode (L48), exchangeForLongLived (L86), refreshLongLived (L122), fetchAccount (L151), graphBaseUrl (L188)
- `app/Modules/Instagram/Services/InstagramGraphClient.php`: apiVersion (L21), isInstagramLogin (L30), graphHost (L40), sendPrivateReply (L53), sendMessage (L77), sendGenericTemplate (L102), sendButtonTemplate (L142), sendAttachment (L166), subscribeAccountFields (L198), getRecentMedia (L218), doesUserFollow (L265), limitText (L305), assertAttachmentSize (L331), post (L357)
- `app/Modules/Instagram/Services/InstagramLog.php`: webhook (L51), comment (L56), dm (L61), send (L66), delivery (L71), funnel (L76), flow (L81), mirror (L86), connect (L91), timeout (L96), any (L104), write (L110)
- `app/Modules/Instagram/Services/KeywordMatcher.php`: matches (L18), normalize (L47), safePregMatch (L56)
- `app/Modules/Instagram/Services/LeadDeliveryService.php`: __construct (L21), renderForPrivateReply (L32), deliver (L46), renderBlock (L180), describeDelivery (L204)
- `app/Modules/Instagram/Services/PrivateReplyService.php`: __construct (L23), send (L36), attemptSend (L64), acquireSendSlot (L147)
- `app/Modules/Integrations/Services/Clients/GoogleClient.php`: __construct (L26), resolve (L37), accessToken (L57), appendSheetRow (L84), readSheetRange (L103), createDocFromTemplate (L124), getForm (L167), listFormResponses (L185), createCalendarEvent (L212)
- `app/Modules/Integrations/Services/ConnectionTester.php`: test (L13), testMeta (L40), testOAuth (L55), testLlm (L67), testSms (L115), testGooglePlaces (L169), testGoogleWorkspace (L188), testQdrant (L208), testStorage (L224)
- `app/Modules/Integrations/Services/CredentialResolver.php`: __construct (L30), system (L32), for (L37), meta (L44), oauth (L49), llm (L74), sms (L88), googlePlaces (L101), google (L107), qdrant (L112), resolve (L119), workspaceLlmConfig (L133), workspaceSmsConfig (L149)
- `app/Modules/Integrations/Services/Credentials/CredentialValueObject.php`: __construct (L7), get (L9), has (L14), toArray (L19)
- `app/Modules/Integrations/Services/Credentials/GenericCredentials.php`: apiKey (L7), url (L12)
- `app/Modules/Integrations/Services/Credentials/LlmCredentials.php`: apiKey (L7), organizationId (L12)
- `app/Modules/Integrations/Services/Credentials/MetaCredentials.php`: appId (L7), appSecret (L13), systemUserToken (L26), verifyToken (L32), configIdWhatsapp (L38), configIdSocial (L44), igAppId (L50), igAppSecret (L57), igManualToken (L70)
- `app/Modules/Integrations/Services/Credentials/OAuthClientCredentials.php`: clientId (L7), clientSecret (L12)
- `app/Modules/Integrations/Services/Credentials/SmsCredentials.php`: apiKey (L7), accountSid (L12), from (L17), apiSecret (L22)
- `app/Modules/Leads/Jobs/ScrapeLeadsJob.php`: __construct (L18), handle (L20)
- `app/Modules/Leads/Services/GooglePlacesScraper.php`: __construct (L22), run (L24), upsertPlace (L68)
- `app/Modules/Shared/Services/ChannelLimitService.php`: logicalChannel (L51), __construct (L56), limitFor (L59), usedFor (L77), canConnect (L93), blockMessage (L101), blockIfExhausted (L116), snapshot (L138)
- `app/Modules/Shared/Services/ChannelManager.php`: register (L13), driver (L18), registered (L28)
- `app/Modules/Shared/Services/ContactService.php`: __construct (L15), upsert (L24), bulkImport (L62), importGridRows (L94), syncAvatarFromUrl (L172), downloadAndStoreAvatar (L197), export (L228)
- `app/Modules/Shared/Services/MetaWebhookRegistrar.php`: verifyInstagramAppObject (L62), registerInstagramObject (L97), registerInstagramAppObject (L171), verifyInstagramObject (L232), normalizeFields (L280), auditInstagramObject (L302)
- `app/Modules/Shared/Services/SegmentResolver.php`: query (L21), materialise (L36), applyRules (L49)
- `app/Modules/Social/Jobs/DispatchScheduledPostsJob.php`: handle (L13)
- `app/Modules/Social/Jobs/PublishSocialPostJob.php`: __construct (L18), handle (L20)
- `app/Modules/Social/Jobs/RefreshSocialTokensJob.php`: handle (L18)
- `app/Modules/Social/Services/Drivers/FacebookDriver.php`: network (L10), fetchAccountInfo (L15), publish (L29)
- `app/Modules/Social/Services/Drivers/InstagramSocialDriver.php`: network (L10), fetchAccountInfo (L15), publish (L29)
- `app/Modules/Social/Services/Drivers/LinkedInDriver.php`: network (L10), fetchAccountInfo (L15), publish (L32)
- `app/Modules/Social/Services/Drivers/SocialNetworkInterface.php`: network (L10), publish (L13), fetchAccountInfo (L16)
- `app/Modules/Social/Services/Drivers/TikTokDriver.php`: network (L12), fetchAccountInfo (L17), publish (L31), pollPublishStatus (L66)
- `app/Modules/Social/Services/Drivers/TwitterDriver.php`: network (L10), fetchAccountInfo (L15), publish (L27)
- `app/Modules/Social/Services/Drivers/YoutubeDriver.php`: network (L13), assertSafeVideoUrl (L21), fetchAccountInfo (L40), publish (L52)
- `app/Modules/Social/Services/OAuth/OAuthManager.php`: __construct (L25), getAuthUrl (L27), exchangeCode (L49), refresh (L71), facebookAuthUrl (L88), facebookExchange (L104), linkedinAuthUrl (L122), linkedinExchange (L135), twitterAuthUrl (L150), twitterExchange (L169), googleAuthUrl (L185), googleExchange (L199), tiktokAuthUrl (L214), tiktokExchange (L227), twitterRefresh (L242), googleRefresh (L257), tiktokRefresh (L273), linkedinRefresh (L290), storeState (L306)
- `app/Modules/Social/Services/SocialPublisher.php`: __construct (L22), publish (L34)
- `app/Modules/Whatsapp/Jobs/ProcessInboundMessageJob.php`: backoff (L27), __construct (L32), handle (L37), failed (L42)
- `app/Modules/Whatsapp/Jobs/ReseedDefaultEcommerceTemplatesJob.php`: __construct (L31), handle (L33), defaultTemplates (L149)
- `app/Modules/Whatsapp/Jobs/SeedDefaultEcommerceTemplatesJob.php`: __construct (L26), handle (L28), backfillLocalRecord (L119)
- `app/Modules/Whatsapp/Jobs/SyncCoexistenceDataJob.php`: __construct (L17), handle (L23)
- `app/Modules/Whatsapp/Jobs/TemplateSyncJob.php`: __construct (L21), handle (L23)
- `app/Modules/Whatsapp/Services/CloudApiClient.php`: __construct (L38), forWorkspace (L43), forPhoneNumber (L74), phoneNumberId (L106), sendText (L117), sendTemplate (L134), markRead (L151), fetchWabaPhoneNumbers (L165), fetchPhoneNumberDetails (L187), registerPhoneNumber (L215), fetchTemplates (L232), checkContacts (L242), requestDisplayNameChange (L261), requestDisplayNameChangeDirect (L267), submitTemplate (L283), editTemplate (L294), deleteTemplate (L310), uploadMedia (L318), getMediaUrl (L335), downloadMedia (L349), sendMedia (L363), sendLocation (L384), sendInteractive (L403), sendPoll (L417), verifyCreds (L434), resumableUpload (L447), post (L492), get (L508)
- `app/Modules/Whatsapp/Services/TemplateValidator.php`: problems (L23), bodyProblems (L76), headerProblems (L100), footerProblems (L124), buttonProblems (L140), variableUrlExampleProblems (L202), urlButtonDomainProblems (L220), exampleProblems (L248), extractPositionalVariables (L279), hostsIn (L287), hostOf (L301)
- `app/Modules/Whatsapp/Services/WhatsappDriver.php`: __construct (L24), send (L28), sendAudio (L103), receiveWebhook (L118), processWebhookPayload (L123), processTemplateStatusUpdate (L182), processPhoneNumberUpdate (L218), verifyCreds (L271), processInboundMessage (L276), processFlowResponse (L493), processStatusUpdate (L530)
- `app/Modules/WhatsappQR/Services/QrDriver.php`: __construct (L25), send (L33), receiveWebhook (L91), verifyCreds (L143), processInboundMessage (L151), resolveContact (L209)
- `app/Modules/WhatsappQR/Services/QrSessionManager.php`: __construct (L15), createSession (L23), resolveWebhookSecret (L72), pollQrCode (L82), checkStatus (L116), logout (L165), getActiveSessions (L194), getSessions (L205), createChannelAccount (L217)
- `app/Services/AnalyticsService.php`: messageVolumeByChannel (L33), campaignFunnel (L63), campaignDeliveryOverTime (L86), campaignDeliveryLag (L125), campaignFailedReasons (L149), aiUsageByDay (L169), aiUsageByModel (L200), aiKpis (L223), topChatbots (L253), conversationsResolvedOverTime (L286), conversationChannelMix (L316), agentLeaderboard (L334), automationRunsByStatus (L395), automationRunsPerAutomation (L420), automationTopErrors (L448), socialPostsByNetwork (L473), socialPostsByStatus (L493), mrrTrend (L512), newClientsPerWeek (L546), planDistribution (L568), topWorkspacesByAiCost (L586), subscriptionStatusBreakdown (L608), revenueByDay (L622), newClientsByDay (L642), platformMessageVolumeByChannel (L661), platformChannelMix (L682), recentClients (L697), recentPayments (L718), contactsGrowthByDay (L744), recentConversations (L764), recentCampaigns (L794), firstResponseTimes (L820), resolutionTimes (L829), slaMetrics (L834), fillDateSeries (L873)
- `app/Services/AuditLogService.php`: log (L12), logAdmin (L40)
- `app/Services/Billing/BillingGatewayRegistry.php`: __construct (L13), registerFromDatabase (L19), registerFromConfig (L174), all (L308), configured (L318), get (L323), listForFrontend (L331)
- `app/Services/Billing/CashfreeGateway.php`: __construct (L39), name (L46), isConfigured (L51), baseUrl (L56), http (L63), createCheckout (L72), handleWebhook (L142), handleStatusChange (L187), handlePaymentSuccess (L216), upsertSubscription (L270), fetchTags (L315), cancel (L329), sync (L351), mapStatus (L372), changePlan (L383), refund (L389), fulfillCheckoutSession (L426), eventId (L432), generateInvoicePdf (L450)
- `app/Services/Billing/InvoiceService.php`: generate (L16)
- `app/Services/Billing/MercadoPagoGateway.php`: __construct (L43), name (L50), isConfigured (L55), http (L60), notificationUrl (L67), createCheckout (L72), handleWebhook (L126), verifySignature (L165), handlePreapproval (L202), handleAuthorizedPayment (L267), parseReference (L336), cancel (L345), sync (L367), mapStatus (L385), changePlan (L396), refund (L432), fulfillCheckoutSession (L465), generateInvoicePdf (L470)
- `app/Services/Billing/MollieGateway.php`: __construct (L47), name (L53), isConfigured (L58), http (L63), webhookUrl (L70), money (L75), interval (L84), createCheckout (L89), handleWebhook (L153), handleFirstPayment (L193), handleRecurringPayment (L286), cancel (L339), sync (L367), mapStatus (L392), changePlan (L403), refund (L441), fulfillCheckoutSession (L472), generateInvoicePdf (L477)
- `app/Services/Billing/MyFatoorahGateway.php`: __construct (L51), name (L60), isConfigured (L65), http (L70), majorAmount (L78), createCheckout (L85), fulfillCheckoutSession (L145), handleWebhook (L248), handleWebhookPaid (L280), handleWebhookFailed (L295), chargeRecurring (L306), cancel (L387), sync (L398), changePlan (L433), refund (L441), generateInvoicePdf (L452)
- `app/Services/Billing/PaddleGateway.php`: __construct (L21), name (L29), isConfigured (L34), baseUrl (L39), createCheckout (L46), handleWebhook (L99), verifyPaddleSignature (L143), handleSubscriptionActivated (L168), handleSubscriptionUpdated (L211), handleSubscriptionCanceled (L234), handleTransactionCompleted (L242), cancel (L302), sync (L322), mapStatus (L345), generateInvoicePdf (L358), fulfillCheckoutSession (L367), changePlan (L372), refund (L408)
- `app/Services/Billing/PaymobGateway.php`: __construct (L56), name (L65), isConfigured (L70), http (L75), authToken (L81), createCheckout (L96), handleWebhook (L195), verifyHmac (L239), handleTransaction (L258), chargeRecurring (L356), cancel (L490), sync (L501), changePlan (L507), refund (L515), fulfillCheckoutSession (L554), generateInvoicePdf (L559)
- `app/Services/Billing/PayPalGateway.php`: __construct (L21), name (L30), isConfigured (L35), baseUrl (L40), getAccessToken (L47), createCheckout (L62), handleWebhook (L163), handleSubscriptionActivated (L213), handleSubscriptionCanceled (L258), handlePaymentCompleted (L266), cancel (L325), sync (L350), mapStatus (L378), generateInvoicePdf (L390), fulfillCheckoutSession (L399), changePlan (L404), refund (L410), verifyPayPalSignature (L448)
- `app/Services/Billing/PaystackGateway.php`: __construct (L41), name (L48), isConfigured (L53), http (L58), createCheckout (L65), handleWebhook (L138), handleSubscriptionCreate (L179), handleChargeSuccess (L233), handleSubscriptionDisabled (L295), handleInvoiceFailed (L306), cancel (L317), sync (L351), mapStatus (L374), changePlan (L385), refund (L391), fulfillCheckoutSession (L424), generateInvoicePdf (L429)
- `app/Services/Billing/RazorpayGateway.php`: __construct (L36), name (L42), isConfigured (L47), http (L52), createCheckout (L59), handleWebhook (L135), handleSubscriptionActivated (L183), handleSubscriptionCharged (L221), handleSubscriptionEnded (L277), cancel (L292), sync (L313), mapStatus (L334), changePlan (L345), refund (L351), fulfillCheckoutSession (L384), generateInvoicePdf (L390)
- `app/Services/Billing/SquareGateway.php`: __construct (L51), name (L60), isConfigured (L65), baseUrl (L70), http (L77), webhookUrl (L85), createCheckout (L90), upsertPlanVariation (L158), upsertCustomer (L196), firstInvoiceUrl (L228), handleWebhook (L248), handleInvoicePaid (L295), handleInvoiceFailed (L351), handleSubscriptionUpdated (L362), mapStatus (L373), cancel (L384), sync (L405), changePlan (L423), refund (L464), fulfillCheckoutSession (L470), generateInvoicePdf (L475)
- `app/Services/Billing/StripeGateway.php`: __construct (L33), name (L40), isConfigured (L45), client (L50), createCheckout (L59), handleWebhook (L121), handleCheckoutCompleted (L174), handleSubscriptionUpdated (L226), handleInvoicePaid (L262), handleInvoicePaymentFailed (L320), fulfillCheckoutSession (L366), cancel (L486), sync (L510), changePlan (L534), refund (L580), subscriptionPeriodEnd (L632), invoiceSubscriptionId (L642), invoicePeriodEnd (L656), invoicePaymentIntentId (L674), generateInvoicePdf (L688), sendPaymentFailedEmail (L698)
- `app/Services/Billing/TapGateway.php`: __construct (L49), name (L56), isConfigured (L61), http (L66), createCheckout (L71), handleWebhook (L121), verifySignature (L159), handleChargeCaptured (L185), activateFromInitialCharge (L215), recordChargePayment (L286), chargeRecurring (L326), cancel (L406), sync (L419), changePlan (L425), refund (L437), fulfillCheckoutSession (L473), fetchCharge (L479), extractSavedCard (L497), nextRenewal (L507), currencyDecimals (L512), majorAmount (L518), minorAmount (L524), formatAmountString (L530), generateInvoicePdf (L536)
- `app/Services/Billing/XenditGateway.php`: __construct (L45), name (L52), isConfigured (L57), http (L62), createCheckout (L70), upsertCustomer (L146), handleWebhook (L182), handlePaymentCreated (L224), activateFromPlan (L286), handlePlanActivated (L330), handlePlanInactivated (L341), cancel (L352), sync (L374), mapStatus (L392), changePlan (L402), refund (L407), fulfillCheckoutSession (L443), generateInvoicePdf (L448)
- `app/Services/ClientWorkspaceService.php`: ensureWorkspaceExists (L14), syncClientUser (L33)
- `app/Services/CurrencyService.php`: format (L12), convert (L29), formatConverted (L56)
- `app/Services/I18n/DefaultTranslations.php`: all (L11), common (L30), nav (L57), topbar (L76), sidebar (L90), admin (L97), pagination (L237), auth (L254), welcome (L282), profile (L301), client (L311), plans (L327), locales (L341), misc (L366)
- `app/Services/I18n/FallbackTranslationProvider.php`: translate (L10)
- `app/Services/I18n/I18nFileService.php`: __construct (L17), path (L22), exists (L28), flatten (L37), unflatten (L61), isAssoc (L82), cacheVersion (L96), getFlatDictionary (L106), putFlatDictionary (L123), allKeys (L142), groups (L150), createLocaleFile (L166), invalidateCache (L181)
- `app/Services/I18n/TranslationKeyScanner.php`: discoverKeys (L13), globRecursive (L41), extractKeysFromJs (L64), extractKeysFromBlade (L90), keyToGroupAndKey (L108), keyToDefaultEnglish (L124)
- `app/Services/I18n/TranslationProviderInterface.php`: translate (L11)
- `app/Services/Install/EnvWriter.php`: __construct (L16), set (L24), stringify (L44), format (L57)
- `app/Services/Install/InstallerService.php`: __construct (L51), isInstalled (L53), requirements (L63), testConnection (L98), applyDatabaseConfig (L119), ensureAppKey (L139), runMigrations (L146), seedCore (L152), seedDemo (L167), createSuperAdmin (L172), clearCaches (L191), writeEnv (L209), markInstalled (L215), isWritable (L220), friendlyDbError (L230)
- `app/Services/License/LicenseManager.php`: enabled (L25), verifyType (L37), verifyTypes (L49), defaultVerifyType (L57), isEnvato (L64), activatedType (L70), storagePath (L83), isActivated (L88), licenseData (L93), maskedCode (L109), connectionCheck (L127), activate (L140), verify (L184), deactivate (L227), checkUpdate (L257), downloadUpdate (L310), http (L357), headers (L366), serverIp (L376), url (L387), cacheHours (L392), cacheValid (L397), storeLicenseData (L402), clearLicenseData (L411), codePath (L419), typePath (L424), storeType (L429), rawCode (L441), storeCode (L452)
- `app/Services/License/Updater.php`: __construct (L25), apply (L31), extract (L108), sourceRoot (L125), copyTree (L145), importSql (L166), rrmdir (L174)
- `app/Services/Mail/MailService.php`: sendWithTemplate (L20), sendRaw (L59), configureMailer (L100), replacePlaceholders (L127)
- `app/Services/MediaService.php`: __construct (L13), store (L15), usedBytes (L46), quotaBytes (L61)
- `app/Services/MetaLogger.php`: log (L12)
- `app/Services/OnboardingService.php`: getProgress (L25), isCompleted (L72), markStep (L112), complete (L133)
- `app/Services/OneSignalService.php`: __construct (L16), isConfigured (L22), sendToUser (L30), sendToUsers (L74)
- `app/Services/ShopifyLogger.php`: log (L12)
- `app/Services/StorageManager.php`: disk (L30), diskName (L40), directoryPrefix (L59), prefixedPath (L67), publicUrl (L85), activeProvider (L103), clearCache (L112), ensureDiskReady (L121), resolved (L153), buildDiskConfig (L208), injectDiskConfig (L254)
- `app/Services/WebhookDispatchService.php`: dispatch (L14), dispatchToEndpoint (L30)
- `app/Services/WebhookIdempotencyService.php`: isNewEvent (L15), release (L45), prune (L62)
- `app/Services/WebPushService.php`: __construct (L17), sendToUser (L51)
- `app/Services/WorkspaceExportService.php`: generate (L22), writeCsv (L147)
