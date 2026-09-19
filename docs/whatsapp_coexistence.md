# Onboard WhatsApp Business app users

**Warning:** **Embedded signup v2 will be deprecated on October 15, 2026.** Migrate your integration to v4 before that date to avoid disruption.

This feature is sometimes referred to as "Coexistence" in support channels and Partner documentation.

You can configure Embedded Signup to allow business customers to onboard using their existing WhatsApp Business app account and phone number. After a business customer chooses this option and onboards successfully, they can use your app to send high volumes of messages. They can still send messages on a one-to-one basis using the WhatsApp Business app, and WhatsApp keeps messaging history between both apps in sync.

## How it works

When you configure Embedded Signup for WhatsApp Business app phone numbers, a business customer who goes through the flow will be given the option to connect their existing WhatsApp Business app account to Cloud API.

If the business customer chooses to connect their existing account and enters their WhatsApp Business app phone number, WhatsApp presents a verification code to enter.

The message instructs the business to copy the verification code and follow the steps:

1. Expect to receive a message from the official Facebook Business Account. Tap **Connect**.
2. Tap the **Connect to the Business Platform** button to continue the onboarding process.
3. Tap the **Confirm** button in the app to give the business the option to share their chat history with you.
4. Paste the verification code.

They can complete the remainder of the Embedded Signup flow. Completing the flow returns their asset IDs and exchangeable token code to the spawning window, as normal. You can then use this information in API calls to onboard the business customer the same way you would any other business customer. You can also synchronize their contacts and messaging history (if permitted by the business) so you can populate it in your app.

## Requirements

- The business customer must use WhatsApp Business app version **2.24.17** or higher.
- You must already be a Solution Partner or Tech Provider.
- You must know how to use Cloud API.
- Your webhook callback must be able to successfully accept and digest webhooks.
- You must use Embedded Signup with session logging.

## Limitations

- To remain compatible with the WhatsApp Business app, business phone numbers that are in use with both the WhatsApp Business app and Cloud API have a fixed throughput of 20 mps.
- If your business customer worked with a partner in the past and still shares the previous credit line, they may see an error when attempting to switch to a new partner. Follow the Meta business-customer support guide to resolve the error.

## Pricing

After a business customer has been onboarded to Cloud API, messages sent by the business via the WhatsApp Business app will continue to be free, but messages sent via Cloud API will be subject to Cloud API pricing.

See Meta's "API Solutions for WhatsApp Business App Users" pricing explainer PDF for breakdowns of common pricing scenarios.

## Customer service window

WhatsApp opens a customer service window only when a WhatsApp user messages a business customer who is already onboarded onto Cloud API. If a WhatsApp user messages a business customer just before the business customer is onboarded onto Cloud API, the business customer can only respond with a template message, since WhatsApp opened no customer service window. If the WhatsApp user messages the business customer after onboarding onto Cloud API, WhatsApp opens a customer service window as normal, and the business customer can then respond with a non-template message.

The 24-hour customer service window restriction applies to messages sent via Cloud API. Messages sent from the WhatsApp Business app are not subject to the customer service window and do not create, extend, or affect Cloud API conversation windows or Cloud API pricing.

## Feature comparison

The following describes features available to business customers who have been onboarded to Cloud API, as well as any changes to WhatsApp Business app functionality post-onboarding.

| Existing feature on the WhatsApp Business App | Changes to features on the WhatsApp Business App after onboarding to Cloud API | Is the WhatsApp Business app feature supported on Cloud API? |
| --- | --- | --- |
| Individual (1:1) chats | Message Edit/Revoke is now supported. | Supported. All chat messages in the most recent 6 months can be synchronized. Messages sent and received are mirrored between the Cloud API and WhatsApp Business app. |
| Contacts | No change. | Supported. All contacts with a WhatsApp number can be synchronized. |
| Group chats | No change. | Not supported. Group chats will not be synchronized. |
| Disappearing messages | Disappearing messages will be turned off for all individual (1:1) chats. | Not supported. |
| View once message | View once messages will be disabled for all individual (1:1) chats. | Not supported. |
| Live location message | Live location messages will be disabled for all individual (1:1) chats. | Not supported. |
| Broadcast lists | Broadcast lists will be disabled. Business will not be able to create new Broadcast lists. Existing Broadcast lists will become read-only. | Not supported. |
| Voice and video calls | No change. | Not supported. |
| Business tools (catalog, orders, status) | No change. | Not supported. |
| Messaging tools (marketing messages, greeting message, away message, quick replies, labels) | No change. | Not supported. |
| Business profile (business name, address, website) | No change. | Not supported. |
| Channels | No change. | Not supported. |

