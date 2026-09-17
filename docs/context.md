# WhatsMine Inbox Fix — Context & Summary

## Problem
Messages were showing in the Node.js terminal (`[QR-Laravel] Forwarding to Laravel: ... text Hi`) but **not appearing in the WhatsMine Inbox** (Laravel frontend at `localhost:8000/app/inbox`).

## Root Cause Analysis

### How the Message Flow Works
```
WhatsApp message → Baileys (Node.js) → Two parallel paths:
  1. processMessage() → saves to Node.js local DB (beta_conversation, beta_chats)
  2. forwardToLaravel() → POST to Laravel /webhooks/qr/{sessionId} → saves to Laravel DB (conversations, messages)
```
The WhatsMine Inbox frontend reads from Laravel's `Conversation` model, so only path #2 matters.

### Why Messages Were Dropped
The `QrWebhookController::receive()` had two guard checks that silently returned `{"status": "ignored"}` (still HTTP 200!):
1. `status = 'active'` check — session must have `status = 'active'` in `whatsapp_qr_sessions`
2. `channelAccount` must exist — session must have a linked `channel_accounts` row

The `channelAccount` was only created when `QrSessionManager::checkStatus()` was called from frontend polling. If the frontend hadn't polled status after Baileys connected, the channel account never existed and all messages were silently dropped.

The Node.js side only logged `Laravel response: 200` — it couldn't distinguish between successful processing and ignored messages.

## Files Modified

### 1. `WhatsMine150/app/Modules/WhatsappQR/Http/Controllers/QrWebhookController.php`
**Changes:**
- **Relaxed guard**: Changed `->where('status', 'active')` to `->where('status', '!=', 'logged_out')`
- **Auto-promote**: When messages arrive and session isn't active, auto-promotes to `active`
- **Auto-create channel account**: New `ensureChannelAccount()` method creates missing `channel_accounts` row on the fly
- **New `syncStatus()` endpoint**: `POST /webhooks/qr/{sessionId}/sync-status` — Node.js calls this when a session connects

### 2. `WhatsMine150/routes/webhooks.php`
**Changes:**
- Added route: `POST webhooks/qr/{sessionId}/sync-status` → `QrWebhookController@syncStatus`
- Placed BEFORE the catch-all `webhooks/qr/{sessionId}` route

### 3. `whatscrm/helper/addon/qr/index.js`
**Changes:**
- **Fix 2**: `forwardToLaravel()` now logs the response body (not just status code) — you can see `{"status":"ok","processed":1}` vs `{"status":"ignored"}` in terminal
- **Fix 3**: When a Baileys session connects (`connection === "open"`), Node.js now calls the Laravel sync-status endpoint to update session status and create channel account immediately

## How It Was Implemented

### Node.js Side (`whatscrm/helper/addon/qr/index.js`)
- Modified `forwardToLaravel()` to read and log response body
- Added `fetch()` call to `/webhooks/qr/{sessionId}/sync-status` in the `connection.update` handler when `connection === "open"`
- Uses `process.env.LARAVEL_URL || "http://localhost:8000"` as base URL

### Laravel Side
- `QrWebhookController.php` rewritten with correct PHP backslashes (was broken by shell escaping during implementation)
- `webhooks.php` rewritten cleanly (had duplicate route lines causing syntax error)
- Both files written via Node.js script files (`fix_php.js`, `fix_routes.js`) to avoid shell escaping issues

## Issues During Implementation
1. **Shell escaping**: Heredoc and `node -e` approaches kept breaking PHP backslashes in namespace/use statements. Fixed by writing content as JavaScript arrays in separate `.js` script files.
2. **Duplicate routes**: Earlier attempts to modify `webhooks.php` via string replacement created duplicate `Route::post` lines. Fixed by rewriting the entire file.
3. **Opcache**: User's Laravel may cache old routes — `php artisan optimize:clear` needed if syntax errors persist.

## To Activate
1. Clear Laravel cache: `php artisan optimize:clear`
2. Restart Laravel server
3. Restart Node.js server: `node app.js`
4. Send a test WhatsApp message — should see `{"status":"ok","processed":1}` in terminal
5. Message should appear in WhatsMine Inbox

## Additional Issues Found (Not Yet Fixed)
- Many messages show "Unsupported message type in Baileys webhook" — these are message types not handled by `processBaileysMsg()` in `processThings.js` (e.g., `interactiveMessage`, `stickerMessage`, `viewOnceMessage`, `protocolMessage`). They're still forwarded to Laravel with empty body.
- Some messages have `senderName: null` — contacts created with no name, only phone number visible.

---

## cPanel Deployment & Meta Integration Enhancements

### 1. cPanel Deployment Configuration
* **Deploy Path Mismatch:** Verified that the user's home directory is `/home/devwithguru`. Reverted `.cpanel.yml` back to `devwithguru` to ensure correct syncing.
* **Manual Deployment Execution:** Explained that cPanel's "Update from Remote" only updates the hidden Git repository folder. Users must click **"Deploy HEAD Commit"** to run `.cpanel.yml` and sync code to the live subdomain folder (`wa.careerinpak.com`).
* **Composer Mismatch:** Since `composer` was not installed globally, guided the user to download `composer.phar` locally using `curl -sS https://getcomposer.org/installer | php` and run it using the PHP 8.3 CLI binary: `/opt/alt/php83/usr/bin/php composer.phar install`.
* **Laravel Initialization:** Generated the application key (`php artisan key:generate`) and set up the `.env` template on the server.

