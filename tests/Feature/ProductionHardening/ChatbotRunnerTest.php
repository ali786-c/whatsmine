<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ChatbotRunner end-to-end test with Http::fake for OpenAI.
 * Verifies that context chunks from the KB are included in the prompt.
 */
class ChatbotRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_runner_includes_kb_context_in_prompt(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        // Seed: KB + document + chunk with a known embedding
        $kb = AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'FAQ',
            'source_type' => 'text',
            'source_ref' => 'Our refund policy is 30 days.',
            'status' => 'indexed',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Our refund policy is 30 days.',
            'tokens' => 8,
            'embedding' => null,
        ]);

        // Manually store a small embedding that matches anything
        $embeddingStore = app(EmbeddingStore::class);
        $embeddingStore->storeEmbedding($chunk, [0.1, 0.2, 0.3]);

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'ai_kb_id' => $kb->id,
            'system_prompt' => 'You are a helpful assistant.',
            'max_context_chunks' => 3,
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conv = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = new Message;
        $message->body = 'What is your refund policy?';
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', $conv);

        $capturedSystemPrompt = null;
        $capturedUserMessage = null;

        // Fake both embedding and chat OpenAI calls using URL-keyed closures
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use (&$capturedSystemPrompt, &$capturedUserMessage) {
                $body = json_decode($request->body(), true);
                $capturedSystemPrompt = collect($body['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';
                $capturedUserMessage = collect($body['messages'] ?? [])->where('role', 'user')->pluck('content')->implode("\n");

                return Http::response([
                    'choices' => [['message' => ['content' => 'Our refund policy is 30 days.']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        // Add LLM workspace credential via AiProviderConfig
        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        $runner = app(ChatbotRunner::class);
        $reply = $runner->run($chatbot, $message);

        $this->assertNotNull($reply, 'ChatbotRunner should return a reply');
        $this->assertStringContainsString('refund', strtolower($reply));
        // KB context is injected into the final USER message (LlamaIndex-style,
        // see docs) — not the system prompt. Assert it reached the LLM call.
        $this->assertNotNull($capturedSystemPrompt, 'System prompt should have been captured');
        $this->assertStringContainsString('refund policy is 30 days', (string) $capturedUserMessage);
    }

    public function test_improve_for_api_rewrites_the_agent_draft_not_the_customer_question(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'system_prompt' => 'You are a helpful assistant.',
            'enabled' => true,
        ]);

        $capturedSystem = null;
        $capturedLastUser = null;
        Http::fake([
            'api.openai.com/v1/chat/completions' => function ($request) use (&$capturedSystem, &$capturedLastUser) {
                $body = json_decode($request->body(), true);
                $capturedSystem = collect($body['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';
                // History user turns come first — the draft is the LAST user message.
                $capturedLastUser = collect($body['messages'] ?? [])->where('role', 'user')->pluck('content')->last();

                return Http::response([
                    'choices' => [['message' => ['content' => 'Please share your order number so we can help you faster.']]],
                    'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 12],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $result = app(ChatbotRunner::class)->improveForApi($chatbot, 'pls send order numbr', $workspace->id, [
            ['role' => 'user', 'content' => 'where is my order'],
        ]);

        $this->assertSame('Please share your order number so we can help you faster.', $result['reply']);
        $this->assertSame(52, $result['tokens_used']);
        // Rewrite framing must reach the model, and the draft — not the
        // customer's question — must be the message being answered.
        $this->assertStringContainsString('UNSENT DRAFT', (string) $capturedSystem);
        $this->assertSame('pls send order numbr', (string) $capturedLastUser);
    }

    public function test_improve_for_api_returns_original_draft_when_upstream_dies(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support Bot',
            'enabled' => true,
        ]);

        Http::fake(['api.openai.com/*' => Http::response('server exploded', 500)]);

        $result = app(ChatbotRunner::class)->improveForApi($chatbot, 'my original text', $workspace->id);

        // Never destroy the agent's typed text over a dead upstream.
        $this->assertSame('my original text', $result['reply']);
        $this->assertSame(0, $result['tokens_used']);
    }
}
