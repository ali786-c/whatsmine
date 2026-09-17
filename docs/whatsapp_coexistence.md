# Onboard WhatsApp Business app users

**Warning:** **Embedded signup v2 will be deprecated on October 15, 2026.** Migrate your integration to v4 before that date to avoid disruption.

This feature is sometimes referred to as "Coexistence" in support channels and Partner documentation.

You can configure Embedded Signup to allow business customers to onboard using their existing WhatsApp Business app account and phone number. After a business customer chooses this option and onboards successfully, they can use your app to send high volumes of messages. They can still send messages on a one-to-one basis using the WhatsApp Business app, and WhatsApp keeps messaging history between both apps in sync.

## How it works

When you configure Embedded Signup for WhatsApp Business app phone numbers, a business customer who goes through the flow will be given the option to connect their existing WhatsApp Business app account to Cloud API.

If the business customer chooses to connect their existing account and enters their WhatsApp Business app phone number, WhatsApp presents a verification code to enter.

The message instructs the business to copy the verification code and follow the steps:
Expect to receive a message from the official Facebook Business Account. Tap **Connect**.
Tap the **Connect to the Business Platform** button to continue the onboarding process.
Tap the **Confirm** button in the app to give the business the option to share their chat history with you.
Paste the verification code.

They can complete the remainder of the Embedded Signup flow. Completing the flow returns their asset IDs and exchangeable token code to the spawning window, as normal. You can then use this information in API calls to onboard the business customer the same way you would any other business customer. You can also synchronize their contacts and messaging history (if permitted by the business) so you can populate it in your app.

## Requirements

- The business customer must use WhatsApp Business app version **2.24.17** or higher.
- You must already be a Solution Partner or Tech Provider.
- You must know how to use Cloud API.
- Your webhook callback must be able to successfully accept and digest webhooks.
- You must use Embedded Signup with session logging.

## Limitations
- To remain compatible with the WhatsApp Business app, business phone numbers that are in use with both the WhatsApp Business app and Cloud API have a fixed throughput of 20 mps.
- If your business customer worked with a partner in the past and still shares the previous credit line, they may see an error when attempting to switch to a new partner. 

## Pricing

After a business customer has been onboarded to Cloud API, messages sent by the business via the WhatsApp Business app will continue to be free, but messages sent via Cloud API will be subject to Cloud API pricing.

## Customer service window

WhatsApp opens a customer service window only when a WhatsApp user messages a business customer who is already onboarded onto Cloud API. The 24-hour customer service window restriction applies to messages sent via Cloud API. Messages sent from the WhatsApp Business app are not subject to the customer service window and do not create, extend, or affect Cloud API conversation windows or Cloud API pricing.

## Feature comparison

- Individual (1:1) chats: Supported. Mirrored between API and App.
- Contacts: Supported. Synchronized.
- Group chats: Not supported.
- Disappearing messages: Not supported.
- View once message: Not supported.
- Live location message: Not supported.
- Broadcast lists: Not supported.
- Voice and video calls: Not supported.
- Channels: Not supported.

## Linked devices

Businesses can link up to four WhatsApp "companion" clients. Once a business customer onboards to Cloud API with an existing WhatsApp Business app account and number, all companion apps will be unlinked from the account, and the business can then re-link any supported companion apps.

## Setting up your app

### Step 1: Subscribe to webhooks
Navigate to the App Dashboard > WhatsApp > Configuration panel and subscribe your app to the following WhatsApp Business account webhook topic fields:
- `history` — describes past messages the business customer has sent/received
- `smb_app_state_sync` — describes the business customer's current and new contacts
- `smb_message_echoes` — describes any new messages the business customer sends with the WhatsApp Business app after having been onboarded

### Step 3: Surface Embedded Signup to customers
When a business completes the flow, you have 24 hours to synchronize their messaging history, otherwise they must be offboarded and they must complete the flow again. 
Onboarding and synchronization can take several minutes.

## Onboarding business customers

Capture the customer's asset IDs and exchangeable token code and use them to onboard the customer as you normally would, but **skip the phone number registration step**, as the number is already registered.

To confirm that the customer's business phone number is registered for both Cloud API and WhatsApp Business app use, request the `is_on_biz_app` and `platform_type` fields on the business phone number ID:
`curl 'https://graph.facebook.com/v25.0/PHONE_ID?fields=is_on_biz_app,platform_type'`

## Synchronizing WhatsApp Business app data
1. Initiate contacts synchronization via `smb_app_data` with `sync_type: smb_app_state_sync`.
2. Initiate message history synchronization via `smb_app_data` with `sync_type: history`.
3. Mirror new WhatsApp Business app messages via `smb_message_echoes` webhooks.

## Offboarding business customers

You cannot use the Deregister API. Instead, your clients can use the WhatsApp Business app to disconnect from Cloud API by navigating to the Settings > Account > Business Platform and clicking the Disconnect Account button. This triggers an `account_update` webhook with a `PARTNER_REMOVED` event.

## Webhooks

- `account_update`
- `account_offboarded`
- `account_reconnected`
- `edit` (for edited messages)
- `revoke` (for deleted messages)
- `history`
- `smb_app_state_sync`
- `smb_message_echoes`