### 2. Config ID Conflict Warning Fix
* **File Modified:** `WhatsMine150/app/Modules/Integrations/Models/IntegrationConfig.php`
* **Changes:** Updated `maskedCredentials()` to only mask fields marked as type `password` (e.g., `app_secret`, `system_user_token`). Public fields (like `app_id`, `config_id_whatsapp`, and `config_id_social`) are returned as plain text.
* **Why:** Previously, all credentials were masked as `••••••••••••`, making the frontend compare `•••••••••••• === `••••••••••••` and falsely trigger a "Config ID conflict" warning even if the actual database values were different.

### 3. Meta Token Exchange Fallback & Dedicated Logging
* **Files Modified:**
  * `WhatsMine150/app/Modules/Inbox/Http/Controllers/InboxSetupController.php`
  * `WhatsMine150/app/Modules/Whatsapp/Http/Controllers/WhatsappEmbeddedSignupController.php`
* **Changes:**
  * **Dedicated Logs:** Added a `logMeta()` helper that creates a dedicated log channel outputting directly to `storage/logs/meta.log` to print raw requests and responses from Meta.
  * **Fallback Token Exchange:** Updated `InboxSetupController` (Instagram/Messenger signup) to try exchanging authorization codes with a `redirect_uri` first, and if that fails, retry without it. This matches the robust exchange flow used in the WhatsApp module.

---

## Smart Automation Wait Nodes & COD Upgrades (Sept 4, 2026)

### 1. Wait For Reply Node
- Modified `Builder.jsx` and `AutomationEngine.php` to support a new `wait_type` for the "Wait" node.
- Workflows can now park indefinitely, waiting for a WhatsApp reply from the user. 
- Custom context variables (like `cod_reply`) are populated automatically when the user replies, enabling complex conditional branching in flow execution.

### 2. COD Verification Flow
- Upgraded the `ecommerce_cod_verification` template inside `AutomationTemplateRepository.php`.
- The new flow automatically fires when a COD order is placed, asks for confirmation using a pre-approved template, waits for a reply, evaluates the reply payload against a Condition node, and sends either a confirmation or cancellation template.

### 3. E-Commerce UI & Compliance Rules
- Created `.agents/AGENTS.md` and `.agents/brain.md` to force AI to always use `send_template` for E-commerce nodes instead of plain text messages.
- Overhauled the Automations Index UI (`Index.jsx`), replacing the template gallery popup modal with a clean, in-line Tab Navigation system.
- Added new `ecommerce_order_cancelled` template to the database seeder to complete the COD flow.

### 4. Zero-Cost AI Product Search (RAG Hybrid)
- Modified `ChatbotRunner.php` to extract keywords from inbound WhatsApp messages and perform a dynamic SQL `LIKE` search against the `ecommerce_products` table.
- Formats the top 5 matching products into a concise string (SKU, Price, Stock) and injects it into the LLM system prompt dynamically.
- Eliminates the need for Vector DB embedding syncs, saving 100% of LLM API costs while keeping the AI's product knowledge perfectly real-time.

 # #   L o c a l   A I   &   O l l a m a   O p t i m i z a t i o n   ( S e p t   5 ,   2 0 2 6 ) 
 
 # # #   1 .   C o r r e c t   D e f a u l t   E m b e d d i n g s   M o d e l 
 -   U p d a t e d   L l m M a n a g e r . p h p   t o   u s e   t h e   c o r r e c t   m o d e l   n a m e   ` n o m i c - e m b e d - t e x t `   f o r   e m b e d d i n g s   w h e n   O l l a m a   i s   s e l e c t e d   a s   t h e   A I   p r o v i d e r .   P r e v i o u s l y ,   t h e   s y s t e m   f a l s e l y   u s e d   t h e   d e f a u l t   C h a t   m o d e l   ( e . g .   q w e n 2 : 0 . 5 b )   f o r   e m b e d d i n g   A P I   c a l l s ,   c a u s i n g   f a t a l   5 0 1   T h i s   s e r v e r   d o e s   n o t   s u p p o r t   e m b e d d i n g s   e r r o r s   f r o m   t h e   l l a m a - s e r v e r . 
 
 # # #   2 .   R A G   P r o m p t   S t r u c t u r e   O p t i m i z a t i o n 
 -   R e w r o t e   c o n t e x t   i n j e c t i o n   i n s i d e   C h a t b o t R u n n e r . p h p .   P r e v i o u s l y ,   m a s s i v e   c o n t e x t   b l o c k s   ( K n o w l e d g e   B a s e ,   O r d e r   S u m m a r i e s ,   P r o d u c t   I n f o )   w e r e   i n j e c t e d   i n t o   t h e   L L M ' s   s y s t e m   m e s s a g e . 
 -   T o   s u p p o r t   s m a l l   l o c a l   m o d e l s   ( l i k e   q w e n   a n d   l l a m a 3 ) ,   c o n t e x t   w a s   s h i f t e d   e n t i r e l y   i n t o   t h e   f i n a l   u s e r   m e s s a g e   u s i n g   a   s t r i c t   L l a m a I n d e x - s t y l e   R A G   t e m p l a t e .   S m a l l   m o d e l s   n o w   r e l i a b l y   c o n s u m e   c o n t e x t   i m m e d i a t e l y   b e f o r e   a n s w e r i n g ,   p r e v e n t i n g   t h e m   f r o m   i g n o r i n g   s y s t e m   r u l e s   o r   h a l l u c i n a t i n g   o u t - o f - c o n t e x t   a n s w e r s .  
 