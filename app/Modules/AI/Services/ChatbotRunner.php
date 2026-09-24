<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Services\Llm\DeadUpstreamException;
use App\Modules\Shared\Models\Message;

class ChatbotRunner
{
    /** Max recent conversation turns replayed to the model (token-cost guard). */
    public const HISTORY_TURNS = 6;

    /** Max characters kept per history turn — huge pasted messages get truncated. */
    public const HISTORY_MAX_CHARS = 500;

    /** Sent when no usable reply could be produced and the bot has no fallback_reply set. */
    public const DEFAULT_FALLBACK = 'Sorry, our assistant is briefly unavailable. Please try again shortly.';

    public function __construct(
        private LlmGateway $llmGateway,
        private EmbeddingStore $embedStore,
    ) {}

    public function run(AiChatbot $bot, Message $inboundMessage, ?array &$meta = null): ?string
    {
        $meta = $meta ?? [];

        if (! $bot->enabled) {
            return null;
        }

        $conversation = $inboundMessage->conversation;
        $body = $inboundMessage->body ?? '';
        $workspaceId = $conversation->workspace_id;

        // 1. Embed the user query
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$body]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable) {
                // proceed without retrieval
            }
        }

        // 2. Retrieve top-k relevant chunks (embedding search, with keyword fallback)
        $contextChunks = [];
        if ($bot->ai_kb_id) {
            $results = [];
            if (! empty($queryEmbedding)) {
                $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            }
            if (empty($results)) {
                // Embedding unavailable (no embed-capable provider) or no relevant hit —
                // fall back to keyword search so the bot still answers from the KB
                // instead of drifting into general knowledge.
                $results = $this->keywordChunks($bot->ai_kb_id, $body, $bot->max_context_chunks ?? 5);
            }
            $contextChunks = array_column(app(KbContextTrimmer::class)->fit($results), 'chunk');
        }

        // Layered prompt: core guardrails + admin global rules + tone + bot prompt
        $systemPrompt = app(AiSystemPrompt::class)->build($bot);

        // Load recent conversation turns as context (capped — see HISTORY_TURNS)
        $historyLimit = min($bot->history_limit ?? 5, self::HISTORY_TURNS);
        $history = [];
        $recentMessages = $conversation->messages()
            ->whereIn('type', ['text', 'template'])
            ->where('id', '!=', $inboundMessage->id)
            ->orderByDesc('sent_at')
            ->take($historyLimit)
            ->get()
            ->reverse()
            ->values();

        foreach ($recentMessages as $m) {
            if (! $m->body) {
                continue;
            }
            $history[] = [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => mb_substr($m->body, 0, self::HISTORY_MAX_CHARS),
            ];
        }

        // Build the augmented user message with context
        $augmentedUserMessage = "";
        
        $hasContext = !empty($contextChunks);
        $orderSummary = $this->orderSummary($workspaceId, $conversation->contact_id);
        $productSummary = $this->productSummary($workspaceId, $body);

        if ($hasContext || $orderSummary !== null || $productSummary !== null) {
            $augmentedUserMessage .= "Context information is below.\n---------------------\n";
            
            if ($hasContext) {
                $contextText = implode("\n\n---\n\n", array_map(fn ($c) => $c->content, $contextChunks));
                $augmentedUserMessage .= "Knowledge Base (highest-priority source of truth — answer from it whenever it addresses the query):\n" . $contextText . "\n\n";
            }
            
            if ($orderSummary !== null) {
                $augmentedUserMessage .= "Customer Recent Orders (Use if asked about orders/shipping/delivery):\n" . $orderSummary . "\n\n";
            }

            if ($productSummary !== null) {
                $augmentedUserMessage .= "Product Information (Use if asked about pricing/stock/products):\n" . $productSummary . "\n\n";
            }
            
            $augmentedUserMessage .= "---------------------\nUse the context above when it is relevant — the Knowledge Base always wins over general knowledge. If it does not contain the answer, say you don't have that information and offer to connect a human agent — do not invent anything. If the query is a simple greeting or small talk (like 'hi' or 'thanks'), just respond naturally and conversationally.\nQuery: ";
        }

        $augmentedUserMessage .= $body;

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $augmentedUserMessage]],
        );

        // 4. Call LLM
        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                [
                    'max_tokens' => $bot->max_tokens ?? 512,
                    'num_ctx' => $bot->num_ctx ?? 2048,
                    'keep_alive' => $bot->keep_alive ?? '10m',
                    'temperature' => (float) ($bot->temperature ?? 0.3),
                ],
                $bot->id,
                $conversation->id,
            );

            $meta = [
                'model' => $response->model,
                'latency_ms' => $response->latencyMs,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
            ];

            return $this->cleanReply($response->content, $bot);
        } catch (\Throwable $e) {
            // Fallback — surface the guard reason to the caller (playground meta,
            // logs) so dead-upstream routing is visible instead of silent.
            if ($meta !== null) {
                $meta['guard'] = $e instanceof DeadUpstreamException ? 'dead_upstream' : 'llm_error';
                $meta['error'] = mb_substr($e->getMessage(), 0, 160);
            }

            return $this->fallbackFor($bot);
        }
    }

    /**
     * Normalise a raw LLM reply for chat delivery.
     *
     * Weak/rotued upstream models leak markdown into WhatsApp replies and can
     * return whitespace-only content (e.g. reasoning models that burned the
     * whole token budget thinking). This strips markdown artifacts and falls
     * back to the bot's configured fallback reply when nothing usable remains.
     */
    private function cleanReply(?string $content, AiChatbot $bot): ?string
    {
        $text = trim((string) $content);

        if ($text === '') {
            return $this->fallbackFor($bot);
        }

        // Strip fenced code blocks, heading markers, and bold/italic emphasis —
        // none of which render on WhatsApp.
        $text = preg_replace('/```[a-zA-Z0-9_-]*\n?|```/', '', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = str_replace(['**', '__'], '', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : $this->fallbackFor($bot);
    }

    /**
     * The reply sent when the LLM could not produce anything usable (dead
     * upstream, network error, empty content). Prefers the bot's own configured
     * fallback reply; otherwise a sane platform default so the customer is
     * never left with silence or a canned upstream greeting.
     */
    private function fallbackFor(AiChatbot $bot): ?string
    {
        return $bot->fallback_reply
            ?: ($bot->fallback_reply !== null ? $bot->fallback_reply : self::DEFAULT_FALLBACK);
    }

    /**
     * Keyword fallback retrieval for when embeddings are unavailable (no
     * embedding-capable provider configured) or similarity search returns no
     * relevant hit. Matches query words against chunk content — good enough
     * for FAQ-style knowledge bases where the question text is in the chunk.
     *
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    private function keywordChunks(int $kbId, string $query, int $limit): array
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', mb_strtolower($query)) ?? '';
        $words = array_filter(explode(' ', $clean), fn ($w) => mb_strlen(trim($w)) > 2);
        $stopWords = ['the', 'and', 'for', 'you', 'have', 'with', 'this', 'that', 'are', 'what', 'how', 'much', 'can', 'get', 'want'];
        $keywords = array_values(array_diff($words, $stopWords));

        if (empty($keywords)) {
            return [];
        }

        return AiKbChunk::where('kb_id', $kbId)
            ->where(function ($builder) use ($keywords) {
                foreach ($keywords as $kw) {
                    $builder->orWhere('content', 'LIKE', '%'.$kw.'%');
                }
            })
            ->take($limit)
            ->get()
            ->map(fn ($chunk) => ['chunk' => $chunk, 'score' => 0.5])
            ->toArray();
    }

    /**
     * Build a short summary of the contact's recent orders, or null when the
     * Ecommerce module is absent / no store is connected / no orders exist.
     */
    private function orderSummary(int $workspaceId, ?int $contactId): ?string
    {
        $storeModel = 'App\Modules\Ecommerce\Models\EcommerceStore';
        $orderModel = 'App\Modules\Ecommerce\Models\EcommerceOrder';

        if (! $contactId || ! class_exists($storeModel) || ! class_exists($orderModel)) {
            return null;
        }

        $hasStore = $storeModel::where('workspace_id', $workspaceId)
            ->where('status', 'connected')
            ->exists();
        if (! $hasStore) {
            return null;
        }

        $orders = $orderModel::where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->latest('placed_at')
            ->take(3)
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        return $orders->map(function ($o) {
            $parts = ['Order '.($o->number ?: $o->external_order_id)];
            if ($o->fulfillment_status) {
                $parts[] = 'status: '.$o->fulfillment_status;
            }
            $parts[] = 'total: '.$o->currency.' '.$o->total;
            if ($o->tracking_url) {
                $parts[] = 'tracking: '.$o->tracking_url;
            }
            if ($o->placed_at) {
                $parts[] = 'placed: '.$o->placed_at->toDateString();
            }

            return '- '.implode(', ', $parts);
        })->implode("\n");
    }

    /**
     * Extracts keywords from the user's message and performs a fast, zero-cost
     * database search to inject relevant product catalog data into the prompt.
     */
    private function productSummary(int $workspaceId, string $query): ?string
    {
        $storeModel = 'App\Modules\Ecommerce\Models\EcommerceStore';
        $productModel = 'App\Modules\Ecommerce\Models\EcommerceProduct';

        if (! class_exists($storeModel) || ! class_exists($productModel)) {
            return null;
        }

        $hasStore = $storeModel::where('workspace_id', $workspaceId)->where('status', 'connected')->exists();
        if (! $hasStore) {
            return null;
        }

        // Extract keywords: strip punctuation, lowercase, split, drop short words
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', strtolower($query));
        $words = array_filter(explode(' ', (string) $clean), fn($w) => strlen(trim($w)) > 2);
        
        // Remove common stop words to improve search relevance
        $stopWords = ['the', 'and', 'for', 'you', 'have', 'with', 'this', 'that', 'are', 'what', 'how', 'much', 'can', 'get', 'want'];
        $keywords = array_diff($words, $stopWords);

        if (empty($keywords)) {
            return null;
        }

        $q = $productModel::where('workspace_id', $workspaceId)->where('status', 'active');
        
        $q->where(function ($queryBuilder) use ($keywords) {
            foreach ($keywords as $kw) {
                $queryBuilder->orWhere('name', 'LIKE', '%' . $kw . '%')
                             ->orWhere('sku', 'LIKE', '%' . $kw . '%');
            }
        });

        // Take top 5 matching products to keep token usage minimal
        $products = $q->take(5)->get();

        if ($products->isEmpty()) {
            return null;
        }

        return $products->map(function ($p) {
            $parts = ["Product: {$p->name}"];
            if ($p->sku) {
                $parts[] = "SKU: {$p->sku}";
            }
            $parts[] = "Price: {$p->price}";
            if ($p->inventory_quantity !== null) {
                $parts[] = "Stock: {$p->inventory_quantity} available";
            }
            return '- ' . implode(' | ', $parts);
        })->implode("\n");
    }

    /**
     * API-friendly variant: run the chatbot with a plain text message.
     * Does not require an existing Message/Conversation model.
     *
     * @param  array  $history  Array of {role, content} prior turns (optional)
     * @return array{reply: string|null, tokens_used: int}
     */
    public function runForApi(AiChatbot $bot, string $message, int $workspaceId, array $history = []): array
    {
        // 1. Embed the user query for RAG
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$message]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable) {
            }
        }

        // 2. Retrieve top-k relevant chunks (embedding search, with keyword fallback)
        $contextChunks = [];
        if ($bot->ai_kb_id) {
            $results = [];
            if (! empty($queryEmbedding)) {
                $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            }
            if (empty($results)) {
                $results = $this->keywordChunks($bot->ai_kb_id, $message, $bot->max_context_chunks ?? 5);
            }
            $contextChunks = array_column(app(KbContextTrimmer::class)->fit($results), 'chunk');
        }

        // 3. Build messages array — layered prompt (guardrails + admin rules + tone + bot prompt)
        $systemPrompt = app(AiSystemPrompt::class)->build($bot);
        
        $augmentedUserMessage = "";
        if (! empty($contextChunks)) {
            $augmentedUserMessage .= "Context information is below.\n---------------------\n";
            $context = implode("\n\n---\n\n", array_map(fn ($c) => $c->content, $contextChunks));
            $augmentedUserMessage .= "Knowledge Base (highest-priority source of truth — answer from it whenever it addresses the query):\n" . $context . "\n\n";
            $augmentedUserMessage .= "---------------------\nAnswer the user's query using the context provided above — the Knowledge Base always wins over general knowledge. If the context does not contain the answer, say so. However, if the query is a simple greeting or conversational (like 'hi' or 'thanks'), just respond naturally and conversationally.\nQuery: ";
        }
        $augmentedUserMessage .= $message;

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $augmentedUserMessage]],
        );

        // 4. Call LLM
        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                ['max_tokens' => 512, 'temperature' => (float) ($bot->temperature ?? 0.3)],
                $bot->id,
            );

            return [
                'reply' => $this->cleanReply($response->content, $bot),
                'tokens_used' => $response->promptTokens + $response->completionTokens,
            ];
        } catch (\Throwable) {
            return ['reply' => $this->fallbackFor($bot), 'tokens_used' => 0];
        }
    }
}
