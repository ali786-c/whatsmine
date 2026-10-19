# WhatsMine v1.5.0 — Full Issues & Bug Report

| | |
|---|---|
| **Audit date** | 2026-10-07 |
| **Repo / branch** | `WhatsMine150` — `master` @ `58c955fb` |
| **Scope** | Full codebase: routing, middleware, authentication/2FA, authorization/RBAC, billing webhooks, outbound integrations (webhooks/media), admin & public surface, dependencies, test suite |
| **Method** | Manual source review + **live exploit PoCs** against a local dev server + `composer audit` + `npm audit` + full PHPUnit run |
| **Environment** | Local dev: `APP_ENV=local`, `APP_DEBUG=true`, PHP 8.3.33, MySQL (XAMPP, port 3306), Vite 6 / React (Inertia) |
| **Prepared by** | Buffy (Codebuff) |

> **Note:** All PoCs ran against a local dev instance only. Every probe account, probe client, contact message, webhook endpoint and temp script created during testing was deleted afterwards (DB verified back to `users=9`, `clients=6`, 0 temp files, 0 leftover processes).

---

## 1. Summary

**21 security findings (3 Critical, 4 High, 8 Medium, 6 Low) + 1 functional bug (B1) + test-suite debt (T1)** — see the table below.

| ID | Severity | Title | Status |
|----|----------|-------|--------|
| C1 | 🔴 Critical | Login brute-force bypass via spoofed `X-Forwarded-For` | **LIVE-PROVEN** |
| C2 | 🔴 Critical | Read-based SSRF through outbound webhooks (+ response body leaked back) | **LIVE-PROVEN** |
| C3 | 🔴 Critical | MyFatoorah payment webhook has no signature/authenticity verification | Static |
| H1 | 🟠 High | 2FA challenge endpoint is unlimited (6-digit code brute force) | Static |
| H2 | 🟠 High | `POST /register` unlimited account creation | **LIVE-PROVEN** |
| H3 | 🟠 High | `POST /contact` unlimited message flooding | **LIVE-PROVEN** |
| H4 | 🟠 High | No per-IP global login cap → credential stuffing | **LIVE-PROVEN** |
| M1 | 🟡 Medium | Stored XSS on public CMS pages (`/p/{slug}`) | Static |
| M2 | 🟡 Medium | JSON-LD `</script>` breakout XSS on public pages | Static |
| M3 | 🟡 Medium | CSP `script-src` allows `'unsafe-inline'` | Static |
| M4 | 🟡 Medium | HSTS header missing | Static |
| M5 | 🟡 Medium | `/healthz/*` endpoints public (`HEALTHZ_TOKEN` unset) | **LIVE-PROVEN** |
| M6 | 🟡 Medium | `GET /admin/search` missing permission middleware | Static |
| M7 | 🟡 Medium | Composer: **56 advisories / 17 packages** (1 critical, 15 high, 30 medium, 8 low) | Tool output |
| M8 | 🟡 Medium | npm: **40 vulnerabilities** (3 critical, 22 high); 27 of them shipped to prod | Tool output |
| L1 | 🟢 Low | CSV formula injection in admin exports | Static |
| L2 | 🟢 Low | `SESSION_SECURE_COOKIE` unset | Static |
| L3 | 🟢 Low | Missing rate limits (locale/currency/theme, magic-link verify, invitation accept) | Static |
| L4 | 🟢 Low | Raw exception messages leaked in 502 responses (Inbox media) | Static |
| L5 | 🟢 Low | Admin logo/favicon accept SVG uploads | Static |
| L6 | 🟢 Low | Abandoned dependency: `unomaduro/larastan` | Tool output |
| **B1** | 🟠 High (functional) | Instagram funnel services **drop the `action` column** → SQL error on every funnel log write | **LIVE (reproduced by test run)** |
| T1 | ⚪ QA | Test suite is not green: 24 errors + 14 failures (triaged — see §7) | See §7 |

**Solid / verified-safe areas** are listed in §8.

---

## 2. 🔴 Critical

### C1 — Login brute-force bypass via `X-Forwarded-For`

