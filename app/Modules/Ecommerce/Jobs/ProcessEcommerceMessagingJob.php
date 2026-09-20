<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\EcommerceTemplateVariables;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessEcommerceMessagingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $storeId,
        public readonly int $contactId,
        public readonly string $eventType,
        public readonly array $context,
        public readonly ?int $stepIndex = null
    ) {}

    public function handle(): void
    {
        $store = EcommerceStore::find($this->storeId);
        $contact = Contact::find($this->contactId);

        if (! $store || ! $contact || empty($contact->phone_e164) || ! $contact->opt_in_whatsapp) {
            return;
        }

        $config = $store->messaging_config ?? [];

        if ($this->eventType === 'order.placed') {
            // Payment method is signalled by the webhook context; COD config
            // is used when the order is not already paid.
            $isPaid = filter_var($this->context['is_paid'] ?? false, FILTER_VALIDATE_BOOL);
            $key = $isPaid ? 'order_placed_paid' : 'order_placed_cod';
            $orderConfig = $config[$key] ?? [];

            if (! empty($orderConfig['enabled']) && ! empty($orderConfig['template_id'])) {
                $this->sendTemplate((int) $orderConfig['template_id'], $contact, $store);
            }
        } elseif ($this->eventType === 'cart.abandoned') {
            $sequence = $config['abandoned_cart_sequence'] ?? [];
            $index = $this->stepIndex ?? 0;

            if (isset($sequence[$index])) {
                $step = $sequence[$index];

                // Verify cart is still abandoned (no order placed since cart creation)
                $hasConverted = EcommerceOrder::where('store_id', $store->id)
                    ->where('contact_id', $contact->id)
                    ->where('created_at', '>=', now()->subDays(7)) // Rough check
                    ->exists();

                if ($hasConverted) {
                    return; // Stop sequence
                }

                if (! empty($step['template_id'])) {
                    $this->sendTemplate((int) $step['template_id'], $contact, $store);
                }

                // Schedule next step if exists
                if (isset($sequence[$index + 1])) {
                    $nextStep = $sequence[$index + 1];
                    $delay = $nextStep['delay_minutes'] ?? 30;

                    self::dispatch($store->id, $contact->id, 'cart.abandoned', $this->context, $index + 1)
                        ->delay(now()->addMinutes((int) $delay));
                }
            }
        }
    }

    private function sendTemplate(int $templateId, Contact $contact, EcommerceStore $store): void
    {
        $template = WhatsappTemplate::find($templateId);
        if (! $template || $template->status !== 'APPROVED') {
            Log::warning('E-Commerce messaging: template not approved, skipping send', [
                'template_id' => $templateId,
                'store_id' => $store->id,
            ]);

            return;
        }

        $channelAccount = \App\Modules\Shared\Models\ChannelAccount::where('workspace_id', $store->workspace_id)
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->first();

        if (! $channelAccount) {
            Log::warning('E-Commerce messaging: no active WhatsApp channel for workspace', [
                'workspace_id' => $store->workspace_id,
            ]);

            return;
        }

        $client = CloudApiClient::forPhoneNumber(
            (string) $channelAccount->credentials['phone_number_id'],
            (int) $store->workspace_id
        );

        if (! $client) {
            Log::warning('E-Commerce messaging: CloudApiClient unavailable', [
                'workspace_id' => $store->workspace_id,
            ]);

            return;
        }

        $components = EcommerceTemplateVariables::sendComponents($template, $contact, $this->context, $store);

        try {
            $resp = $client->sendTemplate(
                trim($contact->phone_e164, '+'),
                $template->name,
                $template->language,
                $components
            );

            if ($resp->successful()) {
                Log::info('E-Commerce auto-message sent', [
                    'contact' => $contact->id,
                    'template' => $template->name,
                ]);
            } else {
                Log::error('E-Commerce auto-message failed', [
                    'template' => $template->name,
                    'status' => $resp->status(),
                    'error' => $resp->json('error.message') ?? $resp->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send E-Commerce auto-message', ['error' => $e->getMessage()]);
        }
    }
}
