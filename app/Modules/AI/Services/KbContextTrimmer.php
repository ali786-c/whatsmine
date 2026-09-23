<?php

namespace App\Modules\AI\Services;

/**
 * Hard cap on how much Knowledge Base context is injected into a chatbot
 * prompt. Chunk size is controlled at indexing time (~800 words ≈ 1000+
 * tokens each), so without this guard a 5-chunk retrieval could push 5k+
 * tokens of context into every single reply — even when the first chunk
 * already answered the question.
 *
 * Strategy: keep the highest-scored chunks first, stop when the character
 * budget is exhausted, and never split a chunk mid-sentence (drop instead —
 * a half-cut chunk confuses the model).
 */
class KbContextTrimmer
{
    /** ~2400 characters ≈ 600 tokens of KB context per reply. */
    public const DEFAULT_MAX_CHARS = 2400;

    public function __construct(private readonly int $maxChars = self::DEFAULT_MAX_CHARS) {}

    /**
     * @param  array<int, array{chunk: \App\Modules\AI\Models\AiKbChunk, score: float}>  $results  EmbeddingStore search results
     * @return array<int, array{chunk: \App\Modules\AI\Models\AiKbChunk, score: float}>  Kept results, within budget
     */
    public function fit(array $results): array
    {
        $kept = [];
        $used = 0;

        foreach ($results as $result) {
            $content = trim((string) ($result['chunk']->content ?? ''));
            if ($content === '') {
                continue;
            }

            if ($used + mb_strlen($content) > $this->maxChars) {
                break; // Budget exhausted — skip the rest (lower-scored anyway)
            }

            $kept[] = $result;
            $used += mb_strlen($content);
        }

        return $kept;
    }
}
