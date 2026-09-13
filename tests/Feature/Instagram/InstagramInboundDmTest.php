<?php

namespace Tests\Feature\Instagram;

use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Instagram\Jobs\ProcessInstagramDmJob;
use App\Modules\Instagram\Models\CommentAutomation;
use App\Modules\Instagram\Models\FunnelParticipant;
use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Services\CommentFunnelService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end tests for the inbound Instagram DM pipeline:
 *
 *   Meta `messaging` webhook event → ProcessInstagramDmJob → (funnel gate)
 *   → InstagramDriver::processWebhookPayload → contact + conversation + message.
 *
 * These are the tests that would have caught every stage of the
 * "connected Instagram but DMs never show in the Inbox" failure.
 */
class InstagramInboundDmTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private InstagramAccount $igAccount;

    private ChannelAccount $channelAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->createWorkspaceContext();

        $this->igAccount = InstagramAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'ig_user_id' => '17841400000001',
            'username' => 'myshop',
            'display_name' => 'My Shop',
            'page_id' => '10000000001',
            'page_token' => 'page-token-secret',
            'status' => 'active',
        ]);

        // The Inbox module's channel account the driver matches inbound DMs on.
        $this->channelAccount = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'instagram',
            'provider' => 'meta',
            'display_name' => 'myshop',
            'credentials' => ['access_token' => 'page-token-secret', 'instagram_account_id' => '17841400000001'],
            'meta_json' => [
                'instagram_page_id' => '17841400000001',
                'instagram_account_id' => '17841400000001',
                'facebook_page_id' => '10000000001',
            ],
            'status' => 'active',
        ]);
    }

    private function seedMetaCredentials(): void
    {
        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'is_default' => true,
            'credentials' => [
                'app_id' => '1234567890',
                'app_secret' => 'test-app-secret',
                'verify_token' => 'test-verify-token',
            ],
        ]);
    }

    /**
     * The exact event shape Meta sends for an inbound IG DM (entry.id = the
     * business IG account, sender.id = the customer's IGSID).
     */
    private function dmEvent(string $igId = '17841400000001', string $sender = 'igsid-customer', string $mid = 'dm-1', string $text = 'hello, is this available?'): array
    {
        return [
            'sender' => ['id' => $sender],
            'recipient' => ['id' => $igId],
            'timestamp' => 1726000000000,
            'message' => ['mid' => $mid, 'text' => $text],
        ];
    }

    private function runDmJob(array $event, string $igId = '17841400000001'): void
    {
        (new ProcessInstagramDmJob($igId, $event))->handle(app(CommentFunnelService::class));
    }

    public function test_complete_inbound_dm_lands_in_inbox_conversation(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['name' => 'Ali Tester', 'username' => 'alitester', 'profile_pic' => ''], 200),
        ]);

        $this->runDmJob($this->dmEvent());

        $conversation = Conversation::where('channel_account_id', $this->channelAccount->id)->firstOrFail();
        $this->assertSame('igsid-customer', $conversation->external_thread_id);
        $this->assertSame(1, $conversation->unread_count);

        $message = Message::where('conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('in', $message->direction);
        $this->assertSame('instagram', $message->channel);
        $this->assertSame('hello, is this available?', $message->body);
        $this->assertSame('dm-1', $message->provider_message_id);

        // Contact keyed on the IGSID so repeat DMs map to ONE contact.
        $contact = $conversation->contact;
        $this->assertSame('igsid-customer', $contact->custom_fields['instagram_psid'] ?? null);
    }

    public function test_duplicate_dm_is_not_stored_twice(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['username' => 'alitester'], 200)]);

        $this->runDmJob($this->dmEvent());
        $this->runDmJob($this->dmEvent()); // Meta re-delivery

        $this->assertSame(1, Message::where('direction', 'in')->count());
    }

    public function test_dm_for_unconnected_account_is_dropped_without_error(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        // No channel_account exists for this IG id — the driver must log and skip.
        $this->runDmJob($this->dmEvent(igId: '99999999999', mid: 'dm-orphan'));

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_funnel_participant_dm_is_not_duplicated_in_inbox(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'msg-nudge'], 200)]);

        // A follow-gate funnel put this participant into awaiting_follow.
        $automation = CommentAutomation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'instagram_account_id' => $this->igAccount->id,
            'name' => 'Reel drop',
            'trigger_type' => 'keyword',
            'keywords' => ['price'],
            'match_mode' => 'contains',
            'reply_message' => 'Thanks!',
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
            'is_active' => true,
            'priority' => 100,
        ]);

        FunnelParticipant::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'instagram_account_id' => $this->igAccount->id,
            'automation_id' => $automation->id,
            'commenter_igsid' => 'igsid-customer',
            'username' => 'alitester',
            'comment_id' => 'comment-123',
            'stage' => FunnelParticipant::STAGE_AWAITING_FOLLOW,
            'expires_at' => now()->addDays(7),
        ]);

        // The reply matches the keyword → funnel handles + mirrors it to the Inbox.
        $this->runDmJob($this->dmEvent(text: 'I followed! DONE'));

        // Exactly ONE inbound message — mirrored by the funnel, NOT duplicated
        // by a second pass through the Inbox driver.
        $this->assertSame(1, Message::where('direction', 'in')->count());
        $this->assertSame(
            1,
            Message::where('body', 'I followed! DONE')->where('direction', 'in')->count(),
        );
    }

    public function test_dm_to_second_account_is_not_hijacked_by_first_accounts_funnel(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['username' => 'alitester'], 200)]);

        // Account A has an awaiting_follow funnel participant for this person.
        $automation = CommentAutomation::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'instagram_account_id' => $this->igAccount->id,
            'name' => 'Account A funnel',
            'trigger_type' => 'keyword',
            'keywords' => ['price'],
            'match_mode' => 'contains',
            'reply_message' => 'Thanks!',
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
            'is_active' => true,
            'priority' => 100,
        ]);

        FunnelParticipant::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'instagram_account_id' => $this->igAccount->id,
            'automation_id' => $automation->id,
            'commenter_igsid' => 'igsid-person',
            'username' => 'alitester',
            'comment_id' => 'comment-a',
            'stage' => FunnelParticipant::STAGE_AWAITING_FOLLOW,
            'expires_at' => now()->addDays(7),
        ]);

        // The same person now DMs account B (a second connected account).
        $igB = InstagramAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'ig_user_id' => '17841400000002',
            'username' => 'secondshop',
            'display_name' => 'Second Shop',
            'page_id' => '10000000002',
            'page_token' => 'page-token-b',
            'status' => 'active',
        ]);

        $channelB = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'instagram',
            'provider' => 'meta',
            'display_name' => 'secondshop',
            'credentials' => ['access_token' => 'page-token-b', 'instagram_account_id' => '17841400000002'],
            'meta_json' => [
                'instagram_page_id' => '17841400000002',
                'instagram_account_id' => '17841400000002',
                'facebook_page_id' => '10000000002',
            ],
            'status' => 'active',
        ]);

        // The DM must be FORWARDED to the Inbox pipeline (B has no funnel),
        // not swallowed by A's funnel participant.
        $this->runDmJob(
            $this->dmEvent(igId: '17841400000002', sender: 'igsid-person', mid: 'dm-b-1', text: 'hello from B'),
            igId: '17841400000002',
        );

        $this->assertSame(
            1,
            Message::where('body', 'hello from B')->where('direction', 'in')->count(),
        );

        // It lands on B's thread, not on A's funnel-mirrored one.
        $this->assertDatabaseHas(Message::class, [
            'conversation_id' => Conversation::where('channel_account_id', $channelB->id)->firstOrFail()->id,
            'body' => 'hello from B',
        ]);

        // And A's funnel participant was NOT consumed by the foreign DM.
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, FunnelParticipant::where('comment_id', 'comment-a')->firstOrFail()->stage);
    }

    public function test_get_started_postback_creates_inbox_message(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['username' => 'alitester'], 200)]);

        // Postbacks have no `message` object — previously skipped entirely.
        $event = [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'timestamp' => 1726000000000,
            'postback' => ['title' => 'Get Started', 'payload' => 'GET_STARTED'],
        ];

        $this->runDmJob($event);

        $message = Message::where('direction', 'in')->firstOrFail();
        $this->assertSame('Get Started', $message->body);
        // The stored payload keeps the original event shape (plus the normalized message).
        $this->assertSame('GET_STARTED', $message->payload['postback']['payload'] ?? null);
    }

    public function test_echo_is_recorded_as_outbound_not_inbound(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 200)]);

        $event = $this->dmEvent(mid: 'echo-1', text: 'we sent this from the IG app');
        $event['message']['is_echo'] = true;

        $this->runDmJob($event);

        $message = Message::where('provider_message_id', 'echo-1')->firstOrFail();
        $this->assertSame('out', $message->direction);
    }
}
