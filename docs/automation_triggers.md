# Automation Triggers — Complete Reference (Sept 28, 2026)

Automation ka **trigger** wo entry point hai jo batata hai "kis event par ye flow chalega". Har automation ke paas **ek hi trigger** hota hai. Ye guide **saare 11 triggers** ko ek ek kar ke explain karti hai — kya karta hai, kab fire hota hai, us me kaunse tokens milte hain, aur asli usage recipes.

> **TL;DR table** — kaunsa trigger kab use karna:
>
> | Trigger | Kab choose karein |
> |---|---|
> | `contact.created` | Naya contact add hote hi welcome / onboarding bhejni hai |
> | `contact.tag_added` | Kisi tag lagne par us segment ko follow-up bhejni hai |
> | `message.received` | Customer ka inbound message — keywords ke sath ya bina |
> | `campaign.sent` | Campaign message deliver hote hi per-recipient follow-up |
> | `form.submitted` | WhatsApp Flow / form submit hone par lead process karni hai |
> | `webhook.received` | Bahar ke system (site, CRM) se flow chalwana hai |
> | `order.placed` | Order confirm + receipt flow |
> | `order.fulfilled` | Shipping / delivery updates |
> | `order.cancelled` | Win-back ya feedback |
> | `cart.abandoned` | Cart recovery dhamakedar flow |
> | `customer.created` | Store ke customer record ka creation |

Sab triggers **active** automations par hi fire hote hain (`status = active`). Ek automation ko pause karne par uska trigger bhi so jata hai.

---

## 1. `contact.created` — Naya Contact

**Kya hai:** Jab workspace me koi naya contact banta hai (inbox me pehla message, import, manual add, ecommerce enrichment) to ye trigger fire hota hai.

**Kab fire hota hai:** Contact row create hote hi (background, event-driven).

**Available tokens:**
- `{{contact.name}}`, `{{contact.first_name}}`, `{{contact.last_name}}`
- `{{contact.email}}`, `{{contact.phone}}`

**Usage recipe — Welcome flow:**
```
[contact.created] → [send_whatsapp: "Hi {{contact.first_name}}! 👋 Welcome to our store.
Reply with MENU to see our catalogue."] → [add_tag: "welcomed"]
```

**Tips:**
- Pehla message aap ka branding moment hai — template use karein to `send_template` node chalega (promotion ke liye zaroori).
- Import ke waqt hazaron contacts bante hain — welcome flow me `wait` node daal kar blast ko thoda spread karein.

---

## 2. `contact.tag_added` — Tag Lagne Par

**Kya hai:** Jab kisi contact ko koi tag lagta hai (manual, import, automation ka `add_tag` node, ecommerce enrichment) to fire hota hai.

**Kab fire hota hai:** Tag attach hote hi — chahe kisi bhi source se laga ho.

**Available tokens:**
- Sab contact tokens (upar wale)
- `{{context.tag_name}}` — jo tag abhi laga

**Usage recipe — VIP onboarding:**
```
[contact.tag_added] → [condition: context.tag_name equals "vip"]
   ├─ (true)  → [send_whatsapp: "{{contact.first_name}}, aap ab VIP member hain! 🌟
   │            Exclusive discount code: VIP20"] → [send_email: VIP welcome pack]
   └─ (false) → [wait: 1 hour] → (khatam — non-VIP ko kuch nahi jata)
```

**Usage recipe — Import follow-up:**
Import ke waqt `tag_id` se lagne wale tags par bhi chalta hai, is liye "imported-leads" tag ke sath ek nurture sequence automate ho jati hai.

**⚠️ Loop warning:** Is trigger wali automation me `add_tag` node ka **iste'mal na karein** agar wo wahi tag laga raha ho jo is trigger ko fire kar raha hai — infinite loop ban jayega. Engine me node-level guard nahi hai; design me hi avoid karein (hamesha *alag* tag lagayen).

---

## 3. `message.received` — Inbound Message (sab se flexible)

**Kya hai:** Customer ka **har inbound message** (text, button tap, list selection, media caption) ye trigger fire karta hai — WhatsApp, Messenger aur Instagram teeno channels par.

**Kab fire hota hai:** Har naye inbound message par. **Keywords filter** laga sakte hain — trigger config me keywords comma-separated dein; message body me se koi ek keyword mila to hi flow chalega (case-insensitive, "contains" match).

**Available tokens:**
- Sab contact tokens
- `{{message.body}}` — customer ka raw message
- `{{context.message_body}}` — wahi, condition nodes ke liye
- `{{context.message_id}}`, `{{context.message_channel}}` — channel = whatsapp / messenger / instagram

**Usage recipe — Keyword menu:**
```
[message.received: keywords = "menu,price,rate"]
 → [quick_replies: "Kya dekhna chahenge?" | buttons: Pricing / Services / Talk to human
    | wait_for_choice ✓ | variable: choice]
     ├─ btn_1 → [send_whatsapp: pricing card]
     ├─ btn_2 → [list_message: services menu]
     └─ btn_3 → [assign_agent]
```

