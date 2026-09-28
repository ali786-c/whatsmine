<?php

namespace App\Listeners;

use App\Events\AutomationWebhookReceived;
use App\Events\CampaignCompleted;
use App\Events\CampaignMessageSent;
use App\Events\CommerceEventReceived;
use App\Events\ContactCreated;
use App\Events\ContactTagAdded;
use App\Events\FormSubmitted;
use App\Events\MessageReceived;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Ecommerce\Jobs\ProcessEcommerceMessagingJob;

class AutomationTriggerListener
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function handleMessageReceived(MessageReceived $event): void
    {
        $contactId = $event->message->conversation?->contact_id;
        $workspaceId = $event->message->conversation?->workspace_id;
        if (! $contactId || ! $workspaceId) {
            return;
        }

        $messageBody = $event->message->body ?? '';

        // Structured interactive choice: quick-reply buttons / list rows carry a
        // reply id in the webhook payload (button_reply.id / list_reply.id) —
        // used for per-option branching when a run parks with wait_for_choice.
        $interactive = is_array($event->message->payload['interactive'] ?? null)
            ? $event->message->payload['interactive']
            : [];
        $replyId = ($interactive['button_reply']['id'] ?? null)
            ?? ($interactive['list_reply']['id'] ?? null)
            // Template quick replies arrive as msg.button with the developer id in payload.
            ?? (is_array($event->message->payload['button'] ?? null)
                ? ($event->message->payload['button']['payload'] ?? null)
                : null);

        // Resume any runs parked on an "Ask question" node awaiting this contact's reply.
        $this->engine->resumeAwaitingReplies($workspaceId, $contactId, $messageBody, $replyId !== null ? (string) $replyId : null);

        $this->fireWithConfig('message.received', $workspaceId, $contactId, [
            'message_id' => $event->message->id,
            'message_channel' => $event->message->channel,
            'message_body' => $messageBody,
        ], $messageBody);
    }

    public function handleContactCreated(ContactCreated $event): void
    {
        $this->fire('contact.created', $event->contact->workspace_id, $event->contact->id);
    }

    public function handleCampaignCompleted(CampaignCompleted $event): void
    {
        // No per-contact trigger for campaign completion; skip.
        // (Per-recipient `campaign.sent` fires from CampaignMessageSent below.)
    }

    /** A tag was attached to a contact → `contact.tag_added` trigger. */
    public function handleContactTagAdded(ContactTagAdded $event): void
    {
        $this->fire('contact.tag_added', $event->contact->workspace_id, $event->contact->id, [
            'tag_name' => $event->tagName,
        ]);
    }

    /** A campaign message reached one recipient → `campaign.sent` trigger. */
    public function handleCampaignMessageSent(CampaignMessageSent $event): void
    {
        $this->fire('campaign.sent', $event->campaign->workspace_id, $event->contact->id, [
            'campaign_id' => $event->campaign->id,
            'campaign_name' => $event->campaign->name,
            'campaign_channel' => $event->campaign->channel,
        ]);
    }

    /** A WhatsApp Flow / form was submitted → `form.submitted` trigger. */
    public function handleFormSubmitted(FormSubmitted $event): void
    {
        $workspaceId = $event->contact?->workspace_id;
        if (! $workspaceId || ! $event->contact?->id) {
            return;
        }

        $this->fire('form.submitted', $workspaceId, $event->contact->id, [
            'form_responses' => $event->responses,
        ]);
    }

    public function handleCommerceEvent(CommerceEventReceived $event): void
    {
        // Trigger native templates if configured in store messaging_config
        if ($event->storeId !== null && in_array($event->eventType, ['order.placed', 'cart.abandoned'])) {
            ProcessEcommerceMessagingJob::dispatch(
                $event->storeId,
                $event->contactId,
                $event->eventType,
                $event->context
            )->onQueue('automation');
        }

        // eventType is one of order.placed / order.fulfilled / order.cancelled /
        // cart.abandoned / customer.created — matched directly against trigger_type.
        $this->fire($event->eventType, $event->workspaceId, $event->contactId, $event->context);
    }

    public function handleAutomationWebhookReceived(AutomationWebhookReceived $event): void
    {
        $automation = Automation::where('id', $event->automationId)
            ->where('status', 'active')
            ->where('trigger_type', 'webhook')
            ->first();

        if (! $automation) {
            return;
        }

        $context = ['payload' => $event->payload];

        if ($event->contactId) {
            $this->engine->triggerForContact($automation, $event->contactId, $context);
        } else {
            // Contactless: trigger a run without a contact (contact_id = null)
            $this->triggerWithoutContact($automation, $context);
        }
    }

    private function triggerWithoutContact(Automation $automation, array $context = []): void
    {
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => null,
            'status' => 'pending',
            'context' => $context,
            'started_at' => now(),
        ]);

        dispatch(new ExecuteAutomationRunJob($run->id))->onQueue('automation');
    }

    private function fire(string $triggerType, int $workspaceId, int $contactId, array $context = []): void
    {
        Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->each(fn ($automation) => $this->engine->triggerForContact($automation, $contactId, $context));
    }

    /**
     * Like fire(), but respects trigger_config.keywords for message.received automations.
     * If keywords are set, the message body must contain at least one keyword (case-insensitive).
     */
    private function fireWithConfig(string $triggerType, int $workspaceId, int $contactId, array $context, string $messageBody = ''): void
    {
        $automations = Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->get();

        $bodyLower = mb_strtolower($messageBody);

        foreach ($automations as $automation) {
            $keywords = $automation->trigger_config['keywords'] ?? [];

            if (! empty($keywords)) {
                $matches = false;
                foreach ($keywords as $kw) {
                    if (str_contains($bodyLower, mb_strtolower((string) $kw))) {
                        $matches = true;
                        break;
                    }
                }
                if (! $matches) {
                    continue;
                }
            }

            $this->engine->triggerForContact($automation, $contactId, $context);
        }
    }
}
