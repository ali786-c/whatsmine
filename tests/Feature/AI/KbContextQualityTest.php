<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\KbContextTrimmer;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KbContextQualityTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // EmbeddingStore relevance threshold
    // -------------------------------------------------------------------------

    public function test_search_drops_chunks_below_relevance_threshold(): void
    {
        [$kb, $chunk] = $this->makeChunk([1.0, 0.0, 0.0]);

        // Query pointing away from the chunk → score will be 0
        $results = app(EmbeddingStore::class)->search($kb->id, [0.0, 1.0, 0.0], 5);

        $this->assertSame([], $results, 'Irrelevant chunks (score 0) must not be returned.');
        $this->assertNotNull($chunk->refresh());
    }

    public function test_search_keeps_highly_relevant_chunks(): void
    {
        [$kb] = $this->makeChunk([1.0, 0.0, 0.0]);

        $results = app(EmbeddingStore::class)->search($kb->id, [1.0, 0.0, 0.0], 5);

        $this->assertCount(1, $results);
        $this->assertGreaterThanOrEqual(EmbeddingStore::MIN_RELEVANCE_SCORE, $results[0]['score']);
    }

    // -------------------------------------------------------------------------
    // KbContextTrimmer token budget
    // -------------------------------------------------------------------------

    public function test_trimmer_caps_total_context_characters(): void
    {
        $trimmer = new KbContextTrimmer(100);

        $chunkA = AiKbChunk::make(['content' => str_repeat('a', 60)]);
        $chunkB = AiKbChunk::make(['content' => str_repeat('b', 60)]);
        $chunkC = AiKbChunk::make(['content' => str_repeat('c', 60)]);

        $kept = $trimmer->fit([
            ['chunk' => $chunkA, 'score' => 0.9],
            ['chunk' => $chunkB, 'score' => 0.8],
            ['chunk' => $chunkC, 'score' => 0.7],
        ]);

        $this->assertCount(1, $kept, 'Only the first chunk fits in a 100-char budget.');
        $this->assertSame(0.9, $kept[0]['score']);
    }

    public function test_trimmer_keeps_everything_within_budget(): void
    {
        $trimmer = new KbContextTrimmer(1000);

        $kept = $trimmer->fit([
            ['chunk' => AiKbChunk::make(['content' => 'short chunk one']), 'score' => 0.9],
            ['chunk' => AiKbChunk::make(['content' => 'short chunk two']), 'score' => 0.8],
        ]);

        $this->assertCount(2, $kept);
    }

    public function test_trimmer_skips_empty_chunks(): void
    {
        $trimmer = new KbContextTrimmer(100);

        $kept = $trimmer->fit([
            ['chunk' => AiKbChunk::make(['content' => '   ']), 'score' => 0.9],
            ['chunk' => AiKbChunk::make(['content' => 'real content here']), 'score' => 0.8],
        ]);

        $this->assertCount(1, $kept);
        $this->assertSame('real content here', $kept[0]['chunk']->content);
    }

    // -------------------------------------------------------------------------
    // Keyword fallback + embed-provider exclusion
    // -------------------------------------------------------------------------

    public function test_runner_falls_back_to_keyword_search_when_embeddings_fail(): void
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        $kb = \App\Modules\AI\Models\AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'HostingGram KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
        $doc = \App\Modules\AI\Models\AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Hosting plans',
            'source_type' => 'faq',
            'source_ref' => '[]',
            'status' => 'indexed',
        ]);
        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Q: What is the price of the Starter hosting plan?\nA: The Starter plan starts at Rs 1,999/year.',
            'tokens' => 20,
            'embedding' => null, // No embeddings stored at all
        ]);

        $chatbot = \App\Modules\AI\Models\AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'HostingGram Bot',
            'ai_kb_id' => $kb->id,
            'system_prompt' => null,
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        $capturedUserMessage = null;
        Http::fake([
            '*embeddings*' => Http::response(['error' => ['message' => 'no embedding models']], 404),
            '*chat/completions*' => function ($request) use (&$capturedUserMessage) {
                $body = json_decode($request->body(), true);
                $capturedUserMessage = collect($body['messages'] ?? [])
                    ->firstWhere('role', 'user')['content'] ?? '';

                return Http::response([
                    'choices' => [['message' => ['content' => 'The Starter plan starts at Rs 1,999 per year.']]],
                    'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 12],
                    'model' => 'test-model',
                ], 200);
            },
        ]);

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conv = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = new Message;
        $message->body = 'starter plan ka price kya hai?';
        $message->direction = 'in';
        $message->channel = 'whatsapp';
        $message->setRelation('conversation', $conv);

        $reply = app(ChatbotRunner::class)->run($chatbot, $message);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('Starter plan starts at Rs 1,999', (string) $capturedUserMessage,
            'KB chunk must reach the prompt via keyword fallback when embeddings fail.');
    }

    public function test_faq_document_chunks_keep_question_and_answer_together(): void
    {
        $job = new \App\Modules\AI\Jobs\IndexDocumentJob(1);
        $formatFaq = new \ReflectionMethod($job, 'formatFaq');
        $formatFaq->setAccessible(true);
        $chunkFaq = new \ReflectionMethod($job, 'chunkFaq');
        $chunkFaq->setAccessible(true);

        $faq = json_encode([
            ['question' => 'What is the price of the Starter hosting plan?', 'answer' => 'The Starter plan starts at Rs 1,999/year.'],
            ['question' => 'What is HostingGram?', 'answer' => 'HostingGram is a web hosting provider.'],
            ['question' => 'What is the price of the PRO hosting plan?', 'answer' => 'The PRO plan starts at Rs 2,999/year.'],
        ]);

        $text = $formatFaq->invoke($job, $faq);
        $chunks = $chunkFaq->invoke($job, $text);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            // Every chunk boundary must fall BETWEEN pairs — never through one.
            $this->assertTrue(
                str_contains($chunk, 'Q: ') && str_contains($chunk, 'A: '),
                'FAQ chunk must contain complete Q&A pairs, never a question severed from its answer.'
            );
        }
        // The price fact must appear intact inside one chunk.
        $priceChunk = collect($chunks)->first(fn ($c) => str_contains($c, 'Rs 1,999/year'));
        $this->assertNotNull($priceChunk);
        $this->assertStringContainsString('What is the price of the Starter hosting plan?', $priceChunk);
    }

    public function test_keyword_fallback_ranks_best_matching_chunk_first(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = \App\Modules\AI\Models\AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test KB',
            'status' => 'active',
        ]);
        $doc = \App\Modules\AI\Models\AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'FAQ',
            'source_type' => 'faq',
            'source_ref' => '[]',
            'status' => 'indexed',
        ]);

        // A chunk that only mentions "plan" vs the chunk that actually answers
        // the Starter-price question (starter + plan + price + hosting).
        $distractor = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Our PRO plan is very popular. Every plan includes support.',
            'tokens' => 12,
            'embedding' => null,
        ]);
        $answer = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 1,
            'content' => 'Q: What is the price of the Starter hosting plan?\nA: The Starter plan starts at Rs 1,999/year.',
            'tokens' => 20,
            'embedding' => null,
        ]);

        $results = app(ChatbotRunner::class)->keywordChunksForDiagnostic(
            $kb->id,
            'What is the price of the Starter hosting plan',
            5
        );

        $this->assertNotEmpty($results);
        $this->assertSame($answer->id, $results[0]['chunk']->id,
            'Ranked fallback must put the chunk matching the most query keywords first.');
        $this->assertGreaterThan($this->scoreOf($results, $distractor->id), $this->scoreOf($results, $answer->id));
    }

    private function scoreOf(array $results, int $chunkId): float
    {
        foreach ($results as $r) {
            if ($r['chunk']->id === $chunkId) {
                return (float) $r['score'];
            }
        }

        return -1.0;
    }

    public function test_omniroute_is_never_used_for_embeddings(): void
    {
        $workspace = $this->createWorkspaceContext()['workspace'];

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'omniroute',
            'credentials' => ['api_key' => 'sk-x', 'base_url' => 'http://omni.test/v1'],
            'enabled' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('embedding-capable');

        LlmManager::forWorkspaceEmbed($workspace->id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{0: \App\Modules\AI\Models\AiKnowledgeBase, 1: AiKbChunk}
     */
    private function makeChunk(array $embedding): array
    {
        $workspace = $this->createWorkspaceContext()['workspace'];
        $kb = \App\Modules\AI\Models\AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => count($embedding),
            'status' => 'active',
        ]);
        $doc = \App\Modules\AI\Models\AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Test doc',
            'source_type' => 'text',
            'source_ref' => 'test content',
            'status' => 'indexed',
        ]);
        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'test chunk content',
            'tokens' => 3,
            'embedding' => null,
        ]);

        app(EmbeddingStore::class)->storeEmbedding($chunk, $embedding);

        return [$kb, $chunk];
    }
}
