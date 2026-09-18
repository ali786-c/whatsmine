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
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // gate button template
                ->push(['message_id' => 'msg-3'], 200),  // lead delivery
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
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_CTA, $participant->stage);

        // The user types the keyword right away (no CTA tap) → the gate
        // template goes first, then the typed keyword verifies + delivers.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-1', 'text' => 'I followed! DONE'],
        ]));
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->fresh()->stage);

        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-2', 'text' => 'DONE'],
        ]));

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
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // first "no" → gate template
                ->push(['message_id' => 'msg-3'], 200)   // NO → re-ask 1
                ->push(['message_id' => 'msg-4'], 200)   // NO → re-ask 2
                ->push(['message_id' => 'msg-5'], 200)   // NO → re-ask 3
                ->push(['message_id' => 'msg-6'], 200)   // NO → re-ask 4
                ->push(['message_id' => 'msg-7'], 200),  // NO → final handoff message
            'graph.facebook.com/*/igsid-customer*' => Http::response(['is_user_follow_business' => true], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        $noEvent = fn (string $mid) => [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => $mid, 'text' => 'no'],
        ];

        // First typed reply only opens the gate (step 2).
        $this->assertTrue($funnel->handleDmReply('17841400000001', $noEvent('dm-0')));
        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);
        $this->assertSame(0, $participant->nudge_count);

        // Four gate re-asks within the gate budget.
        foreach ([1, 2, 3, 4] as $nudge) {
            $this->assertTrue($funnel->handleDmReply('17841400000001', $noEvent('dm-'.$nudge)));
            $this->assertSame($nudge, $participant->fresh()->nudge_count);
            $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->fresh()->stage);
        }

        // Fifth NO after the gate: budget exhausted → ONE final handoff message
        // (never silence), then closed for agent takeover.
        $this->assertTrue($funnel->handleDmReply('17841400000001', $noEvent('dm-5')));
        $participant = $participant->fresh();
        $this->assertSame(FunnelParticipant::STAGE_CLOSED, $participant->stage);
        $this->assertStringContainsString('our team', (string) (Http::recorded()[6][0]['message']['text'] ?? ''));
        Http::assertSentCount(7); // reply + gate + 4 re-asks + final handoff message
    }

    public function test_gate_yes_reply_reminds_keyword_then_keyword_delivers(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // first YES → gate template
                ->push(['message_id' => 'msg-3'], 200)   // YES → keyword reminder
                ->push(['message_id' => 'msg-4'], 200),  // DONE → delivery
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // First YES (still awaiting CTA) → opens the gate (button template).
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-yes', 'text' => 'yes'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);

        // Second YES (now at the gate) → keyword reminder.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-yes-2', 'text' => 'yes'],
        ]));
        $this->assertSame(1, $participant->fresh()->nudge_count);

        // The reminder must contain the exact keyword.
        $reminder = (string) (Http::recorded()[2][0]['message']['text'] ?? '');
        $this->assertStringContainsString('DONE', $reminder);

        // Now the real keyword (case-insensitive "done") → delivery.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-done', 'text' => 'done'],
        ]));
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->fresh()->stage);
        Http::assertSentCount(4);
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
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // first typed DONE → gate template
                ->push(['message_id' => 'msg-3'], 200),  // re-ask — NOT the delivery
            'graph.facebook.com/*/igsid-customer*' => Http::response(['is_user_follow_business' => false], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // First reply opens the gate; second DONE hits the liar check.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-0', 'text' => 'hi'],
        ]));

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
        Http::assertSentCount(3); // private reply + gate + re-ask only — no delivery text
    }

    public function test_unverified_follow_check_fails_open_and_delivers(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // first typed DONE → gate template
                ->push(['message_id' => 'msg-3'], 200),  // delivery (fail-open)
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
            'message' => ['mid' => 'dm-0', 'text' => 'hi'],
        ]));

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

    public function test_gated_private_reply_carries_i_followed_buttons_from_the_start(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::response(['recipient_id' => 'igsid-customer', 'message_id' => 'msg-1'], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        app(CommentFunnelService::class)->handleComment('17841400000001', $this->commentValue());

        // The FIRST message already carries the quick-reply buttons.
        $first = Http::recorded()[0][0]['message'];
        $this->assertSame([
            ['content_type' => 'text', 'title' => 'Send me the link', 'payload' => '__CTA_TAP__'],
        ], $first['quick_replies']);

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_CTA, $participant->stage);
    }

    public function test_gated_private_reply_falls_back_to_plain_text_when_buttons_rejected(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['error' => ['message' => 'quick_replies not allowed on private replies', 'code' => 100]], 400)
                ->push(['recipient_id' => 'igsid-customer', 'message_id' => 'msg-1'], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        app(CommentFunnelService::class)->handleComment('17841400000001', $this->commentValue());

        // Attempt 1 had buttons and was rejected; attempt 2 is plain text.
        $this->assertCount(2, Http::recorded());
        $this->assertArrayHasKey('quick_replies', Http::recorded()[0][0]['message']);
        $this->assertArrayNotHasKey('quick_replies', Http::recorded()[1][0]['message']);

        // The funnel still advanced — the fallback message went out.
        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);
        $this->assertSame('msg-1', $participant->private_reply_message_id);
    }

    public function test_no_reply_gets_follow_re_ask_not_generic_nudge(): void
    {
        // Profile check inconclusive (fail-open) + user explicitly says "no" —
        // the reply must repeat the FOLLOW ask, never the generic "reply DONE" nudge.
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // "no" typed first → gate template
                ->push(['message_id' => 'msg-3'], 200),  // re-ask — still the follow ask
            'graph.facebook.com/*/igsid-customer*' => Http::response(['error' => ['message' => 'User consent is required to access user profile.', 'code' => 10]], 403),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // First typed reply (from awaiting_cta) only opens the gate.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-0', 'text' => 'no'],
        ]));
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail()->stage);

        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-no', 'text' => 'no'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);
        $this->assertNull($participant->delivered_at);
        $this->assertSame(1, $participant->nudge_count);

        $reAsk = (string) (Http::recorded()[2][0]['message']['text'] ?? '');
        $this->assertStringContainsString('follow our account', $reAsk);
        Http::assertSentCount(3);
    }

    public function test_cta_tap_sends_gate_template_with_visit_profile_button(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200),  // gate button template
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'cta_message' => 'Thanks for your interest! Click below.',
            'cta_button_label' => 'Send me the link',
            'gate_message' => 'Follow us on Instagram to unlock this!',
            'visit_profile_label' => 'Follow on Instagram',
            'confirm_follow_label' => "I'm following ✅",
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // User taps the CTA button — quick_reply payload is the reserved marker.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-cta', 'quick_reply' => ['payload' => '__CTA_TAP__'], 'text' => 'Send me the link'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_AWAITING_FOLLOW, $participant->stage);
        $this->assertNotNull($participant->dm_thread_opened_at);

        // The second send must be the BUTTON TEMPLATE with editable labels
        // and a web_url button pointing at the creator's profile.
        $template = Http::recorded()[1][0];
        $this->assertSame('template', $template['message']['attachment']['type']);
        $this->assertSame('button', $template['message']['attachment']['payload']['template_type']);
        $this->assertSame('Follow us on Instagram to unlock this!', $template['message']['attachment']['payload']['text']);
        $buttons = $template['message']['attachment']['payload']['buttons'];
        $this->assertSame('web_url', $buttons[0]['type']);
        $this->assertSame('Follow on Instagram', $buttons[0]['title']);
        $this->assertSame('https://instagram.com/myshop', $buttons[0]['url']);
        $this->assertSame('postback', $buttons[1]['type']);
        $this->assertSame("I'm following ✅", $buttons[1]['title']);
        $this->assertSame('DONE', $buttons[1]['payload']);
    }

    public function test_gate_confirm_postback_verifies_and_delivers(): void
    {
        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // gate button template
                ->push(['message_id' => 'msg-3'], 200),  // delivery
            'graph.facebook.com/*/igsid-customer*' => Http::response(['is_user_follow_business' => true], 200),
        ]);

        $this->automation([
            'follow_gate' => true,
            'reply_keyword' => 'DONE',
            'delivery' => ['type' => 'text', 'text' => 'Here is your file!'],
        ]);

        $funnel = app(CommentFunnelService::class);
        $funnel->handleComment('17841400000001', $this->commentValue());

        // CTA tap → gate template.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-cta', 'quick_reply' => ['payload' => '__CTA_TAP__'], 'text' => 'Send me the link'],
        ]));

        // "I'm following ✅" postback tap → verification → delivery.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'postback' => ['payload' => 'DONE', 'title' => "I'm following ✅"],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->stage);
        Http::assertSentCount(3);
    }

    public function test_follow_check_can_be_disabled_via_config(): void
    {
        config(['instagram.follow_check' => false]);

        Http::fake([
            'graph.facebook.com/*/17841400000001/messages' => Http::sequence()
                ->push(['message_id' => 'msg-1'], 200)   // private reply (hook + CTA)
                ->push(['message_id' => 'msg-2'], 200)   // first typed DONE → gate template
                ->push(['message_id' => 'msg-3'], 200),  // delivery (trust mode)
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
            'message' => ['mid' => 'dm-0', 'text' => 'hi'],
        ]));

        // Trust mode: the user's DONE delivers without any profile lookup.
        $this->assertTrue($funnel->handleDmReply('17841400000001', [
            'sender' => ['id' => 'igsid-customer'],
            'recipient' => ['id' => '17841400000001'],
            'message' => ['mid' => 'dm-done', 'text' => 'DONE'],
        ]));

        $participant = FunnelParticipant::where('comment_id', 'comment-123')->firstOrFail();
        $this->assertSame(FunnelParticipant::STAGE_DELIVERED, $participant->stage);
        Http::assertSentCount(3); // no profile GET happened at all
    }
}
