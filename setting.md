# Pusher Setup & Troubleshooting Guide

This guide contains the configuration and troubleshooting steps required to properly set up Pusher for real-time messaging (e.g., live inbox updates).

## 1. Environment Configuration (.env)
Make sure the following variables are properly set in your `.env` file on the live server (VPS):

```env
BROADCAST_CONNECTION=pusher

PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_APP_CLUSTER=your_cluster
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https

# Frontend Keys
VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_APP_CLUSTER="${PUSHER_APP_CLUSTER}"
```
**Important:** If `BROADCAST_CONNECTION` is left as `log` or `null`, the frontend will connect to Pusher, but the backend channel authentication (`/broadcasting/auth`) will fail and return an empty response!

## 2. Server Commands (After updating .env)
Whenever you update the `.env` file, clear the Laravel config cache:
```bash
php artisan config:clear
```
If you changed any `VITE_*` keys and the backend does not dynamically pass them to the frontend, you must rebuild the frontend assets:
```bash
npm install
npm run build
```

## 3. Content Security Policy (CSP)
If Pusher connections are being blocked by the browser with a CSP error, ensure the Pusher domains are whitelisted in `app/Http/Middleware/SecureHeaders.php` within the `connectSources` method:

```php
// Add this to allow Pusher WebSockets and Fallbacks
$sources[] = 'wss://*.pusher.com';
$sources[] = 'ws://*.pusher.com';
$sources[] = 'https://*.pusher.com';
```

## 4. Frontend Debugging (Optional)
To debug Pusher connection issues on the frontend, you can enable console logging in `resources/js/echo.js`:
```javascript
window.Pusher = Pusher;
window.Pusher.logToConsole = true; // Enables detailed Pusher logs in the browser console
```

## 5. Inbox State Bug Fix (React)
If the left sidebar updates via polling but the main chat view doesn't, ensure that `initialMessages` is added to the `useEffect` dependency array in `resources/js/Pages/Inbox/Show.jsx`:
```javascript
useEffect(() => {
    setMessages(initialMessages ?? []);
    // ...
}, [conversation.id, initialMessages]); // <-- Added initialMessages
```
