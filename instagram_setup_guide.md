# Instagram Comment Automation — Complete Setup & Operations Guide

A complete, copy-paste-ready guide for deploying and operating the Instagram comment-automation module (`app/Modules/Instagram`) on production.

> **What it does:** Someone comments on your reel/post → the bot sends **one private-reply DM** → the DM asks them to follow and reply with a keyword → their reply opens the **24-hour window** → the bot delivers the "required thing" (link / file / message). The whole thread is mirrored into the shared Inbox so a human agent can take over at any point.

```
Customer comments "price" on your reel
        │
        ▼
Meta webhook ──► /webhooks/instagram/{token}   (verify + HMAC signature + dedupe)
        │
        ▼
ProcessInstagramCommentJob (instagram queue)
        │
        ▼
CommentFunnelService ── match automation? ──► ONE private-reply DM
        │                                      (hook + follow ask + keyword)
        ▼
Customer follows & replies "DONE" ──► 24h window OPENS
        │
        ▼
LeadDeliveryService ──► link / file / text sent ──► stage: delivered
        │
        └── whole thread mirrored in shared Inbox (human can take over)
```

---

## Prerequisites Checklist

- [ ] VPS deployed with the latest `master` (migrations ran — see Part E)
- [ ] PHP **8.2+** on the VPS (`php -v`)
- [ ] Queue worker running the `instagram` queue (Part E)
- [ ] Laravel scheduler cron active (Part E)
- [ ] A Meta app (the **existing WhatsApp app works — no new app needed**)
- [ ] Instagram account(s) converted to **Professional** (Business/Creator)
- [ ] Each professional IG account linked to a Facebook Page

---

## Part A — Meta App Dashboard (developers.facebook.com)

Use the **same app** that already runs WhatsApp. Add these pieces:

### 1. Add the product
App Dashboard → **Add Product** → **Facebook Login for Business** → **Set Up**.

### 2. Create a Login Configuration
Facebook Login for Business → **Configurations** → **Create Configuration**:

- Name: `Instagram Automation`
- Tick these **5 permissions**:

| Permission | Why it is needed |
|---|---|
| `pages_show_list` | Fetch the list of managed Pages |
| `pages_read_engagement` | Obtain Page access tokens |
| `instagram_basic` | Read IG professional account details |
| `instagram_manage_comments` | Receive comment webhooks + send private replies |
| `instagram_manage_messages` | Send DMs + read the user's replies (24h window) |

- Save → **copy the Config ID** (this is `config_id_social`).

> ⚠️ This Config ID must be **different** from the WhatsApp Embedded Signup Config ID. Using the same one makes the Instagram login show WhatsApp permissions.

### 3. OAuth redirect URIs — BOTH
**Facebook Login for Business → Settings → Valid OAuth Redirect URIs** must contain exactly these TWO lines (the `App Domains` field alone is NOT enough for OAuth — each exact URI is required or Meta shows the "Can't Load URL" / "URL blocked" error):

```
https://wa.careerinpak.com/app/instagram/setup
https://wa.careerinpak.com/app/inbox/setup
```

| URI | Used by |
|---|---|
| `/app/instagram/setup` | **Instagram → Connect** (comment-automation module) |
| `/app/inbox/setup` | **Inbox → Setup → Connect Instagram** (Inbox DMs) |

> Both connect buttons can be used — they do not conflict. The webhook object is registered through the single shared registrar, so whichever flow runs produces the same subscription. Comment automation alone only needs the first URI.

### 4. Copy credentials
Settings → Basic → copy **App ID** and **App Secret**.

### 5. App mode
- **Live mode** (Settings → Basic → toggle) — needs Privacy Policy URL + Data Deletion callback; *or*
- **Development mode** — works too, but every Facebook user who connects must have a role on the app (Roles → Admin / Developer / Tester).

> **App Review note:** `instagram_manage_comments` and `instagram_manage_messages` are Advanced Access permissions. Connecting **your own** accounts needs **no review** (you hold an app role). For SaaS clients' accounts, pass App Review first.

---

## Part B — Admin Panel (your app)

Go to **Admin → Integrations → Meta App** and fill:

| Field | Value |
|---|---|
| **App ID** | from Part A step 4 |
| **App Secret** | from Part A step 4 |
| **Webhook Verify Token** | any long random string, e.g. `php -r "echo bin2hex(random_bytes(20));"` |
| **Config ID (Instagram/Messenger)** | the **new** config ID from Part A step 2 |

Save. The Setup page (`Instagram → Connect`) should now show no configuration warning and an active **Connect Instagram** button.

