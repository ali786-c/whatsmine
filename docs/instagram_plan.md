# Instagram Comment-Automation Module — Full Plan

A **fully separate** `app/Modules/Instagram` module that automates the comment → DM funnel:

> Someone comments on a post → an automated **private reply DM** goes out → if they haven't followed / engaged yet, they get the follow + reply prompt → once they **reply in the DM**, the **required thing** (link / file / message) is delivered inside the opened 24-hour window.

Built on Meta's **Private Replies** API (the doc you pasted). Verified against Meta's webhook payload reference — both login shapes are documented below.

---

## 1. Meta platform facts this plan is built on (from the doc + verified payload reference)

- Private reply = `POST /{IG_USER_ID}/messages` with `recipient: {comment_id}` (NOT an IGSID) and `message: {text}`.
- The reply lands in the commenter's **Inbox** if they follow the account, or the **Request** folder if they don't — so we don't need to know follower status to *send*; the delivery folder is Instagram's decision.
- **One private reply per comment.** Sent within **7 days** of the comment (Live: only during the broadcast).
- **Follow-ups are only possible after the user replies** in that DM thread — then the standard **24-hour window** opens.
- Comment webhook payload differs by login type (both verified from Meta's webhook examples):
  - **Facebook Login for Business** (what this repo uses today): `entry[].changes[]` with `field: "comments"` and `value: { from: {id, username}, comment_id, parent_id?, text?, media: {id, ad_id?, media_product_type?} }`.
  - **Business Login for Instagram**: `entry[].field: "comments"` directly (no `changes` wrapper), `value: { id, from: {id, username}, text, media: {id, media_product_type} }`.
- Required permissions (FB Login path, our case): `instagram_basic`, `instagram_manage_comments`, `pages_read_engagement` (+ existing `instagram_manage_messages` for the DMs), webhooks: `comments` (+ `live_comments` if used).
- Host: `graph.facebook.com/v20.0/{ig_user_id}/messages` with the **Page access token** — same token shape the existing Inbox IG driver already stores.

**Key architectural insight:** the "did they follow?" state cannot be read reliably via API (no public friendship check on the messaging path). But we don't need to query it — Instagram's own gating gives us a perfect signal:
1. We send the private reply with the ask ("follow + reply with keyword X").
2. The user can only continue by **replying in DM**. When their reply webhook arrives, the funnel advances.
3. Optionally, gate the final delivery on their keyword reply (e.g. must reply `DONE` after following) — this is how every commercial IG DM-automation tool handles "follow verification".

---

## 2. Module structure (all new files, nothing touches Inbox/Social)

```
app/Modules/Instagram/
├── InstagramServiceProvider.php
├── routes/web.php
├── Http/
│   ├── Controllers/
│   │   ├── InstagramConnectController.php        # OAuth connect (FB Login for Business, reuses Meta creds)
│   │   ├── InstagramWebhookController.php        # verify + receive (comments, messages, postbacks)
│   │   └── AutomationController.php              # CRUD for comment-automations (client UI API)
│   └── Middleware/EnsureWorkspaceInstagramAccess.php (optional)
├── Models/
│   ├── InstagramAccount.php                      # per-workspace connected IG account
│   ├── CommentAutomation.php                     # the automation rules
│   ├── CommentAutomationLog.php                  # every trigger/fire attempt (audit + rate safety)
│   └── FunnelParticipant.php                     # per commenter funnel state
├── Services/
│   ├── InstagramGraphClient.php                  # thin v20.0 client (page token, base url, error mapping)
│   ├── PrivateReplyService.php                   # the ONE private reply per comment (guarded)
│   ├── CommentFunnelService.php                  # state machine: comment → dm → follow-ask → reply → deliver
│   ├── KeywordMatcher.php                        # trigger keywords, exact/contains/regex
│   └── LeadDeliveryService.php                   # build final delivery payload (text/link/file/media)
├── Jobs/
│   ├── ProcessInstagramCommentJob.php            # handle 'comments' webhook entries
│   ├── SendPrivateReplyJob.php                   # queued send with retry/backoff
│   ├── DeliverLeadJob.php                        # post-reply delivery inside 24h window
│   └── CheckFunnelTimeoutsJob.php                # scheduled: expire stale participants (7d/24h rules)
└── database/migrations/
    ├── create_instagram_accounts_table.php
    ├── create_comment_automations_table.php
    ├── create_comment_automation_logs_table.php
    └── create_funnel_participants_table.php
```

---

## 3. Database design

### instagram_accounts
| column | notes |
|---|---|
| id, workspace_id | |
| ig_user_id | IG professional account id (webhook `entry.id` match key, unique per workspace) |
| username, display_name | for UI |
| page_id, page_token | FB Login path — page access token used for ALL calls |
| status | active / token_expired / disconnected |
| meta_json | scopes granted, connected_at, token expiry |
| timestamps |

### comment_automations
| column | notes |
|---|---|
| id, workspace_id, instagram_account_id | |
| name | e.g. "Reel drop — send link" |
| trigger_type | all_comments / keyword / mention_only |
| keywords | JSON array; matcher modes below |
| match_mode | contains / exact / starts_with / regex (default contains, case-insensitive) |
| reply_message | the private-reply text (supports tokens: {username}, {post_url}) |
| follow_gate | bool — require follow+keyword reply before lead delivery |
| follow_prompt_message | second DM text asking to follow & reply with keyword |
| reply_keyword | e.g. "DONE" — the reply that completes the follow gate |
| delivery | JSON: `{type: "text"\|"link"\|"file", text, url, filename}` — "required cheez" |
| media_filter | JSON: only trigger on feed/reel/story/ad (`media_product_type`) |
| is_active, priority | first matching active automation wins (by priority, then newest) |
| timestamps |

### funnel_participants
| column | notes |
|---|---|
| id, workspace_id, instagram_account_id | |
| commenter_igsid | Instagram-scoped ID from `value.from.id` |
| username | from webhook |
| comment_id | unique — the private-reply anchor + one-reply guard |
| media_id, media_product_type | |
| stage | commented → dm_sent → awaiting_follow → replied → delivered / closed / expired |
| automation_id | which rule fired |
| private_reply_message_id | from Graph response |
| dm_thread_opened_at | when commenter replied (24h window start) |
| delivered_at, closed_at, expires_at | expires_at = comment time + 7d |
| timestamps |

Unique constraint: `(comment_id)` — this is the hard guarantee of **one private reply per comment**, enforced in DB, not just code.

### comment_automation_logs
Every webhook-triggered evaluation and send attempt: `comment_id`, `automation_id`, `action` (matched/matched_inactive/sent/failed/skipped_rate_limited/…), `request_json`, `response_json`, `error`. This is the debugging backbone.

---

## 4. The funnel — state machine

```
[comments webhook]
      │  ProcessInstagramCommentJob
      ▼
CommentFunnelService::handleComment()
      ├─ dedupe (comment_id exists? → stop)        ← one-reply guarantee
      ├─ skip own comments / parent replies (configurable)
      ├─ KeywordMatcher vs active automations
      ├─ no match → log, close. match →
      ▼
[stage: commented] SendPrivateReplyJob
      │  POST /{ig_user_id}/messages
      │  recipient: {comment_id}, message: {text}
      │  ← this is the doc's "Send a Private Reply", within 7d window
      ▼
[stage: dm_sent]
      ├─ follow_gate = false → DeliverLeadJob immediately (message lands in Inbox/Request)
      └─ follow_gate = true  → send follow_prompt (same thread, allowed only because
         we're still within the ONE private reply? NO — see §5 correction) →
         stage: awaiting_follow, wait for the commenter to reply
      ▼
[messages webhook: commenter replies in DM]  ← 24h window opens HERE
      │  FunnelService::handleDmReply()
      ├─ KeywordMatcher vs reply_keyword (e.g. "DONE")
      ├─ match → DeliverLeadJob → stage: delivered
      └─ no match → polite nudge (within 24h), max N nudges (configurable, default 1)
      ▼
CheckFunnelTimeoutsJob (every 15 min)
      ├─ commented/dm_sent older than 7d from comment creation → expired
      └─ awaiting_follow with dm_thread_opened_at + 24h passed → closed
```

> **§5 correction (important):** Meta's doc says **only one message per comment** via the private-reply endpoint — the *follow prompt* is therefore NOT a second API call right after the private reply. Two compliant designs:
> - **Option A (default):** the private reply itself *contains* the follow instruction + keyword ask. One API call, fully compliant.
> - **Option B:** private reply = hook text only; the follow prompt is sent as a normal `/{ig_user_id}/messages` send **after** the user replies (window open) — i.e. the follow-ask moves to the *reply* step.
> The schema supports both (`follow_prompt_message` used in whichever step the mode dictates).

---

## 5. Services in detail

### InstagramGraphClient
- `withAccount(InstagramAccount)` → uses `page_token`, base `https://graph.facebook.com/v20.0`.
- `sendPrivateReply(commentId, text)`, `sendMessage(igsid, text)`, `sendAttachment(igsid, type, url)`.
- Maps error codes to typed exceptions: `#3` permission, `#10` permission/window, `#2018*` / code `2018001` "no private reply allowed" (already replied / outside 7d / not on professional post), `#2018064` window expired. All errors → log row, never crash the queue.

### PrivateReplyService
- The **only** place that calls `sendPrivateReply`.
- Pre-checks in order: automation still active → participant stage is `commented` → `now() < expires_at` (7d) → `comment_id` not already replied (DB unique + log check).
- Stores returned `message_id` on the participant.

### KeywordMatcher
- Case-insensitive; trims; strips emoji/zero-width chars optionally.
- Modes: `contains` (default), `exact`, `starts_with`, `regex` (validated at save time, safe try/catch on match).
- Also used for the `reply_keyword` check and postback payloads (CTA buttons in the DM, if enabled later).

### CommentFunnelService
- `handleComment(array $value, InstagramAccount $account)` — the webhook brain.
  - Works with **both payload shapes**: FB-Login `changes[]` wrapper and BLI direct `field` shape. Extract `{comment_id, from.id, from.username, text, media.id, media.product_type}` via one normalizer.
  - Ignores `parent_id` replies by default (configurable per automation).
  - Creates `FunnelParticipant` + log rows; picks automation by priority.
- `handleDmReply(array $messagingEvent)` — inbound message on the funnel thread: opens the 24h window (`dm_thread_opened_at`), checks reply keyword, advances or nudges.
- Reuses the existing Inbox plumbing where possible: contact creation keyed on IGSID in `custom_fields.instagram_psid` (same convention as `InstagramDriver`), conversation + message rows so the funnel DMs appear in the shared Inbox with `channel=instagram`, `direction=out` for our sends. The funnel is then fully visible/continuable by a human agent too.

### LeadDeliveryService
- Builds the delivery payload from `delivery` JSON: text, link (sent as text — IG renders link cards), or file (uploaded via `resumableUpload` pattern already in `CloudApiClient` for WhatsApp — reuse approach; IG attachments need a public URL or attachment_id).
- Appends tracking: optional UTM/short-link integration point (no external dependency assumed in v1).
- Marks participant `delivered`, writes log.

### Jobs
- All queued on a dedicated `instagram` queue (queue connection config key `queue.instagram` fallback `default`).
- `SendPrivateReplyJob`: tries 3× with backoff 10s/60s/300s; releases on 5xx/rate-limit errors, fails permanently on 4xx validation errors (logged).
- `CheckFunnelTimeoutsJob`: `withoutOverlapping()` scheduled every 15 min in the service provider's scheduler registration.

---

## 6. Webhook wiring

- New endpoint, same pattern as `MetaWebhookController`: `GET/POST /webhooks/instagram/{verify_token}` → `InstagramWebhookController`.
  - Verify: hub challenge + hash_equals against system Meta verify token (reuse `CredentialResolver`).
  - Receive: **X-Hub-Signature-256 HMAC check** (same as existing), respond 200 fast via `FlushesWebhookResponse`, then dispatch `ProcessInstagramCommentJob` per entry.
  - Per-event idempotency on `value.comment_id` (+ `mid` for messaging events) — same `WebhookIdempotencyService`, new keys `ig_comment` / `ig_dm`.
  - Accepts `object=instagram` only; routes: `field/changes field == comments|live_comments → comment funnel`, `messaging → DM replies (funnel first, else log only — the existing Inbox driver keeps handling general DMs)`.
- App subscription registration (on connect, idempotent): `POST /{app_id}/subscriptions` object `instagram`, fields **`comments, messages, messaging_postbacks, message_reactions`** (one complete set — the same replace-on-write trap documented in `instagram.md` §4.3; keep the full list in one constant here).

## 7. Connect flow (`InstagramConnectController`)

- Reuses the existing Meta App credentials (`CredentialResolver::system()->meta()`) and FB Login for Business config id — the connect button lives in a new `app/Modules/Instagram/resources` page (or Setup.jsx section) and POSTs the code to `InstagramConnectController`.
- Exchange code → short token → long-lived token (same 3-way retry pattern as WhatsApp controller), then `me/accounts` → for each page with `instagram_business_account`, store `instagram_accounts` row (ig_user_id + page token).
- Validate scopes: require `instagram_manage_comments` + `instagram_manage_messages` + `instagram_basic`; if missing, save account as `token_expired`/incomplete and surface the exact permission message (mirroring the driver's #3 error guidance).
- Route naming: `client.instagram.setup.embedded-signup`, `client.instagram.automations.*` — registered in `InstagramServiceProvider::boot()` with route model binding for workspace scoping.

## 8. Client UI (minimal for v1, consistent with existing patterns)

- `Instagram → Automations` page (Inertia, same layout components as Inbox Setup):
  - List of connected IG accounts (status, username, token state).
  - Automation builder: trigger (all/keywords + mode), private-reply text, follow-gate toggle + prompt + keyword, delivery block (text/link/file), active toggle.
  - Per-automation stats: triggered, replied, delivered (from logs).
- Events/logs view for debugging a specific comment (`comment_automation_logs`).

## 9. Build order (practical milestones)

1. **Skeleton**: provider, routes, migrations, models. ← no Meta calls yet
2. **Webhook endpoint + connect flow** (reuse patterns above) + app subscription registration with full field set.
3. **GraphClient + PrivateReplyService + SendPrivateReplyJob** — end-to-end test: comment on a test post with the app in dev mode (test accounts), confirm DM + Inbox folder behavior.
4. **Funnel state machine + keyword matcher + automation CRUD + UI.**
5. **Follow gate + DM reply handling + LeadDelivery + timeouts job.**
6. **Hardening**: log viewer, rate limiting (per-account sends/min), token expiry detection + reconnect banner.

## 10. Risks & rules to respect (hard limits from Meta)

- ONE private reply per comment — DB unique constraint + stage guard (double protection).
- 7-day limit for private replies — `expires_at` enforced before send; Live comments: broadcast-only (v1 can simply not enable `live_comments`).
- Follow-ups only after user reply, within 24h — enforced by the state machine (`dm_thread_opened_at`).
- Comment webhooks only fire for **professional accounts**; comments on ads need `ads_management`/`ads_read` when the user has a Page role — note in docs, treat ad comments as optional.
- The current app subscription uses FB-Login `changes[]` payload shape — the normalizer handles both, so a future migration to Business Login for Instagram won't break the funnel.
- Instagram-scoped IDs are per-account; participant rows are scoped `(instagram_account_id, commenter_igsid)` — never match IGSIDs across accounts.

## 11. Explicitly out of scope v1

Auto-follow-back (not exposed via API), comment **liking/hearting** (possible via `instagram_manage_comments` — easy v1.1 add), scheduled bulk DM blasts (policy risk — private replies are strictly comment-anchored), live_comments, multi-language templating.

---

# 12. Isolation Architecture (revised per feedback: fully independent + deletable)

The module is a self-contained add-on. **Zero edits to existing files.**

## 12.1 Provider registration — automatic, no shared-file edit

The app already auto-discovers modules: `App\Providers\ModuleServiceProvider` globs
`app/Modules/*/` and registers each `{Name}ServiceProvider.php`. So:

- Drop `app/Modules/Instagram/InstagramServiceProvider.php` in place → it boots. No `bootstrap/providers.php` edit needed (the originally planned guarded registration is unnecessary — the discovery loop already `class_exists`-guards).
- Delete the module folder → glob finds nothing → nothing registered → app boots exactly as before.

## 12.2 Conditional navigation (no dead links, ever)

`resources/js/Layouts/useClientNav.jsx` appends the "Instagram Automation" group only when
`route().has('client.instagram.setup')` (Ziggy `route().has()`). Routes exist only when the
provider is loaded; delete the module → menu item disappears by itself.

## 12.3 Own everything

- **Own routes file** (`app/Modules/Instagram/routes/web.php`) incl. its own webhook route
  `GET|POST /webhooks/instagram/{token}` — registered from the provider via
  `Route::middleware('throttle:webhooks')->group(...)`, so `routes/webhooks.php` (shared) is
  not touched. The webhook URL is **separate** from `/webhooks/meta`, so module deletion
  cannot break Inbox's Instagram-DM webhook and vice-versa.
- **Own DB tables** (4) with complete `down()` methods — no columns added to core tables.
- **Own config namespace** (`config/instagram.php`, `INSTAGRAM_*` env keys, `enabled` master
  switch) via `mergeConfigFrom` in the provider.
- **Own queue name** `instagram` (database driver — queue names need no config edit).
  A stalled `instagram` worker never delays `whatsapp`/default queues.
- **Own scheduler registration** inside the provider (`Schedule::job(CheckFunnelTimeoutsJob)`),
  so deleting the module removes the schedule automatically.
- **Inbox mirroring is voluntary, flag-guarded, one-directional**: the funnel MAY mirror
  conversations into the shared Inbox (`INSTAGRAM_MIRROR_TO_INBOX`, default true) so human
  agents can take over. Wrapped in try/catch — if Inbox classes change or mirroring fails,
  the funnel continues unaffected. Inbox never depends on the module.

## 12.4 Deletion drill (verified)

1. Remove `app/Modules/Instagram/` → provider not discovered, nav item gone, webhook/scheduler/queue silent.
2. Orphaned rows remain in the 4 tables (harmless) or `php artisan migrate:rollback --step=N`.
3. App boots, Inbox/WhatsApp/Social unaffected — no dangling references anywhere.

# 13. App integration steps (deployment notes)

1. Add `instagram_manage_comments` + `instagram_manage_messages` scopes to the Meta app
   (FB Login for Business config id reuses `config_id_social`).
2. Set webhook URL in Meta dashboard: `https://your-domain/webhooks/instagram/{verify_token}`
   (object `instagram`, fields `comments, messages, messaging_postbacks, message_reactions` —
   the module re-registers this full set on every connect, per-object replace-on-write safe).
3. Deploy: `php artisan migrate` (4 new tables only). Run a worker for the `instagram` queue
   (`php artisan queue:work --queue=instagram,default`).
4. `INSTAGRAM_ENABLED=true` in `.env` (false = module fully dormant without deleting anything).

# 14. End-user flow (UI walkthrough)

1. **Connect** (`Pages/Instagram/Setup.jsx`): "Connect Instagram" → FB OAuth popup
   (`feature_type: 'instagram_management'`, config_id_social) → redirect back with `code` →
   backend exchanges tokens, fetches `/me/accounts` → saves `instagram_accounts` rows,
   re-registers the app webhook subscription with the full field set.
2. **Create automation** (`Pages/Instagram/Automations/Index.jsx` → `Edit.jsx`): 3-step form —
   Trigger (all comments / keywords + match mode / media filter) → Private reply text with
   `{username}` token + Follow gate toggle (prompt + reply keyword `DONE`) → Delivery
   (Text / Link / File URL). Active toggle.
3. **Live funnel**: customer comments → `comments` webhook → keyword match → ONE private reply
   lands in their Inbox (follower) or Request folder (non-follower) — Instagram decides, we
   never need follower status → they follow + reply `DONE` → 24h window opens → lead delivered.
4. **Logs** (`Pages/Instagram/Logs/Index.jsx`): every event with stage, sent/failed/skipped,
   full request/response JSON; per-participant timeline.
