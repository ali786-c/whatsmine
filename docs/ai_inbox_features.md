# WhatsMine — AI Chatbot & Inbox Features (Sept 25, 2026)

This release hardens the AI chatbot's answer quality, makes the inbox feel native-WhatsApp (quotes, voice notes, reliable media), and documents every server requirement needed to keep the voice-note pipeline working after deploys.

## 1. Chatbot Knowledge-Base Grounding (Hybrid Retrieval)

The bot no longer hedges ("please confirm with our team") when the knowledge base actually contains the answer.

- **Hybrid retrieval (RRF):** embedding search and keyword search always both run; results are fused with Reciprocal Rank Fusion (`k=60`). Either path alone can miss — together they are resilient.
- **Keyword scoring upgrades:** a `SYNONYMS` map expands customer phrasing (Urdu/English), exact number matches get a +0.3 bonus, exact phrase matches a +0.5 bonus.
- **Grounded-answer retry:** if the first LLM reply looks hedged (`HEDGE_PATTERNS`) while retrieval had strong hits, the job retries once with a grounding nudge. `meta['grounded_retry']` records when this happens.
- **Diagnostics:** `AiDiagnostic` now probes both retrieval paths separately (`embedding_hits`, `keyword_hits`, `via`) so mis-grounding is debuggable in one glance.

## 2. Playground Conversation Memory

The AI playground now remembers the conversation like a real chat.

- The client sends its recent turns; `AiChatbotController::sanitizeHistory()` keeps only `user`/`assistant` roles, clamps each message to `HISTORY_MAX_CHARS` (500), and caps total turns at `HISTORY_TURNS * 2`.
- `ChatbotRunner::run()` accepts an optional 4th `clientHistory` argument — the production WhatsApp path still loads history from the DB (`meta['history_turns']` reports the count).

## 3. Inbox: Quoted-Reply Display (Inbound)

Customer quote-replies now render WhatsApp-style in the conversation.

- `Show.jsx` `extractQuoted()` parses Cloud API (`payload.context.{id,quoted_message}`) and Baileys (`quotedMsg`/`quotedMessage`/`contextInfo.stanzaId`) shapes.
- `findQuotedBody()` resolves the quoted `provider_message_id` from already-loaded messages; media quotes show a typed label (`QUOTED_TYPE_LABELS`).
- `QuotedPreview` renders the familiar grey quoted block inside `MessageBubble`.

## 4. Bot Replies Quote the Customer's Message

Outbound AI replies arrive on the customer's phone as a native quote of their question — the bot feels human.

- `CloudApiClient::sendText(to, body, previewUrl, quotedMessageId)` adds `context.message_id`.
- `GenerateAiReplyJob` stores `'payload' => ['quoted_message_id' => $message->provider_message_id]`.
- `WhatsappDriver::send()` retries once **without** the quote if the upstream rejects the context, so the reply is never lost.

## 5. Inbound Media Reliability ("Image unavailable" fix)

`InboxController::serveMedia()` previously redirected to `disk->url()`, which 404s whenever the `/storage` symlink is missing. It now **streams bytes inline** (`streamStored()`: correct Content-Type map, `inline` disposition, 24h Cache-Control) across all three paths (QR cache hit, QR download, Cloud download). Symlink or not, images/audio/video render.

## 6. Voice Notes: Record & Send from the Inbox Composer

Agents can record and send WhatsApp voice notes directly from the inbox. **Instagram conversations support the same composer** (see §6.1).

- **Composer UI:** mic button left of the textarea (MediaRecorder, prefers `audio/ogg;codecs=opus`, falls back to webm), recording indicator with seconds, audio preview player before send.
- **Server transcode:** Chrome records `audio/webm`, which the WhatsApp Cloud API rejects. `InboxController::transcodeAudioToOgg()` converts to `audio/ogg` (libopus 32k, 16kHz, mono) so it sends as a native voice note.
- **Graceful errors:** every failure path sets a human-readable reason (see §8) and returns a 422 with that exact reason instead of a cryptic 500 or a Meta rejection.

### 6.1 Instagram Voice Notes & Media

Instagram Messaging differs from WhatsApp in two ways the implementation absorbs:

| | WhatsApp Cloud API | Instagram Messaging |
|---|---|---|
| Accepted audio | `audio/ogg` (opus) as **voice note**; aac/m4a/mp3 as audio | **aac, m4a, wav, mp4 only** — no ogg, no webm |
| Delivery | Upload → `media_id` | **No upload API** — attachment references a **public URL** Meta fetches |
| Voice-bubble UX | Yes (`voice: true`) | Standard audio bubble |

