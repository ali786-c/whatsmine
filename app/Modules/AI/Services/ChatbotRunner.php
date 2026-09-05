<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\Message;

class ChatbotRunner
{
    public function __construct(
        private LlmGateway $llmGateway,
        private EmbeddingStore $embedStore,
    ) {}

    public function run(AiChatbot $bot, Message $inboundMessage): ?string
    {
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

        // 2. Retrieve top-k relevant chunks
        $contextChunks = [];
        if ($bot->ai_kb_id && ! empty($queryEmbedding)) {
            $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            $contextChunks = array_column($results, 'chunk');
        }

        // Base system prompt remains strictly for behavioral instructions
        $systemPrompt = $bot->system_prompt ?? 'You are a helpful assistant.';

        // Load recent conversation turns as context
        $historyLimit = $bot->history_limit ?? 5;
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
                'content' => $m->body,
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
                $augmentedUserMessage .= "Knowledge Base:\n" . $contextText . "\n\n";
            }
            
            if ($orderSummary !== null) {
                $augmentedUserMessage .= "Customer Recent Orders (Use if asked about orders/shipping/delivery):\n" . $orderSummary . "\n\n";
            }

            if ($productSummary !== null) {
                $augmentedUserMessage .= "Product Information (Use if asked about pricing/stock/products):\n" . $productSummary . "\n\n";
            }
            
            $augmentedUserMessage .= "---------------------\nAnswer the user's query using the context provided above. If the context does not contain the answer, say so. However, if the query is a simple greeting or conversational (like 'hi' or 'thanks'), just respond naturally and conversationally.\nQuery: ";
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
                    'max_tokens' => $bot->max_tokens ?? 256,
                    'num_ctx' => $bot->num_ctx ?? 2048,
                    'keep_alive' => $bot->keep_alive ?? '10m'
                ],
                $bot->id,
                $conversation->id,
            );

            return $response->content;
        } catch (\Throwable $e) {
            // Fallback
            return $bot->fallback_reply ?? null;
        }
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

        // 2. Retrieve top-k relevant chunks
        $contextChunks = [];
        if ($bot->ai_kb_id && ! empty($queryEmbedding)) {
            $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            $contextChunks = array_column($results, 'chunk');
        }

        // 3. Build messages array
        $systemPrompt = $bot->system_prompt ?? 'You are a helpful assistant.';
        
        $augmentedUserMessage = "";
        if (! empty($contextChunks)) {
            $augmentedUserMessage .= "Context information is below.\n---------------------\n";
            $context = implode("\n\n---\n\n", array_map(fn ($c) => $c->content, $contextChunks));
            $augmentedUserMessage .= "Knowledge Base:\n" . $context . "\n\n";
            $augmentedUserMessage .= "---------------------\nAnswer the user's query using the context provided above. If the context does not contain the answer, say so. However, if the query is a simple greeting or conversational (like 'hi' or 'thanks'), just respond naturally and conversationally.\nQuery: ";
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
                ['max_tokens' => 512],
                $bot->id,
            );

            return [
                'reply' => $response->content,
                'tokens_used' => $response->promptTokens + $response->completionTokens,
            ];
        } catch (\Throwable) {
            return ['reply' => $bot->fallback_reply ?? null, 'tokens_used' => 0];
        }
    }
}
