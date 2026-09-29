# whatscrm (Node.js) — QR Webhook Signing Deploy Note

**Required for:** QR (Baileys) webhook HMAC authentication, shipped on the Laravel side of WhatsMine.

Until the Node service ships these changes, QR webhooks keep working in **non-production** Laravel
environments (warnings are logged). In **production**, unsigned QR webhooks are rejected with `401`
as soon as this Laravel update deploys — so deploy the Node update together with (or before) the
Laravel update in production.

## 1. Store the per-session webhook secret

Laravel now generates a 64-char random secret per QR session and sends it in the create call:

```
POST {WHATSCRM_URL}/api/qr/laravel/create
{
  "sessionId": "qr_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "title": "WhatsApp",
  "webhookSecret": "<64-char random string>"
}
```

The Node service must accept the new `webhookSecret` field and store it with the session
(e.g. `session.webhookSecret = body.webhookSecret`), the same place it already keeps
`sessionId`, session state and credentials.

## 2. Sign every webhook call to Laravel

For **both** endpoints Laravel exposes:

- `POST /webhooks/qr/{sessionId}`
- `POST /webhooks/qr/{sessionId}/sync-status`

send these headers on every request:

```
X-Qr-Signature: sha256=<hex of HMAC_SHA256(rawBody, webhookSecret)>
X-Qr-Timestamp: <unix seconds when the request is sent>
```

Example (Node):

```js
const crypto = require('crypto');

function signHeaders(session, rawBody) {
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = 'sha256=' +
    crypto.createHmac('sha256', session.webhookSecret).update(rawBody).digest('hex');

  return { 'X-Qr-Signature': signature, 'X-Qr-Timestamp': timestamp };
}

// IMPORTANT: sign the EXACT raw body string that is sent — not a re-serialized object.
await fetch(`${laravelUrl}/webhooks/qr/${session.id}`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    ...signHeaders(session, rawBody),
  },
  body: rawBody,
});
```

Notes:

- The HMAC must be computed over the **raw request body bytes**, not a re-serialized JSON object.
- `X-Qr-Timestamp` must be within ±5 minutes of Laravel's clock (replay protection). Keep the
  server clock synced (NTP).
- On `401` responses Laravel logs the exact reason (`missing_signature`, `invalid_signature`,
  `stale_timestamp`, `missing_webhook_secret`) — check the Laravel log when integrating.

## 3. Optional shared-secret mode (no Node changes to storage)

If you prefer not to store per-session secrets, set one shared secret on **both** sides:

- Laravel: `WHATSCRM_WEBHOOK_SECRET=<same value>` in `.env`
- Node: use that value instead of `session.webhookSecret` when signing.

A shared secret configured on the Laravel side always wins over the per-session secret.
Per-session secrets are still recommended (a leaked shared secret exposes every session).

## 4. Legacy sessions (upgrade path)

- Old sessions created before this change have no stored secret on the Laravel side.
- In **non-production** Laravel environments, the first unsigned webhook call auto-provisions a
  per-session secret and logs a warning — but the Node service was not told that secret, so its
  next signed call would fail. After upgrading Laravel, create new sessions (or trigger any
  webhook once in dev) and restart/recreate Node sessions so both sides share the new secret.
- In production, legacy sessions without a secret are rejected with
  `401 missing_webhook_secret` — recreate the session from the WhatsApp QR screen.

## 5. Rollout checklist

1. Deploy the updated Node service (accepts `webhookSecret`, signs all webhook calls).
2. Deploy the Laravel update (migration `2026_09_29_000001_add_webhook_secret_to_whatsapp_qr_sessions_table.php` runs automatically with `php artisan migrate`).
3. Create a fresh QR session and confirm inbound messages flow and
   `storage/logs/laravel.log` shows no `QR webhook` warnings.
4. Only then enable production traffic — Laravel fails closed there.
