<?php

namespace Tests\Feature\Inbox;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Inbox\Services\HandoverService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
