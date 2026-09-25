<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Modules\AI\Jobs\GenerateAiReplyJob;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quote-replies on outbound WhatsApp messages.
 *
 * When the AI chatbot (or any sender) replies to an inbound WhatsApp message,
 * the driver must pass the inbound message's provider ID as the Cloud API
 * `context.message_id` — that is what renders a native quote on the
 * customer's phone, making the bot feel human. If the upstream rejects the
 * context (expired window, unknown id), the send must retry WITHOUT the
 * quote so the reply is never lost.
 */
class WhatsappQuotedReplyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function text_send_with_quoted_message_id_includes_graph_context(): void
    {
        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Stock aa gaya hai! Abhi order kar lein.',
            'payload' => ['quoted_message_id' => 'wamid.INBOUND123'],
            'status' => 'queued',
            'sent_by' => 'bot',
            'sent_at' => now(),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT456']]], 200)]);

        $id = app(WhatsappDriver::class)->send($message->fresh()->load('conversation.channelAccount'));

        $this->assertSame('wamid.OUT456', $id);
        Http::assertSent(function ($req) {
            $data = $req->data();

            return ($data['context']['message_id'] ?? null) === 'wamid.INBOUND123'
                && ($data['text']['body'] ?? null) === 'Stock aa gaya hai! Abhi order kar lein.';
        });
    }

    #[Test]
    public function text_send_without_quote_has_no_context_block(): void
    {
        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Plain message',
            'payload' => [],
            'status' => 'queued',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT789']]], 200)]);

        app(WhatsappDriver::class)->send($message->fresh()->load('conversation.channelAccount'));

        Http::assertSent(fn ($req) => ! isset($req->data()['context']));
    }

    #[Test]
    public function rejected_quote_context_retries_without_the_quote(): void
    {
        $conversation = $this->makeConversation();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Reply after window closed',
            'payload' => ['quoted_message_id' => 'wamid.EXPIRED'],
            'status' => 'queued',
            'sent_by' => 'bot',
            'sent_at' => now(),
        ]);

        // First call (with context) → Meta error; second call (no context) → success.
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)
                ->push(['messages' => [['id' => 'wamid.OUT999']], 200]),
        ]);

        $id = app(WhatsappDriver::class)->send($message->fresh()->load('conversation.channelAccount'));

        $this->assertSame('wamid.OUT999', $id);
        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => ! isset($req->data()['context']) && ($req->data()['text']['body'] ?? null) === 'Reply after window closed');
    }

    #[Test]
    public function ai_bot_reply_references_the_inbound_message(): void
    {
        $conversation = $this->makeConversation();

        // Inbound customer message carrying its WhatsApp provider ID.
        $inbound = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'Ya kab tak available ho ga',
            'payload' => [],
            'status' => 'delivered',
            'provider_message_id' => 'wamid.CUSTOMER1',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $workspaceId = $conversation->workspace_id;
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
        $bot = AiChatbot::create([
            'workspace_id' => $workspaceId,
            'name' => 'Quote Bot',
            'enabled' => true,
            'channels' => ['whatsapp'],
        ]);

        Http::fake([
            'api.openai.com/v1/*' => Http::response([
                'choices' => [['message' => ['content' => 'Filhal exact date confirm nahi, main update deta hoon.']]],
                'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 12],
                'model' => 'gpt-4o-mini',
            ], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.BOT1']], 200]),
        ]);

        (new GenerateAiReplyJob($bot->id, $inbound->id))
            ->handle(app(\App\Modules\AI\Services\ChatbotRunner::class), app(ChannelManager::class));

        // The bot's outbound message must carry the inbound WhatsApp ID as the
        // quote reference (and the driver must have sent it as context).
        $botMessage = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'out')->where('sent_by', 'bot')->latest('id')->first();
        $this->assertNotNull($botMessage);
        $this->assertSame('wamid.CUSTOMER1', $botMessage->payload['quoted_message_id'] ?? null);
        Http::assertSent(fn ($req) => ($req->data()['context']['message_id'] ?? null) === 'wamid.CUSTOMER1');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeConversation(): Conversation
    {
        $waba = WhatsappBusinessAccount::create([
            'workspace_id' => 1,
            'waba_id' => 'WABA-TEST-1',
            'credentials' => ['system_user_token' => 'TEST-TOKEN'],
            'status' => 'active',
        ]);
        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'PN123',
            'display_phone' => '+447700900000',
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => 1,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'WA Test',
            'phone_number_id' => 'PN123',
            'credentials' => ['access_token' => 'TEST-TOKEN'],
        ]);
        $contact = Contact::create(['workspace_id' => 1, 'phone_e164' => '+447700900111']);

        return Conversation::create([
            'workspace_id' => 1,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => '+447700900111',
        ]);
    }
}
