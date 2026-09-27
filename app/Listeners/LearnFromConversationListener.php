<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Modules\AI\Jobs\ExtractConversationLearningJob;
use App\Modules\AI\Services\AiMemoryService;
use App\Modules\Shared\Models\Message;
use Illuminate\Support\Facades\Cache;

/**
 * Turns outbound message flow into throttled background learning jobs.
 *
 * Runs inline with every MessageSent event but touches only a cache counter —
 * all LLM work happens later on the 'ai' queue, once per
 * AiMemoryService::EXTRACTION_EVERY qualifying outbound messages per
 * conversation. Cheap by design: no DB queries on the hot path.
 */
class LearnFromConversationListener
{
    private const COUNTER_TTL_SECONDS = 604800; // 7 days

    public function handle(MessageSent $event): void
    {
        try {
            $this->process($event->message);
        } catch (\Throwable) {
            // Learning must never break message delivery — swallow everything.
        }
    }

    private function process(Message $message): void
    {
        if ($message->direction !== 'out') {
            return;
        }

        // Only real conversation traffic teaches the bot: agent replies and
        // bot replies both carry signal; broadcasts/automation blasts do not.
        if (! in_array($message->sent_by, ['human', 'bot'], true)) {
            return;
        }

        $conversationId = $message->conversation_id;
        if (! $conversationId) {
            return;
        }

        // Tiny messages ("ok", "thanks", "+92…") carry nothing to learn.
        if (mb_strlen((string) $message->body) < AiMemoryService::MIN_MESSAGE_CHARS) {
            return;
        }

        // Some drivers re-dispatch MessageSent for the same message (send path
        // + echo path). Count each message exactly once — a duplicate dispatch
        // must not advance the throttle or double-fire the extraction job.
        if (! Cache::add("ai_learn_seen:{$message->id}", 1, 300)) {
            return;
        }

        $key = "ai_learn_count:{$conversationId}";
        if (! Cache::has($key)) {
            Cache::put($key, 0, self::COUNTER_TTL_SECONDS);
        }

        $count = Cache::increment($key);
        if ($count % AiMemoryService::EXTRACTION_EVERY !== 0) {
            return;
        }

        ExtractConversationLearningJob::dispatch($conversationId);
    }
}
