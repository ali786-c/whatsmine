<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\KbContextTrimmer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