## Linked devices

Businesses can link up to four WhatsApp "companion" clients to their WhatsApp Business app account on other devices ("linked devices" in the Help Center). All companion clients are supported except for WhatsApp for Windows and WhatsApp for WearOS.

Once a business customer onboards to Cloud API with an existing WhatsApp Business app account and number, all companion apps will be unlinked from the account, and the business can then re-link any supported companion apps.

WhatsApp users who use an unsupported companion client to message an onboarded business can do so, but the message will not trigger `messages` webhooks, so the business won't be able to mirror the message in their own app. Messages sent from an onboarded business (by any means) that are viewed in an unsupported companion device will appear with placeholder text, instructing the WhatsApp user to view the message in their primary device.

## Setting up your app

### Step 1: Subscribe to webhooks

Navigate to the App Dashboard > WhatsApp > Configuration panel and subscribe your app to the following WhatsApp Business account webhook topic fields, and make sure your app's callback code can digest payloads for each of them. These fields are in addition to any fields you are already subscribed to as a partner:

- `history` — describes past messages the business customer has sent/received
- `smb_app_state_sync` — describes the business customer's current and new contacts
- `smb_message_echoes` — describes any new messages the business customer sends with the WhatsApp Business app after having been onboarded

> In this codebase the subscription is registered programmatically with the complete field list — see `CloudApiClient::WEBHOOK_SUBSCRIPTION_FIELDS` and `docs/whatsapp_coexistence_implementation.md` §5. No manual Dashboard configuration is required, but re-run `php artisan whatsapp:register-webhook` if the subscription predates the coexistence fields.

### Step 2: Surface Embedded Signup to customers

Once you have confirmed that the feature has been enabled, surface Embedded Signup to your business customers.

When a business completes the flow and you onboard the customer, you have 24 hours to synchronize their messaging history, otherwise they must be offboarded and they must complete the flow again. For this reason:

- onboard and synchronize as soon as the business completes the flow
- inform the business that you are synchronizing their WhatsApp Business app data
- advise them to keep the WhatsApp Business app open to facilitate the synchronization process

Onboarding and synchronization can take several minutes, depending on the size of the business's messaging history, their internet speed, and how quickly you can digest webhooks.

When you complete the onboarding process, the WhatsApp Business app will automatically refresh and indicate to the business that their number is now connected to the API. After you finish synchronizing the business's messaging history, inform the customer that the process is complete.

## Onboarding business customers

When a business customer successfully completes the Embedded Signup flow, Embedded Signup returns their asset IDs and an exchangeable token code to the window that spawned the flow, as normal, but the session event payload sets `event` to `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`:

```json
{
  "data": {
    "waba_id": "<CUSTOMER_WABA_ID>"
  },
  "type": "WA_EMBEDDED_SIGNUP",
  "event": "FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING",
  "version": 3
}
```

Capture the customer's asset IDs and exchangeable token code and use them to onboard the customer as you normally would, but **skip the phone number registration step**, as the number is already registered.

Once you have completed the onboarding steps, you can begin the messaging history synchronization process.

### Check onboarding status (optional)

To confirm that the customer's business phone number is registered for both Cloud API and WhatsApp Business app use, request the `is_on_biz_app` and `platform_type` fields on the business phone number ID:

