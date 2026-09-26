<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Services\Llm\DeadUpstreamException;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\Shared\Models\Message;

class ChatbotRunner
{
    /** Max recent conversation turns replayed to the model (token-cost guard). */
    public const HISTORY_TURNS = 6;

    /** Max characters kept per history turn — huge pasted messages get truncated. */
    public const HISTORY_MAX_CHARS = 500;

    /** Sent when no usable reply could be produced and the bot has no fallback_reply set. */
    public const DEFAULT_FALLBACK = 'Sorry, our assistant is briefly unavailable. Please try again shortly.';

    /**
     * Hybrid retrieval keeps embedding hits down to this cosine score instead of
     * the strict MIN_RELEVANCE_SCORE cutoff: the rank fusion decides what makes
     * the final cut, and a chunk the keyword channel ranks first must not be
     * crowded out just because the embedding channel scored it 0.2.
     */
    public const HYBRID_MIN_EMBED_SCORE = 0.15;

    /** Standard Reciprocal Rank Fusion constant. */
    private const RRF_K = 60;

    /** System nudge for the single grounded-answer retry. */
    private const GROUNDING_NUDGE = 'The Knowledge Base context included above DOES contain the information needed to answer. Reply again using the exact plan names, prices, currencies and figures from that context. Do not offer to confirm with the team, do not say the information is missing, and do not invent anything that is not in the context.';

    /** Reply patterns that mean "the model hedged although it had context". */
    private const HEDGE_PATTERNS = [
        '/confirm (it|this|them)? ?with (our |the )?team/i',
        "/(i|we) (do not|don't|cannot|can't) have (the |that |your |this )?(specific |exact )?(information|details|pricing|price|rates)/i",
        '/not able to share/i',
        "/(i|we) (will|'ll) (confirm|check|find out)( that| this)? (for you|with our team)/i",
        "/(i|we) (do not|don't) know the exact/i",
    ];

    /**
     * Domain synonyms bridging the words customers use with the words KBs use
     * ("which plan is the cheapest" ↔ "our lowest-priced option"). Synonyms
     * participate in scoring like regular keywords.
     */
    private const SYNONYMS = [
        'cheapest' => ['lowest', 'budget', 'affordable', 'economy'],
        'cheap' => ['lowest', 'budget', 'affordable'],
        'lowest' => ['cheapest', 'budget'],
        'price' => ['pricing', 'cost', 'rate', 'rates'],
        'pricing' => ['price', 'cost', 'rate', 'rates'],
        'cost' => ['price', 'pricing'],
        'rate' => ['price', 'pricing'],
        'plan' => ['package', 'offer'],
        'package' => ['plan'],
        'unlimited' => ['unmetered'],
        'unmetered' => ['unlimited'],
        'storage' => ['disk', 'space'],
        'bandwidth' => ['traffic'],
        'buy' => ['order', 'purchase'],
        'order' => ['buy', 'purchase'],
        'discount' => ['sale', 'offer'],
        'delivery' => ['shipping'],
        'shipping' => ['delivery'],
        'refund' => ['money back', 'return'],
        'support' => ['help'],
    ];

    public function __construct(
        private LlmGateway $llmGateway,
        private EmbeddingStore $embedStore,
    ) {}

