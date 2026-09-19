# WhatsApp Coexistence (V4) Complete Implementation Guide

This document is a comprehensive, deep-dive summary of everything we implemented to enable the Meta **WhatsApp Coexistence (Embedded Signup V4)** feature.

This feature allows businesses to connect their existing WhatsApp Business App numbers to the Cloud API without deleting the app. The number functions simultaneously on the mobile app and the CRM/SaaS platform.

> [!IMPORTANT]
> **System Stability Guarantee**
> All code changes were implemented in an additive and backwards-compatible manner. **Normal (standalone) WhatsApp numbers are not affected by these changes.** If a webhook arrives without coexistence payloads, the system processes it exactly as it did before.

> [!WARNING]
> **Known gap — `session_event` does not reach the backend.**
> The controller checks `$validated['session_event']` to detect a coexistence onboarding (see §2), but `handleWaEmbeddedCode` in `resources/js/Pages/Inbox/Setup.jsx` only sends `{ code, waba_id, phone_number_id }` — it drops the `session_event` that `waitForWabaSessionInfo()` resolves. As a result, coexistence onboardings currently take the **normal** onboarding path: `registerNumber()` runs and `SyncCoexistenceDataJob` is never dispatched, so contacts and history are never synchronized (Meta's 24-hour window lapses). The fix is a one-liner in `handleWaEmbeddedCode`'s POST body (plus accepting the field in validation, which the controller already does). Until that ships, §2's skip-register logic is effectively dead code.

---

## 1. Frontend Updates (React)

**File Modified:** `resources/js/Pages/Inbox/Setup.jsx`

To trigger the V4 Embedded Signup flow (which includes the Coexistence "Connect Existing Account" option), the Meta SDK initialization was updated:

- Added `featureType: 'whatsapp_business_app_onboarding'` to the `extrasMap`.
- Set `sessionInfoVersion: '3'`.
- **Event listener added:** `waitForWabaSessionInfo()` listens for the `WA_EMBEDDED_SIGNUP` `postMessage` from `https://www.facebook.com` and resolves with `{ waba_id, phone_number_id, session_event }` (the `session_event` being Meta's event type, e.g. `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` for coexistence onboarding, with a 120s timeout).

**Outstanding:** `handleWaEmbeddedCode()` must pass `session_event` through in the POST body to `client.whatsapp.setup.embedded-signup`. See the warning at the top of this document.

---

## 2. Backend Embedded Signup Flow

**File Modified:** `app/Modules/Whatsapp/Http/Controllers/WhatsappEmbeddedSignupController.php`

The controller validates an optional `session_event` field and adjusts the onboarding flow when it is `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`:

- **Skipping phone registration:**
  Coexistence numbers are *already registered*. Attempting to re-register them via the Cloud API fails. When the session event indicates coexistence, the code skips `$this->registerNumber()`.
- **Dispatching the Sync Job:**
  In the same branch, it dispatches `SyncCoexistenceDataJob` (see §3) on the `whatsapp` queue to initiate the background sync within Meta's required 24-hour window.

Note: this branch only executes when the frontend actually sends `session_event` (see the warning at the top). The controller-side validation for the field is already in place.

---

## 3. Background Data Synchronization

**File Created:** `app/Modules/Whatsapp/Jobs/SyncCoexistenceDataJob.php`

When a Coexistence number is onboarded, Meta requires the platform to explicitly request historical data (messages and contacts) via the Graph API.

- The job fires `POST` requests to `https://graph.facebook.com/v20.0/{business_phone_number_id}/smb_app_data` with `messaging_product: whatsapp`. (Meta's docs reference newer Graph versions, e.g. v25.0; the codebase standardizes on v20.0 everywhere, so the job matches.)
- **Contacts Sync:** sends `sync_type: smb_app_state_sync` to instruct Meta to begin sending the address book contacts. Future contact changes continue to arrive as `smb_app_state_sync` webhooks.
- **History Sync:** sends `sync_type: history` to instruct Meta to stream past chat history in chunked webhooks (up to 180 days, depending on what the business agreed to share).

Each sync type can only be initiated **once** per onboarding; repeating requires the customer to offboard and redo Embedded Signup. Failure responses are logged (not retried), so monitor `whatsapp` queue logs during onboarding.

---

## 4. Webhook Payload Processing

**File Modified:** `app/Modules/Whatsapp/Services/WhatsappDriver.php`

The webhook parser (`processWebhookPayload`) was extended to handle the new payloads introduced by Coexistence.

### A. Message Echoes (`smb_message_echoes`)

When a business owner manually replies to a customer via their mobile WhatsApp Business App, Meta fires a webhook so our CRM can show that outbound message.

- **Payload shape:** the webhook field is `smb_message_echoes`, but the array inside the payload is `message_echoes`.
- **Logic:** the driver merges `$value['messages']` and `$value['message_echoes']` into a single array before processing, so inbound messages and manual outbound echoes are digested through the same code path.

**Known caveats:**

- **Fixed — direction-aware echoes:** `processInboundMessage()` now detects outgoing echoes (`smb_message_echoes` field, or `from` equal to the business's `display_phone_number`) and stores them with `direction: 'out'`, `sent_by: 'human'`, source `whatsapp_echo`, on the **customer's** conversation (`msg.to`). No unread bump, `last_inbound_at` untouched, and `MessageSent` is dispatched instead of `MessageReceived`, so chatbots/automations never react to the business's own app replies. Recipient-less echoes are skipped gracefully. Covered by `tests/Feature/Meta/WhatsappCoexistenceEchoTest.php`.
- Inbound webhook idempotency is enforced by `WebhookIdempotencyService` on the Meta message ID (`whatsapp_msg`), so duplicate deliveries (e.g. an echo arriving for a message also mirrored by Cloud API) do not create duplicate rows.

### B. App State Sync (`smb_app_state_sync`)

The driver intercepts the contacts address book sync from the mobile app and currently **logs the payload only** — contact records are not upserted into the CRM yet. This lays the groundwork for automatic contact upserts (map `contact.full_name` / `first_name` / `phone_number`, honoring the `action: add|remove` semantics).

### C. History Webhooks (`history`)

The driver intercepts bulk history chunks (up to 180 days, three phases, ordered by `chunk_order` with `progress` up to 100) and currently **logs and skips them** — historical messages are *not* persisted into conversations.

Meta does not allow date filters on the sync request, so any retention filtering must be done server-side. If/when we persist history, the handler should:

- buffer the whole payload first and process asynchronously (single webhooks can describe thousands of messages),
- discard messages older than a chosen threshold (e.g. 7 days) to protect the DB from load spikes, and
- handle `media_placeholder` entries, whose actual contents arrive in follow-up `history` webhooks (only for media sent within 14 days of onboarding).

### D. WABA-Level Events & Graceful Handling

Coexistence introduces account-level events that may omit `phone_number_id`:

- `ACCOUNT_OFFBOARDED` and `ACCOUNT_RECONNECTED`, and
- `PARTNER_REMOVED` (with optional `disconnection_info.reason` / `initiated_by`).

All three are delivered **inside the `account_update` field** as `event` values — they are not webhook fields of their own.

**Fix applied:** these payloads do not contain a `phone_number_id`. The previous logic discarded them; `processPhoneNumberUpdate()` now recognizes these events, logs them (including `disconnection_info`), and returns without requiring a phone number. Status flips on `WhatsappBusinessAccount` / `ChannelAccount` are a natural follow-up but are not implemented yet.

### E. Edit and Revoke Messages

The driver receives `type: "edit"` and `type: "revoke"` messages (which occur when users edit/delete messages on the mobile app, or WhatsApp users edit/revoke in the 15-minute / two-day windows).

**Current behavior:** these types are not in the driver's `$allowedTypes` list, so they are stored as `type: 'unsupported'` with the full payload in `payload` (the `edit.original_message_id` / `revoke.original_message_id` are not applied to existing messages). They are logged, stored gracefully, and do not crash processing or raise "unsupported message type" errors. Rendering and applying edits/revokes in the inbox UI is not implemented.

---

## 5. Webhook Field Subscription (Automated)

No manual Dashboard configuration is required. The Meta App's webhook subscription for the `whatsapp_business_account` object is registered programmatically with the **complete field list**, defined once in `CloudApiClient::WEBHOOK_SUBSCRIPTION_FIELDS` and used by every registrar:

- `WhatsappEmbeddedSignupController::subscribeWabaWebhooks()` (on every embedded signup), and
- the `whatsapp:register-webhook` artisan command.

This matters because **POST /{app_id}/subscriptions replaces the entire fields list on each call**. If any registrar sent a partial list, the coexistence fields would silently disappear the next time any client connects. The shared constant prevents that.

Subscribed fields:

- `messages` (edit/revoke arrive as message types inside this field)
- `message_template_status_update`
- `phone_number_name_update`
- `phone_number_quality_update`
- `account_update` (carries `ACCOUNT_OFFBOARDED`, `ACCOUNT_RECONNECTED`, `PARTNER_REMOVED` as `event` values)
- `history`
- `smb_app_state_sync`
- `smb_message_echoes`

> [!NOTE]
> `ACCOUNT_OFFBOARDED`, `ACCOUNT_RECONNECTED`, and `PARTNER_REMOVED` are **event types inside the `account_update` field**, not separate webhook fields (per Meta's account_update webhook reference). Subscribing to `account_update` is sufficient to receive them — the driver already parses them from the `event` key. If the subscription was created before this fix, re-run `php artisan whatsapp:register-webhook` once to add the coexistence fields.

---

## 6. Known Gaps & Follow-ups

In priority order:

1. **Send `session_event` from the frontend** (blocking — see warning at top). Without it, coexistence onboarding takes the normal path and sync never starts.
2. **Persist history webhooks** (§4C) — currently logged only; without this the "mirror chat history" promise of coexistence is unrealized. Buffer + async processing + age cutoff recommended.
3. **Upsert contacts from `smb_app_state_sync`** (§4B) — currently logged only.
4. ~~**Handle echo direction** (§4A)~~ — **FIXED**: echoes now store as outbound (`direction: 'out'`) on the customer's conversation and fire `MessageSent` only.
5. **Apply edit/revoke events** (§4E) — update or soft-delete the referenced messages instead of storing them as `unsupported`.
6. **React to account-level events** (§4D) — flip WABA/channel status on `PARTNER_REMOVED` / `ACCOUNT_OFFBOARDED` / `ACCOUNT_RECONNECTED`.
7. **Support chatbot decision** — coexistence requires subscribing to `smb_message_echoes`; confirm no chatbot reacts to echoed outbound messages before enabling broadly.
