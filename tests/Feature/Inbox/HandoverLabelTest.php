<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Jobs\GenerateAiReplyJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Inbox\Services\HandoverService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AI → human handover: the customer must get an immediate "connecting you"
 * reply (configurable per bot) and the conversation must carry the
 * "Waiting for you" label until a human agent actually replies.
 */
class HandoverLabelTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    /**
     * Conversation whose channel account links the GIVEN chatbot (tests the
     * service's own resolution path).
     */
    private function makeConversationWithBot(AiChatbot $chatbot): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel'      => 'whatsapp',
            'status'       => 'active',
            'display_name' => 'WA Bot',
            'phone_number_id' => 'PN-HANDOVER-2',
            'credentials'  => ['access_token' => 'TEST-TOKEN'],
            'meta_json'    => ['ai_chatbot_id' => $chatbot->id],
        ]);

        $contact = Contact::firstOrCreate(
            ['workspace_id' => $this->ctx['workspace']->id, 'phone_e164' => '+447700900222'],
        );

        return Conversation::create([
            'workspace_id'       => $this->ctx['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id'         => $contact->id,
            'status'             => 'open',
            'external_thread_id' => '+447700900222',
        ]);
    }

    /** Seed the WABA + phone rows the CloudApiClient looks up for a phone_number_id. */
    private function seedWabaForPhoneNumber(string $phoneNumberId): void
    {
        $waba = \App\Modules\Whatsapp\Models\WhatsappBusinessAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'waba_id'      => 'WABA-HANDOVER',
            'credentials'  => ['system_user_token' => 'TEST-TOKEN'],
            'status'       => 'active',
        ]);
        \App\Modules\Whatsapp\Models\WhatsappPhoneNumber::create([
            'waba_id_fk'      => $waba->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone'   => '+447700900000',
        ]);
    }

    private function makeConversation(array $credentials = []): Conversation
    {
        $account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel'      => 'whatsapp',
            'status'       => 'active',
            'display_name' => 'WA Test',
            'phone_number_id' => 'PN-HANDOVER-1',
            'credentials'  => $credentials ?: ['access_token' => 'TEST-TOKEN'],
            'meta_json'    => ['ai_chatbot_id' => $this->makeChatbot()->id],
        ]);

        $contact = Contact::firstOrCreate(
            ['workspace_id' => $this->ctx['workspace']->id, 'phone_e164' => '+447700900111'],
            ['workspace_id' => $this->ctx['workspace']->id, 'phone_e164' => '+447700900111'],
        );

        return Conversation::create([
            'workspace_id'       => $this->ctx['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id'         => $contact->id,
            'status'             => 'open',
            'external_thread_id' => '+447700900111',
        ]);
    }

    private function makeChatbot(array $attrs = []): AiChatbot
    {
        return AiChatbot::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'name'         => 'Test Bot',
            'enabled'      => true,
        ], $attrs));
    }

    #[Test]
    public function announce_sends_ack_and_attaches_waiting_label(): void
    {
        // Ensure the WABA + phone exist so the driver's CloudApiClient resolves
        // and the ack actually sends (status: sent, not failed).
        $this->seedWabaForPhoneNumber('PN-HANDOVER-1');

        $conversation = $this->makeConversation();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HANDOVER1']]], 200),
        ]);

        // Seed an inbound customer message so the language detector has input.
        Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'in',
            'channel'         => 'whatsapp',
            'type'            => 'text',
            'body'            => 'I want to talk to human please',
            'status'          => 'delivered',
            'sent_at'         => now(),
        ]);

        app(HandoverService::class)->announce($conversation);

        // Ack message stored as bot-sent.
        $ack = Message::where('payload->kind', 'handover_ack')->latest('id')->first();
        $this->assertNotNull($ack);
        $this->assertSame('bot', $ack->sent_by);
        $this->assertSame('sent', $ack->status);
        $this->assertStringContainsString('Connecting you with a human agent', $ack->body);

        // Waiting label attached, auto-created once for the workspace.
        $label = InboxLabel::where('workspace_id', $this->ctx['workspace']->id)
            ->where('name', 'Waiting for you')
            ->first();
        $this->assertNotNull($label);
        $this->assertTrue((bool) $label->auto_assigned);
        $this->assertTrue($conversation->labels->contains($label->id));
    }

    #[Test]
    public function bot_configured_handover_reply_wins_over_default(): void
    {
        // The conversation's channel account links THIS bot — the service
        // resolves the chatbot from the conversation itself.
        $chatbot = $this->makeChatbot(['handover_reply' => 'Ek minute, human agent aa raha hai!']);
        $conversation = $this->makeConversationWithBot($chatbot);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.H2']]], 200)]);

        app(HandoverService::class)->announce($conversation);

        $ack = Message::where('payload->kind', 'handover_ack')->latest('id')->first();
        $this->assertSame('Ek minute, human agent aa raha hai!', $ack->body);
    }

    #[Test]
    public function roman_urdu_customer_gets_roman_urdu_default(): void
    {
        $conversation = $this->makeConversation();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.H3']]], 200)]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'in',
            'channel'         => 'whatsapp',
            'type'            => 'text',
            'body'            => 'human se baat karao chahiye abhi',
            'status'          => 'delivered',
            'sent_at'         => now(),
        ]);

        app(HandoverService::class)->announce($conversation);

        $ack = Message::where('payload->kind', 'handover_ack')->latest('id')->first();
        $this->assertStringContainsString('human agent', $ack->body);
        $this->assertStringContainsString('aap', $ack->body);
    }

    #[Test]
    public function remove_waiting_label_detaches_only_the_waiting_label(): void
    {
        $conversation = $this->makeConversation();
        $service = app(HandoverService::class);

        $service->attachWaitingLabel($conversation);
        $other = InboxLabel::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'VIP', 'color' => '#000000']);
        $conversation->labels()->syncWithoutDetaching([$other->id]);

        $service->removeWaitingLabel($conversation);

        $conversation->refresh();
        $names = $conversation->labels->pluck('name')->all();
        $this->assertNotContains('Waiting for you', $names);
        $this->assertContains('VIP', $names);
    }

    #[Test]
    public function waiting_label_is_created_only_once_per_workspace(): void
    {
        $conversationA = $this->makeConversation();
        $conversationB = $this->makeConversation();

        $service = app(HandoverService::class);
        $service->attachWaitingLabel($conversationA);
        $service->attachWaitingLabel($conversationB);

        $this->assertSame(
            1,
            InboxLabel::where('workspace_id', $this->ctx['workspace']->id)->where('name', 'Waiting for you')->count(),
        );
        $this->assertTrue($conversationA->labels->contains('name', 'Waiting for you'));
        $this->assertTrue($conversationB->labels->contains('name', 'Waiting for you'));
    }

    #[Test]
    public function roman_urdu_human_request_triggers_handover_and_waiting_label(): void
    {
        Notification::fake();

        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'in',
            'channel'         => 'whatsapp',
            'type'            => 'text',
            'body'            => 'mujhe insan se baat karao abhi',
            'status'          => 'delivered',
            'sent_at'         => now(),
        ]);
        $message->setRelation('conversation', $conversation->load('channelAccount'));

        app(AutoReplyListener::class)->handle(new MessageReceived($message));

        $conversation->refresh();
        $this->assertSame('human', $conversation->assigned_to);
        $this->assertNotNull($conversation->handover_at);

        $label = InboxLabel::where('workspace_id', $this->ctx['workspace']->id)
            ->where('name', HandoverService::WAITING_LABEL)
            ->first();
        $this->assertNotNull($label);
        $this->assertTrue($conversation->labels->contains($label->id));
    }

    #[Test]
    public function ai_reply_with_handover_marker_fires_handover_and_strips_marker(): void
    {
        Notification::fake();
        $this->seedWabaForPhoneNumber('PN-HANDOVER-2');

        $chatbot = $this->makeChatbot();
        $conversation = $this->makeConversationWithBot($chatbot);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HM1']]], 200)]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'in',
            'channel'         => 'whatsapp',
            'type'            => 'text',
            'body'            => 'mujhe agent se baat karni hai',
            'status'          => 'delivered',
            'sent_at'         => now(),
        ]);

        $this->mock(ChatbotRunner::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn('Ji bilkul, main aap ko human agent se connect kar raha hoon 🙏 '.HandoverService::HANDOVER_MARKER);

        (new GenerateAiReplyJob($chatbot->id, $message->id))
            ->handle(app(ChatbotRunner::class), app(ChannelManager::class));

        $conversation->refresh();
        $this->assertSame('human', $conversation->assigned_to);
        $this->assertNotNull($conversation->handover_at);

        $label = InboxLabel::where('workspace_id', $this->ctx['workspace']->id)
            ->where('name', HandoverService::WAITING_LABEL)
            ->first();
        $this->assertNotNull($label);
        $this->assertTrue($conversation->labels->contains($label->id));

        // The customer sees the AI's reassurance — never the raw marker.
        $ack = Message::where('payload->kind', 'handover_ack')->latest('id')->first();
        $this->assertNotNull($ack);
        $this->assertStringContainsString('human agent se connect', $ack->body);
        $this->assertStringNotContainsString(HandoverService::HANDOVER_MARKER, $ack->body);
        $this->assertSame(
            0,
            Message::where('conversation_id', $conversation->id)
                ->where('body', 'like', '%'.HandoverService::HANDOVER_MARKER.'%')
                ->count(),
        );
    }

    #[Test]
    public function ai_reply_without_marker_replies_normally(): void
    {
        Notification::fake();
        $this->seedWabaForPhoneNumber('PN-HANDOVER-2');

        $chatbot = $this->makeChatbot();
        $conversation = $this->makeConversationWithBot($chatbot);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HM2']]], 200)]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'in',
            'channel'         => 'whatsapp',
            'type'            => 'text',
            'body'            => 'delivery kitne din mein hoti hai?',
            'status'          => 'delivered',
            'sent_at'         => now(),
        ]);

        $this->mock(ChatbotRunner::class)
            ->shouldReceive('run')
            ->once()
            ->andReturn('Delivery 3 se 5 din mein hoti hai.');

        (new GenerateAiReplyJob($chatbot->id, $message->id))
            ->handle(app(ChatbotRunner::class), app(ChannelManager::class));

        $conversation->refresh();
        $this->assertSame('bot', $conversation->assigned_to);
        $this->assertNull($conversation->handover_at);
        $this->assertNotNull(Message::where('conversation_id', $conversation->id)
            ->where('body', 'Delivery 3 se 5 din mein hoti hai.')
            ->where('sent_by', 'bot')
            ->first());
    }
}