```bash
curl 'https://graph.facebook.com/v25.0/<BUSINESS_PHONE_NUMBER_ID>?fields=is_on_biz_app,platform_type' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

If `is_on_biz_app` is `true` and `platform_type` is `CLOUD_API`, the business phone number is able to use Cloud API and the WhatsApp Business app:

```json
{
  "is_on_biz_app": true,
  "platform_type": "CLOUD_API",
  "id": "<BUSINESS_PHONE_NUMBER_ID>"
}
```

## Synchronizing WhatsApp Business app data

After you onboard the business customer, you have 24 hours to synchronize their contacts and messaging history, otherwise they must be offboarded and complete the flow again. For this reason, begin the synchronization process as soon as you finish onboarding the business.

Make sure that you subscribed to the business's WABA when you onboarded the business, and that you are subscribed to the additional webhook fields (see Step 1), otherwise you will miss important webhooks.

> In this codebase, steps 1 and 2 below are executed by `SyncCoexistenceDataJob`, dispatched by the embedded-signup controller when it detects a `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING` session event (note: the frontend must send `session_event` for this to trigger — see the warning in `docs/whatsapp_coexistence_implementation.md`). Each sync type can only be performed once per onboarding; if it must be repeated, the customer must first offboard, then complete the Embedded Signup flow again.

### Step 1: Initiate contacts synchronization

Use the SMB App Data API to request the business customer's contacts information. If the request is successful, WhatsApp sends a set of `smb_app_state_sync` webhooks describing the WhatsApp contacts in the business's WhatsApp Business app. Future additions or changes to the business's WhatsApp contacts will trigger a corresponding `smb_app_state_sync` webhook.

```bash
curl -X POST \
  'https://graph.facebook.com/<API_VERSION>/<BUSINESS_PHONE_NUMBER_ID>/smb_app_data' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{"messaging_product": "whatsapp", "sync_type": "smb_app_state_sync"}'
```

Upon success:

```json
{
  "messaging_product": "whatsapp",
  "request_id": "<REQUEST_ID>"
}
```

Store the `request_id` value in case you need to contact support.

### Step 2: Initiate message history synchronization

Use the SMB App Data API again, this time to initiate messaging history synchronization. Upon success, zero, one, or more `history` webhooks will be triggered, depending on whether the business chose to share their messaging history with you. This step can also only be performed once.

- **Messaging history shared:** a series of `history` webhooks will be triggered, describing each message sent to, or received from, WhatsApp users within the covered period. See [History webhooks](#history) below.
- **Messaging history not shared:** a `history` webhook with error code `2593109` will be triggered instead.

```bash
curl -X POST \
  'https://graph.facebook.com/<API_VERSION>/<BUSINESS_PHONE_NUMBER_ID>/smb_app_data' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -H 'Content-Type: application/json' \
  -d '{"messaging_product": "whatsapp", "sync_type": "history"}'