| | |
|---|---|
| **Severity** | Critical |
| **Status** | **LIVE-PROVEN** (8/8 attempts bypassed lockout; fixed IP locked at attempt 6) |
| **Class** | CWE-290 Authentication Bypass by Spoofing / CWE-307 Improper Restriction of Excessive Auth Attempts |
| **Root cause** | [bootstrap/app.php:142-143](bootstrap/app.php#L142-L143) — `$middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'))` and `TRUSTED_PROXIES` is **not set** in `.env` → the app trusts **every** proxy, so `Request::ip()` returns the attacker-controlled `X-Forwarded-For` value |
| **Amplifier** | [app/Http/Requests/Auth/LoginRequest.php:81-83](app/Http/Requests/Auth/LoginRequest.php#L81-L83) — `throttleKey() = email|ip`, and [LoginRequest.php:62](app/Http/Requests/Auth/LoginRequest.php#L62) allows 5 attempts per key |

**Evidence (live):**

- With a **rotating `X-Forwarded-For`** header on one target email: **8/8 attempts accepted, no lockout ever triggered** (needs only 6 to lock a real IP).
- With a **fixed IP**: attempt 6 → `Too many login attempts. Please try again in 50 seconds.` (lockout works, but only when the key is stable).

**Impact:**

1. **Unlimited password guessing on any account** — the only real defence against online brute force is neutralized.
2. **Audit-log poisoning** — recorded IPs in audit/login logs become attacker-chosen.
3. **`contact_messages.ip_address` poisoning** (see H3).
4. **`throttle:webhooks` bypass** — [AppServiceProvider.php:126-131](app/Providers/AppServiceProvider.php#L126-L131) keys the webhook limiter on `$request->getClientIp()`; with `trustProxies('*')` an attacker rotates the key at will (limit is 1000/min, so also weak by design).

**Recommended fix:**

1. Set `TRUSTED_PROXIES` in `.env` to the real load-balancer IPs (empty string = trust no proxy when running bare), never `*` in production.
2. Make the login throttle key **proxy-independent** for anonymous attempts: `email` only (or `email + session id`), so spoofed IPs cannot reset the bucket.

---

### C2 — Read-based SSRF via outbound webhooks (secret exfiltration proven)

| | |
|---|---|
| **Severity** | Critical |
| **Status** | **LIVE-PROVEN** (internal service on `127.0.0.1:8099` secret read back through the app UI/API) |
| **Class** | CWE-918 Server-Side Request Forgery |
| **Where** | 1) [app/Http/Controllers/Client/WebhookEndpointController.php:48](app/Http/Controllers/Client/WebhookEndpointController.php#L48) and [:69](app/Http/Controllers/Client/WebhookEndpointController.php#L69) — `'url' => ['required', 'url', 'max:500']`, **no private-IP / host validation** <br> 2) [app/Jobs/DispatchWebhookJob.php:47](app/Jobs/DispatchWebhookJob.php#L47) — `Http::timeout(10)->post($this->endpoint->url, …)` (Laravel follows redirects by default, so an external URL can 302 into an internal one) <br> 3) [app/Http/Controllers/Client/WebhookEndpointController.php:112](app/Http/Controllers/Client/WebhookEndpointController.php#L112) — `deliveries()` returns the **full** `WebhookDelivery` model to Inertia, including `response_body` (up to 2000 chars) |

**Evidence (live):** a temporary HTTP service bound to `127.0.0.1:8099` was registered as a webhook endpoint URL; the dispatch job fetched it and the **secret returned by the internal service was read back** from `GET /app/webhooks/{id}/deliveries`.

**Impact:** reach into loopback / RFC1918 / link-local space from the app server (cloud metadata `169.254.169.254`, internal admin panels, databases with HTTP APIs, XAMPP/panel UIs), with a **full read oracle** via delivery logs. Bypasses every network-level assumption of the deployment.

**Missing guard already exists elsewhere in this codebase** (reuse, do not reinvent):

- `app/Modules/Ecommerce/Services/StoreUrlGuard.php`
- `app/Modules/Inbox/Http/Controllers/InboxController.php:918-922` (`isPrivateIp()`)
- `app/Modules/Social/Services/Drivers/YoutubeDriver.php` (`assertSafeVideoUrl()`)

**Recommended fix:**

1. Validate at **both** save time and dispatch time: resolve DNS → reject loopback, private, link-local, CGNAT, multicast, IPv6 ULA/LL; reject `http(s)` only, no credentials in URL.
2. **Do not follow redirects**, or re-run the same validation on every hop.
3. Cap and truncate `response_body` in API responses; consider masking headers/auth material in delivery logs.

---

### C3 — MyFatoorah payment webhook accepts unauthenticated state changes

| | |
|---|---|
| **Severity** | Critical |
| **Status** | Static (code path fully traced; not exploited against a live gateway) |
| **Class** | CWE-345 Insufficient Verification of Data Authenticity |
| **Where** | [app/Services/Billing/MyFatoorahGateway.php:248](app/Services/Billing/MyFatoorahGateway.php#L248) — comment at [:251](app/Services/Billing/MyFatoorahGateway.php#L251) says *"There is no HMAC to verify — rely on the paymentId lookup"*, but the handler **does not re-fetch the invoice from the MyFatoorah API either**. `handleWebhookPaid()` at [:280-293](app/Services/Billing/MyFatoorahGateway.php#L280-L293) and `handleWebhookFailed()` at [:295-301](app/Services/Billing/MyFatoorahGateway.php#L295-L301) act purely on the **attacker-supplied** `InvoiceId` + `InvoiceStatus`. |

**Impact:** anyone who can POST to the webhook route can:

- mark an existing subscription **active** without paying (`InvoiceStatus: "PAID"`), or
- set **other users'** subscriptions to `past_due` (`InvoiceStatus: "FAILED"`).

**Contrast — the other 12 gateways all verify** (`app/Services/Billing/*Gateway.php`): Cashfree, MercadoPago, Paddle, Paymob, Paystack, Razorpay, Square, Tap use `hash_hmac`/`hash_equals`; Stripe uses SDK `Webhook::constructEvent`; PayPal verifies via `verifyPayPalSignature()`; Xendit compares an `x-callback-token` with `hash_equals`; Mollie **re-fetches the payment from its API** (MollieGateway.php:155-166 — the exact pattern to copy).

**Recommended fix:** re-fetch the invoice status from MyFatoorah's API by `InvoiceId` and only trust **that** response (Mollie pattern), or verify a MyFatoorah-provided shared secret/header if the gateway supports one. Never trust the POST body for billing state.

---

## 3. 🟠 High

### H1 — 2FA challenge has no throttle and no attempt counter

| | |
|---|---|
| **Severity** | High |
| **Status** | Static |
| **Class** | CWE-307 |
| **Where** | [routes/auth.php:45](routes/auth.php#L45) — `Route::post('two-factor-challenge', …)` has **no `throttle` middleware** (compare `POST magic-link` at [routes/auth.php:40](routes/auth.php#L40) which correctly has `throttle:5,1`); [app/Http/Controllers/Auth/TwoFactorController.php:119](app/Http/Controllers/Auth/TwoFactorController.php#L119) `verify()` has **no `RateLimiter` / attempt counter**. |

**Impact:** after a valid password (or via a stolen session), a **6-digit TOTP/backup code** can be brute-forced with unlimited requests. 1,000,000 keyspace at even 10 req/s ≈ a day, realistically far less.

**Fix:** `->middleware('throttle:5,1')` on the route **plus** a `RateLimiter` keyed on the pending-auth user id inside `verify()`, clearing on success.

---

### H2 — Unlimited account registration

| | |
|---|---|
| **Severity** | High |
| **Status** | **LIVE-PROVEN** — 15/15 accounts created, each with a fresh guest session (defeats session-sticky limits) |
| **Class** | CWE-770 Allocation of Resources Without Limits |
| **Where** | [routes/auth.php:27](routes/auth.php#L27) — `Route::post('register', …)` with no throttle |

**Impact:** spam/bot account flood → DB & storage growth, abuse of trial plans, poison of analytics, queue exhaustion (welcome mails/jobs).

**Fix:** `throttle:10,1` (per IP) + per-session cap; consider CAPTCHA after a threshold.

---

### H3 — Unlimited contact-form submissions

| | |
|---|---|
| **Severity** | High |
| **Status** | **LIVE-PROVEN** — 15/15 accepted in a burst; 12/12 stored rows confirmed in DB |
| **Class** | CWE-770 |
| **Where** | [routes/web.php:32](routes/web.php#L32) — `Route::post('/contact', …)` no throttle; stored IP comes from the spoofable client IP (ties into C1) |

**Impact:** DB bloat / notification spam; stored `ip_address` is attacker-controlled (spoofing) so any IP-based blocklist is useless.

**Fix:** `throttle:5,1` on the route; take IP from a trusted position or drop the spoofable column value.

---

### H4 — No per-IP global cap → credential stuffing

| | |
|---|---|
| **Severity** | High |
| **Status** | **LIVE-PROVEN** — 15 *distinct* emails from a single IP were never throttled; only the same `email|ip` bucket ever fired |
| **Class** | CWE-307 |
| **Where** | [app/Http/Requests/Auth/LoginRequest.php:62](app/Http/Requests/Auth/LoginRequest.php#L62) + [:81-83](app/Http/Requests/Auth/LoginRequest.php#L81-L83) — limiter is keyed `email|ip` only |

**Impact:** a single attacker IP can test thousands of username/password pairs (classic credential-stuffing from breach lists) without ever hitting the 5-attempt lock, because each guess is a different email.

**Fix:** add a second limiter keyed **only on IP** (e.g. 30/min, 200/hour) in `ensureIsNotRateLimited()`, keeping the existing per-email counter.

---

## 4. 🟡 Medium

### M1 — Stored XSS on public CMS pages

| | |
|---|---|
| **Severity** | Medium (High if untrusted admins can edit CMS pages) |
| **Status** | Static |
| **Class** | CWE-79 Cross-site Scripting (Stored) |
| **Where** | Validation at [app/Http/Controllers/Admin/CmsPageController.php:26](app/Http/Controllers/Admin/CmsPageController.php#L26) and [:43](app/Http/Controllers/Admin/CmsPageController.php#L43) is only `'content' => ['nullable', 'string']`; **no HTML purifier is installed** (checked `composer.json`); rendered at [resources/js/Pages/marketing/CmsPage.jsx:21](resources/js/Pages/marketing/CmsPage.jsx#L21) via `dangerouslySetInnerHTML={{ __html: page.content ?? '' }}`; route `/p/{slug}` is **public**. |

**Impact:** any stored `<script>`/event-handler payload executes for **every visitor** of the public page → session/cookie theft, defacement, admin takeover when an admin previews it.

**Fix:** sanitize server-side on save (e.g. `mews/purifier` or `symfony/dom-crawler`-based allowlist) with an allowlist of tags/attributes; keep `dangerouslySetInnerHTML` only for already-sanitized content.

---

### M2 — JSON-LD `</script>` breakout XSS

| | |
|---|---|
| **Severity** | Medium |
| **Status** | Static |
| **Class** | CWE-79 |
| **Where** | [resources/js/Components/SeoHead.jsx:60](resources/js/Components/SeoHead.jsx#L60) — `dangerouslySetInnerHTML={{ __html: JSON.stringify(graph) }}` inside `<script type="application/ld+json">`. `JSON.stringify` does **not** escape `<`, `>` or `U+2028/2029`, so a value containing `</script><script>…` closes the tag. Graph data comes from SEO settings (source: [resources/js/Pages/Welcome.jsx:2160](resources/js/Pages/Welcome.jsx#L2160) `jsonLd={jsonLd}`). |

**Impact:** stored XSS on the **public homepage/marketing pages** if any SEO field (site name, titles, org data) can contain user-influenced text.

**Fix:** `JSON.stringify(graph).replace(/</g, '\\u003c')` (or `>` and `&`) before injecting — the standard JSON-LD escaping rule.

---

### M3 — CSP allows `'unsafe-inline'` scripts

| | |
|---|---|
| **Severity** | Medium |
| **Status** | Static |
| **Where** | [app/Http/Middleware/SecureHeaders.php:47](app/Http/Middleware/SecureHeaders.php#L47) builds `$scriptSrc = "'self' 'unsafe-inline'"…` and applies it to both `script-src` ([:57](app/Http/Middleware/SecureHeaders.php#L57)) and `script-src-elem` ([:58](app/Http/Middleware/SecureHeaders.php#L58)). |

**Impact:** the CSP does not stop inline-script XSS (M1/M2 payloads still execute); the header gives a false sense of protection and blocks migration to a strict policy later.

**Fix:** move to hashes/nonces for the inline bootstrap script, drop `'unsafe-inline'` from `script-src`, keep it in `style-src` only if unavoidable.

---

### M4 — HSTS header missing

| | |
|---|---|
| **Severity** | Medium |
| **Status** | Static |
| **Where** | [app/Http/Middleware/SecureHeaders.php](app/Http/Middleware/SecureHeaders.php) sets `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` and CSP but **no `Strict-Transport-Security`** (grep for `Strict-Transport`/`HSTS` returns nothing). |

**Impact:** first-visit/downgrade MITM can strip HTTPS for a session; no preload/umbrella protection for the domain.

**Fix:** `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` (conditionally, once HTTPS-only deployment is confirmed).

---

### M5 — Health endpoints publicly readable

| | |
|---|---|
| **Severity** | Medium |
| **Status** | **LIVE-PROVEN** — `curl /healthz/db` → `{"status":"ok","db":"connected"}`; `/healthz/queue` leaks queue driver + job depth |
| **Class** | CWE-200 Exposure of Sensitive Information |
| **Where** | [routes/web.php:104](routes/web.php#L104) — `return ! filled($token) || hash_equals($token, $request->bearerToken() ?? '');` and **`HEALTHZ_TOKEN` is unset in `.env`** → the guard always returns `true` (fail-open). Guarded only by `throttle:30,1`. |

**Impact:** infrastructure fingerprinting (DB connectivity, queue backend & depth, Redis presence) for anyone; fail-open design means forgetting the env var silently exposes it.

**Fix:** fail **closed** — if no token configured, return 401 (or only allow from a private network / `127.0.0.1`), and set a strong `HEALTHZ_TOKEN`.

---

### M6 — `GET /admin/search` has no permission middleware

| | |
|---|---|
| **Severity** | Medium |
| **Status** | Static |
| **Class** | CWE-862 Missing Authorization |
| **Where** | [routes/admin.php:40](routes/admin.php#L40) — `Route::get('/search', [AdminSearchController::class, 'search'])` with **no `permission:*`**, while every neighbouring admin route carries one (e.g. `:41+` `permission:view_clients`, `permission:manage_subscriptions`). Admin group middleware is only `web, auth:admin, licensed, demo` ([bootstrap/app.php:101](bootstrap/app.php#L101) — RBAC applied **per-route**). |

**Impact:** any authenticated admin-role account **without** the relevant permissions can enumerate **Clients, Users, Subscriptions, Plans** (see `AdminSearchController::search`) via a keyword search — bypassing the RBAC shown in the UI. |

**Fix:** add `->middleware('permission:…')` (or a dedicated `view_dashboard`-style permission) to the route.

---

### M7 — Composer: 56 security advisories across 17 packages

| | |
|---|---|
| **Severity** | Medium (critical individual advisory) |
| **Status** | Tool output — `composer audit` run 2026-10-07: **`Found 56 security vulnerability advisories affecting 17 packages`** — 1 critical, 15 high, 30 medium, 8 low (2 unrated) |

Notables:

- **`mtdowling/jmespath.php` — critical**
- **`web-token/jwt-framework`** — Bleichenbacher/Marvin padding-oracle class issues (`GHSA-5739-39v2-5754`) plus the PBES2 `p2c` CPU-amplification advisory (`GHSA-3prj-6hqw-cm82`)
- **`laravel/framework`** — including *XSS in Debug Page Information* (relevant while `APP_DEBUG=true`)
- **`dompdf/dompdf`** — 6 advisories incl. **local file read via SVG data-URI** and chroot bypass (PDF generation is used by this app)
- **`guzzlehttp/guzzle` / `guzzlehttp/psr7`** — host confusion, cookie scope, CRLF injection, proxy-downgrade
- **`league/commonmark`**, `symfony/http-foundation`, `symfony/http-kernel`, `symfony/mime`, `phpseclib`, `paragonie/sodium_compat`, `league/flysystem`
- **`unomaduro/larastan` is abandoned** (→ `larastan/larastan`) — see L6

**Fix:** `composer update` within semver ranges for the patched majors, verify `dompdf`/`guzzle` majors if a major bump is required, re-run `composer audit`.

---

### M8 — npm: 40 vulnerabilities (27 reachable in production install)

| | |
|---|---|
| **Severity** | Medium |
| **Status** | Tool output — `npm audit` run 2026-10-07 |

- **All deps:** `{critical: 3, high: 22, moderate: 14, low: 1}` → **40 total**
- **`npm audit --omit=dev` (shipped to prod):** `{critical: 1, high: 15, moderate: 10, low: 1}` → **27 total**

Critical: **`websocket-driver`** (also 2 more among dev tooling: `concurrently`, `shell-quote`). High that matter for the shipped bundle: **`axios`, `vite`, `ws`, `undici`, `protobufjs`, `socket.io-parser`, `postcss`, `brace-expansion`/`braces`/`micromatch`/`picomatch`, `nanoid`, `tmp`, `js-yaml`, `lodash-es`, `browserslist`, `source-map-js`, `tailwindcss`, `fast-glob`, `chokidar`, `@grpc/grpc-js`, `form-data`, `follow-redirects`**.

**Fix:** `npm update` (lockfile refresh) for semver-safe bumps, then review majors for `vite` (project is on Vite 6) and `websocket-driver` transitive chain; re-run `npm audit --omit=dev` until it is empty.

---

## 5. 🟢 Low

### L1 — CSV formula injection in admin exports

- **Where:** [app/Http/Controllers/Admin/ClientController.php:340-362](app/Http/Controllers/Admin/ClientController.php#L340-L362) (`fputcsv` at :349) and [app/Http/Controllers/Admin/SubscriptionController.php:137-157](app/Http/Controllers/Admin/SubscriptionController.php#L137-L157) (`fputcsv` at :147).
- **Impact:** a client/user name of `=cmd|'/c calc'!A1` executes when the CSV is opened in Excel/Sheets (admin-side attack via attacker-controlled profile fields).
- **Fix:** prefix cells starting with `=`, `+`, `-`, `@`, TAB/CR with `'` (or wrap as `\t`).

---

### L2 — `SESSION_SECURE_COOKIE` unset

- **Where:** [config/session.php:172](config/session.php#L172) — `'secure' => env('SESSION_SECURE_COOKIE')` and the variable is not in `.env`.
- **Impact:** session cookie may be sent over plain HTTP → session hijack on any HTTP hop.
- **Fix:** `SESSION_SECURE_COOKIE=true` in production `.env`.

---

### L3 — Missing rate limits on low-value endpoints

| Route | Location |
|---|---|
| `PUT /locale` | [routes/web.php:25](routes/web.php#L25) |
| `PUT /currency` | [routes/web.php:27](routes/web.php#L27) |
| `POST /theme/update` | [routes/web.php:28](routes/web.php#L28) |
| `GET magic-link/{token}` (verify) | [routes/auth.php:41](routes/auth.php#L41) |
| `GET/POST invitations/{token}` | [routes/auth.php:85-86](routes/auth.php#L85-L86) |

- **Impact:** token-guessing pressure on magic-link/invitation verification (bounded but non-zero), and trivial write-endpoint spam for locale/currency/theme.
- **Fix:** `throttle:10,1` on the state-changing ones; the token routes should be throttled per token **and** expire the token after N failures.

---

### L4 — Raw exception messages leaked in 502 responses (Inbox media)

- **Where:** [app/Modules/Inbox/Http/Controllers/InboxController.php:692](app/Modules/Inbox/Http/Controllers/InboxController.php#L692) `abort(502, 'Could not fetch QR media: '.$e->getMessage())`, also [:762](app/Modules/Inbox/Http/Controllers/InboxController.php#L762), [:1035](app/Modules/Inbox/Http/Controllers/InboxController.php#L1035) (`{'error': $e->getMessage()}`), [:1143-1145](app/Modules/Inbox/Http/Controllers/InboxController.php#L1143-L1145); plus [:832](app/Modules/Inbox/Http/Controllers/InboxController.php#L832) embeds `proc_open` diagnostics.
- **Impact:** internal hostnames/paths/driver errors (and, with `APP_DEBUG=true`, stack info) disclosed to clients.
- **Fix:** log the exception, return a generic message + error id.

---

### L5 — SVG accepted for admin logo/favicon

- **Where:** [app/Http/Controllers/Admin/SystemSettingsController.php:105](app/Http/Controllers/Admin/SystemSettingsController.php#L105) (`logo … mimes:png,jpg,jpeg,gif,svg,webp`) and [:133](app/Http/Controllers/Admin/SystemSettingsController.php#L133) (favicon).
- **Impact:** SVG can carry `<script>`/`onload`; served same-origin it becomes stored XSS (defense-in-depth gap — it needs an admin to upload, and the file is not sanitized).
- **Fix:** drop `svg`, or sanitize with an SVG purifier before storing; serve uploads from a separate domain with `Content-Disposition: attachment` where possible.

---

### L6 — Abandoned dependency

- **Where:** `composer audit` → `unomaduro/larastan is abandoned. Use larastan/larastan instead.` (no further security patches).
- **Fix:** `composer require --dev larastan/larastan` and update `phpstan` config namespace.

---

## 6. Dependency advisories (detail)

See **M7** (composer: 56 advisories / 17 packages) and **M8** (npm: 40 vulns, 27 in prod install). Reproduce with:

- `composer audit --format=plain` (run 2026-10-07: `Found 56 security vulnerability advisories affecting 17 packages`, 1 abandoned)
- `npm audit --json` → `metadata.vulnerabilities = {info:0, low:1, moderate:14, high:22, critical:3}`
- `npm audit --omit=dev --json` → `{info:0, low:1, moderate:10, high:15, critical:1}`

---

## 7. Test suite status & functional bug (T1, B1)

**Command:** `php -d memory_limit=2048M vendor/bin/phpunit` (PHP 8.3.33, MySQL `whatsmine_testing`, clean checkout — no project source was modified during this audit)

**Result:** `Tests: 559, Assertions: 1437, Errors: 24, Failures: 14, PHPUnit Deprecations: 12` → **exit code 2**

> ⚠️ Environment note: the run **requires MySQL running** — with `mysqld` down every test errors out. `vendor/bin/phpunit` also needs `memory_limit=2048M` (default 128M OOMs inside `Cpdf.php`).

### 7.1 B1 — real product bug found while triaging (🟠 High, functional)

**Instagram funnel services silently drop the `action` value → SQL error on every funnel log write.**

- [app/Modules/Instagram/Services/PrivateReplyService.php:67](app/Modules/Instagram/Services/PrivateReplyService.php#L67) and [app/Modules/Instagram/Services/LeadDeliveryService.php:48](app/Modules/Instagram/Services/LeadDeliveryService.php#L48) both define:
  ```php
  $log = fn (string $action, array $extra = []) => CommentAutomationLog::create(array_merge([
      'workspace_id' => …, 'stage' => $participant->stage,
  ], $extra));   // ← $action is accepted but NEVER merged into the attributes
  ```
- The column is mandatory: migration [2026_09_12_000400_create_comment_automation_logs_table.php:18](app/Modules/Instagram/database/migrations/2026_09_12_000400_create_comment_automation_logs_table.php#L18) → `$table->string('action', 48)->index();` (NOT NULL, no default).
- **Observed:** `SQLSTATE[HY000]: 1364 Field 'action' doesn't have a default value` thrown from `PrivateReplyService.php:67` — this is the cause of **all 24 test errors**.
- Other call sites do it correctly (`CommentFunnelService.php:52` and `:616` both pass `'action' => …`), which confirms it is an oversight in these two closures.
- **Impact:** every DM send / skip / rate-limit / failure log in the comment-funnel path throws. On a DB with the Instagram module migrated (the local `whatsmine` DB has **no** `comment_automation_logs` table yet, so the bug is latent but will hit any fresh install) this breaks the feature and floods error logs.
- **Fix:** merge `['action' => $action]` into the create payload in both closures (1-line fix each) — or give the column a default in the migration if older rows must remain valid.

### 7.2 The 24 errors

| Tests | Error | Root cause |
|---|---|---|
| `Instagram\InstagramFunnelTest` (17) + `Instagram\InstagramInboundDmTest` (7) | `1364 Field 'action' doesn't have a default value` | **B1** (above) |

### 7.3 The 14 failures — all triaged, none are security bugs

| # | Test | Symptom | Root cause (verified) |
|---|---|---|---|
| 1 | `Auth\RegistrationTest::test_new_users_can_register` | not authenticated after `POST /register` (302 → `/`) | **Stale test.** Registration now requires `agree_terms` ([RegisteredUserController.php:45](app/Http/Controllers/Auth/RegisteredUserController.php#L45)) → validation redirect. Fix: add `'agree_terms' => 'accepted'` to the payload. |
| 2–5 | `Campaign\SchedulerTest` (4) | 404 instead of redirect | **Stale test.** `Campaign::getRouteKeyName()` = `uuid` ([Campaign.php:46-49](app/Modules/Broadcasting/Models/Campaign.php#L46-L49)) but the test posts `$campaign->id` → `No query results for model …`. Fix: pass `$campaign->uuid`. |
| 6–8 | `Inbox\LabelCrudTest` (3) | 404 instead of 200/403 | **Stale test.** `Conversation::getRouteKeyName()` = `uuid` ([Conversation.php:26-29](app/Modules/Shared/Models/Conversation.php#L26-L29)), test passes `$conv->id`. |
| 9 | `MarketingSuite\MultiTenantScopingTest` | 404 instead of 403 | Same pattern — `Contact::getRouteKeyName()` = `uuid` ([Contact.php:75-78](app/Modules/Shared/Models/Contact.php#L75-L78)). |
| 10 | `Meta\MetaInboundWebhookTest::global_webhook_dedupes_duplicate_entry_ids` | `inbound_webhook_events` count 0, expected 1 | **Test ↔ implementation mismatch.** [WhatsappWebhookController.php:68](app/Modules/Whatsapp/Http/Controllers/WhatsappWebhookController.php#L68) only records the idempotency row when `$eventKey !== null`; the payload has `changes => []` so `eventKey` is null and `isNewEvent()` is never called. Decide: record on entry id too, or change the test payload. |
| 11–12 | `Polish\UpgradeModalTest`, `ProductionHardening\PlanLimitTest` | 404 instead of redirect | Same uuid-vs-id pattern (campaign/limit routes). |
| 13–14 | `Realtime\TypingEndpointTest` (2) | 404 instead of 200/403 | Same uuid-vs-id pattern — response body captured: `{"message":"No query results for model [App\\Modules\\Shared\\Models\\Conversation] 1"}`. |

**Verdict:** 13 of 14 failures are **stale tests** (models moved to `uuid` route keys + a new `agree_terms` rule without updating the tests) and 1 is an implementation decision (Meta dedupe). **No security regression is implicated by the failures** — but the suite is red, so it cannot protect against regressions either. Fixing B1 + the 14 tests would take the suite to **559/559**.

**Deprecations (12):** PHPUnit 11 metadata/deprecation warnings from the same runs — cosmetic, but clear them before a PHPUnit 12 upgrade.

---

## 8. Verified as solid ✅

These were explicitly checked and found **safe / correctly implemented**:

1. **`.env` never committed** — `git ls-files` + full history scan clean; repo-wide secret scan (API keys, tokens, passwords) returned **0 matches**.
2. **PR #1 hardening present** (commit `4a95963f`): QR webhook HMAC verification, inbox media SSRF guard, upload allowlist, random filenames — all verified in source.
3. **Upload endpoints** consistently use `mimes:` allowlists.
4. **Broadcast channels** check workspace membership before authorizing.
5. **IDOR checks present** on invoices, media, support tickets, team, webhook endpoints (e.g. `app/Policies/WebhookEndpointPolicy.php` matches `user_id`).
6. **Login lockout works** when the key is stable (5 attempts → "Too many login attempts…") — the weakness is only the spoofable key (C1).
7. **`SetLocale`** validates the locale against the enabled list (no path/`..` injection).
8. **CSRF exemption is narrow** — only webhook callback paths (`bootstrap/app.php:151-159`).
9. **12/13 payment gateways verify webhook authenticity** (HMAC/`hash_equals`, SDK signature, API re-fetch, or shared token); only MyFatoorah is missing it (C3).
10. **`POST magic-link/send` is throttled** (`throttle:5,1`, routes/auth.php:40).
11. **Existing SSRF building blocks reusable**: `StoreUrlGuard`, `InboxController::isPrivateIp()`, `YoutubeDriver::assertSafeVideoUrl()`.

---

## 9. Remediation roadmap

| Priority | Action | Effort | Findings |
|---|---|---|---|
| **P0 — now** | Set `TRUSTED_PROXIES` to real proxy IPs (or empty); make login throttle key not depend on client IP | S | C1 |
| **P0 — now** | Validate webhook target URLs (block private/loopback/link-local + DNS resolve + no redirect following); truncate `response_body` in responses | M | C2 |
| **P0 — now** | Re-fetch MyFatoorah invoice status from their API before trusting `InvoiceStatus` | S | C3 |
| **P1 — this week** | `throttle` on 2FA challenge + per-user attempt counter | S | H1 |
| **P1 — this week** | `throttle` on `POST /register` and `POST /contact` | S | H2, H3 |
| **P1 — this week** | Add per-IP global login limiter alongside the email bucket | S | H4 |
| **P1 — this week** | Merge `$action` into the `CommentAutomationLog::create()` payload in the two Instagram funnel closures | XS | **B1** |
| **P1 — this week** | Add `permission:` middleware to `GET /admin/search` | XS | M6 |
| **P1 — this week** | Fail-closed `/healthz/*` + set `HEALTHZ_TOKEN` | XS | M5 |
| **P2 — next sprint** | HTML-purify CMS content; escape `<` in JSON-LD `JSON.stringify` | S | M1, M2 |
| **P2 — next sprint** | `composer update` / `npm update` to clear advisories; re-run audits | M | M7, M8, L6 |
| **P2 — next sprint** | CSV formula-escaping on exports; `SESSION_SECURE_COOKIE=true` | XS | L1, L2 |
| **P3 — hardening** | CSP nonce/hash (drop `unsafe-inline`), add HSTS, throttle locale/currency/theme + token routes, generic 502 errors, drop/sanitize SVG | M | M3, M4, L3, L4, L5 |
| **P3 — QA** | Get the test suite to green (fix B1 + 14 stale tests) and enforce it in CI | S | T1, B1 |

*(XS < 1 h · S ≈ half a day · M ≈ 1–2 days · L ≈ longer)*

---

## 10. Methodology & limitations

**What was done**

- Full manual review of routes (`routes/*.php`), middleware (`app/Http/Middleware`, `bootstrap/app.php`), auth/2FA flows, admin RBAC wiring, billing gateways, outbound webhook/media paths, upload endpoints, and public marketing pages.
- **Live PoCs** on a local dev instance: X-Forwarded-For lockout bypass, SSRF read via webhook deliveries, registration flooding, contact flooding, per-IP credential stuffing, `/healthz/*` probing. All probes used disposable data that was deleted afterwards.
- `composer audit`, `npm audit` (incl. `--omit=dev`), full `vendor/bin/phpunit` run.
- Secret scan across the repository and git history.

**What was NOT done (scope limits)**

- No external/network pentest, no testing against a production deployment.
- Node/WhatsApp driver runtime, third-party APIs, and infra (server hardening, DB permissions) reviewed only at the integration boundary.
- No load/stress testing; no browser-level click-through of every page.
- Dependency advisories were **listed, not individually exploited** — presence of an advisory does not guarantee the vulnerable code path is reachable in this app.
- Test-suite failures were **root-caused individually** (§7.3) by reproducing the 404/registration responses with temporary debug tests (since deleted); B1 was confirmed against the migration and the model code.