**Usage recipe — AI receptionist:**
```
[message.received] (no keywords = sab messages)
 → [condition: contact.tag exists "human_requested"]
   ├─ (false) → [ai_reply: "You are the store's WhatsApp assistant..."]
   └─ (true)  → [assign_agent] → [send_whatsapp: "Agent jald hi jud raha hai..."]
```

**Tips:**
- **Bot se double-reply na ho:** Isi contact ke liye agar koi `ask_question`/`wait_for_choice` run parked hai to wo bhi isi message par resume hoga — dono cheezein coexist kar sakti hain lekin test zaroor karein.
- Keywords khali chhorne par **har** message par flow chalega — ye sirf tab karein jab aap AI/chatbot-first design kar rahe hon.

---

## 4. `campaign.sent` — Campaign Deliver Hua (per-recipient)

**Kya hai:** Campaign (broadcast) ka message **ek recipient** ko successfully deliver hone par fire hota hai. **Per contact** chalta hai — 1000 recipients = 1000 runs, har ek apne contact ke sath.

**Kab fire hota hai:** `SendCampaignMessageJob` ke send succeed hote hi (status `sent`).

**Available tokens:**
- Sab contact tokens
- `{{context.campaign_name}}` — campaign ka naam
- `{{context.campaign_channel}}` — whatsapp / messenger / instagram
- (`{{context.campaign_id}}` bhi context me mojood hai)

**Usage recipe — Post-campaign engagement tag:**
```
[campaign.sent] → [add_tag: "received-diwali-offer"] → [wait: 3 days]
 → [condition: contact.tag exists "responded"] 
   ├─ (false) → [send_whatsapp: "Hi! Kya aap ne offer dekhi? 10% off: HELLO10"]
   └─ (true)  → (khatam)
```

**Tips:**
- Ye trigger campaign launch se **pehle** banayen aur active rakhen — campaign chalne ke baad me trigger lagane par purane recipients cover nahi honge.
- Heavy campaigns ke sath is trigger ka flow chhota rakhein — har recipient par chalta hai.

---

## 5. `form.submitted` — WhatsApp Flow / Form Complete

**Kya hai:** Customer ne WhatsApp ke andar Flow (native form) submit kiya — response data ke sath ye trigger fire hota hai.

**Kab fire hota hai:** WhatsApp webhook me `nfm_reply` aane par — driver structured response store karta hai (`wa_flow_responses` table) aur event dispatch karta hai.

**Available tokens:**
- Sab contact tokens
- `{{context.form_responses.*}}` — form ke saare jawab. ⚠️ Note: token renderer abhi top-level context keys parhta hai, is liye **messages me nested dot-tokens na use karein**; jawab tak pohanchne ka reliable tareeqa `condition` node (`context.form_responses` exists / contains) + message me plain text hai. Nested token support engine me aa to jaye ga, magar aaj ke version me is par rely na karein.

**Usage recipe — Appointment lead:**
```
[form.submitted] → [add_tag: "appointment-lead"] → [send_whatsapp:
 "Shukriya! Aap ki booking request mil gayi. Hamari team 2 ghante me
 confirm karegi."] → [assign_agent]
```

**Usage recipe — Survey thank-you + agent routing:**
```
[form.submitted] → [condition: context.form_responses exists]
   ├─ (true)  → [send_whatsapp: "Feedback ke liye shukriya 💚"] → [add_tag: "survey-done"]
   └─ (false) → (khatam)
```

**Tips:**
- Flow bhejna alag cheez hai — `whatsapp_form` node (ya inbox se) flow bhejta hai; **submit ka jawab** ye trigger uthata hai. Dono ko ek automation me joda ja sakta hai lekin alag automations bhi ban sakti hain.
- Flow responses inbox me `nfm_reply` message ke tor par bhi dikhti hain — agent ko raw JSON preview ke sath.

---

## 6. `webhook.received` — Bahari Duniya Se

**Kya hai:** Koi bahar ka system (aap ki website, CRM, payment gateway) aap ke automation ke **dedicated webhook URL** par POST kare to flow chalta hai.

**Kab fire hota hai:** `POST {APP_URL}/automation/webhook/{token}` — token builder ke trigger panel se generate hota hai (regenerate bhi ho sakta hai).

**Setup:**
1. Builder → trigger panel → **Generate Token**
2. URL copy karein aur apne system me configure karein
3. POST body `context.payload` me available hota hai

**Available tokens:**
- Sab contact tokens (agar `contact_id` body me diya ho — phir flow us contact ke liye chalta hai)
- `{{context.payload}}` — poora request body (condition me `exists`/`contains` ke liye)

