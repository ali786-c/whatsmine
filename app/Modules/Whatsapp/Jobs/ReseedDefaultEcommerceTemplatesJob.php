<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Modules\Whatsapp\Services\TemplateValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-seeds the default ecommerce templates for EXISTING WABAs:
 *
 * - REJECTED templates  -> fixed via Meta's edit endpoint (POST /{template_id}),
 *                         which resets them to PENDING and re-runs review.
 * - Missing templates   -> created via POST /{waba_id}/message_templates.
 * - PENDING/APPROVED    -> skipped untouched.
 *
 * Every payload passes TemplateValidator first; anything invalid is logged
 * and skipped instead of burned against Meta's 100-templates/hour limit.
 */
class ReseedDefaultEcommerceTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $wabaId) {}

    public function handle(): void
    {
        $waba = WhatsappBusinessAccount::find($this->wabaId);
        if (! $waba) {
            return;
        }

        $client = CloudApiClient::forWorkspace($waba->workspace_id);
        if (! $client) {
            Log::warning('ReseedDefaultEcommerceTemplatesJob: No Cloud API Client configured.', ['waba_id' => $waba->waba_id]);
            return;
        }

        $defaults = $this->defaultTemplates();

        foreach ($defaults as $t) {
            $problems = TemplateValidator::problems($t);
            if ($problems !== []) {
                Log::error("ReseedDefaultEcommerceTemplatesJob: skipping {$t['name']}, payload invalid", ['problems' => $problems]);
                continue;
            }

            $existing = WhatsappTemplate::where('waba_id', $waba->waba_id)
                ->where('name', $t['name'])
                ->orderByRaw("CASE WHEN status = 'APPROVED' THEN 0 WHEN status = 'PENDING' THEN 1 ELSE 2 END")
                ->first();

            if ($existing && in_array($existing->status, ['APPROVED', 'PENDING', 'IN_REVIEW'], true)) {
                continue; // nothing to do
            }

            $metaPayload = [
                'name' => $t['name'],
                'language' => 'en_US',
                'category' => $t['category'],
                'components' => $t['components'],
            ];

            try {
                if ($existing && $existing->meta_template_id && $existing->status === 'REJECTED') {
                    // Edit resets the template to PENDING and re-runs review.
                    $resp = $client->editTemplate($existing->meta_template_id, $metaPayload);
                } else {
                    // Not present on Meta (or no meta id) — create fresh.
                    $resp = $client->submitTemplate($waba->waba_id, $metaPayload);
                }

                $status = 'PENDING';
                $metaId = $existing?->meta_template_id;
                $rejectionReason = null;

                if ($resp->successful()) {
                    $metaId = $resp->json('id') ?? $metaId;
                } else {
                    $status = 'REJECTED';
                    $rejectionReason = $resp->json('error.error_user_msg')
                        ?? $resp->json('error.message')
                        ?? 'Meta rejected template (HTTP '.$resp->status().').';
                    Log::warning("ReseedDefaultEcommerceTemplatesJob: Meta refused {$t['name']}", ['error' => $rejectionReason]);
                }

                if ($existing) {
                    $existing->update([
                        'status' => $status,
                        'meta_template_id' => $metaId,
                        'rejection_reason' => $rejectionReason,
                        'components' => $t['components'],
                    ]);
                } else {
                    WhatsappTemplate::create([
                        'workspace_id' => $waba->workspace_id,
                        'waba_id' => $waba->waba_id,
                        'name' => $t['name'],
                        'language' => 'en_US',
                        'category' => $t['category'],
                        'status' => $status,
                        'components' => $t['components'],
                        'meta_template_id' => $metaId,
                        'rejection_reason' => $rejectionReason,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("ReseedDefaultEcommerceTemplatesJob: exception on {$t['name']}: ".$e->getMessage());
            }
        }
    }

    /**
     * Single source of truth for the default ecommerce templates.
     * SeedDefaultEcommerceTemplatesJob delegates here so new-WABA seeding
     * and manual reseeding can never drift apart.
     *
     * @return array<int, array{name: string, category: string, components: array}>
     */
    public static function defaultTemplates(): array
    {
        return [
            [
                'name' => 'ecommerce_order_cod',
                'category' => 'UTILITY',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, your order #{{2}} is placed! The total amount of {{3}} will be collected on delivery. Please confirm your order by clicking the button below.',
                        'example' => ['body_text' => [['John', '1001', '$50.00']]],
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => 'Confirm Order'],
                            ['type' => 'QUICK_REPLY', 'text' => 'Cancel Order'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_order_paid',
                'category' => 'UTILITY',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, we received your payment of {{2}} for order #{{3}}. Thank you for your purchase! We will notify you when it ships.',
                        'example' => ['body_text' => [['John', '$50.00', '1001']]],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_order_shipped',
                'category' => 'UTILITY',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => 'Great news {{1}}! Your order #{{2}} is on the way. Track it here: {{3}} — we will notify you as soon as it is delivered.',
                        'example' => ['body_text' => [['John', '1001', 'https://track.com/123']]],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_order_cancelled',
                'category' => 'UTILITY',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => 'We understand, {{1}}. Your order #{{2}} has been cancelled as requested. Let us know if you need any help!',
                        'example' => ['body_text' => [['John', '1001']]],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_order_confirmed',
                'category' => 'UTILITY',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => 'Thank you {{1}}! Your COD order #{{2}} for {{3}} has been confirmed and is now being processed for delivery.',
                        'example' => ['body_text' => [['John', '1001', '$50.00']]],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_winback',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "Hi {{1}}, it's been a while! We miss you. Use code {{2}} for {{3}} off your next purchase.",
                        'example' => ['body_text' => [['John', 'WELCOMEBACK15', '15%']]],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_review_request',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "Hope you're loving your recent purchase! Could you take 10 seconds to leave a review here: {{1}}? Your feedback means a lot to our small team.",
                        'example' => ['body_text' => [['https://store.com/review']]],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_vip_thanks',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "Hi {{1}}, I'm the founder. I personally wanted to thank you for your VIP order #{{2}}! We truly appreciate your support.",
                        'example' => ['body_text' => [['John', '1001']]],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_abandoned_cart',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, we noticed you left something in your cart! Complete your purchase of {{2}} easily here: {{3}} — your items are reserved for a limited time.',
                        'example' => ['body_text' => [['John', '$50.00', 'https://store.com/cart/recovery']]],
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'URL', 'text' => 'Complete Purchase', 'url' => 'https://store.com/cart/recovery'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
