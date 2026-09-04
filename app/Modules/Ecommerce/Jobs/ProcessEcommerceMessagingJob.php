<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Ecommerce\Models\EcommerceStore;
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
            // For now, assuming COD for all order placements if COD is enabled in config.
            // In a real app, you'd check payment status in context.
            $orderConfig = $config['order_placed_cod'] ?? [];
            
            if (!empty($orderConfig['enabled']) && !empty($orderConfig['template_id'])) {
                $this->sendTemplate($orderConfig['template_id'], $contact, $store);
            }
        } 
        elseif ($this->eventType === 'cart.abandoned') {
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

                if (!empty($step['template_id'])) {
                    $this->sendTemplate($step['template_id'], $contact, $store);
                }

                // Schedule next step if exists
                if (isset($sequence[$index + 1])) {
                    $nextStep = $sequence[$index + 1];
                    $delay = $nextStep['delay_minutes'] ?? 30;
                    
                    self::dispatch($store->id, $contact->id, 'cart.abandoned', $this->context, $index + 1)
                        ->delay(now()->addMinutes((int)$delay));
                }
            }
        }
    }

    private function sendTemplate(int $templateId, Contact $contact, EcommerceStore $store): void
    {
        $template = WhatsappTemplate::find($templateId);
        if (!$template || $template->status !== 'APPROVED') {
            return;
        }

        // Mapping variables based on event type
        $variables = [];
        $variables[] = $contact->first_name ?? 'there';
        
        if ($this->eventType === 'order.placed') {
            $variables[] = $this->context['order_number'] ?? 'your order';
            $variables[] = $this->context['order_total'] ?? 'the total';
        } elseif ($this->eventType === 'cart.abandoned') {
            $variables[] = $this->context['cart_total'] ?? 'your items';
            $variables[] = $this->context['recovery_url'] ?? $store->domain;
        }

        try {
            // Retrieve channel account to get token
            $channelAccount = \App\Modules\Shared\Models\ChannelAccount::where('workspace_id', $store->workspace_id)
                ->where('channel', 'whatsapp')
                ->where('status', 'active')
                ->first();

            if (!$channelAccount) return;

            $token = $channelAccount->credentials['system_user_token'] ?? $channelAccount->credentials['access_token'] ?? null;
            $phoneNumberId = $channelAccount->credentials['phone_number_id'] ?? null;

            if (!$token || !$phoneNumberId) return;

            // Simplified send template call - assuming CloudApiClient has a method for this, 
            // or we use the API directly.
            \Illuminate\Support\Facades\Http::withToken($token)
                ->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => trim($contact->phone_e164, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $template->name,
                        'language' => ['code' => $template->language],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $variables)
                            ]
                        ]
                    ]
                ]);
            
            Log::info('E-Commerce auto-message sent', ['contact' => $contact->id, 'template' => $template->name]);
            
        } catch (\Throwable $e) {
            Log::error('Failed to send E-Commerce auto-message', ['error' => $e->getMessage()]);
        }
    }
}