```

If the request is successful, the API responds with the same `{"messaging_product": "whatsapp", "request_id": "<REQUEST_ID>"}` payload. This response only indicates successful acceptance of the request; it does not indicate whether the business shared their messaging history with you. Store the `request_id` value in case you need to contact support.

### Step 3: Mirror new WhatsApp Business app messages

Onboarded businesses are still able to use the WhatsApp Business app and supported companion devices to send and receive messages. Each time a business sends a message with one of these apps, it triggers an `smb_message_echoes` webhook, which you must digest and display in the contact message thread history in your app.

## Message mirroring in this app (SaaS inbox)

Once a coexistence number is connected, the customer's conversation in the SaaS inbox mirrors both directions of messaging automatically:

| Direction | How it arrives | Where it lands in the inbox |
| --- | --- | --- |
| Customer → business (inbound) | `messages` webhook field (`type: text`, media, etc.) | Customer's conversation, `direction: 'in'`, unread count +1, `MessageReceived` event fires (automations/chatbots react) |
| Business → customer, sent **from the SaaS** | Cloud API send; delivery/read updates arrive as `statuses` | Customer's conversation, `direction: 'out'` |
| Business → customer, sent **from the WhatsApp Business mobile app** | `smb_message_echoes` webhook field (payload array is `message_echoes`) | **Same customer's conversation**, `direction: 'out'`, `sent_by: 'human'`, contact source `whatsapp_echo` |

Key implementation rules (see `WhatsappDriver::processInboundMessage()`):

- An echo's `from` is the **business's own number** and its `to` is the customer. The driver detects this (`smb_message_echoes` field, or `from` equal to `metadata.display_phone_number`) and files the message under the **customer's** contact/conversation — never under the business's own number. A ghost conversation for the business number must not be created.
- Echoes raise **no unread count** and do not touch `last_inbound_at` / `first_response_at` — the business sent the message, nobody is waiting for a reply.
- Echoes dispatch `MessageSent` instead of `MessageReceived`, so chatbots, auto-replies, and automation funnels **never trigger on the business's own app replies** (self-reply-loop protection).
- Echo idempotency uses the same `WebhookIdempotencyService` guard as inbound messages (key `whatsapp_msg` on the Meta message ID), so mirrored Cloud API messages and their echo duplicates collapse into one row.
- A `to`-less echo is logged and skipped, never crashes the webhook.

> Historical note: builds before the direction-aware-echo fix stored echoes as inbound messages on the business's own number, creating ghost conversations. Messages stored during that window live in the business-number conversation and may need a one-time cleanup/merge.

## Reporting conversion activity

Onboarded business customers may run Click to WhatsApp ads, so report purchase/lead-gen signals on behalf of the business using the Conversions API. See Meta's "Conversions API for business messaging" documentation.

## Offboarding business customers

You cannot use the Deregister API to deregister a business phone number from Cloud API if it is already in use with both Cloud API and the WhatsApp Business app.

Instead, your clients can use the WhatsApp Business app to disconnect from Cloud API by navigating to **Settings** > **Account** > **Business Platform** and clicking the **Disconnect Account** button. When your client disconnects from Cloud API, an `account_update` webhook with a `PARTNER_REMOVED` event is triggered. This webhook may include a `disconnection_info` object that indicates the reason for the disconnection and whether it was initiated by your client or the system.

## Errors

If you onboard a business customer with a WhatsApp Business app phone number, you may receive an unsupported messages webhook with error code `131060`. This is expected and can occur in the following scenarios:

- **First-time messaging:** a WhatsApp user messages your business for the first time. This is especially common when users tap one of your ads that click to WhatsApp and immediately send a message. The error typically resolves within a few seconds, after which WhatsApp delivers messages normally.
- **Unsupported companion device:** a WhatsApp user with an unsupported companion device sends or receives a message to or from your business.

If you receive this webhook, instruct the business to check the WhatsApp Business app for the message.

## Webhooks

The coexistence webhook fields our app subscribes to (via `CloudApiClient::WEBHOOK_SUBSCRIPTION_FIELDS`) are `messages`, `account_update`, `history`, `smb_app_state_sync`, and `smb_message_echoes`, alongside the non-coexistence fields. The sections below describe the coexistence-specific payloads.

> Note: `ACCOUNT_OFFBOARDED`, `ACCOUNT_RECONNECTED`, and `PARTNER_REMOVED` are **event types delivered inside the `account_update` field**, not separate webhook fields. Edit and revoke arrive as `type: "edit"` / `type: "revoke"` **inside the `messages` field**. (Earlier revisions of this doc listed `account_offboarded` and `account_reconnected` as fields; they are not.)

### account_update — coexistence events

Describes account-level changes relevant to coexistence. Trigger events: the business phone number changes, the WABA's status changes, or the business offboards/reconnects.

Example `PARTNER_REMOVED` payload:

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "102290129340398",
      "time": 1739212624,
      "changes": [
        {
          "value": {
            "phone_number": "15550783881",
            "event": "PARTNER_REMOVED",
            "disconnection_info": {
              "reason": "PRIMARY_INACTIVITY",
              "initiated_by": "SYSTEM"
            }
          },
          "field": "account_update"
        }
      ]
    }
  ]
}
```

The `disconnection_info` object contains:

- `reason` — why the client was disconnected. Values: `ACCOUNT_DISCONNECTED` (enforcement or the client deleted their WhatsApp account), `BUSINESS_DOWNGRADE` (client registered their business phone number with the consumer WhatsApp app), `CHANGE_NUMBER` (client changed their phone number), `COMPANION_INACTIVITY` (companion device inactive ~30 days), `PRIMARY_INACTIVITY` (primary device inactive ~14 days), `USER_RE_REGISTERED` (client re-registered on a new device).
- `initiated_by` — whether the disconnection was client- or system-initiated. Values: `USER`, `SYSTEM`.

`ACCOUNT_OFFBOARDED` and `ACCOUNT_RECONNECTED` are delivered the same way, as `{"event": "ACCOUNT_OFFBOARDED"}` or `{"event": "ACCOUNT_RECONNECTED"}` inside `account_update`, when the business offboards due to device change/re-registration or re-onboards after previously offboarding.

### Edit and Revoke

