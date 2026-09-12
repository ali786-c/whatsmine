# Instagram Module — Complete Deep Dive

Analysis of every Instagram-related function in the codebase, derived from reading the source. Instagram is implemented in **two fully separate integrations** that share nothing:

| # | Integration | What it does | Driver |
|---|-------------|--------------|--------|
| 1 | **Instagram DMs (Inbox)** | 1:1 direct messages with customers inside the shared Inbox, with automations/AI | `app/Modules/Inbox/Services/InstagramDriver.php` |
| 2 | **Instagram Publishing (Social)** | Scheduling & publishing feed posts (image + caption) to an IG professional account | `app/Modules/Social/Services/Drivers/InstagramSocialDriver.php` |

---

## 1. Instagram DMs (Inbox)

### 1.1 Registration & lifecycle

`InboxServiceProvider::boot()` registers the driver with the channel manager so outbound sends resolve by channel name:

```php
$manager->register('instagram', InstagramDriver::class);
```

### 1.2 Connecting an account — `InboxSetupController::embeddedSignupInstagram()`

Route: `POST /setup/embedded-signup/instagram` (`client.inbox.setup.embedded-signup.instagram`).

Flow, step by step:

1. **Validates** the OAuth `code` (max 2048 chars) and that Meta App credentials exist.
2. **`registerInstagramAppWebhook()`** — registers the app-level webhook subscription for `object=instagram` against `POST /{app_id}/subscriptions` using the App Access Token (`app_id|app_secret`):
   - callback: `route('webhooks.meta.receive', ['token' => verifyToken])` → `/webhooks/meta/{verify_token}`
   - fields: `messages, messaging_postbacks, message_reactions`
   - reads back `GET /{app_id}/subscriptions` and logs a snapshot so you can confirm the subscription is active.
3. **`exchangeCodeForToken()`** → short-lived token, then **`exchangeForLongLivedToken()`** → 60-day token.
4. Fetches `GET /me/accounts` with `fields=id,name,access_token,instagram_business_account{id,name,username}` (limit 50).
5. For **every page that has an `instagram_business_account`**:
   - **`subscribePageToInstagram()`** — `POST /{page_id}/subscribed_apps` with `subscribed_fields=messages,messaging_postbacks,message_reactions,message_reads`. Without this, Meta never delivers inbound IG messages (the connect flow previously skipped it and no messages ever arrived — documented in code).
   - Persists a `ChannelAccount` in a **fixed shape** (mirrors `InboxDemoSeeder` and what `InstagramDriver` expects):
     - `credentials.access_token` → **page token** used by `send()`
     - `credentials.instagram_account_id` → IG account id
     - `meta_json.instagram_page_id` → IG account id (**matches webhook `entry.id`**)
     - `meta_json.instagram_account_id` → same, diagnostic copy
     - `meta_json.facebook_page_id` → the FB page id
   - Re-connect **merges** `meta_json` instead of replacing, preserving keys like an assigned `ai_chatbot_id`, and refreshes the token.
6. If zero pages had IG accounts, returns an actionable 422: link IG to the FB Page in Meta Business Suite, ensure IG is a **Professional (Business/Creator)** account, and ensure the Social embedded-signup config includes `instagram_basic`.

**Frontend** (`resources/js/Pages/Inbox/Setup.jsx`): the Instagram button goes through a **full-page OAuth redirect** (not FB.login): `facebook.com/v20.0/dialog/oauth` with `config_id`, `extras = { feature_type: 'instagram_management' }`, `state` stored in `sessionStorage` (`meta_social_oauth`), redirect back to `/app/inbox/setup` where `handleEmbeddedCode` POSTs the code.

### 1.3 Sending — `InstagramDriver::send()`

