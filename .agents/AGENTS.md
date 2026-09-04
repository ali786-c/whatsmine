# Workspace Specific Rules

- **WhatsApp Automations:** ALWAYS use `send_template` instead of `send_whatsapp` (plain messages) for E-Commerce flows, even when responding to user actions (like button clicks). Do not use plain text messages for any order-related notifications (confirmed, cancelled, shipped, etc).