    /**
     * Run the chatbot for one inbound message.
     *
     * @param  array<int, array{role: string, content: string}>|null  $clientHistory  Pre-sanitized prior turns (playground); null → load from DB conversation
     */
    public function run(AiChatbot $bot, Message $inboundMessage, ?array &$meta = null, ?array $clientHistory = null): ?string
    {
        $meta = $meta ?? [];

        if (! $bot->enabled) {
            return null;
        }

        $conversation = $inboundMessage->conversation;
        $body = $inboundMessage->body ?? '';
        $workspaceId = $conversation->workspace_id;

        // 0. Emoji intelligence (engine-level, not model-level): decode emoji
        // into semantic phrases for retrieval, harvest extra keywords, and
        // derive sentiment/urgency/escalation signals for the prompt + meta.
        $emojiEngine = app(EmojiEngine::class);
        $emoji = $emojiEngine->analyze($body);

        // Playground runs a synthetic conversation (id 0) with no DB messages,
        // so the caller supplies sanitized prior turns instead. Production
        // (WhatsApp inbox) passes null and history is loaded from the DB below.

        // 1. Embed the user query — emoji-normalized so "😍" embeds as "love
        // it" and matches KB prose instead of dying as an unknown token.
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$emojiEngine->normalize($body)]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable) {
                // proceed without retrieval
            }
        }

        // 2. Retrieve top-k relevant chunks (hybrid: embedding + keyword, rank-fused)
        $contextChunks = [];
        if ($bot->ai_kb_id) {
            $results = $this->retrieveHybrid($bot->ai_kb_id, $emojiEngine->normalize($body), $queryEmbedding, $bot->max_context_chunks ?? 5, $emoji['keywords']);
            $contextChunks = array_column(app(KbContextTrimmer::class)->fit($results), 'chunk');
        }

        // Layered prompt: core guardrails + admin global rules + tone + bot prompt
        $systemPrompt = app(AiSystemPrompt::class)->build($bot);

        // Engine-derived emoji layer: only present when the message carries
        // emoji — gives weak local models an explicit, deterministic read of
        // the customer's sentiment/urgency that they'd otherwise underuse.
        $emojiLayer = $emojiEngine->promptLayer($emoji);
        if ($emojiLayer !== null) {
            $systemPrompt .= "\n\n".$emojiLayer;
        }

        // Load recent conversation turns as context (capped — see HISTORY_TURNS)
        // unless the caller already supplied them (playground).
        $history = $clientHistory ?? [];

        if ($clientHistory === null) {
            $historyLimit = min($bot->history_limit ?? 5, self::HISTORY_TURNS);
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

        // 4. Call LLM — with one grounded-answer retry when the KB had context
        // but the model hedged anyway ("I'll confirm with our team").
        $opts = [
            'max_tokens' => $bot->max_tokens ?? 512,
            'num_ctx' => $bot->num_ctx ?? 2048,
            'keep_alive' => $bot->keep_alive ?? '10m',
            'temperature' => (float) ($bot->temperature ?? 0.3),
        ];

        try {
            $response = $this->llmGateway->chat($workspaceId, $messages, $opts, $bot->id, $conversation->id);
            $response = $this->groundedRetry($response, $messages, $workspaceId, $opts, $bot->id, $hasContext, $meta);

            $meta = array_merge([
                'model' => $response->model,
                'latency_ms' => $response->latencyMs,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'history_turns' => intdiv(count($history), 2),
            ], $emojiEngine->metaFor($emoji), $meta);

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
     * Diagnostic access to the keyword retrieval (the runner's own method is
     * private; AiDiagnostic replays the exact same logic).
     *
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    public function keywordChunksForDiagnostic(int $kbId, string $query, int $limit): array
    {
        return $this->keywordChunks($kbId, $query, $limit);
    }

    /**
     * Diagnostic access to the rank fusion so AiDiagnostic can replay the
     * exact hybrid pipeline (embed → search, keyword → fuse) and show what
     * the bot would really retrieve.
     *
     * @param  array<int, array{chunk: AiKbChunk, score: float}>  $embedResults
     * @param  array<int, array{chunk: AiKbChunk, score: float}>  $keywordResults
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    public function fuseForDiagnostic(array $embedResults, array $keywordResults, int $limit): array
    {
        return $this->fuseRrf($embedResults, $keywordResults, $limit);
    }

    /**
     * Keyword retrieval — the channel that saves the bot when embeddings are
     * unavailable OR loosely matched. Upgraded beyond the old hit-ratio:
     *
     *  - synonyms: "cheapest" also matches "lowest", "price" matches "cost"...
     *  - phrase bonus: the full query phrase inside a chunk out-ranks scattered words
     *  - number bonus: figures from the query (1999, 3999...) found literally in a
     *    chunk are a strong signal — customers and KBs both price with numbers
     *
     * @param  list<string>  $extraKeywords
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    private function keywordChunks(int $kbId, string $query, int $limit, array $extraKeywords = []): array
    {
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', str_replace(',', '', mb_strtolower($query))) ?? '';
        $words = array_values(array_filter(explode(' ', $clean), fn ($w) => mb_strlen(trim($w)) > 2));
        $stopWords = ['the', 'and', 'for', 'you', 'have', 'with', 'this', 'that', 'are', 'what', 'how', 'much', 'can', 'get', 'want', 'any', 'which', 'your', 'offer'];
        $keywords = array_values(array_diff($words, $stopWords));

        // Emoji-derived keywords join the base set: "😍" normalized to "love
        // it" already lands as words above, but emoji-only fragments ("🔥🔥")
        // leave no words at all — the engine's keywords rescue retrieval.
        foreach ($extraKeywords as $ek) {
            if (! in_array($ek, $keywords, true)) {
                $keywords[] = $ek;
            }
        }

        if (empty($keywords)) {
            return [];
        }

        $expanded = $this->expandKeywords($keywords);
        $numbers = array_values(array_filter($words, fn ($w) => ctype_digit($w) && mb_strlen($w) >= 2));
        $phrase = implode(' ', $keywords);

        $candidates = AiKbChunk::where('kb_id', $kbId)
            ->where(function ($builder) use ($expanded) {
                foreach ($expanded as $kw) {
                    $builder->orWhere('content', 'LIKE', '%'.$kw.'%');
                }
            })
            ->limit(200)
            ->get();

        return $candidates
            ->map(function (AiKbChunk $chunk) use ($expanded, $numbers, $phrase, $keywords) {
                $content = mb_strtolower($chunk->content ?? '');
                $matched = 0;
                foreach ($expanded as $kw) {
                    if (str_contains($content, $kw)) {
                        $matched++;
                    }
                }

                // Base score: matched keywords / original query keywords — a
                // chunk can only score 1.0 by matching every real query word
                // (synonyms merely unlock the LIKE candidates and shared hits).
                $score = $matched > 0 ? $matched / max(count($keywords), 1) : 0.0;

                // Phrase bonus — the whole query verbatim is the strongest match.
                // (emoji keywords appended after the phrase was built are not
                // part of it; $phrase is non-empty by construction here)
                if (str_contains($content, $phrase)) {
                    $score += 0.5;
                }

                // Number bonus — every literal figure hit is a strong signal.
                foreach ($numbers as $num) {
                    if (str_contains($content, $num)) {
                        $score += 0.3;
                    }
                }

                return ['chunk' => $chunk, 'score' => $score];
            })
            ->filter(fn (array $r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /**
     * Query keywords plus their synonym expansions (deduped, lowercase).
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function expandKeywords(array $keywords): array
    {
        $expanded = [];
        foreach ($keywords as $kw) {
            $expanded[] = $kw;
            foreach (self::SYNONYMS[$kw] ?? [] as $syn) {
                if (! in_array($syn, $expanded, true)) {
                    $expanded[] = $syn;
                }
            }
        }

        return $expanded;
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
     * Hybrid retrieval: embedding search and keyword search run side by side and
     * are merged with Reciprocal Rank Fusion, then top-k is taken.
     *
     * The previous one-or-the-other logic had a blind spot: once the embedding
     * search returned ANY hit above the relevance threshold, the keyword channel
     * never ran — so the chunk that literally contained the answer (e.g. reseller
     * pricing) could be crowded out by loosely-related chunks the embedder liked.
     *
     * @param  array<int, float>  $queryEmbedding
     * @param  list<string>  $extraKeywords  emoji-engine keywords folded into the keyword channel
     * @return array<int, array{chunk: AiKbChunk, score: float}> RRF-fused results
     */
    private function retrieveHybrid(int $kbId, string $query, array $queryEmbedding, int $limit, array $extraKeywords = []): array
    {
        $embedResults = [];
        if (! empty($queryEmbedding)) {
            $embedResults = $this->embedStore->search(
                $kbId,
                $queryEmbedding,
                max($limit, 5),
                self::HYBRID_MIN_EMBED_SCORE,
            );
        }

        $keywordResults = $this->keywordChunks($kbId, $query, max($limit, 5), $extraKeywords);

        return $this->fuseRrf($embedResults, $keywordResults, $limit);
    }

    /**
     * Reciprocal Rank Fusion over the two retrieval channels. RRF only uses
     * channel RANKS, so cosine scores and keyword hit-ratios — which are not
     * comparable scales — never need normalising.
     *
     * @param  array<int, array{chunk: AiKbChunk, score: float}>  $embedResults
     * @param  array<int, array{chunk: AiKbChunk, score: float}>  $keywordResults
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    private function fuseRrf(array $embedResults, array $keywordResults, int $limit): array
    {
        $fused = [];

        foreach ([$embedResults, $keywordResults] as $channel) {
            foreach (array_values($channel) as $rank => $result) {
                $chunkId = $result['chunk']->id ?? spl_object_id($result['chunk']);
                $fused[$chunkId] = ($fused[$chunkId] ?? 0.0) + 1.0 / (self::RRF_K + $rank + 1);
            }
        }

        arsort($fused);

        $byId = collect($embedResults)
            ->merge($keywordResults)
            ->keyBy(fn ($r) => $r['chunk']->id ?? spl_object_id($r['chunk']));

        return collect($fused)
            ->take($limit)
            ->map(fn (float $score, $chunkId) => [
                'chunk' => $byId[$chunkId]['chunk'],
                'score' => $score,
            ])
            ->values()
            ->toArray();
    }

    /**
     * One retry with a grounding nudge, but ONLY when the KB actually supplied
     * context and the first reply still hedged ("I'll confirm with our team").
     * A retry whose first message still carries the hedged reply as history
     * gives the model a concrete example to overwrite — models recover far
     * more reliably this way than from a system instruction alone.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts
     * @param  array<string, mixed>|null  $meta
     */
    private function groundedRetry(
        LlmResponse $response,
        array $messages,
        int $workspaceId,
        array $opts,
        ?int $chatbotId,
        bool $hadContext,
        ?array &$meta = null,
    ): LlmResponse {
        if (! $hadContext || ! $this->looksHedged($response->content)) {
            return $response;
        }

        $retryMessages = $messages;
        $lastIndex = count($retryMessages) - 1;
        if (isset($retryMessages[$lastIndex]) && $retryMessages[$lastIndex]['role'] === 'user') {
            $retryMessages[$lastIndex] = [
                'role' => 'user',
                'content' => $retryMessages[$lastIndex]['content']."\n\n[Your previous draft hedged: \"".
                    mb_substr(trim((string) $response->content), 0, 300).
                    "\" — answer it properly now.]",
            ];
        }
        $retryMessages[] = ['role' => 'assistant', 'content' => (string) $response->content];
        $retryMessages[] = ['role' => 'system', 'content' => self::GROUNDING_NUDGE];

        try {
            $retry = $this->llmGateway->chat($workspaceId, $retryMessages, $opts, $chatbotId);
        } catch (\Throwable) {
            return $response; // keep the hedged reply — never lose a reply over a retry
        }

        // Accept only if the retry is a real answer, not another hedge.
        if ($this->looksHedged($retry->content)) {
            return $response;
        }

        if ($meta !== null) {
            $meta['grounded_retry'] = true;
        }

        return $retry;
    }

    /** True when the reply offers to "confirm with the team" or claims missing info. */
    private function looksHedged(?string $content): bool
    {
        $text = trim((string) $content);
        if ($text === '') {
            return false;
        }

        foreach (self::HEDGE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
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
        // Emoji intelligence — same engine-level treatment as run() so the
        // playground/API path behaves identically to production.
        $emojiEngine = app(EmojiEngine::class);
        $emoji = $emojiEngine->analyze($message);
        $normalizedMessage = $emojiEngine->normalize($message);

        // 1. Embed the user query for RAG
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$normalizedMessage]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable) {
            }
        }

        // 2. Retrieve top-k relevant chunks (hybrid: embedding + keyword, rank-fused)
        $contextChunks = [];
        if ($bot->ai_kb_id) {
            $results = $this->retrieveHybrid($bot->ai_kb_id, $normalizedMessage, $queryEmbedding, $bot->max_context_chunks ?? 5, $emoji['keywords']);
            $contextChunks = array_column(app(KbContextTrimmer::class)->fit($results), 'chunk');
        }

        // 3. Build messages array — layered prompt (guardrails + admin rules + tone + bot prompt)
        $systemPrompt = app(AiSystemPrompt::class)->build($bot);
        $emojiLayer = $emojiEngine->promptLayer($emoji);
        if ($emojiLayer !== null) {
            $systemPrompt .= "\n\n".$emojiLayer;
        }
        
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

        // 4. Call LLM — with the same grounded-answer retry as run().
        $opts = ['max_tokens' => 512, 'temperature' => (float) ($bot->temperature ?? 0.3)];

        try {
            $response = $this->llmGateway->chat($workspaceId, $messages, $opts, $bot->id);
            $retryMeta = [];
            $response = $this->groundedRetry($response, $messages, $workspaceId, $opts, $bot->id, $contextChunks !== [], $retryMeta);

            return [
                'reply' => $this->cleanReply($response->content, $bot),
                'tokens_used' => $response->promptTokens + $response->completionTokens,
            ];
        } catch (\Throwable) {
            return ['reply' => $this->fallbackFor($bot), 'tokens_used' => 0];
        }
    }
}
