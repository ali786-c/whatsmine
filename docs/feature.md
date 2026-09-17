# WhatsMine - New Features (Sept 4, 2026)

## 1. Smart "Wait for Reply" Node for Automation
We have introduced a major upgrade to the Automation Builder by upgrading the traditional "Wait" node. 
- **Previous Behavior:** The Wait node could only pause a workflow for a specific amount of time (e.g., Wait 2 Hours).
- **New Behavior:** Users can now switch the wait type from `time` to `reply`. When set to `reply`, the workflow is parked indefinitely until the customer sends a WhatsApp reply.
- **Custom Variables:** The exact reply from the customer can be automatically saved to a custom context variable (e.g., `cod_reply`), allowing the workflow to make conditional decisions based on what the user said (like "Yes" or "No").

## 2. Dynamic E-Commerce COD Flow
We successfully overhauled the `ecommerce_cod_verification` template to utilize the new Smart Wait Node. The new 7-step flow operates as follows:
1. **Trigger:** Order is Placed.
2. **Condition:** Checks if `context.payment_method` is equal to `cod`.
3. **Action:** Sends a WhatsApp template asking the user to confirm their order.
4. **Wait Node:** Parks the flow waiting for the user to reply. The reply is saved to `cod_reply`.
5. **Condition:** Evaluates if `context.cod_reply` matches the exact button payload for "Confirm" or "Cancel".
6. **Action (YES Branch):** Sends the `ecommerce_order_confirmed` WhatsApp template.
7. **Action (NO Branch):** Sends the `ecommerce_order_cancelled` WhatsApp template.

## 3. Template Usage Enforcement (AGENTS.md)
We introduced permanent customization rules inside `.agents/AGENTS.md` and `.agents/brain.md` which instructs all future AI assistants to **strictly use pre-approved WhatsApp templates** for e-commerce automation nodes instead of sending plain text `send_whatsapp` messages. This ensures 100% compliance with Meta's 24-hour messaging window policies.

## 4. UI/UX: Automation Template Tab Gallery
The template selection UI has been vastly improved:
- The old modal/popup for the Template Gallery was completely removed.
- Introduced a seamless tabbed navigation system in `Index.jsx` for the Automations page (`My Automations` and `Pre-built Templates`).
- Cleaned up obsolete templates (removed the unneeded WooCommerce & Shopify routing template).

## 5. Zero-Cost AI Product Search (RAG Hybrid)
We successfully integrated a real-time E-Commerce product catalog search directly into the AI Chatbot Engine without relying on expensive Vector Embeddings.
- **Dynamic Context Injection:** When a customer sends a message on WhatsApp, `ChatbotRunner.php` intercepts it and extracts keywords.
- **Zero API Sync Cost:** It queries the local `ecommerce_products` table for matches instead of relying on OpenAI embeddings. This means when stock or prices change, there is NO sync delay and NO embedding API cost.
- **Ghost Injection:** The top matching products (with their SKU, precise price, and live stock count) are formatted and silently injected into the LLM system prompt right before the LLM generates a response. This allows the AI to perfectly answer questions like *"Do you have the black shirt?"* while consuming minimal tokens.
