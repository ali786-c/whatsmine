<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiConversationSummary;
use App\Modules\AI\Models\AiMemory;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Support\Carbon;

/**
 * Workspace-level AI learning: the engine turns real conversations into a
 * compact list of durable facts (prices promised, policies stated, customer
 * preferences) and injects the most useful ones into every bot reply.
 *
 * Token budget rules:
 *  - the injected block is capped (top memories, short lines);
 *  - extraction runs in the background once per EXTRACTION_EVERY outbound
 *    messages, never inline with a customer reply;
 */
class AiMemoryService
{
    /** Extract one learning batch per N outbound messages (token-cost guard). */
    public const EXTRACTION_EVERY = 20;

    /** Min outbound message chars before a turn is worth summarising/learning from. */
    public const MIN_MESSAGE_CHARS = 20;

    /** Max memories injected into one prompt. */
    public const INJECT_LIMIT = 15;

    /** Max chars per injected memory line. */
    public const INJECT_LINE_CHARS = 160;

    /** Cap the transcript window fed to the extraction prompt. */
    public const EXTRACT_WINDOW_MESSAGES = 30;

    /** Cache lifetime (seconds) for the injected block per workspace. */
    public const BLOCK_CACHE_SECONDS = 120;

    public function __construct(
        private readonly \App\Modules\AI\Services\LlmGateway $llmGateway,
    ) {}

    // -------------------------------------------------------------------------
    // Injection
    // -------------------------------------------------------------------------

    /**
     * Build the compact "learned memory" system block, or null when the
     * workspace has no usable memories. Cached briefly per workspace — this
     * runs on every bot reply.
     */
    public function injectBlock(int $workspaceId, ?AiChatbot $bot = null): ?string
    {
        $memories = AiMemory::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($bot) {
                $q->whereNull('chatbot_id');
                if ($bot?->id) {
                    $q->orWhere('chatbot_id', $bot->id);
                }
            })
            ->orderByDesc('usefulness')
            ->orderByDesc('updated_at')
            ->limit(self::INJECT_LIMIT)
            ->get();

        if ($memories->isEmpty()) {
            return null;
        }

        $lines = $memories
            ->map(fn (AiMemory $m) => '- '.mb_substr($m->content, 0, self::INJECT_LINE_CHARS))
            ->implode("\n");

        // Mark every injected memory as used — single batched touch, so the
        // "which memories actually help" signal survives the nightly decay.
        AiMemory::query()
            ->whereIn('id', $memories->pluck('id'))
            ->update([
                'use_count' => \Illuminate\Support\Facades\DB::raw('use_count + 1'),
                'last_used_at' => now(),
            ]);

