<?php

namespace Tests\Feature\Inbox;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Http\Controllers\AiDraftController;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AI draft suggestion endpoint: authorisation, bot resolution (channel-account
 * link first, then first enabled workspace bot) and the runForApi hand-off.
 * The runner itself is mocked — its pipeline is covered by the AI suite.
 */
class AiDraftTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        $workspace = $this->ctx['workspace'];
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $this->account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'WA',
            'status' => 'active',
            'meta_json' => ['ai_chatbot_id' => null],
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_to' => 'bot',
        ]);
    }

    private function addInbound(string $body): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    private function mockRunner(?string $draft = 'Here is a suggested reply!', int $tokens = 120): void
    {
        $runner = \Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('runForApi')->once()->andReturn([
            'reply' => $draft,
            'tokens_used' => $tokens,
        ]);
        $this->app->instance(ChatbotRunner::class, $runner);
    }

    public function test_route_exists_with_limit_middleware(): void
    {
        $route = Route::getRoutes()->getByName('client.inbox.ai-draft.store');

        $this->assertNotNull($route);
        $this->assertStringContainsString('limit:ai_tokens_per_month,ai_tokens', implode(',', $route->gatherMiddleware()));
    }

    public function test_returns_draft_for_latest_inbound_message(): void
    {
        $this->mockRunner();

        $workspace = $this->ctx['workspace'];
        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Bot',
            'enabled' => true,
        ]);
        $this->account->update(['meta_json' => ['ai_chatbot_id' => $chatbot->id]]);

        $this->addInbound('Kya packages hain?');
        $this->addInbound('Aur delivery kitne din ki hai?');

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid));

        $response->assertOk()
            ->assertJsonStructure(['draft', 'meta' => ['tokens_used']]);
        $this->assertSame('Here is a suggested reply!', $response->json('draft'));
    }

    public function test_falls_back_to_first_enabled_workspace_bot(): void
    {
        $this->mockRunner();

        // No bot linked to the channel account — workspace has one enabled bot.
        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Workspace Bot',
            'enabled' => true,
        ]);

        $this->addInbound('hello');

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid));

        $response->assertOk();
    }

    public function test_422_when_no_bot_configured(): void
    {
        $this->addInbound('hello');

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid));

        $response->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_422_when_no_inbound_message_exists(): void
    {
        // No runner mock — the controller must 422 before ever reaching it.
        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid));

        $response->assertStatus(422);
    }

    public function test_forbidden_for_other_workspace_conversation(): void
    {
        // No runner mock — authorisation aborts before the runner is touched.
        $other = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $other['workspace']->id]);
        $account = ChannelAccount::create([
            'workspace_id' => $other['workspace']->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'WA-2',
            'status' => 'active',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $other['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hi',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $conversation->uuid));

        $response->assertForbidden();
    }

    public function test_502_when_runner_returns_empty_draft(): void
    {
        $this->mockRunner('');

        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);
        $this->addInbound('hello');

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid));

        $response->assertStatus(502)
            ->assertJsonStructure(['error']);
    }

    public function test_history_is_built_and_passed_to_runner(): void
    {
        // Capture the arguments runForApi receives.
        $captured = [];
        $runner = \Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('runForApi')
            ->once()
            ->andReturnUsing(function ($bot, $message, $workspaceId, $history) use (&$captured) {
                $captured = ['message' => $message, 'history' => $history];

                return ['reply' => 'ok', 'tokens_used' => 1];
            });
        $this->app->instance(ChatbotRunner::class, $runner);

        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);

        // Explicit sent_at ordering — identical timestamps would make the
        // "latest inbound" lookup ambiguous on second-granularity columns.
        Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'first customer message',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now()->subMinutes(3),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'our earlier reply',
            'status' => 'sent',
            'sent_by' => 'human',
            'sent_at' => now()->subMinutes(2),
        ]);
        $latest = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'latest customer question',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now()->subMinutes(1),
        ]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid));

        $this->assertSame('latest customer question', $captured['message']);
        $this->assertSame('latest customer question', $latest->body);
        $this->assertCount(2, $captured['history']);
        $this->assertSame('user', $captured['history'][0]['role']);
        $this->assertSame('first customer message', $captured['history'][0]['content']);
        $this->assertSame('assistant', $captured['history'][1]['role']);
        $this->assertSame('our earlier reply', $captured['history'][1]['content']);
    }

    public function test_typed_draft_improves_instead_of_generating_reply(): void
    {
        $captured = [];
        $runner = \Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('improveForApi')
            ->once()
            ->andReturnUsing(function ($bot, $draft, $workspaceId, $history) use (&$captured) {
                $captured = ['draft' => $draft, 'history' => $history];

                return ['reply' => 'Pls send your order number', 'tokens_used' => 88];
            });
        $this->app->instance(ChatbotRunner::class, $runner);

        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);
        $this->addInbound('where is my order');

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid), [
                'draft' => '  pls send order number  ',
            ]);

        $response->assertOk()
            ->assertJsonPath('draft', 'Pls send your order number')
            ->assertJsonPath('mode', 'improve');
        $this->assertSame('pls send order number', $captured['draft']);
        // The customer's latest message still rides along as history context.
        $this->assertCount(1, $captured['history']);
        $this->assertSame('user', $captured['history'][0]['role']);
        $this->assertSame('where is my order', $captured['history'][0]['content']);
    }

    public function test_improve_passes_conversation_history_for_pronoun_context(): void
    {
        $captured = [];
        $runner = \Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('improveForApi')
            ->once()
            ->andReturnUsing(function ($bot, $draft, $workspaceId, $history) use (&$captured) {
                $captured = ['draft' => $draft, 'history' => $history];

                return ['reply' => 'improved', 'tokens_used' => 5];
            });
        $this->app->instance(ChatbotRunner::class, $runner);

        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);

        // Explicit sent_at ordering — see test_history_is_built_and_passed_to_runner.
        Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'kya package hai',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now()->subMinutes(2),
        ]);
        Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hamara pro package 2000/month hai',
            'status' => 'sent',
            'sent_by' => 'human',
            'sent_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid), [
                'draft' => 'yes that one',
            ]);

        $this->assertCount(2, $captured['history']);
        $this->assertSame('user', $captured['history'][0]['role']);
        $this->assertSame('assistant', $captured['history'][1]['role']);
    }

    public function test_502_when_improve_runner_returns_empty(): void
    {
        $runner = \Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('improveForApi')->once()->andReturn(['reply' => '', 'tokens_used' => 0]);
        $this->app->instance(ChatbotRunner::class, $runner);

        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid), [
                'draft' => 'something',
            ]);

        $response->assertStatus(502)
            ->assertJsonStructure(['error']);
    }

    public function test_whitespace_only_draft_falls_back_to_reply_generation(): void
    {
        $this->mockRunner();

        AiChatbot::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);
        $this->addInbound('hello');

        $response = $this->actingAs($this->ctx['user'])
            ->postJson(route('client.inbox.ai-draft.store', $this->conversation->uuid), [
                'draft' => '   ',
            ]);

        $response->assertOk()
            ->assertJsonPath('draft', 'Here is a suggested reply!');
    }
}
