<?php

namespace App\Modules\Inbox\Services;

use App\Events\MessageSent;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Support\Facades\Log;

/**
 * AI → human handover experience.
 *
 * When handover fires (customer asked for a human), three things must happen:
 *   1. The customer gets an immediate acknowledgment — "connecting you with
 *      a human" — instead of silence (the bot stops replying from this point,
 *      so without this the thread just goes dead).
 *   2. The conversation gets the workspace's "Waiting for you" label so the
 *      team's inbox visibly queues who needs attention.
 *   3. When a human agent sends the first reply, the waiting label comes off.
 *
 * The handover reply text is configurable per bot (AiChatbot::handover_reply);
 * empty falls back to a language-aware platform default.
 */
class HandoverService
{
    /** The label name every workspace shares for handover queues. */
    public const WAITING_LABEL = 'Waiting for you';

    /** Label color: amber — "needs attention". */
    private const WAITING_COLOR = '#f59e0b';

    public function __construct(private ChannelManager $channelManager) {}

    /**
     * Marker the AI appends to its reply when it decides the customer needs a
     * human. GenerateAiReplyJob detects it, fires the handover, and relays the
     * reply without the marker.
     */
    public const HANDOVER_MARKER = '[HUMAN_HANDOVER]';

    /**
     * Announce the handover to the customer and mark the conversation as
     * waiting for a human agent. Resolves the conversation's linked chatbot
     * (for the configurable reply) and channel itself.
     */
    public function announce(Conversation $conversation, ?string $replyOverride = null): void
    {
        $channelAccount = $conversation->channelAccount;
        $meta = $channelAccount?->getAttribute('meta_json');
        $chatbotId = is_array($meta) ? ($meta['ai_chatbot_id'] ?? null) : null;
        $chatbot = $chatbotId ? AiChatbot::find($chatbotId) : null;
        // conversations.channel can be '' on legacy rows — the channelAccount
        // is the authority for which driver to send through.
        $channel = $conversation->getAttribute('channel') ?: ($channelAccount?->getAttribute('channel') ?? 'whatsapp');

        // Mark the handover on the conversation itself: bot replies stop and the
        // inbox shows the thread as human-owned. Idempotent — safe to re-announce.
        $conversation->update(['assigned_to' => 'human', 'handover_at' => now()]);

        $this->sendHandoverReply($conversation, $chatbot, $channel, $replyOverride);
        $this->attachWaitingLabel($conversation);
    }

    /**
     * Attach (or refresh) the "Waiting for you" label on a conversation.
     * Creates the label once per workspace on first use.
     */
    public function attachWaitingLabel(Conversation $conversation): void
    {
        $label = InboxLabel::firstOrCreate(
            [
                'workspace_id' => $conversation->getAttribute('workspace_id'),
                'name'         => self::WAITING_LABEL,
            ],
            [
                'color'         => self::WAITING_COLOR,
                'auto_assigned' => true,
            ],
        );

        $conversation->labels()->syncWithoutDetaching([$label->id]);
    }

    /**
     * Remove the waiting label — called on the first outbound message sent by
     * a human agent after a handover.
     */
    public function removeWaitingLabel(Conversation $conversation): void
    {
        $label = InboxLabel::where('workspace_id', $conversation->getAttribute('workspace_id'))
            ->where('name', self::WAITING_LABEL)
            ->first();

        if ($label) {
            $conversation->labels()->detach([$label->id]);
        }
    }

    /**
     * Send the "connecting you" bot reply. Bot-internal: stored with
     * sent_by='bot' so agent-vs-bot analytics stay truthful, dispatched via
     * the normal channel driver and MessageSent broadcast.
     */
    private function sendHandoverReply(Conversation $conversation, ?AiChatbot $chatbot, string $channel, ?string $replyOverride = null): void
    {
        $body = $replyOverride
            ?? trim((string) ($chatbot->handover_reply ?? ''))
            ?: $this->defaultReply($conversation);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'out',
            'channel'         => $channel,
            'type'            => 'text',
            'body'            => $body,
            'payload'         => ['kind' => 'handover_ack'],
            'status'          => 'queued',
            'sent_by'         => 'bot',
            'sent_at'         => now(),
        ]);

        try {
            $driver = $this->channelManager->driver($channel);
            $providerId = $driver->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $providerId]);
        } catch (\Throwable $e) {
            $message->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            Log::warning('HandoverService ack send failed', [
                'conversation_id' => $conversation->id,
                'channel'         => $channel,
                'error'           => $e->getMessage(),
            ]);
        }

        $conversation->update(['last_message_at' => now()]);
        $message->load('conversation');
        MessageSent::dispatch($message);
    }

    /**
     * Platform-default handover acknowledgment. The last few customer words
     * decide the language (Roman Urdu detection: common Roman-Urdu/Hindi
     * tokens; otherwise English) — mirrors the bot's own language-matching
     * rule without a full LLM pass.
     */
    private function defaultReply(Conversation $conversation): string
    {
        $lastInbound = $conversation->messages()
            ->where('direction', 'in')
            ->orderByDesc('sent_at')
            ->value('body') ?? '';

        $roman = 'ap '."\u{200c}".'ka |aap ka|kab |kitna|kaise|kaisay|chahiye|chahye|mil|human chahiye|batao|bata dein|karna hai|krna hai|kr do|kar do|shukriya|thanks bhai';
        $isRoman = preg_match('/\b(human|agent|insaan|bande|bnde)\b/iu', $lastInbound) === 1
            && preg_match('/\b(chahiye|chahye|kab|kaise|kaisay|mil|batao|dein|karo|krna|ho)\b/iu', $lastInbound) === 1;

        return $isRoman
            ? 'Ji bilkul — aap ko human agent se connect kar raha hoon, thori dair mein wo aap se baat karenge. Shukriya! 🙏'
            : 'Connecting you with a human agent — they will reply shortly. Thank you for your patience! 🙏';
    }
}
