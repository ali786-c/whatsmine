<?php

namespace Tests\Feature\AI;

use App\Events\MessageSent;
use App\Listeners\LearnFromConversationListener;
use App\Modules\AI\Jobs\ExtractConversationLearningJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiConversationSummary;
use App\Modules\AI\Models\AiMemory;
use App\Modules\AI\Services\AiMemoryService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Self-learning engine: memory injection, background extraction (strict JSON),
 * throttle, decay/prune, workspace toggle and the ChatbotRunner integration.
 */
class AiMemoryTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $contact = Contact::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    private function service(): AiMemoryService
    {
        return app(AiMemoryService::class);
    }

    private function addMessage(string $direction, string $body, string $sentBy = 'human'): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => $direction,
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'sent_by' => $sentBy,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // injectBlock / summaryBlock
    // -------------------------------------------------------------------------

    public function test_inject_block_is_null_without_memories(): void
    {
        $this->assertNull($this->service()->injectBlock($this->workspace->id));
    }

    public function test_inject_block_includes_top_memories_in_order(): void
    {
        AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'policy', 'content' => 'Refunds allowed within 30 days.', 'usefulness' => 5, 'source' => 'auto']);
        AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'business', 'content' => 'Pro plan costs Rs 2999/month.', 'usefulness' => 2, 'source' => 'auto']);

        $block = $this->service()->injectBlock($this->workspace->id);

        $this->assertNotNull($block);
        $this->assertStringContainsString('Refunds allowed within 30 days.', $block);
        $this->assertStringContainsString('Pro plan costs Rs 2999/month.', $block);
        // Highest usefulness first
        $this->assertLessThan(
            mb_strpos($block, 'Pro plan costs'),
            mb_strpos($block, 'Refunds allowed'),
        );
    }

    public function test_inject_block_honours_bot_scoped_memories(): void
    {
        $bot = AiChatbot::create(['workspace_id' => $this->workspace->id, 'name' => 'Bot A', 'enabled' => true]);
        $otherBot = AiChatbot::create(['workspace_id' => $this->workspace->id, 'name' => 'Bot B', 'enabled' => true]);

        AiMemory::create(['workspace_id' => $this->workspace->id, 'chatbot_id' => $otherBot->id, 'kind' => 'fact', 'content' => 'Only for bot B.', 'source' => 'auto']);
        AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'fact', 'content' => 'Global for all bots.', 'source' => 'auto']);

        $block = $this->service()->injectBlock($this->workspace->id, $bot);

        $this->assertStringContainsString('Global for all bots.', $block);
        $this->assertStringNotContainsString('Only for bot B.', $block);
    }

    public function test_inject_block_counts_usage(): void
    {
        $memory = AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'fact', 'content' => 'We deliver in 48 hours.', 'source' => 'auto']);

        $this->service()->injectBlock($this->workspace->id);

        $this->assertSame(1, $memory->fresh()->use_count);
        $this->assertNotNull($memory->fresh()->last_used_at);
    }

    public function test_summary_block_is_null_without_summary(): void
    {
        $this->assertNull($this->service()->summaryBlock($this->conversation->id));
        $this->assertNull($this->service()->summaryBlock(null));
    }

    // -------------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------------

    public function test_extract_creates_summary_and_memories(): void
    {
        Queue::fake();

        $this->addMessage('in', 'Aap ka Pro plan kitne ka hai?', 'human');
        $this->addMessage('out', 'Pro plan Rs 2999 per month ka hai, unlimited chats ke sath.', 'bot');

        $fakeJson = json_encode([
            'summary' => 'Customer asked about the Pro plan price; agent quoted Rs 2999/month with unlimited chats.',
            'facts' => [
                ['kind' => 'business', 'content' => 'Pro plan is priced at Rs 2999/month including unlimited chats.'],
                ['kind' => 'unknown_kind', 'content' => 'Should be dropped by kind validation.'],
            ],
        ]);

        \Http::fake([
            '*' => \Http::response([
                'choices' => [['message' => ['content' => $fakeJson]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        // No provider configured → gateway would fail; craft the call through
        // parse + direct service path instead: configure an OpenAI provider.
        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        $this->service()->extractForConversation($this->conversation->id);

        $summary = AiConversationSummary::where('conversation_id', $this->conversation->id)->first();
        $this->assertNotNull($summary);
        $this->assertStringContainsString('Pro plan', $summary->summary);
        $this->assertGreaterThan(0, $summary->last_summarized_message_id);

        $this->assertSame(1, AiMemory::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(
            'Pro plan is priced at Rs 2999/month including unlimited chats.',
            AiMemory::where('workspace_id', $this->workspace->id)->first()->content,
        );
    }

    public function test_extract_skips_malformed_json(): void
    {
        $this->addMessage('out', 'Our refund policy is 30 days, no questions asked.', 'human');

        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        \Http::fake([
            '*' => \Http::response([
                'choices' => [['message' => ['content' => 'I cannot answer that in JSON, sorry!']]],
            ], 200),
        ]);

        $this->service()->extractForConversation($this->conversation->id);

        $this->assertNull(AiConversationSummary::where('conversation_id', $this->conversation->id)->first());
        $this->assertSame(0, AiMemory::count());
    }

    public function test_extract_parses_fenced_json(): void
    {
        $fakeJson = "```json\n".json_encode([
            'summary' => 'Short chat.',
            'facts' => [['kind' => 'policy', 'content' => 'Free shipping above Rs 5000.']],
        ])."\n```";

        $this->assertSame(
            1,
            count($this->service()->parseExtraction($fakeJson)[1]),
        );
    }

    public function test_extract_reinforces_duplicate_memory_instead_of_duplicating(): void
    {
        AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'policy', 'content' => 'Refunds allowed within 30 days.', 'usefulness' => 1, 'source' => 'auto']);

        $this->addMessage('out', 'Our refund policy is 30 days, no questions asked.', 'human');

        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        $fakeJson = json_encode([
            'summary' => 'Agent restated the refund policy.',
            'facts' => [['kind' => 'policy', 'content' => 'Refunds allowed within 30 days.']],
        ]);

        \Http::fake([
            '*' => \Http::response([
                'choices' => [['message' => ['content' => $fakeJson]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $this->service()->extractForConversation($this->conversation->id);

        $this->assertSame(1, AiMemory::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(2, AiMemory::where('workspace_id', $this->workspace->id)->first()->usefulness);
    }

    // -------------------------------------------------------------------------
    // Listener + throttle
    // -------------------------------------------------------------------------

    public function test_listener_dispatches_learning_job_every_th_qualifying_message(): void
    {
        Queue::fake();
        Cache::flush();

        for ($i = 0; $i < AiMemoryService::EXTRACTION_EVERY; $i++) {
            $message = $this->addMessage('out', 'This is a meaningful outbound reply number '.$i.' for learning.', 'human');
            event(new MessageSent($message->refresh()));
        }

        Queue::assertPushed(ExtractConversationLearningJob::class, 1);
    }

    public function test_listener_ignores_short_inbound_and_broadcast_messages(): void
    {
        Queue::fake();
        Cache::flush();

        $short = $this->addMessage('out', 'ok', 'human');
        event(new MessageSent($short->refresh()));

        $inbound = $this->addMessage('in', 'A customer message that is long enough to learn from normally.', 'human');
        event(new MessageSent($inbound->refresh()));

        $broadcast = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'A broadcast blast message that is long enough as well.',
            'sent_by' => 'broadcast',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        event(new MessageSent($broadcast));

        Queue::assertNotPushed(ExtractConversationLearningJob::class);
    }

    public function test_learning_disabled_workspace_never_extracts(): void
    {
        WorkspaceSetting::set($this->workspace->id, 'ai_learning_enabled', '0');
        $this->assertFalse($this->service()->learningEnabled($this->workspace->id));

        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        \Http::fake(['*' => $this->never()]);

        $this->addMessage('out', 'This message would normally trigger a learning extraction call.', 'human');
        $this->service()->extractForConversation($this->conversation->id);

        $this->assertNull(AiConversationSummary::where('conversation_id', $this->conversation->id)->first());
    }

    // -------------------------------------------------------------------------
    // Decay / prune
    // -------------------------------------------------------------------------

    public function test_prune_keeps_max_memories_lowest_value_first(): void
    {
        for ($i = 0; $i < AiMemory::MAX_MEMORIES + 5; $i++) {
            AiMemory::create([
                'workspace_id' => $this->workspace->id,
                'kind' => 'fact',
                'content' => 'Memory number '.$i,
                'usefulness' => $i < 5 ? 0 : 1,
                'source' => 'auto',
            ]);
        }

        $this->service()->prune($this->workspace->id);

        $this->assertSame(AiMemory::MAX_MEMORIES, AiMemory::where('workspace_id', $this->workspace->id)->count());
        $this->assertSame(0, AiMemory::where('workspace_id', $this->workspace->id)->where('usefulness', 0)->count());
    }

    public function test_decay_only_touches_stale_auto_memories(): void
    {
        $stale = AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'fact', 'content' => 'Stale one.', 'usefulness' => 3, 'source' => 'auto']);
        $fresh = AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'fact', 'content' => 'Fresh one.', 'usefulness' => 3, 'source' => 'auto']);
        $manual = AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'fact', 'content' => 'Manual one.', 'usefulness' => 3, 'source' => 'manual']);

        // Backdate only the stale one.
        $stale->forceFill(['updated_at' => now()->subDays(40)])->save();
        $manual->forceFill(['updated_at' => now()->subDays(40)])->save();

        $this->service()->decay($this->workspace->id);

        $this->assertSame(2, $stale->fresh()->usefulness);
        $this->assertSame(3, $fresh->fresh()->usefulness);
        $this->assertSame(3, $manual->fresh()->usefulness);
    }

    // -------------------------------------------------------------------------
    // ChatbotRunner integration
    // -------------------------------------------------------------------------

    public function test_runner_injects_memory_block_and_meta_flag(): void
    {
        AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'business', 'content' => 'Pro plan costs Rs 2999/month.', 'usefulness' => 5, 'source' => 'auto']);

        $bot = AiChatbot::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Support Bot',
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        $inbound = new Message;
        $inbound->body = 'What is the price?';
        $inbound->direction = 'in';
        $inbound->channel = 'whatsapp';
        $inbound->setRelation('conversation', $this->conversation);

        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        $captured = [];
        \Http::fake([
            'api.openai.com/v1/chat/completions' => function ($request) use (&$captured) {
                $body = json_decode($request->body(), true);
                $captured = $body['messages'] ?? [];

                return \Http::response([
                    'choices' => [['message' => ['content' => 'Pro plan Rs 2999 per month hai.']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $meta = [];
        $reply = app(\App\Modules\AI\Services\ChatbotRunner::class)->run($bot, $inbound, $meta);

        $this->assertNotNull($reply);
        $this->assertNotFalse(collect($captured)->contains(
            fn ($m) => $m['role'] === 'system' && str_contains($m['content'], 'Pro plan costs Rs 2999/month.'),
        ), 'Memory block should be injected as a system message');
        $this->assertTrue($meta['memory_injected']);
        $this->assertFalse($meta['summary_used']);
    }

    public function test_runner_injects_summary_block_for_summarized_conversation(): void
    {
        AiConversationSummary::create([
            'conversation_id' => $this->conversation->id,
            'workspace_id' => $this->workspace->id,
            'summary' => 'Customer ordered 2 Pro plans and asked for invoice.',
            'last_summarized_message_id' => 5,
            'message_count' => 6,
        ]);

        $bot = AiChatbot::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Support Bot',
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        $inbound = new Message;
        $inbound->body = 'Invoice mila?';
        $inbound->direction = 'in';
        $inbound->channel = 'whatsapp';
        $inbound->setRelation('conversation', $this->conversation);

        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        $captured = [];
        \Http::fake([
            'api.openai.com/v1/chat/completions' => function ($request) use (&$captured) {
                $body = json_decode($request->body(), true);
                $captured = $body['messages'] ?? [];

                return \Http::response([
                    'choices' => [['message' => ['content' => 'Ji, invoice ban rahi hai.']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $meta = [];
        app(\App\Modules\AI\Services\ChatbotRunner::class)->run($bot, $inbound, $meta);

        $this->assertNotFalse(collect($captured)->contains(
            fn ($m) => $m['role'] === 'system' && str_contains($m['content'], 'Customer ordered 2 Pro plans'),
        ), 'Summary block should be injected as a system message');
        $this->assertTrue($meta['summary_used']);
    }

    public function test_playground_history_replay_is_untouched_by_learning_layers(): void
    {
        // Regression guard: the existing client-supplied history path must not
        // change when learning layers are (or are not) present.
        AiMemory::create(['workspace_id' => $this->workspace->id, 'kind' => 'fact', 'content' => 'Some learned fact.', 'source' => 'auto']);

        $bot = AiChatbot::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Support Bot',
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'enabled' => true,
        ]);

        $captured = [];
        \Http::fake([
            'api.openai.com/v1/chat/completions' => function ($request) use (&$captured) {
                $body = json_decode($request->body(), true);
                $captured = $body['messages'] ?? [];

                return \Http::response([
                    'choices' => [['message' => ['content' => 'Done.']]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        $inbound = new Message;
        $inbound->body = 'Continue';
        $inbound->direction = 'in';
        $inbound->channel = 'playground';
        $fakeConv = new Conversation;
        $fakeConv->id = 0;
        $fakeConv->workspace_id = $this->workspace->id;
        $inbound->setRelation('conversation', $fakeConv);

        $history = [
            ['role' => 'user', 'content' => 'First message'],
            ['role' => 'assistant', 'content' => 'First reply'],
        ];

        app(\App\Modules\AI\Services\ChatbotRunner::class)->run($bot, $inbound, $meta, $history);

        $learningBlockRoles = [];
        foreach ($captured as $i => $m) {
            if (($m['role'] ?? '') === 'system' && str_contains($m['content'] ?? '', 'Some learned fact.')) {
                $learningBlockRoles[] = [$i, $m['content']];
            }
        }

        // History turns must still be present verbatim, in order.
        $roles = array_column($captured, 'role');
        $contents = array_column($captured, 'content');

        $firstHistoryIdx = array_search('First message', $contents, true);
        $this->assertNotFalse($firstHistoryIdx);
        $this->assertSame('user', $roles[$firstHistoryIdx]);

        $secondHistoryIdx = array_search('First reply', $contents, true);
        $this->assertNotFalse($secondHistoryIdx);
        $this->assertSame('assistant', $roles[$secondHistoryIdx]);

        // Exactly one memory block, injected as a system message after the main
        // system prompt and before the history replay.
        $this->assertCount(1, $learningBlockRoles);
        [$blockIdx, $blockContent] = $learningBlockRoles[0];
        $this->assertGreaterThan(0, $blockIdx, 'Memory block must come after the main system prompt');
        $this->assertLessThan($firstHistoryIdx, $blockIdx, 'Memory block must come before the history replay');
        $this->assertStringContainsString('Some learned fact.', $blockContent);
    }
}
