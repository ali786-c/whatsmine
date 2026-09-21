<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Jobs\GenerateAiReplyJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The AI reply must be dispatched as a queued job — never generated inline
 * inside the MessageReceived listener, which would delay the inbound
 * broadcast (and the whole webhook worker) by LLM latency.
 */
class AiReplyDispatchTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private Conversation $conversation;

    private ChannelAccount $account;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();

        $workspace = $this->ctx['workspace'];
        $this->contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
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
            'contact_id' => $this->contact->id,
            'status' => 'open',
            'assigned_to' => 'bot',
        ]);
    }

    private function inboundMessage(string $body): Message
    {
        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
        $message->setRelation('conversation', $this->conversation->load('channelAccount'));

        return $message;
    }

    public function test_ai_reply_is_dispatched_as_queued_job(): void
    {
        Bus::fake([GenerateAiReplyJob::class]);

        $workspace = $this->ctx['workspace'];
        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test Bot',
            'enabled' => true,
        ]);
        $this->account->update(['meta_json' => ['ai_chatbot_id' => $chatbot->id]]);

        $message = $this->inboundMessage('hello there');
        app(AutoReplyListener::class)->handle(new MessageReceived($message));

        Bus::assertDispatched(GenerateAiReplyJob::class);
    }

    public function test_no_job_dispatched_without_chatbot(): void
    {
        Bus::fake([GenerateAiReplyJob::class]);

        $message = $this->inboundMessage('hello there');
        app(AutoReplyListener::class)->handle(new MessageReceived($message));

        Bus::assertNotDispatched(GenerateAiReplyJob::class);
    }
}