**Usage recipe — Payment received:**
```bash
curl -X POST https://aap-ka-domain.com/automation/webhook/TOKEN \
  -H "Content-Type: application/json" \
  -d '{"contact_id": 42, "event": "payment.success", "amount": "2500"}'
```
```
[webhook.received] → [send_whatsapp: "Payment received! Order confirm ho gaya ✅"] 
 → [add_tag: "paid"]
```

**Tips:**
- `contact_id` ke baghair bhi chal sakta hai (contact-less run) — lekin phir message nodes ko contact chahiye hoga, is liye jahan mumkin ho contact_id bhejein.
- Token ghlad se public na karein — ye secret hai.

---

## 7. `order.placed` — Naya Order

**Kya hai:** Ecommerce store me naya order place hone par fire hota hai (WooCommerce/Shopify sync).

**Available tokens:**
- Sab contact tokens
- `{{context.order_number}}`, `{{context.order_total}}`, `{{context.order_currency}}`, `{{context.tracking_url}}`

**Usage recipe:**
```
[order.placed] → [send_whatsapp: "Order {{context.order_number}} confirm! 
 Total: {{context.order_total}} {{context.order_currency}}. Shukriya 🙏"]
 → [add_tag: "customer"]
```

---

## 8. `order.fulfilled` — Order Ship / Complete

**Kya hai:** Order fulfill hone par fire hota hai.

**Available tokens:** order wale sab + `{{context.tracking_url}}`

**Usage recipe:**
```
[order.fulfilled] → [send_whatsapp: "Aap ka order {{context.order_number}} 
 dispatch ho gaya 🚚 Track: {{context.tracking_url}}"] → [wait: 5 days]
 → [send_whatsapp: "Delivery kaisi rahi? 1–5 rate karein"] (poll options ke sath)
```

---

## 9. `order.cancelled` — Order Cancel

**Kya hai:** Order cancel hone par fire hota hai — win-back aur feedback ka perfect moment.

**Usage recipe:**
```
[order.cancelled] → [wait: 2 hours] → [send_whatsapp: 
 "Koi masla hua tha? Agle order par 15% off: COMEBACK15"]
```

---

## 10. `cart.abandoned` — Cart Recovery

**Kya hai:** Customer ne cart bhari aur checkout complete nahi kiya — fire hota hai abandonment ke waqt.

**Available tokens:** order wale + `{{context.cart_total}}`, `{{context.recovery_url}}`

**Usage recipe — 3-touch recovery:**
```
[cart.abandoned] → [wait: 1 hour] 
 → [send_whatsapp: "Aap ki cart me {{context.cart_total}} ka saman para hai 🛒 
    Jaldi karein: {{context.recovery_url}}"]
 → [wait: 1 day]
 → [send_whatsapp: "Last chance! Coupon CART10 se 10% off 💸"]
```

**Tips:** Pehla touch soft ho, aakhri me discount — ye proven sequence hai.

---

## 11. `customer.created` — Store Customer

**Kya hai:** Ecommerce store me customer record banna — bina order ke. Onboarding/newsletter flows ke liye.

**Usage recipe:**
```
[customer.created] → [send_whatsapp: "Welcome! Naye customers ke liye 
 WELCOME10 coupon: pehle order par 10% off 🎁"]
```

---

## Trigger Fire Kaise Hota Hai (engine internals)

```
Event (e.g. MessageReceived)                    ← drivers dispatch karti hain
      ↓
AutomationTriggerListener
      ↓ workspace ke saare ACTIVE automations
      ↓ jinke trigger_type match kare
AutomationEngine::triggerForContact()
      ↓ AutomationRun create (status: pending)
ExecuteAutomationRunJob (automation queue)
      ↓
Nodes execute hote hain → AutomationRunLog me trace
```

- Triggers **event-driven** hain — polling nahi, sab kuch Laravel queue par. Worker (`php artisan queue:work`) chalna zaroori hai, warna runs `pending` par ruke rahenge.
- Har run ka log **Runs** page par dikhta hai — kaunsa node chala, kya result tha.
- Keywords filter sirf `message.received` ke liye hai; baqi triggers me config khali hai.

## Testing Apna Trigger

1. Builder me flow bana kar **Test** button dabayen — dry-run preview mile ga (koi message nahi jata, koi token nahi lagta).
2. Real test: chhoti automation bana kar trigger ka real event karwayen (khud ko message bhejein, test tag lagayen, test webhook curl karein) aur **Runs** page se verify karein.
3. `php artisan queue:failed` se failed runs ki wajah dekhein.

## Server Deploy (jab tak feature me migration ho)

```bash
cd /www/wwwroot/wa.careerinpak.com && git pull origin master
php artisan migrate
php artisan queue:restart
php artisan optimize:clear
```

> **Note:** Trigger fires queue par hote hain — deploy ke baad `queue:restart` **zaroori** hai warna worker purane code par chalta rahega.