- Credentials read from the conversation's `channelAccount.credentials` (`access_token`, `instagram_account_id`); recipient = `conversation.external_thread_id` (the customer's IGSID).
- **Text** → `POST /{ig_account_id}/messages` with `{recipient:{id}, message:{text}, messaging_type: RESPONSE}`.
- **Image** → sends the photo as an attachment (`type:image, payload:{url, is_reusable:true}`) **then the caption as a separate follow-up text message** — an IG attachment carries no text. (Inbox product-share cards rely on this; see `InboxController` line ~289.)
- **`postMessage()` fallback (important):** if `/{ig_account_id}/messages` fails, it retries via **`POST /me/messages`** (Page endpoint) with the same payload. This is what makes sends work on **Meta TEST / Facebook-Login-based connections**, where IG DMs must go through the linked Page. Normal accounts succeed on the primary path and are unaffected.
- **Permission error mapping:** error code **(#3)** ("app lacks capability") from either attempt is converted into a human instruction: add `instagram_manage_messages` to the Instagram embedded-signup config, get Advanced Access, set app Live, reconnect. (Receiving works without the permission — only sending fails — which is why inbound already works in that state.)
- Every failure path logs `ig_account_id`, recipient, error code and message before falling back.

### 1.4 Receiving — webhook pipeline

**`MetaWebhookController`** (both instagram & page objects):

- `verify()` — GET handshake; returns `hub_challenge` when `hub_mode=subscribe` and the token matches the system Meta verify token; otherwise 403.
- `receive()`:
  1. Verify token check (403 on mismatch).
  2. **X-Hub-Signature-256 HMAC-SHA256 check** against the app secret (401 on mismatch; in production a missing secret is a hard 401 + `meta.webhook.no_secret` critical log).
  3. Objects other than `instagram`/`page` are acknowledged and ignored.
  4. **Per-event idempotency** via `entryEventKey()`: hashes the message/reaction/read `mid`s inside `entry.messaging` (sorted), *not* `entry.id` (which is the account id and constant for every webhook — keying on it would drop everything after the first message). Entries with no identifiable event hash their `messaging`/`changes` blob; fully empty → `null` → fail-open processing.
  5. Responds `200 ok` immediately (flush) then dispatches **`ProcessInboundInboxMessageJob`** on the `whatsapp` queue, which routes by object: `instagram → InstagramDriver::processWebhookPayload()`, `page → MessengerDriver`.

**`InstagramDriver::processWebhookPayload()`**:

- Iterates `entry[]` × `entry.messaging[]` with extensive logging (entry count/ids, per-entry messaging count, processed count).
- Skips events without `message` (postbacks/read receipts/reactions are ignored — only DMs are ingested).
- **Idempotency** on `message.mid` via `WebhookIdempotencyService::isNewEvent('instagram', mid)` — duplicates skipped.
- **Echo handling** (`message.is_echo = true`) — messages the business sent from the Instagram app or another tool:
  - Thread is keyed on the **recipient** (the customer), stored `direction: 'out'`, `status: 'sent'`, `sent_by: 'human'`.
  - Skips echoes missing a recipient, with no matching channel account, or whose `mid` already exists in `messages` (a platform-sent message echoing back is never double-stored).
  - Updates `last_message_at`/`status` but does **not** bump `unread_count`.
- **Inbound** (`processInboundMessage`):
  - Matches the channel account by webhook `entry.id` against **either** persisted key (`meta_json->instagram_page_id` OR `meta_json->instagram_account_id`) via `whereJsonContains`, so the account is found regardless of storage shape. No match → detailed warning including `known_ids` of every IG account, message dropped (returns null, HTTP still 200).
  - Falls back through nothing else — no workspace-level guess (unlike WhatsApp), which is correct for IG since the IGSID is only usable with the right account.
  - Creates/updates **Contact** and **Conversation** (see 1.5), then a `Message` row: `type: 'text'`, `payload: $event`, `provider_message_id: mid`, `sent_at: now()`.
  - Conversation: `last_message_at`, `status: 'open'`, `unread_count + 1`.
  - Dispatches **`MessageReceived`** (automations/AI listen on this).

### 1.5 Contact resolution — `resolveInstagramContact()`

- Keyed on the stable **IGSID** stored in `contact.custom_fields.instagram_psid` — repeat messages map to ONE contact instead of creating a new "Unknown" per message.
- **Profile enrichment** via `fetchSenderProfile()`: `GET /{igsid}?fields=name,username,profile_pic` with the page token (requires `instagram_manage_messages`; failures are logged and swallowed — messaging works without the profile):
  - Splits `name` into first/last on create.
  - Stores `custom_fields.instagram_username`.
  - On repeat messages backfills name only when the contact **has no name yet** (never clobbers manual edits) and refreshes the username when changed.
  - Avatar synced from `profile_pic` through `ContactService::syncAvatarFromUrl()` (shared with WhatsApp/Messenger).
- New contacts dispatch **`ContactCreated`**.

### 1.6 Outbound capability matrix (current limits)

| Feature | Supported |
|---|---|
| Text | ✅ |
| Image + caption (split into 2 messages) | ✅ |
| Generic template / buttons / carousel | ❌ (no interactive support) |
| Reactions, read receipts, typing indicators | ❌ (received events ignored; never sent) |
| Media download of customer-sent images | ❌ (inbound `type` is always forced to `text`; an image DM shows an empty body) |

### 1.7 Automation/AI integration

`AutomationEngine` treats instagram as a first-class channel but with platform-driven restrictions encoded explicitly:

- **Image-only media**: `in_array($channel, ['messenger','instagram']) && $type !== 'image'` → `skipped: "{channel} supports image media only."`
- **No proactive first contact**: IG threads can only start after the customer messages first — `resolveChannelTarget()` returns a soft error `"No open instagram conversation with this contact — a instagram thread can only start after the contact messages first."` when no thread exists (IGSID lives on `conversation.external_thread_id`).
- Outbound channel selection prefers the contact's most recent conversation on that channel (replies continue on the same account/thread).
- Question/wait steps work over IG by parking the run until the next inbound message.

---

## 2. Instagram Publishing (Social module)

### 2.1 OAuth — `OAuthManager`

- `instagram` shares the **Facebook OAuth** path: `facebook.com/v19.0/dialog/oauth`.
- Scopes: **`instagram_basic, instagram_content_publish, pages_show_list`**.
- Token exchange via the same FB code-exchange (`facebookExchange`). No refresh: *"Facebook/Instagram tokens are long-lived; use token extension instead."*

### 2.2 Account verification — `InstagramSocialDriver::fetchAccountInfo()`

`GET graph.instagram.com/me?fields=id,name,profile_picture_url` → returns `account_id`, `name`, `picture_url` for the `SocialAccount` row.

### 2.3 Publishing — `InstagramSocialDriver::publish()` (classic 2-step container flow)

1. **Container**: `POST graph.facebook.com/v19.0/{ig_user_id}/media` with `caption` + `image_url` (first non-empty of `media_urls`). Requires at least one image, else `RuntimeException('Instagram posts require at least one image.')`. Container id missing → error with Meta's JSON body.
2. **Publish**: `POST graph.facebook.com/v19.0/{ig_user_id}/media_publish` with `creation_id`. Returns the published media id.

`SocialPublisher` wires this driver into its driver map and moves `SocialPost` status `→ publishing → (posted/failed)` per account (`SocialPostAccount`).

### 2.4 Publishing limits (current)

- Single image only — **no video/Reels, no carousel** (only `media_urls[0]` used), no stories, no first-comment, no product tagging.
- Uses Graph **v19.0** here vs **v20.0** everywhere else in the repo.

---

## 3. Data model summary

```
ChannelAccount (channel='instagram', provider='meta')
├── credentials: { access_token: <page token>, instagram_account_id: <ig id> }
├── meta_json:   { instagram_page_id: <ig id>, instagram_account_id: <ig id>, facebook_page_id: <page id> }
└── used by: send() (credentials), webhook matching (meta_json ids), Setup UI

Contact
├── custom_fields.instagram_psid      ← stable thread key (IGSID)
├── custom_fields.instagram_username  ← refreshed on each message
└── avatar ← syncAvatarFromUrl(profile_pic)

Conversation
├── external_thread_id = IGSID (customer id, inbound or echo-recipient)
└── channel_account_id pins the thread to the IG account

Message
├── channel 'instagram', direction in|out, type 'text' (always, today)
├── payload = raw webhook event
└── provider_message_id = message.mid (echo + inbound dedupe)
```

Webhook endpoints involved: `GET/POST /webhooks/meta/{verify_token}` (`webhooks.meta.receive`) — one endpoint serves `object=instagram` **and** `object=page` (Messenger).

---

## 4. Deep-check findings — gaps & risks found while reading

1. **Inbound attachments are degraded.** `processInboundMessage()` hardcodes `type: 'text'` and body from `$event['message']['text']` only. A customer sending an image/voice creates a message with an **empty body** (payload still saved). Fix: map `message.attachments[0].type` (image/video/audio) and use `payload.url`.
2. **Echo dispatches `MessageReceived`.** `processEchoMessage()` fires `MessageReceived` for an **outbound** message. Any automation/chatbot listening to `MessageReceived` without filtering `direction` can trigger on the business's own Instagram-app replies (self-reply loop risk). Same class of bug exists in the WhatsApp coexistence echo path.
3. **App-level IG webhook fields are partial.** `registerInstagramAppWebhook()` subscribes `messages, messaging_postbacks, message_reactions` — no `message_reads`/`message_deliveries` at the app level (page-level subscription has `message_reads`, but Meta delivers instagram-object fields per the app subscription). Read receipts never arrive. Reminder: `POST /{app_id}/subscriptions` **replaces the whole field list** per object — if this call ever adds a field, keep the full set in one place.
4. **24-hour messaging window is not handled.** Sends always use `messaging_type: RESPONSE` but nothing checks the 24h human-agent window; messages outside the window will fail with a Meta error surfaced as a generic send failure.
5. **Social publish ignores everything after the first image** and has no video/Reels/carousel support; also pinned to Graph v19.0 (inconsistent with v20.0 elsewhere).
6. **`verifyCreds()` is a stub** (`return true`) — acceptable, it's part of `ChannelDriverInterface`, but means no connection health check exists for IG.

---

## 5. Quick reference — where to look

| Task | File / symbol |
|---|---|
| Send a DM | `Inbox/Services/InstagramDriver.php :: send() / postMessage()` |
| Ingest webhook | `Inbox/Services/InstagramDriver.php :: processWebhookPayload()` |
| Echo (out-from-app) | `InstagramDriver :: processEchoMessage()` |
| Contact profile/avatar | `InstagramDriver :: resolveInstagramContact() / fetchSenderProfile()` |
| Connect flow | `Inbox/Http/Controllers/InboxSetupController.php :: embeddedSignupInstagram()` |
| App webhook registration | `InboxSetupController :: registerInstagramAppWebhook()` |
| Page subscription | `InboxSetupController :: subscribePageToInstagram()` |
| Webhook verify/signature/dedupe | `Inbox/Http/Controllers/MetaWebhookController.php` |
| Queue routing | `Inbox/Jobs/ProcessInboundInboxMessageJob.php` |
| Driver registration | `Inbox/InboxServiceProvider.php` |
| Publish a post | `Social/Services/Drivers/InstagramSocialDriver.php :: publish()` |
| Social OAuth scopes | `Social/Services/OAuth/OAuthManager.php :: facebookAuthUrl()` |
| Automation rules | `Automation/Services/AutomationEngine.php` (image-only, no-proactive-contact) |
| Connect UI | `resources/js/Pages/Inbox/Setup.jsx` (`instagram_management` feature type) |