Edit/revoke events arrive inside the `messages` webhook field as messages with `type: "edit"` or `type: "revoke"`. A WhatsApp user can edit a previously sent message (text, media with caption) within 15 minutes, or revoke (delete) a previously sent message within two days.

Example `edit` payload:

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "<WABA_ID>",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "<BUSINESS_DISPLAY_PHONE_NUMBER>",
              "phone_number_id": "<BUSINESS_PHONE_NUMBER_ID>"
            },
            "contacts": [{ "profile": { "name": "<WHATSAPP_USER_PROFILE_NAME>" }, "wa_id": "<WHATSAPP_USER_ID>" }],
            "messages": [
              {
                "from": "<WHATSAPP_USER_PHONE_NUMBER>",
                "id": "<WHATSAPP_MESSAGE_ID>",
                "timestamp": "<WEBHOOK_TRIGGER_TIMESTAMP>",
                "type": "edit",
                "edit": {
                  "original_message_id": "<ORIGINAL_WHATSAPP_MESSAGE_ID>",
                  "message": {
                    "context": { "id": "<CONTEXT_ID>" },
                    "type": "image",
                    "image": {
                      "caption": "<MEDIA_ASSET_CAPTION>",
                      "mime_type": "<MEDIA_ASSET_MIME_TYPE>",
                      "sha256": "<MEDIA_ASSET_SHA256_HASH>",
                      "id": "<MEDIA_ASSET_ID>",
                      "url": "<MEDIA_ASSET_URL>"
                    }
                  }
                }
              }
            ]
          },
          "field": "messages"
        }
      ]
    }
  ]
}
```

Example `revoke` payload:

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "<WABA_ID>",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "<BUSINESS_DISPLAY_PHONE_NUMBER>",
              "phone_number_id": "<BUSINESS_PHONE_NUMBER_ID>"
            },
            "contacts": [{ "profile": { "name": "<WHATSAPP_USER_PROFILE_NAME>" }, "wa_id": "<WHATSAPP_USER_ID>" }],
            "messages": [
              {
                "from": "<WHATSAPP_USER_PHONE_NUMBER>",
                "id": "<WHATSAPP_MESSAGE_ID>",
                "timestamp": "<WEBHOOK_TRIGGER_TIMESTAMP>",
                "type": "revoke",
                "revoke": {
                  "original_message_id": "<ORIGINAL_WHATSAPP_MESSAGE_ID>"
                }
              }
            ]
          },
          "field": "messages"
        }
      ]
    }
  ]
}
```

### History

Describes the WhatsApp Business app chat history of a business that has chosen to share their chat history with a partner, or the business's decision to decline chat history sharing.

Trigger events: a partner synchronizes the WhatsApp Business app chat history of a business customer they have onboarded with a WhatsApp Business app phone number, and the customer either agreed or declined to share their chat history.

#### Chat history contents

If the business approved chat history sharing when the partner requests it, a series of `history` webhooks will be triggered, describing all messages sent or received within **180 days** of the time when the business was onboarded onto Cloud API:

- messages that are part of a group chat will not be included
- media messages will not include media asset IDs; instead, additional history webhooks containing media message asset IDs will be sent separately, but only for media messages sent within 14 days of onboarding

For efficiency purposes, a single webhook could describe thousands of messages, so capture its contents first, then process the contents asynchronously.

#### Phases and chunks

Webhooks are divided into three history phases, where day 0 indicates the time when the business was onboarded onto Cloud API:

- phase 0: day 0 through day 1
- phase 1: day 1 through day 90
- phase 2: day 90 through day 180

For each phase, chat history webhooks may be sent in separate chunks, depending on the total number of messages that comprise the thread:

- `chunk_order` orders the chunks in their sequential order, as they may not be delivered sequentially
- `phase` monitors phase progress; a value of `2` indicates that the current phase is complete
- `progress` monitors overall progress; a value of `100` indicates that synchronization is complete

If there is no chat history available for a given phase, no corresponding webhooks will be sent.

