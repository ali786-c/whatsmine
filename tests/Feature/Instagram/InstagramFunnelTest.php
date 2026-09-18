<?php

namespace Tests\Feature\Instagram;

use App\Modules\Instagram\Jobs\ProcessInstagramCommentJob;
use App\Modules\Instagram\Jobs\ProcessInstagramDmJob;
use App\Modules\Instagram\Models\CommentAutomation;
use App\Modules\Instagram\Models\CommentAutomationLog;
use App\Modules\Instagram\Models\FunnelParticipant;
use App\Modules\Instagram\Models\InstagramAccount;
use App\Modules\Instagram\Services\CommentFunnelService;
use App\Modules\Instagram\Services\KeywordMatcher;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstagramFunnelTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private InstagramAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->createWorkspaceContext();

        $this->account = InstagramAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'ig_user_id' => '17841400000001',
            'username' => 'myshop',
            'display_name' => 'My Shop',
            'page_id' => '10000000001',
            'page_token' => 'page-token-secret',
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

    private function commentValue(array $overrides = []): array
    {
        return array_merge([
            'comment_id' => 'comment-123',
            'from' => ['id' => 'igsid-customer', 'username' => 'curious_buyer'],
            'text' => 'price please',
            'media' => ['id' => 'media-1', 'media_product_type' => 'REEL'],
        ], $overrides);
    }

    private function automation(array $overrides = []): CommentAutomation
    {
        return CommentAutomation::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'instagram_account_id' => $this->account->id,
            'name' => 'Reel drop',
            'trigger_type' => 'keyword',
            'keywords' => ['price', 'cost'],
            'match_mode' => 'contains',
            'reply_message' => 'Thanks {username}! 🎉',
            'follow_gate' => false,
            'delivery' => ['type' => 'link', 'text' => 'Here it is:', 'url' => 'https://example.com/offer'],
            'is_active' => true,
            'priority' => 100,
        ], $overrides));
    }

    public function test_keyword_matcher_modes(): void
    {
        $this->assertTrue(KeywordMatcher::matches(['price', 'cost'], 'contains', 'What is the PRICE?'));
        $this->assertTrue(KeywordMatcher::matches(['price'], 'exact', 'Price'));
        $this->assertFalse(KeywordMatcher::matches(['price'], 'exact', 'the price please'));
        $this->assertTrue(KeywordMatcher::matches(['pri'], 'starts_with', 'Price check'));
        $this->assertTrue(KeywordMatcher::matches(['^pric'], 'regex', 'price check'));
        $this->assertFalse(KeywordMatcher::matches(['nope'], 'contains', 'hello'));
        $this->assertFalse(KeywordMatcher::matches([], 'contains', 'price'));
    }

    public function test_webhook_verify_accepts_challenge_and_rejects_bad_token(): void
    {
        $this->seedMetaCredentials();

        $this->get(route('webhooks.instagram.verify', ['token' => 'test-verify-token']).'?hub_mode=subscribe&hub_challenge=abc123')
            ->assertOk()
            ->assertSeeText('abc123');

        $this->get(route('webhooks.instagram.verify', ['token' => 'wrong-token']).'?hub_mode=subscribe&hub_challenge=abc123')
            ->assertForbidden();
    }

    public function test_webhook_dispatches_comment_and_dm_jobs_on_module_queue(): void
    {
        Queue::fake();
        $this->seedMetaCredentials();

        $payload = [
            'object' => 'instagram',
            'entry' => [[
                'id' => '17841400000001',
                'changes' => [['field' => 'comments', 'value' => $this->commentValue()]],
                'messaging' => [['sender' => ['id' => 'igsid-x'], 'recipient' => ['id' => '17841400000001'], 'message' => ['mid' => 'm-1', 'text' => 'hi']]],
            ]],
        ];

        $signature = 'sha256='.hash_hmac('sha256', (string) json_encode($payload), 'test-app-secret');

        $this->postJson(route('webhooks.instagram.receive', ['token' => 'test-verify-token']), $payload, ['X-Hub-Signature-256' => $signature])
            ->assertOk();

        Queue::assertPushedOn('instagram', ProcessInstagramCommentJob::class);
        Queue::assertPushedOn('instagram', ProcessInstagramDmJob::class);
    }

    public function test_comment_without_gate_sends_one_dm_with_embedded_delivery(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::response(['recipient_id' => 'igsid-customer', 'message_id' => 'msg-1'], 200),
        ]);

        $this->automation(['follow_gate' => false]);

        app(CommentFunnelService::class)->handleComment('17841400000001', $this->commentValue());

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->stage);
        $this->assertSame('msg-1', $participant->private_reply_message_id);
        $this->assertNotNull($participant->delivered_at);

        // Exactly ONE Graph send happened (everything embedded in the single private reply).
        Http::assertSentCount(1);

        $this->assertDatabaseHas(CommentAutomationLog::class, ['comment_id' => 'comment-123', 'action' => 'dm_sent']);
    }

    public function test_follow_gate_flow_reply_keyword_delivers_lead(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply
                ->push(['message_id' => 'msg-2'], 200),  // lead delivery after reply
            'graph.facebook.com/*/igsid-customer*' => Http::response(['is_user_follow_business' => true], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);

        // The ONE private reply must already contain the follow ask + keyword.
        $replyText = (string) (Http::recorded()[0][0]['message']['text'] ?? '');
        $this->assertStringContainsString('DONE', $replyText);

        // User replies with the keyword → 24h window opens → delivery.
        $handled = $funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-1', 'text' => 'I followed! DONE'],
        ]);

        $this->assertTrue($handled);
        $participant = $participant->fresh();
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->stage);
        $this->assertNotNull($participant->dm_thread_opened_at);
        $this->assertNotNull($participant->delivered_at);
    }

    public function test_duplicate_comment_never_sends_two_private_replies(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::response(['message_id' => 'msg-1'], 200),
        ]);

        $this->automation();

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());
        $funnel->handleComment('17841400000001', $this->commentValue()); // re-delivered webhook

        Http::assertSentCount(1); // ONE private reply per comment — enforced
        $this->assertSame(1, FunnelParticipant::where('comment_id', 'comment-123')->count());
    }

    public function test_non_matching_comment_logs_no_match(): void
    {
        $this->automation();

        app(CommentFunnelService::class)->handleComment('17841400000001', $this->commentValue(['text' => 'nice post']));

        $this->assertSame(0, FunnelParticipant::count());
        $this->assertDatabaseHas(CommentAutomationLog::class, ['comment_id' => 'comment-123', 'action' => 'no_match']);
    }

    public function test_post_scoped_automation_ignores_comments_on_other_posts(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'msg-1'], 200)]);

        $this->automation(['media_ids' => ['media-1']]);

        // Comment on a DIFFERENT post — must not fire.
        app(CommentFunnelService::class)->handleComment('17841400000001', $this->commentValue(['media' => ['id' => 'media-999', 'media_product_type' => 'REEL']]));

        $this->assertSame(0, FunnelParticipant::count());
        $this->assertDatabaseHas(CommentAutomationLog::class, ['comment_id' => 'comment-123', 'action' => 'no_match']);
    }

    public function test_post_scoped_automation_beats_account_wide_rule(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'msg-1'], 200)]);

        // Account-wide rule with higher priority (lower number wins in the old order).
        $this->automation(['reply_message' => 'GENERAL', 'priority' => 1]);
        // Post-specific rule with lower priority — specificity must override.
        $this->automation(['reply_message' => 'PERPOST', 'priority' => 100, 'media_ids' => ['media-1']]);

        app(CommentFunnelService::class)->handleComment('17841400000001', $this->commentValue()); // comment on media-1

        $sentText = (string) (Http::recorded()[0][0]['message']['text'] ?? '');
        $this->assertStringContainsString('PERPOST', $sentText);
        $this->assertStringNotContainsString('GENERAL', $sentText);
    }

    public function test_comment_without_media_id_matches_account_wide_but_not_post_scoped(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'msg-1'], 200)]);

        $this->automation(['reply_message' => 'PERPOST', 'media_ids' => ['media-1']]);

        // Comment webhook value without a media id at all.
        app(CommentFunnelService::class)->handleComment('17841400000001', [
            'comment_id' => 'comment-123',
            'from' => ['id' => 'igsid-customer', 'username' => 'curious_buyer'],
            'text' => 'price please',
        ]);

        $this->assertSame(0, FunnelParticipant::count());
    }

    public function test_dm_reply_forwarded_to_inbox_when_not_funnel(): void
    {
        // Not a funnel participant → the funnel declines, the job forwards to Inbox.
        // InstagramDriver::processWebhookPayload will not match a channel account, so
        // it logs and returns — the important part is that no exception escapes.
        $job = new ProcessInstagramDmJob('99999999999', [
            'sender' => ['id' => 'igsid-stranger'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-x', 'text' => 'hello there'],
        ]);

        $job->handle(app(CommentFunnelService::class));

        $this->assertTrue(true); // reached → forwarding path did not throw
    }

    public function test_timeout_job_expires_stale_participants(): void
    {
        $automation = $this->automation();

        $participant = FunnelParticipant::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'instagram_account_id' => $this->account->id,
            'automation_id' => $automation->id,
            'commenter_igsid' => 'igsid-customer',
            'username' => 'curious_buyer',
            'comment_id' => 'comment-old',
            'stage' => FunnelParticipant::STAGE_COMMENTED,
            'expires_at' => now()->subDays(8),
        ]);

        (new \App\Modules\Instagram\Jobs\CheckFunnelTimeoutsJob)->handle(app(\App\Modules\Instagram\Services\FunnelTimeoutService::class));

        $this->assertSame(FunnelParticipant::STAGE_EXPIRED, $participant->fresh()->stage);
        $this->assertDatabaseHas(CommentAutomationLog::class, ['comment_id' => 'comment-old', 'action' => 'expired']);
    }

    public function test_module_is_isolated_and_configurable(): void
    {
        // Master switch makes the module dormant.
        config(['instagram.enabled' => false]);
        $this->assertFalse(config('instagram.enabled'));

        // Its own tables exist and its own config namespace is loaded.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('instagram_accounts'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('comment_automations'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('funnel_participants'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('comment_automation_logs'));
        $this->assertSame('instagram', config('instagram.queue'));
    }

    public function test_gate_no_reply_repeats_ask_then_closes_after_budget(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (follow ask)
                ->push(['message_id' => 'msg-2'], 200)   // NO → re-ask (follow-up 1)
                ->push(['message_id' => 'msg-3'], 200),  // NO → re-ask (follow-up 2)
            'graph.facebook.com/*/igsid-customer*' => Http::response(['is_user_follow_business' => true], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);

        $noEvent = fn (string $mid) => [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => $mid, 'text' => 'no'],
        ];

        $this->assertTrue($funnel->handleDmReply('17841400000001', $noEvent('dm-1')));
        $this->assertTrue($funnel->handleDmReply('17841400000001', $noEvent('dm-2')));
        $this->assertSame(2, $participant->fresh()->nudge_count);
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->fresh()->stage);

        // Third NO: budget exhausted → closed for agent handoff, no further sends.
        $this->assertTrue($funnel->handleDmReply('17841400000001', $noEvent('dm-3')));
        $this->assertSame(FunnelParticipant::STAGE_CLOSED, $participant->fresh()->stage);
        Http::assertSentCount(3); // private reply + 2 re-asks — the third NO sent nothing
    }

    public function test_gate_yes_reply_reminds_keyword_then_keyword_delivers(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (follow ask)
                ->push(['message_id' => 'msg-2'], 200)   // YES → keyword reminder
                ->push(['message_id' => 'msg-3'], 200),  // DONE → delivery
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-yes', 'text' => 'yes'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);
        $this->assertSame(1, $participant->nudge_count);

        // The reminder must contain the exact keyword.
        $reminder = (string) (Http::recorded()[1][0]['message']['text'] ?? '');
        $this->assertStringContainsString('DONE', $reminder);

        // Now the real keyword (case-insensitive "done") → delivery.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-done', 'text' => 'done'],
        ]));
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->fresh()->stage);
        Http::assertSentCount(3);
    }

    public function test_ungated_automation_does_not_intercept_yes_no_replies(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'msg-1'], 200)]);

        $this->automation(['follow_gate' => false]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail()->stage);

        // Delivered funnels no longer intercept replies — "no" flows to the Inbox pipeline.
        $handled = $funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-no', 'text' => 'no'],
        ]);

        $this->assertFalse($handled);
    }

    public function test_verified_follow_liar_never_receives_the_delivery(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (follow ask)
                ->push(['message_id' => 'msg-2'], 200),  // re-ask — NOT the delivery
            'graph.facebook.com/*/igsid-customer*' => Http::response(['is_user_follow_business' => false], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // They claim they followed with the exact keyword — but the API says otherwise.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-lie', 'text' => 'DONE'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);
        $this->assertNull($participant->delivered_at);
        $this->assertSame(1, $participant->nudge_count);
        Http::assertSentCount(2); // private reply + re-ask only — no delivery text
    }

    public function test_unverified_follow_check_fails_open_and_delivers(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply
                ->push(['message_id' => 'msg-2'], 200),  // delivery
            'graph.facebook.com/*/igsid-customer*' => Http::response(['error' => ['message' => 'User consent is required to access user profile.', 'code' => 10]], 403),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // Unknown follow status must NOT block a real lead (fail-open).
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-done', 'text' => 'DONE'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->stage);
        $this->assertNotNull($participant->delivered_at);
    }

    public function test_no_reply_gets_follow_re_ask_not_generic_nudge(): void
    {
        // Profile check inconclusive (fail-open) + user explicitly says "no" —
        // the reply must repeat the FOLLOW ask, never the generic "reply DONE" nudge.
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (follow ask)
                ->push(['message_id' => 'msg-2'], 200),  // re-ask — still the follow ask
            'graph.facebook.com/*/igsid-customer*' => Http::response(['error' => ['message' => 'User consent is required to access user profile.', 'code' => 10]], 403),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-no', 'text' => 'no'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);
        $this->assertNull($participant->delivered_at);
        $this->assertSame(1, $participant->nudge_count);

        $reAsk = (string) (Http::recorded()[1][0]['message']['text'] ?? '');
        $this->assertStringContainsString('follow our account', $reAsk);
        Http::assertSentCount(2);
    }

    public function test_follow_check_can_be_disabled_via_config(): void
    {
        config(['instagram.follow_check' => false]);

        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply
                ->push(['message_id' => 'msg-2'], 200),  // delivery
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // Trust mode: the user's DONE delivers without any profile lookup.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-done', 'text' => 'DONE'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->stage);
        Http::assertSentCount(2); // no profile GET happened at all
    }
}
