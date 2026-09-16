# Instagram Comment Automation — Complete Setup & Operations Guide

**Guide v2 — Sep 15, 2026.** A complete, copy-paste-ready guide for deploying and operating the Instagram comment-automation module (`app/Modules/Instagram`) on production.

> **What it does:** Someone comments on your reel/post → the bot sends **one private-reply DM** → the DM asks them to follow and reply with a keyword → the bot **verifies the follow via the Graph API** (not just the user's word) → the reply opens the **24-hour window** → the bot delivers the "required thing" (link / file / message). The whole thread is mirrored into the shared Inbox so a human agent can take over at any point.

```
Customer comments "price" on your reel
        │
        ▼
Meta webhook ──► /webhooks/instagram/{token}   (token + HMAC signature + dedupe)
        │
        ▼
ProcessInstagramCommentJob (instagram queue)
        │
        ▼
CommentFunnelService ── match automation? ──► ONE private-reply DM
        │                                      (hook + follow ask + keyword
        │                                       + tappable YES/NO quick replies)
        ▼
Customer replies (consent event — Meta now allows profile access)
        │
        ▼
doesUserFollow() ── GET /{igsid}?fields=is_user_follow_business
        ├─ true  + keyword  ──► DELIVERY (link / file / text) ──► delivered
        ├─ true  + "yes"    ──► keyword reminder
        ├─ false (verified) ──► "please follow us first" (bounded loop)
        │                        budget exhausted ──► Inbox agent handoff
        └─ unknown          ──► fail-open (lead never blocked)
        │
        └── whole thread mirrored in shared Inbox (human can take over)
```

---

## Connection mode: Instagram Login (the only path)

| Property | Value |
|---|---|
| Login credentials | **Instagram username/password** (no Facebook account) |
| Facebook Page required | **No** |
| Graph host | `graph.instagram.com` |
| Token type | Instagram User token, **60 days** (auto-refreshed daily) |
| Connect button | **"Connect with Instagram"** (purple, primary) |
| Works for | Professional (business/creator) accounts |

> **Migration note:** the old Facebook-Page connect flow (Facebook Login embedded signup for Instagram) has been **removed** from the UI and code. If an account was connected the old way, reconnect it once with **Connect with Instagram** — the new flow needs no Facebook Page and delivers DMs/comments through `graph.instagram.com`. WhatsApp and Messenger are unaffected: they still use the same Meta app and their own flows.

---

## Prerequisites Checklist

- [ ] VPS deployed with the latest `master` (migrations ran — see Part E)
- [ ] PHP **8.2+** on the VPS (`php -v`)
- [ ] Queue worker running the `instagram` queue (Part E)
- [ ] Laravel scheduler cron active (Part E) — also powers the daily token refresh
- [ ] A Meta app (the **existing WhatsApp app works — no new app needed**)
- [ ] Instagram App ID + App Secret configured (Part A — for Instagram Login)
- [ ] Instagram account(s) converted to **Professional** (Business/Creator)

---

## Part A — Meta App Dashboard (developers.facebook.com)

### 1. Instagram Login setup (new primary path)

1. App Dashboard → **Instagram** product → **API setup with Instagram login**
2. If the product is missing: **Add Product** → **Instagram** → **Set up** (choose the *API setup with Instagram login* option)
3. Open **Business login settings** and copy the **Instagram app ID** + **Instagram app secret**
4. In the same section add the **OAuth redirect URI** (exact, no trailing slash):
   ```
   https://wa.careerinpak.com/app/instagram/setup
   ```
   (If the Inbox Channels page also starts IG-Login flows, add `https://wa.careerinpak.com/app/inbox/setup` too.)
5. **Scopes requested automatically by the app:** `instagram_business_basic`, `instagram_business_manage_messages`, `instagram_business_manage_comments` — nothing to configure by hand.
6. **Access level:** connecting **your own** accounts works on Standard Access. App Review + Business Verification are only needed when third parties' accounts connect (SaaS).

### 2. Facebook Login setup — NOT needed for Instagram anymore

The Instagram connect flow **no longer uses Facebook Login at all** (no Page, no `config_id_social` requirement for Instagram). Leave your existing Facebook Login for Business configuration untouched — **Messenger and WhatsApp still use theirs**. Just don't add anything new for Instagram here.

### 3. Copy credentials

Settings → Basic → copy the **App ID** and **App Secret**. These plus the IG App ID/Secret all go into the admin panel next.

> **No manual webhook setup in the Meta Dashboard is required** — the app registers the `instagram` webhook object **itself through one shared registrar** used by every connect flow (one callback URL `/webhooks/instagram/{verify_token}`, one field set `comments, messages, messaging_postbacks, message_reactions`). Do **not** manually set the instagram callback to `/webhooks/meta` in the Dashboard — that would kill comment automation.

---

## Part B — Admin Panel (your app)

Go to **Admin → Integrations → Meta App** and fill:

| Field | Value | Used by |
|---|---|---|
| **App ID** | Meta App ID | webhook registration + WhatsApp + Messenger |
| **App Secret** | Meta App Secret | same |
| **Instagram App ID** | from Part A step 1 | **Instagram Login flow** (`ig_app_id`) |
| **Instagram App Secret** | from Part A step 1 | **Instagram Login flow** (`ig_app_secret`) |
| **Webhook Verify Token** | any long random string, e.g. `php -r "echo bin2hex(random_bytes(20));"` | webhook endpoint URL |
| **Config ID (Instagram/Messenger)** | Messenger embedded-signup config | **Messenger only** — Instagram no longer uses it |

Save. Once the IG App ID/Secret are present, the client panel shows the primary purple **Connect with Instagram** button.

---

## Part C — Client Panel Connect

### Connect with Instagram

1. Sidebar → **Instagram → Setup** (or **Inbox → Setup** → connect drawer)
2. Click **Connect with Instagram** → Instagram's own login window opens
3. Log in with the **Instagram credentials** of the professional account → allow the permissions ("View profile and access media (required)", comments, messages — leave all ON)
4. Redirect back → the account is stored automatically with `auth_type = instagram_login`:
   - Inbox channel row created (DMs land in the shared Inbox)
   - Comment-automation module row created (automations can be built)
   - Webhook registration + account field subscription run automatically
5. **ONE connection powers both features** — Inbox DMs AND comment automation.

There is no other connect path anymore — the old Facebook-Page flow was removed.

### Token lifetime (Instagram Login)

The Instagram User token is valid **60 days**. A scheduled job (`refresh-instagram-login-tokens`, daily 02:30 via the scheduler) refreshes every token before it expires and renews it to a fresh 60 days — so accounts stay connected indefinitely as long as the **cron + worker** are alive. If a token ever does expire, `php artisan instagram:diagnose` reports it with the account name and a "reconnect" instruction.

---

## Part D — First Automation

**Instagram → Comment Automations → New** (3-step wizard):

| Step | Field | Example |
|---|---|---|
| 1. **Kab chalega?** | Trigger: keywords / mentions / all comments | `price`, `rate` (contains) |
| 1. | Limit to specific posts (advanced options) | **Pick posts** → select reels; empty = all posts |
| 2. **Kya bhejna hai?** | Private-reply DM text | *"Thanks for commenting! Follow and reply **DONE** to get the link 👇"* |
| 2. | **Follow gate** | On — keyword `DONE` |
| 3. **Kaise milega?** | Delivery: link / file / text | the "required thing" |
| — | Enabled | ✅ |

**Follow-gate behavior (with verification):**

1. Commenter gets the one private-reply DM (with **"✅ I followed" / "Not yet"** tappable quick replies where supported)
2. Any reply opens the 24h window AND enables the profile lookup
3. Bot calls `GET /{igsid}?fields=is_user_follow_business`:
   - **verified follower + keyword** → delivery instantly
   - **verified NOT following** → "please follow us first" prompt, repeats within the bounded budget (`INSTAGRAM_MAX_NUDGES`, default 2), then hands the thread to an Inbox agent
   - **inconclusive** (no consent yet / network error) → **fail-open**, delivery is never blocked by a broken check
4. Lying ("DONE" without following) gets caught by the API — no free deliveries.

**Per-post precedence:** a post-specific automation always overrides an account-wide one on its posts, regardless of priority numbers.

---

## Part E — VPS Deployment Runbook

Standard deploy (after new commits are pushed to `master`):

```bash
cd /www/wwwroot/wa.careerinpak.com

git pull origin master
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan cache:clear
php artisan queue:restart
sudo systemctl restart php8.2-fpm 2>/dev/null || sudo systemctl restart php-fpm
```

### Queue worker (supervisor)

The worker command **must include `instagram` first** (so DMs are not starved behind the WhatsApp backlog):

```ini
command=php /www/wwwroot/wa.careerinpak.com/artisan queue:work database --queue=instagram,whatsapp,broadcast,automation,automations,social,leads,ai,default --tries=3 --max-time=3600
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart all
```

### Scheduler cron (mandatory)

Powers: funnel window sweeps, stranded-send healing, **Instagram-Login token refresh**, WhatsApp template sync, social token refresh, billing:

```
* * * * * cd /www/wwwroot/wa.careerinpak.com && php artisan schedule:run >> /dev/null 2>&1
```

### Frontend assets

Committed to the repo (`public/build`) — **no npm needed on the VPS**.

### Post-deploy verification

```bash
php artisan instagram:diagnose        # full pipeline check — start HERE when anything breaks
php artisan migrate:status | grep instagram
php artisan route:list | grep instagram
```

Webhook endpoint probe (should print `hello`):
```bash
curl "https://wa.careerinpak.com/webhooks/instagram/<VERIFY_TOKEN>?hub.mode=subscribe&hub.verify_token=<VERIFY_TOKEN>&hub.challenge=hello"
```

---

## Part F — Live End-to-End Test

1. From a **second** Instagram account, comment `price` on your reel
2. Within ~5–10s the commenter receives the **private-reply DM**
3. **Without following, reply `DONE`** → the bot should reply *"It seems you are not following us yet!"* (the API caught the lie)
4. Follow the account, then reply `DONE` (or tap **✅ I followed**) → the bot sends the **delivery**
5. Every step is visible under **Instagram → Funnel Logs** (stage, action, error, payload) and `storage/logs/instagram/dm.log` (`follow-status resolved: true/false`)

Note: Meta allows **one private reply per comment** — to re-test, comment again (a fresh comment starts a fresh funnel).

---

## Diagnose Command — first stop for any problem

```bash
php artisan instagram:diagnose
```

Checks, in order: module installed → Meta credentials → webhook subscription registered at Meta (with field list) → stored token validity → token scopes → page subscription → queue backlog → funnel participants awaiting the gate → job self-test → **comment-automation readiness** (accounts, active automations, scheduler heartbeat) → **Instagram Login readiness** (IG app credentials, IG-login accounts, per-account token runway in days).

It names the exact broken hop and the exact fix. If everything is green but messages are still missing, see the troubleshooting table below.

Repair without reconnecting:

```bash
php artisan instagram:register-webhook
```

---

## Troubleshooting — issues hit during this deployment (full changelog)

| # | Symptom | Root cause | Fix |
|---|---|---|---|
| 1 | Zero webhooks arriving, diagnose FAIL at credentials | `verify_token` field empty in admin → Meta subscription never created | Fill Webhook Verify Token → `instagram:register-webhook` |
| 2 | *(historical)* Page subscribe fails `(#200) pages_messaging needed` | Token scopes missing on the old FB-login flow | Flow removed — reconnect with **Connect with Instagram** |
| 3 | DMs arrive only when running `queue:work --stop-when-empty` manually | Worker's `--queue` list missing `instagram` | Add `instagram,` to the supervisor command, restart (Part E) |
| 4 | **`Data too long for column 'avatar'`** in laravel.log, message lost | IG avatar CDN URLs ≈700 chars > `contacts.avatar` column (512) | Fixed permanently: column is `TEXT` + avatar sync is non-fatal (run `php artisan migrate --force`) |
| 5 | One account's DM swallowed by another account's funnel | Cross-workspace participant matching | Fixed in `CommentFunnelService` (account-scoped queries) |
| 6 | "Get Started" postbacks dropped | Postback events not forwarded | Fixed — postbacks flow to the Inbox pipeline |
| 7 | Webhook subscription silently drifts (fields/callback replaced) | Multiple registrars fighting | Fixed — single shared `MetaWebhookRegistrar` + drift audit on every connect |
| 8 | Crash on Meta field-shape changes | Assumed string fields, got objects | Fixed — `normalizeFields()` tolerates both shapes |
| 9 | Tinker multi-line paste parse errors | Shell quoting | Use single-line `--execute="..."` commands |
| 10 | User replies swallowed after funnel close / automation delete | Reply handler returned before mirroring | Fixed — message mirrors to the Inbox thread for agent handoff |
| 11 | Delivery sent to users who never followed ("DONE" liars) | Trust-based gate | Fixed — Graph API verification (`is_user_follow_business`) before delivery; bounded NO-loop; fail-open on inconclusive |
| 12 | Connected OK, token valid, par account **NOT subscribed** on graph.instagram.com — zero DMs | Code POSTed to `/{ig_id}/subscribed_fields` — **endpoint does not exist** (docs: Enable Subscriptions uses `/subscribed_apps`), so the account-level subscription never happened | Fixed — now POSTs to `/{ig_id}/subscribed_apps` per docs; run `php artisan instagram:register-webhook` once after pull |
| 13 | `Instagram app subscription FAILED: Error validating application. Cannot get application info due to a system error.` | Meta rejects the **Instagram app's** App-Access token via API (known Meta-side rejection on some IG apps) — API registration AND API verification dono unavailable | Register the IG-app webhook **manually** once: Meta Dashboard → Instagram app → Webhooks → object `instagram` → Configure → callback `/webhooks/instagram` + same verify token + fields (command prints exact values). Account-level subscription command se hi ho jata hai |

Quick log locations:

```bash
grep "Instagram webhook" storage/logs/laravel.log | tail -25
tail -20 storage/logs/instagram/dm.log        # funnel + follow-verification audit
tail -20 storage/logs/instagram/webhook.log
tail -20 storage/logs/worker.log              # supervisor worker stdout
```

---

## Configuration Reference

### env / config (`config/instagram.php`)

| Env key | Default | Meaning |
|---|---|---|
| `INSTAGRAM_ENABLED` | `true` | Master switch — `false` makes the module fully dormant |
| `INSTAGRAM_MIRROR_TO_INBOX` | `true` | Mirror funnel DM threads into the shared Inbox |
| `INSTAGRAM_GRAPH_VERSION` | `v20.0` | Graph API version for all calls (both hosts) |
| `INSTAGRAM_QUEUE` | `instagram` | Queue name for module jobs |
| `INSTAGRAM_SENDS_PER_MINUTE` | `30` | Per-account outbound rate limit |
| `INSTAGRAM_MAX_NUDGES` | `2` | Follow-up budget — keyword reminders + gate-loop repeats are bounded by this |
| `INSTAGRAM_FOLLOW_CHECK` | `true` | **Real follow verification** via the User Profile API (`is_user_follow_business`, checked after the user's first DM = consent). `false` = legacy trust-mode |

### Admin credentials (integrations table)

| Key | Meaning |
|---|---|
| `app_id` / `app_secret` | Meta app (webhook registration, WhatsApp, Messenger) |
| `ig_app_id` / `ig_app_secret` | **Instagram app** (Business Login for Instagram) |
| `verify_token` | webhook callback URL token |
| `config_id_whatsapp` / `config_id_social` | embedded-signup configs (WhatsApp / legacy IG-Messenger) |

---

## Meta Hard Limits (all enforced in code + DB)

1. **ONE private reply per comment** — `funnel_participants.comment_id` DB-unique + stage guard. Hook, follow-ask and (if no gate) delivery are all embedded in that single message.
2. **7-day private-reply window** — `expires_at = comment + 7 days`; the timeout sweep expires stale participants and self-heals stranded sends.
3. **Follow-ups only after the user replies, within 24h** — `dm_thread_opened_at` opens the window on the participant's first reply; sends outside the window are refused. The Human-Agent tag (7-day) is the documented escalation path beyond that.
4. **Profile/follow data needs DM consent** — `is_user_follow_business` is only readable after the user has messaged the account; the funnel checks it exactly at that point, so the consent rule is satisfied by design.
5. Inbox vs Request folder placement is decided by Instagram itself.

## Funnel Stages

`commented → dm_sent → awaiting_follow → replied → delivered` — terminal states: `delivered`, `expired`, `closed`. Rate-limited/transient failures are retried by the queue; permanent failures close the funnel; mid-send crashes are healed by the sweep; budget-exhausted gate loops close for **agent handoff** (thread fully mirrored in the Inbox).

---

## Module Map

```
app/Modules/Instagram/
├── InstagramServiceProvider.php     # auto-discovered; routes/webhook/scheduler/config
├── routes/web.php                   # client panel routes (app/instagram/*)
├── config → ../../config/instagram.php
├── database/migrations/             # 4 tables + media_ids (+ shared contacts.avatar TEXT migration)
├── Models/                          # InstagramAccount, CommentAutomation, FunnelParticipant, CommentAutomationLog
├── Services/
│   ├── InstagramAuthService         # NEW — IG-Login OAuth: authorize, exchange, 60-day refresh, /me
│   ├── CommentFunnelService         # state machine + gate loop + quick replies
│   ├── PrivateReplyService / LeadDeliveryService / KeywordMatcher
│   ├── InboxMirrorService / FunnelTimeoutService
│   └── InstagramGraphClient         # host routing: graph.instagram.com (IG-login) vs graph.facebook.com (legacy)
├── Jobs/
│   ├── ProcessInstagramCommentJob / ProcessInstagramDmJob / CheckFunnelTimeoutsJob
│   └── RefreshInstagramLoginTokensJob   # NEW — daily 60-day token renewal
└── Http/Controllers/
    ├── ConnectController            # IG-Login redirect-back + persistence
    ├── InstagramWebhookController   # dual-signature verify (FB secret OR IG secret)
    ├── AutomationController (CRUD + wizard presets + recent-posts)
    └── LogsController

resources/js/Pages/Instagram/        # Setup.jsx (Connect-with-Instagram button), Automations/{Index,Edit}.jsx (3-step wizard), Logs/Index.jsx
tests/Feature/Instagram/             # InstagramFunnelTest.php + InstagramInboundDmTest.php (run on PHP 8.2+)
```

## Removal / Deletion Drill

1. `rm -rf app/Modules/Instagram` (optionally also `config/instagram.php`, `resources/js/Pages/Instagram/`)
2. Provider auto-discovery finds nothing → routes, webhook, scheduler vanish; the webhook registrar self-heals to the Inbox-only callback on the next connect
3. Sidebar group disappears via its `route().has()` guard
4. Tables keep orphaned rows (harmless) or drop them: `php artisan migrate:rollback --step=4`
5. Everything else (Inbox, WhatsApp, Social) runs untouched.

---

> The rich HTML version of this guide (`meta_setup_guide.html`, v1.7) has the same content with visuals — open it in a browser and print to PDF if a PDF copy is needed.
