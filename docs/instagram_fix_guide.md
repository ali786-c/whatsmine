# Instagram DM Fix Guide — verify_token + Webhook Registration

> **📜 Historical (Sep 13, 2026):** Ye us waqt ke fix ka record hai. Ab ke current system ke liye **`instagram_setup_guide.md` (v2)** dekho — usme Instagram Login flow (bina Facebook Page), follow verification, gate loop, token refresh aur saare 11 fixes documented hain. Webhook/verify-token ka ye fix aaj bhi valid hai.

**Masla:** Instagram connected hai, log DM bhej rahe hain, lekin Inbox mein messages show nahi ho rahe.

**Root cause (from `php artisan instagram:diagnose`):** Meta ke paas hamara `instagram` webhook subscription hi nahi hai, kyunki `verify_token` field khali hai. Meta events bhej hi nahi raha — Inbox ka code bilkul theek hai.

---

## 1. Sab se bara sawal: kya WhatsApp affect hoga? — **NAHI** ✅

Deep code check se proof (100% safe):

| Cheez | WhatsApp | Instagram |
|---|---|---|
| Webhook **object** | `whatsapp_business_account` | `instagram` (SEPARATE object) |
| Webhook **URL** | `/webhooks/whatsapp/...` | `/webhooks/instagram/{token}` |
| Verify token | **Apne aap generate** hota hai: `hash('sha256', app_id + app_secret + 'wh_global_verify')` — `WhatsappWebhookController::globalVerifyToken()` | `verify_token` DB field se aata hai (`MetaCredentials::verifyToken()`) |
| Field subscription | `messages, message_template_status_update` etc. (WABA-level) | `comments, messages, messaging_postbacks, message_reactions` |

**Key point:** WhatsApp ka verify token `verify_token` field se **bilkul use nahi hota** — wo app_id+app_secret ka hash hai jo code khud banata hai. Instagram aur WhatsApp ki subscriptions Meta pe **alag-alag objects** hain — ek change karne se doosri ko kuch nahi hota.

Registrar ka code (`MetaWebhookRegistrar`) sirf `object=instagram` pe POST karta hai — WhatsApp object ko touch tak nahi karta. **WhatsApp bilkul safe hai.**

---

## 2. verify_token kahan se milega?

**Ye Meta se nahi milta!** Ye tera APNA secret hai — koi bhi random string tu khud banata hai. Meta sirf ye check karta hai ke challenge ke waqt tu wohi string wapas bharta hai.

### Step 2.1 — Token generate karo (VPS pe):

```bash
openssl rand -hex 16
```

Output kuch aisa hoga: `a3f9c2e8b1d4f7a0c5e9b2d8f4a1c6e3` — **ise copy kar le, yehi tera verify_token hai.**

### Step 2.2 — Admin panel mein paste karo:

1. Browser mein kholo: `https://wa.careerinpak.com/admin/integrations`
2. **Meta App** card par **Edit** karo
3. **Webhook Verify Token** field mein wo copied token paste karo
4. **Save**

**Code-wise ye field kahan save hoti hai:** `integration_configs` table, `provider = meta_app`, `credentials` JSON mein `verify_token` key. (Agar admin panel mein field na dikhe, tinker se bhi daal sakte hain — Step 2.3.)

### Step 2.3 — (Optional) Tinker se direct daalo:

```bash
cd /www/wwwroot/wa.careerinpak.com
php artisan tinker --execute="
\$c = \App\Modules\Integrations\Models\IntegrationConfig::where('provider','meta_app')->first();
\$creds = \$c->credentials;
\$creds['verify_token'] = 'YAHAN_APNA_TOKEN';
\$c->credentials = \$creds;
\$c->save();
echo 'verify_token saved';
"
```

---

## 3. Webhook register karo (naya command — reconnect ki zaroorat NAHI)

```bash
php artisan instagram:register-webhook
```

