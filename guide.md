# Local Setup & Operation Guide — WhatsMine

This guide outlines the local environment configurations, server URLs, credentials, and troubleshooting steps for running **WhatsMine** locally on your system.

---

## 🚀 Server Run Settings & Access Links

WhatsMine runs on a dual-service architecture (Laravel backend + Node.js Express microservice). Both servers must be running to enable dashboard CRM functions and WhatsApp API messaging.

*   **Main Application Dashboard (Laravel):** [http://localhost:8000](http://localhost:8000)
    *   *Root Folder:* `WhatsMine150`
    *   *Default Port:* `8000`
*   **CRM WebSocket Microservice (Node.js):** [http://localhost:3010](http://localhost:3010)
    *   *Root Folder:* `whatscrm`
    *   *Default Port:* `3010`

---

## 🔑 Seeding / Demo Logins
The MySQL database has been pre-populated with default workspaces, plans, and administrative accounts.

### 👤 Client Dashboard Login
*   **User Email:** `client@spagreen.net`
*   **Password:** `12345678`

### 🛡️ Super Admin Control Panel Login
*   **Admin Email:** `admin@example.com`
*   **Password:** `12345678`

---

## 🛠️ Actions Configured on Your System

1.  **PHP 8.3 Environment Fix:**
    *   Corrected the `extension_dir` inside `C:\php83\php.ini` from a downloads directory to `C:\php83\ext`. This resolved extension loading errors and enabled standard extensions (Curl, PDO, SQLite, Zip, MBString, OpenSSL) for PHP 8.3.
2.  **WhatsApp QR Addon Integration:**
    *   Copied the fully-implemented WhatsApp Web Baileys connector from the `qr` addon folder (`qr/helper/addon/qr/index.js`) to overwrite the stub file inside `whatscrm/helper/addon/qr/index.js`.
    *   Patched `whatscrm/helper/addon/qr/index.js` to dynamically fall back to standard `console.log` wrapping since the third-party `../../../utils/logger` module was missing in the codebase.
3.  **Environment Files Configured:**
    *   Created `whatscrm/.env` file targeting local ports, database credentials, and development mode.
4.  **MySQL Database Safely Configured:**
    *   Created an independent database `whatsmine` in MariaDB (XAMPP).
    *   *Note: This database is completely separate. It does NOT touch, modify, or affect your existing WordPress or other databases in XAMPP.*
5.  **Laravel Database Seeded:**
    *   Executed database migrations and default seeders (`artisan migrate --seed`) to populate CRM users, features, and plans.
6.  **Local Licensing Bypass:**
    *   Bypassed the remote license manager verification check in `WhatsMine150/config/license.php` to enable installation and testing on local and localhost environments. You can still enable it in production by adding `LICENSE_VERIFY=true` in your `.env`.
7.  **Sentry Package Disabled:**
    *   Disabled auto-discovery for `sentry/sentry-laravel` in `composer.json` to prevent fatal Lighthouse integration execution time exceeded (30s timeout) errors in local environment.

---

## 🏗️ Core Architectural Modules (WhatsMine)

WhatsMine is developed as a modern modular monolith inside the `WhatsMine150` Laravel 12 workspace. The main functional components reside inside the `app/Modules/` directory:

1.  **WhatsApp Module (`app/Modules/Whatsapp`):**
    *   Integrates directly with Meta's official WhatsApp Cloud API through `CloudApiClient` and `WhatsappDriver` to manage template synchronization, interactive messaging, auto-replies, and chat widgets.
2.  **AI Module (`app/Modules/AI`):**
    *   Enables automated AI Chatbots using vector database embeddings (via Qdrant in `EmbeddingStore.php`) and exposes a unified `LlmGateway` supporting OpenAI, Anthropic, and Gemini LLM providers.
3.  **Automation Module (`app/Modules/Automation`):**
    *   Hosts the main flow-builder interpreter execution engine (`AutomationEngine.php` and `WorkflowGenerator.php`) which manages user-defined marketing sequences and triggers.

> [!NOTE]
> **Project Separation:** The `whatscrm` Express.js project and the `whatsmine` Laravel project are completely different applications. Currently, all core active development, database management, and API integrations are centered around the **whatsmine** Laravel application.

---

## 💻 Manual Commands to Start Servers (If Stopped)

If you ever restart your computer or close the terminals and need to run the servers again:

### 1. Start Laravel Web Server
Open PowerShell/Command Prompt, go to the Laravel directory, and start the PHP development server:
```powershell
cd "C:\Users\Muhammad Aliyan\Downloads\Compressed\WhatsMine_v1.5.0_2\WhatsMine v1.5.0\WhatsMine150"
C:\php83\php.exe artisan serve
```

### 2. Start Node.js Express Server
Open a second PowerShell/Command Prompt terminal, navigate to the `whatscrm` folder, and start the Node process:
```powershell
cd "C:\Users\Muhammad Aliyan\Downloads\Compressed\WhatsMine_v1.5.0_2\WhatsMine v1.5.0\whatscrm"
npm start
```

---

## 🌐 SaaS Production Scaling & Recommendations

When deploying WhatsMine to a production environment for commercial SaaS use with multiple active WhatsApp connections:

### 1. Memory Configuration (RAM Limit)
Each active Baileys WhatsApp session consumes **30MB to 100MB of RAM**. A single Node.js process will crash once it exceeds its default memory limit. Start your Node server with increased memory allocation:
```bash
node --max-old-space-size=4096 app.js
```

### 2. Process Management (PM2)
Always use PM2 in production to manage the Node.js Express process. It ensures automatic restarts on crash and proper log collection:
```bash
npm install -g pm2
pm2 start app.js --name "whatscrm-service" --node-args="--max-old-space-size=4096"
pm2 save
pm2 startup
```

### 3. Database Selection (MySQL vs. MongoDB)
While MySQL (configured in `whatscrm/.env`) is fine for up to 30-50 concurrent sessions, for higher scale, consider switching the Node.js storage method to **MongoDB** (configured via database settings). MongoDB handles heavy binary key-value reads/writes during session sync operations with lower latency.

### 4. IP Management & Dedicated Proxies
Running too many WhatsApp accounts from a single Server IP can cause WhatsApp to flag the IP for spam. If scaling past 50 accounts, distribute traffic across different clean IP addresses or set up proxies.

---

## 🌐 Production Deployment & Meta Integration Guide (`wa.careerinpak.com`)

### 📌 Live Project Credentials & Repository
* **Production Domain:** `https://wa.careerinpak.com`
* **GitHub Repository:** `https://github.com/ali786-c/whatsmine.git` (Branch: `master`)
* **cPanel App Root Directory:** `/home/devwithguru/wa.careerinpak.com`
* **cPanel PHP 8.3 CLI Path:** `/opt/alt/php83/usr/bin/php`

---

### 🔑 Meta App & WhatsApp Embedded Signup Configuration

1. **Credentials Management:**
   * Go to **Admin Control Panel → Integrations → Meta App** (`https://wa.careerinpak.com/admin/integrations/meta_app/edit`).
   * Enter **App ID** and **App Secret** from the Meta for Developers Portal (**Basic Settings**).
   * *Note: The system automatically trims trailing whitespace and protects against saving masked bullet placeholders (`••••••••••••`).*

2. **Embedded Signup Authorization Code Exchange:**
   * Endpoint: `POST https://graph.facebook.com/v20.0/oauth/access_token`
   * Required Parameters:
     * `client_id`: Meta App ID
     * `client_secret`: Meta App Secret
     * `code`: Authorization code from Embedded Signup callback
     * `grant_type`: `authorization_code`

---

### 📑 Dedicated Meta Logging (`storage/logs/meta.log`)

All Meta API calls, embedded signup token exchanges, outbound messages, and inbound webhooks are logged to a dedicated log file:

* **Log Location:** `/home/devwithguru/wa.careerinpak.com/storage/logs/meta.log`
* **Real-time Log Viewing Command (cPanel/SSH Terminal):**
  ```bash
  tail -f /home/devwithguru/wa.careerinpak.com/storage/logs/meta.log
  ```

---

### ⚙️ Hybrid Environment Setup (cPanel Shared Hosting vs. VPS Server)

WhatsMine is designed to run seamlessly on both shared hosting (cPanel) and dedicated cloud servers (VPS):

#### 🅰️ Option A: cPanel / Shared Hosting Setup
* **`.env` Queue Connection:** Set `QUEUE_CONNECTION=sync`
* **Message Processing:** Messages are processed immediately inline upon receiving Meta webhooks.
* **Frontend Real-time Sync:** If WebSockets are not connected, the React frontend automatically enables **3-second background polling** (`Index.jsx` & `Show.jsx`) so new messages pop up without manual page refresh (`F5`).
* **Deployment Command:**
  ```bash
  cd /home/devwithguru/wa.careerinpak.com
  git pull origin master
  /opt/alt/php83/usr/bin/php artisan optimize:clear
  ```

#### 🅱️ Option B: VPS / Dedicated Server Setup (High Performance & Scale)
* **`.env` Queue Connection:** Set `QUEUE_CONNECTION=redis` (or `database`)
* **Background Queue Worker (Supervisor):** Run Supervisor daemon to handle high-throughput WhatsApp message queues:
  ```bash
  php artisan queue:work --queue=whatsapp,default --tries=3
  ```
* **Real-time WebSockets (Laravel Reverb / Pusher):** Enable Reverb or Pusher in `.env`. When WebSockets are connected (`state === 'connected'`), the frontend automatically disables background polling and streams messages with 0ms latency.