        return "Learned memory (durable facts about this business and its customers, learned from real conversations):\n".$lines;
    }

    /**
     * Build the rolling-conversation-summary block, or null when this chat has
     * no summary yet. Gives the bot memory of turns that fell OUTSIDE the
     * replayed history window — without touching history settings at all.
     */
    public function summaryBlock(?int $conversationId): ?string
    {
        if (! $conversationId) {
            return null;
        }

        $summary = AiConversationSummary::query()
            ->where('conversation_id', $conversationId)
            ->first();

        if (! $summary || trim((string) $summary->summary) === '') {
            return null;
        }

        return "Conversation memory (what happened earlier in this chat, before the recent messages the user will send):\n"
            .mb_substr(trim((string) $summary->summary), 0, AiConversationSummary::MAX_CHARS);
    }

    // -------------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------------

    /**
     * Summarise + learn from the messages since the last processed message.
     * One LLM call, strict-JSON output; any failure is swallowed — learning
     * must never break messaging.
     */
    public function extractForConversation(int $conversationId): void
    {
        $conversation = Conversation::find($conversationId);
        if (! $conversation) {
            return;
        }

        $workspaceId = (int) $conversation->workspace_id;
        if (! $this->learningEnabled($workspaceId)) {
            return;
        }

        $summary = AiConversationSummary::firstOrNew(['conversation_id' => $conversationId]);
        $afterId = (int) ($summary->last_summarized_message_id ?? 0);

        $messages = Message::query()
            ->where('conversation_id', $conversationId)
            ->where('id', '>', $afterId)
            ->whereIn('type', ['text', 'template'])
            ->whereNotNull('body')
            ->orderBy('id')
            ->limit(self::EXTRACT_WINDOW_MESSAGES)
            ->get();

        // Nothing new worth burning tokens on.
        $usable = $messages->filter(fn (Message $m) => mb_strlen((string) $m->body) >= self::MIN_MESSAGE_CHARS);
        if ($usable->isEmpty()) {
            // Still advance the cursor so tiny messages don't pile up forever.
            if ($messages->isNotEmpty() && $summary->exists) {
                $summary->last_summarized_message_id = $messages->last()->id;
                $summary->save();
            }

            return;
        }

        $transcript = $messages
            ->map(fn (Message $m) => ($m->direction === 'out' ? 'Agent' : 'Customer').': '.mb_substr((string) $m->body, 0, 300))
            ->implode("\n");

        $prompt = $this->extractionPrompt($transcript, $summary->summary ?? null);

        $result = $this->callLlm($workspaceId, $prompt);
        if ($result === null) {
            return; // LLM down / bad JSON — keep cursor, retry next batch
        }

        [$newSummary, $facts] = $result;

        $summary->workspace_id = $workspaceId;
        $summary->summary = mb_substr($newSummary, 0, AiConversationSummary::MAX_CHARS);
        $summary->last_summarized_message_id = $messages->last()->id;
        $summary->message_count = ($summary->message_count ?? 0) + $messages->count();
        $summary->save();

        foreach ($facts as $fact) {
            $this->storeMemory($workspaceId, $conversationId, $fact);
        }

        $this->prune($workspaceId);
    }

    private function extractionPrompt(string $transcript, ?string $existingSummary): string
    {
        $existing = $existingSummary ? "Previous summary:\n{$existingSummary}\n\n" : '';

        return <<<TXT
You maintain memory for a customer-support AI. Below is a chat transcript and {$existing}the current rolling summary.

Return STRICT JSON only (no markdown, no commentary):
{"summary": "<updated rolling summary of the conversation, max 100 words, same language as the chat>", "facts": [{"kind": "fact|preference|policy|order_info|business", "content": "<one durable fact worth remembering, max 160 chars, same language as the chat>"}]}

Rules for facts:
- Only durable, reusable facts: prices/quotes given, policies stated, promises made, customer preferences, business details.
- Skip greetings, small talk, one-off logistics ("I'll check", "ok"), and anything already covered.
- 0 to 4 facts. Empty array when nothing durable appeared.

Transcript:
{$transcript}
TXT;
    }

    /**
     * @return array{0: string, 1: array<int, array{kind: string, content: string}>}|null
     */
    private function callLlm(int $workspaceId, string $prompt): ?array
    {
        $opts = ['max_tokens' => 400, 'temperature' => 0.1];

        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                [['role' => 'user', 'content' => $prompt]],
                $opts,
            );
        } catch (\Throwable) {
            return null;
        }

        return $this->parseExtraction((string) $response->content);
    }

    /**
     * Parse the strict-JSON extraction output. Returns null on anything
     * malformed — a bad model response must never create garbage memories.
     *
     * @return array{0: string, 1: array<int, array{kind: string, content: string}>}|null
     */
    public function parseExtraction(string $raw): ?array
    {
        $text = trim($raw);
        // Tolerate models that wrap JSON in a fence despite instructions.
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;

        $decoded = json_decode($text, true);
        if (! is_array($decoded) || ! isset($decoded['summary']) || ! is_string($decoded['summary'])) {
            return null;
        }

        $allowedKinds = ['fact', 'preference', 'policy', 'order_info', 'business'];
        $facts = [];

        foreach ((array) ($decoded['facts'] ?? []) as $fact) {
            if (! is_array($fact)) {
                continue;
            }
            $content = trim((string) ($fact['content'] ?? ''));
            $kind = (string) ($fact['kind'] ?? 'fact');
            if ($content === '' || ! in_array($kind, $allowedKinds, true)) {
                continue;
            }

            $facts[] = [
                'kind' => $kind,
                'content' => mb_substr($content, 0, AiMemory::MAX_CONTENT_CHARS),
            ];
            if (count($facts) >= 4) {
                break;
            }
        }

        return [trim($decoded['summary']), $facts];
    }

    private function storeMemory(int $workspaceId, int $conversationId, array $fact): void
    {
        $content = $fact['content'];

        // Exact-duplicate guard: reinforce usefulness instead of duplicating.
        $existing = AiMemory::query()
            ->where('workspace_id', $workspaceId)
            ->where('content', $content)
            ->first();

        if ($existing) {
            $existing->increment('usefulness');

            return;
        }

        AiMemory::create([
            'workspace_id' => $workspaceId,
            'chatbot_id' => null,
            'kind' => $fact['kind'],
            'content' => $content,
            'usefulness' => 1,
            'source_conversation_id' => $conversationId,
            'source' => 'auto',
        ]);
    }

    // -------------------------------------------------------------------------
    // Maintenance
    // -------------------------------------------------------------------------

    /**
     * Decay usefulness of stale memories and hard-cap the workspace at
     * MAX_MEMORIES (lowest usefulness first).
     */
    public function decayAndPrune(int $workspaceId): void
    {
        $this->decay($workspaceId);
        $this->prune($workspaceId);
    }

    public function decay(int $workspaceId): void
    {
        AiMemory::query()
            ->where('workspace_id', $workspaceId)
            ->where('source', 'auto')
            ->where('updated_at', '<', Carbon::now()->subDays(30))
            ->where('usefulness', '>', 0)
            ->decrement('usefulness');
    }

    public function prune(int $workspaceId): void
    {
        $count = AiMemory::query()->where('workspace_id', $workspaceId)->count();
        if ($count <= AiMemory::MAX_MEMORIES) {
            return;
        }

        $ids = AiMemory::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('usefulness')
            ->orderBy('updated_at')
            ->limit($count - AiMemory::MAX_MEMORIES)
            ->pluck('id');

        AiMemory::query()->whereIn('id', $ids)->delete();
    }

    // -------------------------------------------------------------------------
    // Toggle
    // -------------------------------------------------------------------------

    /** Workspace-level kill switch for background learning. Default ON. */
    public function learningEnabled(int $workspaceId): bool
    {
        return \App\Models\WorkspaceSetting::flag($workspaceId, 'ai_learning_enabled', true);
    }
}