#### Payload syntax — chat history sharing approved

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "<WABA_ID>",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "<BUSINESS_PHONE_NUMBER>",
              "phone_number_id": "<BUSINESS_PHONE_NUMBER_ID>"
            },
            "history": [
              {
                "metadata": {
                  "phase": "<PHASE>",
                  "chunk_order": "<CHUNK_ORDER>",
                  "progress": "<PROGRESS>"
                },
                "threads": [
                  {
                    "id": "<WHATSAPP_USER_PHONE_NUMBER>",
                    "messages": [
                      {
                        "from": "<BUSINESS_OR_WHATSAPP_USER_PHONE_NUMBER>",
                        "to": "<WHATSAPP_USER_PHONE_NUMBER>",
                        "id": "<WHATSAPP_MESSAGE_ID>",
                        "timestamp": "<DEVICE_TIMESTAMP>",
                        "type": "<MESSAGE_TYPE>",
                        "<MESSAGE_TYPE>": {},
                        "history_context": {
                          "status": "<MESSAGE_STATUS>"
                        }
                      }
                    ]
                  }
                ]
              }
            ]
          },
          "field": "history"
        }
      ]
    }
  ]
}
```

Key fields:

- `<PHASE>` — history phase (0, 1, 2) as described above.
- `<CHUNK_ORDER>` — chunk number for ordering sets of webhooks sequentially.
- `<PROGRESS>` — percentage of total synchronization progress (0–100).
- `from` — the business's phone number (message sent by the business) or the WhatsApp user's phone number (message sent by the user to the business).
- `to` — only included if the message object represents an SMB message echo (business-sent message).
- `type: media_placeholder` — the message contained a media asset whose contents are omitted; a separate history webhook with the media contents and asset ID follows, but only if the message was sent within the last two weeks.
- `history_context.status` — most recent delivery status: `DELIVERED`, `ERROR`, `PENDING`, `PLAYED`, `READ`, `SENT`.

#### Example payload — chat history sharing approved

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "102290129340398",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "15550783881",
              "phone_number_id": "106540352242922"
            },
            "history": [
              {
                "metadata": {
                  "phase": 0,
                  "chunk_order": 1,
                  "progress": 55
                },
                "threads": [
                  {
                    "id": "16505551234",
                    "messages": [
                      {
                        "from": "15550783881",
                        "id": "wamid.HBgLMTY0NjcwNDM1OTUVAgARGBIyNDlBOEI5QUQ4NDc0N0FCNjMA",
                        "timestamp": "1739230955",
                        "type": "text",
                        "text": {
                          "body": "Here's the info you requested! https://www.meta.com/quest/quest-3/"
                        },
                        "history_context": { "status": "READ" }
                      },
                      {
                        "from": "15550783881",
                        "id": "wamid.QyNUEHBgLMTY0NjcwNDM1OTUVAgARGBI1Rj3NEYxMzAzMzQ5MkEA",
                        "timestamp": "1739230970",
                        "type": "media_placeholder",
                        "history_context": { "status": "PLAYED" }
                      },
                      {
                        "from": "16505551234",
                        "id": "wamid.N0FCNjMAHBgLMTY0NjcwNDM1OTUVAgARGBIyNDlBOEI5QUQ4NDc0",
                        "timestamp": "1739230970",
                        "type": "text",
                        "text": { "body": "Thanks!" },
                        "history_context": { "status": "READ" }
                      }
                    ]
                  },
                  {
                    "id": "12125557890",
                    "messages": [
                      {
                        "from": "15550783881",
                        "id": "wamid.BIyNDlBOEI5N0FCNjMAHBgLMTY0NjcwNDM1OTUVAgARGQUQ4NDc0",
                        "timestamp": "1739230970",
                        "type": "text",
                        "text": {
                          "body": "Thanks for your order! As a thank you, use code THANKS30 to get 30% of your next order."
                        },
                        "history_context": { "status": "DELIVERED" }
                      }
                    ]
                  }
                ]
              }
            ]
          },
          "field": "history"
        }
      ]
    }
  ]
}
```

#### Example payload for media message asset