**Ye command kya karegi:**
1. `verify_token` se app-level `instagram` subscription register karegi (callback: `https://wa.careerinpak.com/webhooks/instagram/{terka_token}`)
2. Fields set karegi: `comments, messages, messaging_postbacks, message_reactions`
3. Meta se read-back kar ke confirm karegi
4. Tera connected Facebook Page (`327892273751355`) ko messaging ke liye re-subscribe karegi

**Expected output:**
```
Instagram subscription confirmed at Meta:
  callback_url: https://wa.careerinpak.com/webhooks/instagram/a3f9...
  fields:       comments, messages, messaging_postbacks, message_reactions
Re-subscribing pages to Instagram messaging...
  [ OK ] #6 ws#1 "aliyan_tarar7" page 327892273751355 subscribed to: messages, ...
```

Agar page re-subscribe par `[FAIL] Token expired?` aaye → Step 5 dekho.

---

## 4. Verify + test karo

```bash
# Diagnose dubara chalao — ab sab [ OK ] hona chahiye
php artisan instagram:diagnose
```

**Bahar se bhi confirm karo** (browser ya terminal se):
```bash
curl "https://wa.careerinpak.com/webhooks/instagram/APNA_TOKEN?hub.mode=subscribe&hub.verify_token=APNA_TOKEN&hub.challenge=hello"
```
Ye **`hello` echo karna chahiye** — iska matlab webhook zinda hai.

**Live test:**
1. Doosre Instagram account se apne `aliyan_tarar7` ko ek DM bhejo
2. 30 second wait karo
3. Inbox (`/app/inbox`) check karo — conversation aa jayegi ✅

**Logs agar phir bhi na aaye:**
```bash
tail -f storage/logs/laravel.log | grep -i instagram
tail -f storage/logs/instagram/webhook.log
```

---

## 5. Agar page token expired ho (`[ !! ]` wali line)

Diagnose mein ye line aayi thi:
```
[ !! ] Could not read page 327892273751355 subscribed_apps (token may be expired)
```

**Fix:** Client panel → **Channels (Inbox → Setup)** → Instagram card → **Connect Instagram** dobara click karo. OAuth flow se naya page token aa jayega. Automations/config **delete nahi honge** — sirf token refresh hota hai (code mein `updateOrCreate` hai, new account nahi banta).

---

## 6. Baad ka permanent fix (zaroori nahi, par kar le)

Ab se har Instagram connect (naya account bhi) apne aap subscription register kar dega — code mein already hai:
- `InboxSetupController::embeddedSignupInstagram()` → `registerInstagramAppWebhook()`
- `Instagram\ConnectController::connect()` → `MetaWebhookRegistrar::registerInstagramObject()`

To future accounts ke liye koi manual step nahi chahiye.

---

## 7. Troubleshooting

| Problem | Wajah | Fix |
|---|---|---|
| `register-webhook` fail ho jaye "verify_token missing" | Admin panel mein field khali | Step 2 dobara dekho |
| Register OK par diagnose abhi bhi FAIL | Config cache | `php artisan config:clear` phir dobara try |
| Curl test `hello` nahi return karta | APP_URL galat ya SSL issue | `.env` mein `APP_URL=https://wa.careerinpak.com` confirm karo |
| DM aati hai par Inbox mein nahi dikhti | Queue worker issue | `ps aux \| grep queue:work` — `--queue=instagram` hona chahiye |
| WhatsApp bhi band ho gaya? | (Ho nahi sakta — alag objects hain) | WhatsApp pe kuch apply nahi hua |

---

## TL;DR — Sirf 4 commands

```bash
# 1. Token banao
openssl rand -hex 16

# 2. Admin → Integrations → Meta App → verify_token field mein paste → Save

# 3. Register + verify
php artisan instagram:register-webhook && php artisan instagram:diagnose

# 4. Doosre IG account se DM bhejo aur Inbox check karo
```

**WhatsApp 100% safe hai** — ye sab sirf `instagram` webhook object ko touch karta hai.
