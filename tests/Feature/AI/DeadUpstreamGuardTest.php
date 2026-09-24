<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dead-upstream guards: scraped/free OmniRoute upstreams (DuckDuckGo "ddgw/*",
 * Antigravity IDE sessions, etc.) answer every prompt with the same canned
 * greeting ("Hello! How can I assist you today?") while ignoring the system
 * prompt entirely. These tests pin the runtime defenses:
 *
 *  1. Greeting-loop detector  — canned greeting + ignored system prompt → fallback
 *  2. Error-shell detector    — upstream 4xx wrapped as 200 with an error string → fallback
 *  3. Healthy model           — real contextual answer passes through untouched
 *  4. Playground meta         — model/latency surfaced so bad routing is visible
 */
class DeadUpstreamGuardTest extends TestCase
{
    use RefreshDatabase;

    private const CANNED_GREETINGS = [
        'Hello! How can I assist you today?',
        'Hello! Welcome! How can I help you today?',
        "Hello! I'm here to help. How can I assist you today?",
        'Sorry to hear that. How can I assist you better?',
    ];

    public function test_canned_greeting_with_ignored_system_prompt_falls_back(): void
    {
        $bot = $this->makeBot();
        [$runner, $message] = $this->makeRunnerAndMessage($bot);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Hello! How can I assist you today?']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
                'model' => 'ddgw/gpt-5.4-nano',
            ], 200),
        ]);

        $meta = null;
        $reply = $runner->run($bot, $message, $meta);

        $this->assertSame('Sorry, our assistant is briefly unavailable. Please try again shortly.', $reply);
        $this->assertSame('dead_upstream', $meta['guard'] ?? null);
    }

    public function test_error_shell_200_response_falls_back(): void
    {
        $bot = $this->makeBot();
        [$runner, $message] = $this->makeRunnerAndMessage($bot);

        Http::fake([
            '*' => Http::response([
                'error' => ['message' => '[418]: DuckDuckGo AI Chat anti-abuse challenge failed: ERR_BN_LIMIT'],
            ], 200),
        ]);

        $meta = null;
        $reply = $runner->run($bot, $message, $meta);

        $this->assertSame('Sorry, our assistant is briefly unavailable. Please try again shortly.', $reply);
        $this->assertSame('dead_upstream', $meta['guard'] ?? null);
    }

    public function test_healthy_reply_passes_through(): void
    {
        $bot = $this->makeBot();
        [$runner, $message] = $this->makeRunnerAndMessage($bot);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'We offer web hosting, WordPress hosting, cloud hosting and domains.']]],
                'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 20],
                'model' => 'gemini-3.6-flash-high',
            ], 200),
        ]);

        $meta = null;
        $reply = $runner->run($bot, $message, $meta);

        $this->assertStringContainsString('web hosting', strtolower((string) $reply));
        $this->assertArrayNotHasKey('guard', $meta ?? []);
        $this->assertSame('gemini-3.6-flash-high', $meta['model'] ?? null);
    }

    public function test_run_for_api_greeting_loop_also_guarded(): void
    {
        $bot = $this->makeBot();

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => 'Hello! Welcome! How can I help you today?']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
                'model' => 'ddgw/gpt-5.4-nano',
            ], 200),
        ]);

        $result = $runner = app(ChatbotRunner::class)->runForApi($bot, 'what product you offer', $bot->workspace_id);

        $this->assertSame('Sorry, our assistant is briefly unavailable. Please try again shortly.', $result['reply']);
    }

    public function test_markdown_stripped_but_real_content_kept(): void
    {
        $bot = $this->makeBot();
        [$runner, $message] = $this->makeRunnerAndMessage($bot);

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => "**Web Hosting** from Rs 1,999/year\n\n*Fast NVMe servers*"]]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 15],
                'model' => 'gemini-3.6-flash-high',
            ], 200),
        ]);

        $reply = app(ChatbotRunner::class)->run($bot, $message);

        $this->assertStringNotContainsString('**', (string) $reply);
        $this->assertStringContainsString('Rs 1,999/year', (string) $reply);
    }

    /**
     * @return array{0: ChatbotRunner, 1: \App\Modules\Shared\Models\Message}
     */
    private function makeRunnerAndMessage(AiChatbot $bot): array
    {
        $contact = Contact::factory()->create(['workspace_id' => $bot->workspace_id]);
        $conv = Conversation::create([
            'workspace_id' => $bot->workspace_id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = new \App\Modules\Shared\Models\Message;
        $message->body = 'what product you offer';
        $message->direction = 'in';
        $message->channel = 'playground';
        $message->setRelation('conversation', $conv);

        return [app(ChatbotRunner::class), $message];
    }

    private function makeBot(): AiChatbot
    {
        $data = $this->createWorkspaceContext();

        \App\Modules\AI\Models\AiProviderConfig::create([
            'workspace_id' => $data['workspace']->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        return AiChatbot::create([
            'workspace_id' => $data['workspace']->id,
            'name' => 'Guard Bot',
            'system_prompt' => 'You are the HostingGram support assistant. Never greet without answering.',
            'enabled' => true,
            'channels' => ['whatsapp'],
            'temperature' => 0.3,
        ]);
    }
}