The follow-up webhook describing a media message's contents arrives on the `history` field with a `messages` array:

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "102290129340398",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "15550783881",
              "phone_number_id": "106540352242922"
            },
            "messages": [
              {
                "from": "16505551234",
                "id": "wamid.QyNUEHBgLMTY0NjcwNDM1OTUVAgARGBI1Rj3NEYxMzAzMzQ5MkEA",
                "timestamp": "1738796547",
                "type": "image",
                "image": {
                  "caption": "Black Prince echeveria",
                  "mime_type": "image/jpeg",
                  "sha256": "3f9d94d399fa61c191bc1d4ca71375a035cd9b9f5b1128e1f0963a415c16b0cc",
                  "id": "24230790383178626"
                }
              }
            ]
          },
          "field": "history"
        }
      ]
    }
  ]
}
```

#### Payload — chat history sharing declined

If the business declined to share history, a `history` webhook with error code `2593109` is triggered:

```json
{
  "messaging_product": "whatsapp",
  "metadata": {
    "display_phone_number": "15550783881",
    "phone_number_id": "106540352242922"
  },
  "history": [
    {
      "errors": [
        {
          "code": 2593109,
          "title": "History sync is turned off by the business from the WhatsApp Business App",
          "message": "History sync is turned off by the business from the WhatsApp Business App",
          "error_data": {
            "details": "History sharing is turned off by the business"
          }
        }
      ]
    }
  ]
}
```

### smb_app_state_sync

Describes one or more WhatsApp contacts in a business customer's WhatsApp Business app.

Trigger events: a partner initiates contacts synchronization for an onboarded coexistence business, or the business adds, edits, or removes a WhatsApp contact.

Payload syntax:

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "<WABA_ID>",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "<BUSINESS_PHONE_NUMBER>",
              "phone_number_id": "<BUSINESS_PHONE_NUMBER_ID>"
            },
            "state_sync": [
              {
                "type": "contact",
                "contact": {
                  "full_name": "<CONTACT_FULL_NAME>",
                  "first_name": "<CONTACT_FIRST_NAME>",
                  "phone_number": "<CONTACT_PHONE_NUMBER>"
                },
                "action": "<ACTION>",
                "metadata": { "timestamp": "<CONTACT_CHANGE_TIMESTAMP>" }
              }
            ]
          },
          "field": "smb_app_state_sync"
        }
      ]
    }
  ]
}
```

Key fields:

- `contact.full_name` / `contact.first_name` — the contact's names as they appear in the business's address book. Not included when a contact is removed.
- `contact.phone_number` — the contact's WhatsApp phone number.
- `action` — `add` (the business added or edited a contact) or `remove` (the business removed a contact).
- `metadata.timestamp` — Unix timestamp when the contact was added, edited, or removed.

### smb_message_echoes

Describes a message sent by a business customer to a WhatsApp user with the WhatsApp Business app or a supported companion device. Trigger event: the business uses the WhatsApp Business app or supported companion device to message a WhatsApp user.

Payload syntax:

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "<WABA_ID>",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "<BUSINESS_PHONE_NUMBER>",
              "phone_number_id": "<BUSINESS_PHONE_NUMBER_ID>"
            },
            "message_echoes": [
              {
                "from": "<BUSINESS_PHONE_NUMBER>",
                "to": "<WHATSAPP_USER_PHONE_NUMBER>",
                "id": "<WHATSAPP_MESSAGE_ID>",
                "timestamp": "<WEBHOOK_TIMESTAMP>",
                "type": "<MESSAGE_TYPE>",
                "<MESSAGE_TYPE>": {}
              }
            ]
          },
          "field": "smb_message_echoes"
        }
      ]
    }
  ]
}
```

Example payload (a text message sent to a WhatsApp user by a business customer with the WhatsApp Business app):

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "id": "102290129340398",
      "changes": [
        {
          "value": {
            "messaging_product": "whatsapp",
            "metadata": {
              "display_phone_number": "15550783881",
              "phone_number_id": "106540352242922"
            },
            "message_echoes": [
              {
                "from": "15550783881",
                "to": "16505551234",
                "id": "wamid.HBgLMTY0NjcwNDM1OTUVAgARGBIyNDlBOEI5QUQ4NDc0N0FCNjMA",
                "timestamp": "1700255121",
                "type": "text",
                "text": {
                  "body": "Here's the info you requested! https://www.meta.com/quest/quest-3/"
                }
              }
            ]
          },
          "field": "smb_message_echoes"
        }
      ]
    }
  ]
}
```

## Need support?

For Coexistence onboarding issues, choose Question Topic "WABiz: Onboarding" / "TechProvider: Onboarding" and Request Type "Embedded Signup - Coexistence Onboarding". For Coexistence API issues, choose Question Topic "WABiz: Cloud API" and Request Type "Coexistence Data Synchronization APIs and Webhooks".
