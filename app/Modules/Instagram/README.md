# Instagram Comment-Automation Module

A **fully separate** module (`App\Modules\Instagram`) that automates the comment → DM funnel on Instagram professional accounts:

> Someone comments on a post/reel → the bot sends **one private-reply DM** (Meta Private Replies API) → the DM asks them to follow and reply with a keyword → their reply opens the **24-hour window** → the bot delivers the "required thing" (link / file / message). The thread is mirrored into the shared Inbox so a human can take over at any point.

## Isolation guarantees (why nothing else can break)

| Concern | How it is satisfied |
|---|---|
| Provider registration | The app's `ModuleServiceProvider` auto-discovers `app/Modules/*/​{Name}ServiceProvider.php`. **Zero shared-file edits.** |
| Deletion | Delete this folder → provider not discovered, nav item disappears (it is guarded by `route().has('client.instagram.setup')` in `useClientNav.jsx`), webhook/scheduler/queue go silent. Nothing else references the module. |
| Webhook | Its **own endpoint** `/webhooks/instagram/{verify_token}` — never touches the Inbox module's `/webhooks/meta` endpoint. |
| Database | 4 own tables with complete `down()` methods; no columns added to core tables. |
| Queue | Jobs run on the dedicated `instagram` queue — a stalled worker cannot delay WhatsApp/Inbox jobs. |
| Config | Own namespace `config/instagram.php` (`INSTAGRAM_*` env keys) with an `INSTAGRAM_ENABLED` master switch — `false` makes the module dormant without deleting anything. |
| Inbox bridge | One-directional and best-effort: the funnel mirrors threads into the Inbox (guarded by try/catch + `INSTAGRAM_MIRROR_TO_INBOX`). If the Inbox integration changes, the funnel keeps working. |
| Shared DMs | The app-level `instagram` webhook object has ONE callback URL, so the module's endpoint receives `messaging` events too and forwards them into the Inbox pipeline (class-guarded). The forwarding uses the Inbox driver's own `processWebhookPayload()` so behaviour is identical. |

## Meta hard limits (all enforced in code + DB)

1. **ONE private reply per comment** — `funnel_participants.comment_id` is a DB unique key *and* the participant stage guard blocks a second send. Everything (hook, follow ask, delivery when no gate) is embedded in that single message.
2. **7-day private-reply window** — `expires_at = comment time + 7 days`, checked before every send; the scheduled `CheckFunnelTimeoutsJob` expires stale participants.
3. **Follow-ups only after the user replies, within 24h** — `dm_thread_opened_at` records when the participant's reply arrives; the state machine refuses sends outside the window and closes stale threads.
4. The reply lands in the commenter's **Inbox** (if they follow the account) or **Request** folder (if not) — Instagram decides; no follower-status API is needed or used.

## Setup

1. **Meta App** — add the scopes `instagram_manage_comments`, `instagram_manage_messages`, `instagram_basic` to the Facebook Login for Business config (`config_id_social` in Admin → Integrations → Meta App).
2. **Connect** — Client panel → **Instagram → Connect**: the button opens Facebook OAuth (`feature_type: instagram_management`). On redirect the module exchanges the code for a long-lived token, fetches `/me/accounts`, stores each IG professional account in `instagram_accounts` (page token encrypted) and registers the app webhook subscription (object `instagram`, fields `comments, messages, messaging_postbacks, message_reactions`).
3. **Create an automation** — Instagram → Comment Automations → New: trigger (keywords / all / mentions + media filter) → private reply text with follow gate → delivery (link / file / text).
4. **Deploy** — `php artisan migrate` (adds the 4 module tables only) and run a worker for the module queue:
   ```bash
   php artisan queue:work --queue=instagram,default
   ```

## Configuration reference

| Env key | Default | Meaning |
|---|---|---|
| `INSTAGRAM_ENABLED` | `true` | Master switch — `false` makes the module fully dormant. |
| `INSTAGRAM_MIRROR_TO_INBOX` | `true` | Mirror funnel threads into the shared Inbox. |
| `INSTAGRAM_GRAPH_VERSION` | `v20.0` | Graph API version. |
| `INSTAGRAM_QUEUE` | `instagram` | Queue name for module jobs. |
| `INSTAGRAM_SENDS_PER_MINUTE` | `30` | Per-account send rate limit. |
| `INSTAGRAM_MAX_NUDGES` | `1` | Keyword-reminder nudges when the reply doesn't match. |

## Deletion drill (verified design)

1. `rm -rf app/Modules/Instagram` (plus, optionally, `config/instagram.php` and the `resources/js/Pages/Instagram` pages).
2. The module's provider is no longer discovered → its routes, webhook endpoint, config and scheduler vanish.
3. The sidebar group disappears automatically (`route().has()` guard in `useClientNav.jsx`) — the only shared-file touch.
4. The four tables keep orphaned rows (harmless) or drop them with `php artisan migrate:rollback --step=4`.
5. Boot the app: Inbox, WhatsApp, Social and every other module run exactly as before — nothing references the deleted classes.

## Tests

`tests/Feature/Instagram/InstagramFunnelTest.php` covers: keyword matcher modes, webhook verify/reject, job dispatch + queue names, the no-gate funnel (one send, delivery embedded), the follow-gate flow (reply → 24h window → delivery), duplicate-comment one-reply enforcement, no-match logging, non-funnel DM forwarding to the Inbox pipeline, the 7-day timeout job, and module config isolation.
