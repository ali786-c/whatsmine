<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Services\EmojiEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Engine-level emoji intelligence: the LLM "understands" emoji, but keyword
 * retrieval strips them and the system prompt never saw them. These tests pin
 * the deterministic layer that feeds retrieval, prompt and run-meta.
 */
class EmojiEngineTest extends TestCase
{
    private EmojiEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(EmojiEngine::class);
    }

    #[Test]
    public function normalizes_emoji_into_semantic_phrases(): void
    {
        $out = $this->engine->normalize('Package 📦 kab aayega?');

        $this->assertStringContainsString('package delivery', $out);
        $this->assertStringContainsString('kab aayega?', $out);
        $this->assertStringNotContainsString('📦', $out);
    }

    #[Test]
    public function normalize_keeps_plain_text_intact(): void
    {
        $this->assertSame('Delivery charge kitna hai?', $this->engine->normalize('Delivery charge kitna hai?'));
    }

    #[Test]
    public function normalize_strips_unknown_emoji_but_flags_them(): void
    {
        $analysis = $this->engine->analyze('Hello 🐙 world');
        $this->assertTrue($analysis['has_emoji']);

        // Unknown emoji carry no keywords — retrieval stays clean.
        $this->assertSame([], $analysis['keywords']);
    }

    #[Test]
    public function analyze_detects_positive_sentiment_and_keywords(): void
    {
        $analysis = $this->engine->analyze('Your product is 😍');

        $this->assertTrue($analysis['has_emoji']);
        $this->assertSame('positive', $analysis['sentiment']);
        $this->assertSame(3, $analysis['intensity']);
        $this->assertTrue($analysis['confident']);
        $this->assertContains('love', $analysis['keywords']);
    }

    #[Test]
    public function analyze_detects_anger_as_escalation_risk(): void
    {
        $analysis = $this->engine->analyze('😡😠 service worst hai');

        $this->assertSame('angry', $analysis['sentiment']);
        $this->assertContains('escalation_risk', $analysis['intents']);
        $this->assertSame(3, $analysis['intensity']);
    }

    #[Test]
    public function analyze_detects_delivery_and_urgency_intents(): void
    {
        $analysis = $this->engine->analyze('🚨 mera 📦 abhi tak nahi aya');

        $this->assertContains('urgency', $analysis['intents']);
        $this->assertContains('purchase_or_delivery', $analysis['intents']);
    }

    #[Test]
    public function analyze_without_emoji_returns_empty_signals(): void
    {
        $analysis = $this->engine->analyze('plain message');

        $this->assertFalse($analysis['has_emoji']);
        $this->assertSame(0, $analysis['count']);
        $this->assertNull($analysis['sentiment']);
        $this->assertSame([], $analysis['intents']);
    }

    #[Test]
    public function prompt_layer_is_null_without_emoji(): void
    {
        $this->assertNull($this->engine->promptLayer($this->engine->analyze('no emoji here')));
    }

    #[Test]
    public function prompt_layer_instructs_deescalation_for_anger(): void
    {
        $layer = $this->engine->promptLayer($this->engine->analyze('😡😡😡'));

        $this->assertNotNull($layer);
        $this->assertStringContainsString('EMOJI SIGNAL', $layer);
        $this->assertStringContainsString('UPSET', $layer);
        $this->assertStringContainsString('de-escalate', $layer);
    }

    #[Test]
    public function prompt_layer_flags_urgency(): void
    {
        $layer = $this->engine->promptLayer($this->engine->analyze('🚨 urgent help'));

        $this->assertNotNull($layer);
        $this->assertStringContainsString('URGENT', $layer);
    }

    #[Test]
    public function expand_keywords_feeds_retrieval_expansion(): void
    {
        $keywords = $this->engine->expandKeywords($this->engine->analyze('🛒 💰'));

        $this->assertContains('order', $keywords);
        $this->assertContains('price', $keywords);
    }

    #[Test]
    public function meta_block_carries_signals_for_diagnostics(): void
    {
        $meta = $this->engine->metaFor($this->engine->analyze('🚨'));

        $this->assertSame(1, $meta['emoji_count']);
        $this->assertSame('urgent', $meta['emoji_sentiment']);
        $this->assertContains('urgency', $meta['emoji_intents']);
    }

    #[Test]
    public function meta_block_is_empty_without_emoji(): void
    {
        $this->assertSame([], $this->engine->metaFor($this->engine->analyze('nothing')));
    }
}
