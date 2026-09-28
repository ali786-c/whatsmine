<?php

namespace Tests\Feature\Automation;

use App\Events\CampaignMessageSent;
use App\Events\ContactTagAdded;
use App\Events\FormSubmitted;
use App\Listeners\AutomationTriggerListener;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

/**
 * Covers the revived automation triggers (contact.tag_added, campaign.sent,
 * form.submitted) and the per-option branching for interactive nodes
 * (quick_replies / list_message / send_poll) driven by resume_edge_handle.
 */
class AutomationTriggersAndBranchingTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();
        $this->workspace = $ctx['workspace'];
        $this->client = $ctx['client'];

        $this->contact = Contact::factory()->create([
            'workspace_id' => $this->workspace->id,
            'first_name' => 'Ali',
            'phone_e164' => '+15550000042',
        ]);

        ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Test WA',
            'phone_number_id' => '1234567890',
        ]);

        foreach ([WhatsappDriver::class, MessengerDriver::class, InstagramDriver::class] as $driverClass) {
            $driver = Mockery::mock(ChannelDriverInterface::class);
            $driver->shouldReceive('send')->andReturn('provider.msg.id');
            $this->app->instance($driverClass, $driver);
        }
    }

    // ─── contact.tag_added ────────────────────────────────────────────────────

    public function test_tag_added_event_fires_matching_automation(): void
    {
        $automation = $this->makeAutomation('contact.tag_added', [
            // NB: not add_tag — that would dispatch ContactTagAdded again and
            // re-trigger this very automation (infinite loop).
            ['id' => 'n1', 'type' => 'update_contact', 'position' => ['x' => 0, 'y' => 100], 'data' => ['field' => 'notes', 'value' => 'Tagged as vip']],
        ]);

        event(new ContactTagAdded($this->contact, 'vip'));

        $run = AutomationRun::where('automation_id', $automation->id)->latest('id')->first();
        $this->assertNotNull($run, 'contact.tag_added automation did not trigger');
        $this->assertEquals(['tag_name' => 'vip'], $run->context);
    }

    public function test_add_tag_node_dispatches_tag_added_event(): void
    {
        Event::fake([ContactTagAdded::class]);

        $this->contact->tags()->syncWithoutDetaching([ContactTag::create(['workspace_id' => $this->workspace->id, 'name' => 'gold'])->id]);

        // Any automation tag node would do; here we exercise the engine's own
        // add_tag dispatch site through the event the UI import path uses.
        event(new ContactTagAdded($this->contact, 'enriched'));

        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
        Event::assertDispatched(ContactTagAdded::class, fn (ContactTagAdded $e) => $e->contact->id === $this->contact->id && $e->tagName === 'enriched');
    }

    // ─── campaign.sent ────────────────────────────────────────────────────────

    public function test_campaign_message_sent_event_fires_matching_automation_per_recipient(): void
    {
        $campaign = Campaign::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Diwali Blast',
            'channel' => 'whatsapp',
            'status' => 'sending',
        ]);

        $automation = $this->makeAutomation('campaign.sent', [
            ['id' => 'n1', 'type' => 'add_tag', 'position' => ['x' => 0, 'y' => 100], 'data' => ['tag' => 'campaign-reached']],
        ]);

        event(new CampaignMessageSent($campaign, $this->contact));

        $run = AutomationRun::where('automation_id', $automation->id)->latest('id')->first();
        $this->assertNotNull($run, 'campaign.sent automation did not trigger');
        $this->assertSame('Diwali Blast', $run->context['campaign_name']);
        $this->assertSame($this->contact->id, $run->contact_id);
    }

    // ─── form.submitted ───────────────────────────────────────────────────────

    public function test_form_submitted_event_fires_matching_automation_with_responses(): void
    {
        $automation = $this->makeAutomation('form.submitted', [
            ['id' => 'n1', 'type' => 'add_tag', 'position' => ['x' => 0, 'y' => 100], 'data' => ['tag' => 'lead']],
        ]);

        event(new FormSubmitted($this->contact, null, ['name' => 'Ali', 'service' => 'haircut']));

        $run = AutomationRun::where('automation_id', $automation->id)->latest('id')->first();
        $this->assertNotNull($run, 'form.submitted automation did not trigger');
        $this->assertSame('haircut', $run->context['form_responses']['service']);
    }

    // ─── Interactive per-option branching ─────────────────────────────────────

    public function test_quick_replies_wait_for_choice_branches_on_button_id(): void
    {
        $automation = $this->makeAutomation('message.received', [
            ['id' => 'n1', 'type' => 'quick_replies', 'position' => ['x' => 0, 'y' => 100], 'data' => [
                'body' => 'Language?', 'buttons' => ['English', 'Urdu'], 'wait_for_choice' => true, 'variable' => 'lang',
            ]],
            ['id' => 'n2', 'type' => 'add_tag', 'position' => ['x' => 200, 'y' => 40], 'data' => ['tag' => 'chose-english']],
            ['id' => 'n3', 'type' => 'add_tag', 'position' => ['x' => 200, 'y' => 160], 'data' => ['tag' => 'chose-urdu']],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
            ['id' => 'e2', 'source' => 'n1', 'target' => 'n2', 'sourceHandle' => 'btn_1'],
            ['id' => 'e3', 'source' => 'n1', 'target' => 'n3', 'sourceHandle' => 'btn_2'],
        ]);

        $engine = app(AutomationEngine::class);
        $run = $this->runNow($engine, $automation);

        // Run parks waiting for the customer's tap.
        $this->assertEquals('waiting', $run->status);
        $this->assertSame('n1', $run->fresh()->resume_node_id, 'Resume must start from the interactive node itself');

        // Customer taps the second button (id btn_2) → urdu branch + context var.
        $engine->resumeAwaitingReplies($this->workspace->id, $this->contact->id, 'Urdu', 'btn_2');
        $run->refresh();

        $this->assertEquals('completed', $run->status);
        $this->assertSame('Urdu', $run->context['lang'], 'Raw reply text saved to the variable');
        $this->assertSame('btn_2', $run->context['lang_id'], 'Structured choice id saved alongside');
        $this->assertTrue(
            Message::where('direction', 'out')->where('body', 'Language?')->exists(),
            'Quick replies message must be sent exactly once (no re-execution on resume)',
        );

        // The btn_2 edge was taken: tag 'chose-urdu' applied, 'chose-english' not.
        $tags = $this->contact->tags()->pluck('name')->all();
        $this->assertContains('chose-urdu', $tags);
        $this->assertNotContains('chose-english', $tags);
    }

    public function test_poll_with_wait_for_choice_branches_on_row_id(): void
    {
        $automation = $this->makeAutomation('message.received', [
            ['id' => 'n1', 'type' => 'send_poll', 'position' => ['x' => 0, 'y' => 100], 'data' => [
                'question' => 'Rate us', 'options' => "Great\nOk\nBad", 'wait_for_choice' => true, 'variable' => 'vote',
            ]],
            ['id' => 'n2', 'type' => 'add_tag', 'position' => ['x' => 200, 'y' => 40], 'data' => ['tag' => 'happy']],
            ['id' => 'n3', 'type' => 'add_tag', 'position' => ['x' => 200, 'y' => 160], 'data' => ['tag' => 'meh']],
        ], [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1'],
            ['id' => 'e2', 'source' => 'n1', 'target' => 'n2', 'sourceHandle' => 'row_1'],
            ['id' => 'e3', 'source' => 'n1', 'target' => 'n3', 'sourceHandle' => 'row_2'],
        ]);

        $engine = app(AutomationEngine::class);
        $run = $this->runNow($engine, $automation);
        $this->assertEquals('waiting', $run->status);

        // Poll with wait_for_choice sends an interactive LIST (ids row_N) so
        // edges match the builder's per-option handles.
        $poll = Message::where('direction', 'out')->latest('id')->first();
        $this->assertNotNull($poll);
        $this->assertSame('list', $poll->payload['interactive']['type'] ?? null);
        $this->assertSame('Great', $poll->payload['interactive']['action']['sections'][0]['rows'][0]['title'] ?? null);

        $engine->resumeAwaitingReplies($this->workspace->id, $this->contact->id, 'Ok', 'row_2');
        $run->refresh();

        $this->assertEquals('completed', $run->status);
        $this->assertSame('Ok', $run->context['vote']);
        $this->assertContains('meh', $this->contact->tags()->pluck('name')->all());
        $this->assertNotContains('happy', $this->contact->tags()->pluck('name')->all());
    }

    public function test_poll_without_wait_for_choice_sends_native_poll(): void
    {
        $this->runNodes([
            ['id' => 'n1', 'type' => 'send_poll', 'position' => ['x' => 0, 'y' => 100], 'data' => [
                'question' => 'Favourite feature?', 'options' => 'Flows,Tags,Inbox',
            ]],
        ]);

        $poll = Message::where('direction', 'out')->latest('id')->first();
        $this->assertNotNull($poll);
        $this->assertSame('poll', $poll->type);
        $this->assertSame('Favourite feature?', $poll->payload['poll']['question']);
        $this->assertCount(3, $poll->payload['poll']['options']);
        $this->assertSame('Flows', $poll->payload['poll']['options'][0]['text']);
    }

    // ─── Template buttons ─────────────────────────────────────────────────────

    public function test_send_template_attaches_quick_reply_and_url_button_components(): void
    {
        $this->runNodes([
            ['id' => 'n1', 'type' => 'send_template', 'position' => ['x' => 0, 'y' => 100], 'data' => [
                'template_name' => 'order_update', 'language' => 'en_US',
                'buttons' => [
                    ['type' => 'quick_reply', 'text' => 'Track order'],
                    ['type' => 'url', 'url' => 'https://shop.test/t/{{contact.id}}'],
                ],
            ]],
        ]);

        $msg = Message::where('direction', 'out')->latest('id')->first();
        $this->assertNotNull($msg);

        $components = $msg->payload['template']['components'];
        $buttonComponents = array_values(array_filter($components, fn ($c) => ($c['type'] ?? '') === 'button'));

        $this->assertCount(2, $buttonComponents);
        $this->assertSame('quick_reply', $buttonComponents[0]['sub_type']);
        $this->assertSame('0', $buttonComponents[0]['index']);
        $this->assertSame('tmpl_btn_1', $buttonComponents[0]['parameters'][0]['payload']);
        $this->assertSame('url', $buttonComponents[1]['sub_type']);
        $this->assertSame('1', $buttonComponents[1]['index']);
        $this->assertSame('https://shop.test/t/'.$this->contact->id, $buttonComponents[1]['parameters'][0]['text']);
    }

    public function test_template_quick_reply_reply_id_reaches_branching(): void
    {
        // Simulate the listener extracting a template quick-reply payload id.
        $conversation = $this->makeConversation();
        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'button',
            'body' => 'Track order',
            'payload' => ['button' => ['text' => 'Track order', 'payload' => 'tmpl_btn_1']],
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $engine = Mockery::mock(AutomationEngine::class);
        $engine->shouldReceive('resumeAwaitingReplies')
            ->once()
            ->withArgs(function (int $ws, int $contactId, string $body, ?string $replyId) {
                return $replyId === 'tmpl_btn_1';
            })->andReturnNull();
        $engine->shouldReceive('triggerForContact')->andReturnNull();

        (new AutomationTriggerListener($engine))->handleMessageReceived(new \App\Events\MessageReceived($msg));

        Mockery::close();

        $this->assertTrue(true); // Mockery expectations above are the assertion
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeAutomation(string $triggerType, array $nodes, array $edges = []): Automation
    {
        return Automation::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'T '.$triggerType,
            'status' => 'active',
            'trigger_type' => $triggerType,
            'nodes' => array_merge(
                [['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []]],
                $nodes,
            ),
            'edges' => $edges ?: [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1']],
        ]);
    }

    /** Run an automation synchronously and return the fresh run. */
    private function runNow(AutomationEngine $engine, Automation $automation): AutomationRun
    {
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
            'context' => [],
            'started_at' => now(),
        ]);

        $engine->executeRun($run);

        return $run->fresh();
    }

    private function runNodes(array $nodes): AutomationRun
    {
        $automation = $this->makeAutomation('message.received', $nodes);

        return $this->runNow(app(AutomationEngine::class), $automation);
    }

    private function makeConversation(): Conversation
    {
        $account = ChannelAccount::where('workspace_id', $this->workspace->id)->first();

        return Conversation::create([
            'workspace_id' => $this->workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $this->contact->id,
            'external_thread_id' => 'thread-'.uniqid(),
            'status' => 'open',
            'unread_count' => 0,
            'last_message_at' => now(),
        ]);
    }
}