> No manual webhook setup in the Meta Dashboard is required — the app registers the `instagram` webhook object **itself through one shared registrar** used by both connect flows (Inbox and Instagram). One callback URL (`/webhooks/instagram/{verify_token}`), one field set (`comments, messages, messaging_postbacks, message_reactions`) — whichever flow connects first or last, the subscription stays identical. The module endpoint forwards DM events into the Inbox pipeline, so **both features run from this single subscription**. Do **not** manually set the instagram callback to `/webhooks/meta` in the Dashboard — that would overwrite the shared registration and kill comment automation.

---

## Part C — Client Panel Connect

1. Sidebar → **Instagram → Connect**
2. **Connect Instagram** → Facebook popup → **Continue as \<you\>** → **Allow** all permissions
3. Redirect back → accounts are fetched via `/me/accounts` and saved. Success flash: `Connected N Instagram account(s).`

Requirements if nothing is found:
- IG account must be **Professional** (Instagram app → Settings → Account type → *Switch to Professional*)
- The IG account must be **linked to a Facebook Page** (Page → Linked accounts)

---

## Part D — First Automation

**Instagram → Comment Automations → New:**

| Step | Field | Example |
|---|---|---|
| 1. Trigger | Keywords + match mode | `price`, `rate` (contains) — or *All comments* |
| 1. Trigger | Limit to specific posts (optional) | **Pick posts** → select specific reels; empty = all posts |
| 2. Private reply | DM text | *"Thanks for commenting! Follow and reply **DONE** to get the link 👇"* |
| 2. Private reply | **Follow gate** | On, keyword `DONE` |
| 3. Delivery | The "required thing" | Link / file URL / text |
| — | Enabled | ✅ |

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
php artisan cache:clear          # important when resources/js/locales/*.json changed
php artisan queue:restart
sudo systemctl restart php8.2-fpm 2>/dev/null || sudo systemctl restart php-fpm
```

### First-time module install (already done on this VPS)

1. **Migrations** — creates the 4 module tables + `media_ids` column:
   ```
   2026_09_12_000100_create_instagram_accounts_table
   2026_09_12_000200_create_comment_automations_table
   2026_09_12_000300_create_funnel_participants_table
   2026_09_12_000400_create_comment_automation_logs_table
   2026_09_12_000500_add_media_ids_to_comment_automations_table
   ```
2. **Queue worker (supervisor)** — the funnel's jobs run on the dedicated `instagram` queue. Update the worker command:
   ```ini
   command=php /www/wwwroot/wa.careerinpak.com/artisan queue:work --queue=instagram,default --tries=3 --timeout=60 --sleep=3
   ```
   ```bash
   sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl restart all
   ```
3. **Scheduler cron** (7-day/24h window sweeps + stranded-send self-healing):
   ```
   * * * * * cd /www/wwwroot/wa.careerinpak.com && php artisan schedule:run >> /dev/null 2>&1
   ```
4. **Frontend assets** are committed to the repo (`public/build`) — **no npm needed on the VPS**.

### Post-deploy verification

```bash
php artisan migrate:status | grep instagram    # 5 migrations "Ran"
php artisan route:list | grep instagram        # module + webhook routes
ls public/build/assets/instagram-*.js          # built asset present
```

Webhook endpoint probe (should print `hello`):
```bash
curl "https://wa.careerinpak.com/webhooks/instagram/<VERIFY_TOKEN>?hub.mode=subscribe&hub.verify_token=<VERIFY_TOKEN>&hub.challenge=hello"
```

---

## Part F — Live End-to-End Test

1. From a **second** Instagram account, comment `price` on your reel
2. Within ~5–10s the commenter receives the **private-reply DM** (Inbox if they follow, Request folder if not)
3. They follow + reply `DONE` → the bot instantly sends the **delivery**
4. Every step is visible under **Instagram → Funnel Logs** (stage, action, error, payload)

If the DM never arrives:

| Symptom | Cause → Fix |
|---|---|
| No entry in Funnel Logs at all | Webhook not reaching the app → run the curl probe; check `route:list` |
| Log entry with token error | Page token expired → reconnect the account in Setup |
| Log entry with permission error | Missing `instagram_manage_comments/messages` in the login config (Part A step 2) |
| Log shows `comment for unconnected/inactive IG account` | The webhook's IG user ID doesn't match a connected account — reconnect |
| Job never processes | Worker not on `instagram` queue → check supervisor command |
| Meta popup **"Can't Load URL"** (domain of this URL isn't included in the app's domains) | `wa.careerinpak.com` missing from App Domains, or the exact URI (with `/setup`) missing from Valid OAuth Redirect URIs (Part A step 3) |
| Meta popup **"URL blocked"** (redirect URI is not white-listed) | The connect button you used has a different redirect URI than the whitelisted ones — add **both** URIs from Part A step 3 |

Note: Meta allows **one private reply per comment** — to re-test, comment again (a fresh comment starts a fresh funnel).

---

## Troubleshooting — issues hit during this deployment

| Issue | Root cause | Fix |
|---|---|---|
| `require(.../app/Modules/Instagram/../../config/instagram.php): Failed to open stream` on every artisan command | Provider config path was one level short (resolved to `app/config/`) | Fixed in commit `3e0097d` (`../../` → `../../../`). Pull latest master. |
| Sidebar shows raw keys (`nav.instagram_setup`) instead of labels | Translations cached for 1h; cache key ignored file changes from `git pull` | Fixed permanently in commit `f967d7f` (mtime in cache key). Immediate relief on old code: `php artisan cache:clear`. |
| `git pull` refuses: local changes to `resources/js/locales/en.json` | Server-side edit of the locale file | Discard if unintentional: `git checkout -- resources/js/locales/en.json`; else `git stash push` → pull → `git stash pop` (resolve conflicts). |
| `Continue as root/super user [yes]?` hangs composer | Composer refuses root without the env flag | `COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction` |
| `PHP Warning: Module "zip" is already loaded` | Duplicate `extension=zip` line in php.ini | Harmless — ignore. |
| `lock file is not up to date with the latest changes in composer.json` | Cosmetic drift between `composer.json` and `composer.lock` | Cosmetic only; install succeeds. |

---

## Configuration Reference (env)

| Env key | Default | Meaning |
|---|---|---|
| `INSTAGRAM_ENABLED` | `true` | Master switch — `false` makes the module fully dormant (no routes/scheduler/webhooks) |
| `INSTAGRAM_MIRROR_TO_INBOX` | `true` | Mirror funnel DM threads into the shared Inbox |
| `INSTAGRAM_GRAPH_VERSION` | `v20.0` | Graph API version for all calls |
| `INSTAGRAM_QUEUE` | `instagram` | Queue name for module jobs |
| `INSTAGRAM_SENDS_PER_MINUTE` | `30` | Per-account outbound rate limit |
| `INSTAGRAM_MAX_NUDGES` | `1` | Keyword-reminder nudges when a reply doesn't match |

---

## Meta Hard Limits (all enforced in code + DB)

1. **ONE private reply per comment** — `funnel_participants.comment_id` DB-unique + stage guard. Hook, follow-ask and (if no gate) delivery are all embedded in that single message.
2. **7-day private-reply window** — `expires_at = comment + 7 days`; the 15-minute `CheckFunnelTimeoutsJob` expires stale participants and self-heals stranded sends (one retry per sweep while the window is open).
3. **Follow-ups only after the user replies, within 24h** — `dm_thread_opened_at` opens the window on the participant's first reply; sends outside the window are refused.
4. Inbox vs Request folder placement is decided by Instagram itself (follower status) — no follower-status API is used.

## Funnel Stages

`commented → dm_sent → awaiting_follow → replied → delivered` — terminal states: `delivered`, `expired`, `closed`. Rate-limited/transient failures are retried by the queue; permanent failures close the funnel; mid-send crashes are healed by the sweep.

---

## Module Map

```
app/Modules/Instagram/
├── InstagramServiceProvider.php     # auto-discovered; routes/webhook/scheduler/config
├── README.md                        # architecture + isolation guarantees
├── routes/web.php                   # client panel routes (app/instagram/*)
├── config → ../../config/instagram.php
├── database/migrations/             # 4 tables + media_ids
├── Models/                          # InstagramAccount, CommentAutomation, FunnelParticipant, CommentAutomationLog
├── Services/                        # CommentFunnelService (state machine), PrivateReplyService,
│                                    # LeadDeliveryService, KeywordMatcher, InboxMirrorService,
│                                    # FunnelTimeoutService, InstagramGraphClient
├── Jobs/                            # ProcessInstagramCommentJob, ProcessInstagramDmJob, CheckFunnelTimeoutsJob
└── Http/Controllers/                # ConnectController (OAuth), InstagramWebhookController,
                                     # AutomationController (CRUD + recent-posts), LogsController

resources/js/Pages/Instagram/        # Setup.jsx, Automations/{Index,Edit}.jsx, Logs/Index.jsx
tests/Feature/Instagram/             # InstagramFunnelTest.php (run on PHP 8.2+: php artisan test --filter=Instagram)
```

## Removal / Deletion Drill

1. `rm -rf app/Modules/Instagram` (optionally also `config/instagram.php`, `resources/js/Pages/Instagram/`)
2. Provider auto-discovery finds nothing → routes, webhook, scheduler vanish
3. Sidebar group disappears via its `route().has()` guard
4. Tables keep orphaned rows (harmless) or drop them: `php artisan migrate:rollback --step=4`
5. Everything else (Inbox, WhatsApp, Social) runs untouched.