- **Transcode target:** on Instagram conversations the composer's webm is converted to **AAC in an MP4 container** (`transcodeAudioToM4a()`, 64k, 44.1kHz, mono) — same ffmpeg, same fail-fast 422 reasons.
- **Storage:** the file is stored via the workspace Storage integration and its **absolute URL** (`StorageManager::publicUrl()`) travels in the message payload. Cloud storage (S3 / Spaces / Wasabi) yields a real public URL automatically; on the local disk the APP URL must be publicly reachable and serve `/storage`.
- **Privacy note:** because Meta fetches the URL itself, Instagram media is necessarily served from a publicly reachable location (WhatsApp's media-id flow keeps files private).
- **Inbound:** customer-sent audio/image/video/file attachments are now mapped to proper message types with the sender's CDN URL, so they render as playable/visible bubbles in the thread (previously everything showed as an empty text bubble).
- Video and document attachments reuse the same public-URL mechanism (`video` / `file` attachment types).
- **24-hour window:** when the window is closed, the driver retries once with the `HUMAN_AGENT` tag (valid for 7 days since the customer's last message) — for **any** message type, audio included. Meta localises the window error (production reported it in Russian), so detection matches English, Russian («за пределами») and the generic word "window". If even the tagged send fails, the surfaced error is an actionable English message explaining the window state instead of the localised Meta string.

### Browser permission chain (why mic could "never" prompt)

| Layer | Symptom when blocked | Fix |
|---|---|---|
| `Permissions-Policy` header | Instant `NotAllowedError`, no prompt, site settings irrelevant | App must send `microphone=(self)` (see §7) |
| Chrome global default | No prompt anywhere | `chrome://settings/content/microphone` → "Sites can ask" |
| Chrome site setting | Prompt never re-appears | Lock icon → Site settings → Microphone → Allow |
| Windows privacy | Works in other apps, not browser | Settings → Privacy & security → Microphone → allow apps + desktop apps |

> The `permissions.query()` pre-check was removed: Chrome can report `denied` even when the site toggle is Allow. `getUserMedia`'s own error is the only authoritative signal, and its raw error name is now appended to the UI message (e.g. `[NotAllowedError]`) plus `console.error` for debugging.

## 6A. AI → Human Handover: Auto-Reply + "Waiting for you" Label

When the customer asks for a human ("talk to human", "human chahiye"…), the handover now has a full customer experience instead of silence:

1. **Immediate acknowledgment** — the bot sends "Connecting you with a human agent…" to the customer. The text is **configurable per chatbot** (`AiChatbot › Handover Reply`, all 17 languages translated in the UI); empty = language-aware default (Roman Urdu customers get the Roman Urdu reply, everyone else English).
2. **"Waiting for you" label** — auto-attached to the conversation (amber, created once per workspace, marked `auto_assigned`). The team's inbox visibly queues who needs a human.
3. **Auto-cleanup** — when a human agent sends the first reply, the waiting label is removed automatically.
4. **AI stays silent** after handover (pre-existing behavior, verified) — the agent owns the thread.

Implementation: `App\Modules\Inbox\Services\HandoverService` (announce / attachWaitingLabel / removeWaitingLabel), wired in `AutoReplyListener::triggerHandover()` and the inbox reply path. Migration adds `ai_chatbots.handover_reply` + `inbox_labels.auto_assigned`. Tests: `tests/Feature/Inbox/HandoverLabelTest.php` (5).

## 7. Engine-Level Emoji Intelligence (EmojiEngine)

The LLM understands emoji natively — but everything AROUND the model is text machinery, and emoji died there: keyword retrieval strips punctuation (`📦` became nothing), the embedder saw an unknown token, the system prompt had no signal, and run metadata recorded nothing.

`app/Modules/AI/Services/EmojiEngine.php` closes the gap — a deterministic, curated (no giant auto-generated table) layer wired into every `ChatbotRunner::run()` AND `runForApi()` (playground) pass:

1. **normalize()** — known emoji become their semantic phrase before embedding/retrieval: "Package 📦 kab aayega?" → "Package package delivery kab aayega?". KB prose written in words now matches emoji-only queries.
2. **analyze()** — sentiment (positive/negative/angry/urgent/neutral), intensity 1–3, confidence (single-dominant-signal wins over mixed), and intents: `escalation_risk` (😡🤬😤), `urgency` (🚨❗⏳), `agreement` (🤝✅), `purchase_or_delivery` (🛒📦🚚💰).
3. **promptLayer()** — a deterministic "EMOJI SIGNAL" block appended to the system prompt ONLY when the message carries emoji (clean tokens otherwise). Explicit instructions steer even weak local models: anger → empathy + de-escalation + handover candidate at 3/3; positive → match energy, suggest next step; urgency → acknowledge time pressure first.
4. **expandKeywords()** — emoji-derived keywords join the keyword-retrieval expansion set, so "🔥🔥" (no words at all) still surfaces relevant KB chunks.
5. **metaFor()** — `emoji_count / emoji_sentiment / emoji_intensity / emoji_intents` land in run meta for diagnostics and eval.

Regression tests: `tests/Feature/AI/EmojiEngineTest.php` (17 tests).

### Universal coverage: CLDR dataset (not just the curated map)

Hand-curating emoji can never cover everything customers send, so the engine loads **`resources/ai/emoji_dataset.php`** — auto-generated from **emojibase-data** (the full Unicode CLDR annotation set: label + tags + group for ~1895 emoji) via `npm run emoji:build` (also wired as a postinstall hook, so `npm install` regenerates it).

- **Curated map wins** for the ~100 customer-service emoji where we know the *sense* ("😍" → "love it") and the sentiment/intents; the **CLDR dataset is the fallback** for everything else ("🚀" → "rocket" + tags launch/rocket/space, "🐙" → "octopus" + tags animal/ocean).
- Dataset-only emoji get a **conservative label classifier**: unambiguous CLDR words ("enraged", "crying", "thumbs up"...) map to sentiment; anything ambiguous stays null — the model remains the deeper judge, the engine never poisons the prompt with a wrong guess.
- Net effect: **every emoji the customer can send** now resolves to words for retrieval, keywords for KB search, and (when unambiguous) a sentiment signal — no manual maintenance as new Unicode emoji ship; regenerate the dataset when emojibase updates.

## 7A. Security Header Change: `microphone=(self)`

**Root cause of the site-wide mic failure:** `app/Http/Middleware/SecureHeaders.php` sent `Permissions-Policy: microphone=()`, which makes Chrome deny every same-origin `getUserMedia` instantly — no prompt, regardless of user settings. It now sends:

```
Permissions-Policy: geolocation=(), microphone=(self), camera=()
```

Same-origin inbox recording works; third-party embeds stay blocked. Camera remains `()` — flip it to `(self)` if a video feature ever needs it. Regression test: `tests/Feature/SecureHeadersTest.php`.

### CSP `media-src`: blob audio playback (play button dead before send)

**Root cause of the dead preview play button:** the CSP had `img-src … blob:` but **no `media-src` directive**. `media-src` is not inherited from `img-src`, so `<audio src="blob:...">` in the composer voice-note preview fell back to `default-src 'self'`, the blob URL was blocked, and `audio.play()` rejected — silently, because the component swallowed the error. Recording, size and duration all worked; only playback was dead. Fixed by adding:

```
media-src 'self' blob:
```

The preview player now also surfaces play failures (red "Play blocked — hard refresh" hint + `console.error('[voice] preview play failed: …')`) instead of failing silently.

## 8. Server Requirements for Voice Notes (aaPanel / AlmaLinux 8)

These three are **mandatory** — miss any one and Chrome voice notes fail with a clear 422 naming the reason:

1. **ffmpeg binary** (static build, no repo needed):
   ```bash
   cd /tmp && curl -L -o ffmpeg.tar.xz https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz
   tar -xf ffmpeg.tar.xz && cp ffmpeg-*-static/ffmpeg /usr/local/bin/ffmpeg && chmod +x /usr/local/bin/ffmpeg
   ffmpeg -version   # verify
   ```
   Optional: pin a custom path via `.env` → `FFMPEG_PATH=/usr/local/bin/ffmpeg` (`config/services.php`).

2. **PHP `disable_functions`:** aaPanel → App Store → PHP 8.2 → Settings → disabled functions → remove **`proc_open`** and **`shell_exec`** → reload PHP. (Calling a disabled function is a fatal `\Error` in PHP 8 — this was the original voice-note 500.)

3. **`open_basedir`:** if set for the site, append `:/usr/local/bin` (or clear it) so PHP can see the binary.

Transcode failure reasons are logged as `Inbox audio: ...` warnings in `storage/logs/laravel.log`.

## 9. Tests Added

| Test | Covers |
|---|---|
| `tests/Feature/AI/PlaygroundHistoryTest.php` | Playground history sanitizing + memory |
| `tests/Feature/AI/WhatsappQuotedReplyTest.php` | Bot quote context + no-quote retry |
| `tests/Feature/AI/InboxMediaServingTest.php` | Inline media streaming without symlink |
| `tests/Feature/SecureHeadersTest.php` | `microphone=(self)` in Permissions-Policy; CSP `media-src 'self' blob: https:` |
| `tests/Feature/AI/VoiceNoteSendTest.php` | Webm voice-note send never 500s on hardened hosts |
| `tests/Feature/Instagram/VoiceNoteTest.php` | Instagram voice-note chain: m4a transcode branch, URL attachment send, inbound attachment mapping, `publicUrl()` |
| `tests/Feature/AI/EmojiEngineTest.php` | Engine-level emoji intelligence: normalization, sentiment/intents, prompt layer, retrieval keywords, run meta |

Full AI suite reference: 38+ tests green before this doc; voice-note + header tests add 3 more.

## 10. Deploy (copy-paste)

```bash
cd /www/wwwroot/wa.careerinpak.com && git stash && git pull origin master
php artisan storage:link        # if not already linked
php artisan queue:restart       # after PHP job code changes
```

Hard-refresh the browser (`Ctrl+Shift+R`) after deploying front-end changes — built assets are committed, the server never runs `npm`.
