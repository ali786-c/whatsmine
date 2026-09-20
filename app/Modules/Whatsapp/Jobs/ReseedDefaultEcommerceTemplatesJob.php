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

        // Names that already exist on Meta — never re-create those, it would
        // just burn the hourly quota on a guaranteed duplicate-name rejection.
        $metaNames = collect($client->fetchTemplates($waba->waba_id))->pluck('name')->all();

        $defaults = $this->defaultTemplates($waba->workspace_id);

        foreach ($defaults as $t) {
            $problems = TemplateValidator::problems($t);
            if ($problems !== []) {
                Log::error("ReseedDefaultEcommerceTemplatesJob: skipping {$t['name']}, payload invalid", ['problems' => $problems]);
                continue;
            }

            $existing = WhatsappTemplate::where('waba_id', $waba->waba_id)
                ->where('name', $t['name'])
                ->where('language', 'en_US')
                ->orderByRaw("CASE WHEN status = 'APPROVED' THEN 0 WHEN status = 'PENDING' THEN 1 ELSE 2 END")
                ->first();

            if ($existing && in_array($existing->status, ['APPROVED', 'PENDING', 'IN_REVIEW'], true)) {
                continue; // nothing to do
            }

            if (! $existing?->meta_template_id && in_array($t['name'], $metaNames, true)) {
                // On Meta without a usable local meta id — editing is
                // impossible, re-creating would be rejected. Backfill + skip.
                Log::info("ReseedDefaultEcommerceTemplatesJob: {$t['name']} exists on Meta without local meta id — skipped.");
                continue;
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
     * Every template follows Meta's Template Fundamentals rules:
     * HEADER (static text, ≤60 chars) + BODY (variables never at the
     * start/end, one example per positional variable) + FOOTER (no
     * variables, ≤60 chars) + BUTTONS (URL button domain matches the
     * body domain, quick-reply text ≤25 chars, no emoji/variables).
     *
     * Send-time variable meanings are defined in
     * \App\Modules\Ecommerce\Services\EcommerceTemplateVariables — keep
     * the two in sync when editing bodies here.
     *
     * @return array<int, array{name: string, category: string, components: array}>
     */
    public static function defaultTemplates(?int $workspaceId = null): array
    {
        // Button + body links point at the workspace's own store domain when
        // one is connected, so the templates read as real data out of the box.
        $base = $workspaceId !== null
            ? rtrim(\App\Modules\Ecommerce\Services\EcommerceTemplateVariables::defaultBaseUrl($workspaceId), '/')
            : 'https://store.com';
        $shop = $base.'/shop';
        $review = $base.'/review';
        $cart = $base.'/cart/recovery';
        $track = str_replace('://', '://track.', $base).'/abc123';
        return [
            [
                'name' => 'ecommerce_order_cod',
                'category' => 'UTILITY',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order Confirmation'],
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, your order #{{2}} has been placed successfully. Amount to pay on delivery: {{3}}. Expected delivery: {{4}}. Please confirm your order so we can ship it right away.',
                        'example' => ['body_text' => [['John', '1001', '$50.00', 'Tue, 24 Sep']]],
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
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Payment Received'],
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, we have received your payment of {{2}} for order #{{3}}. Your order is now being prepared for dispatch. Thank you for shopping with us!',
                        'example' => ['body_text' => [['John', '$50.00', '1001']]],
                    ],
                    ['type' => 'FOOTER', 'text' => 'Reply to this message if you need any help.'],
                ],
            ],
            [
                'name' => 'ecommerce_order_shipped',
                'category' => 'UTILITY',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order Shipped'],
                    [
                        'type' => 'BODY',
                        'text' => 'Good news {{1}}! Your order #{{2}} is on its way and will arrive by {{3}}. Track your package live here: {{4}} — see you soon!',
                        'example' => ['body_text' => [['John', '1001', 'Tue, 24 Sep', $track]]],
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'URL', 'text' => 'Track Package', 'url' => $track],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_order_cancelled',
                'category' => 'UTILITY',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order Cancelled'],
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, your order #{{2}} has been cancelled as requested. Any payment made will be refunded within 5-7 business days. We hope to serve you again soon!',
                        'example' => ['body_text' => [['John', '1001']]],
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'URL', 'text' => 'Shop Again', 'url' => $shop],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_order_confirmed',
                'category' => 'UTILITY',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Order Confirmed'],
                    [
                        'type' => 'BODY',
                        'text' => 'Thank you {{1}}! Your COD order #{{2}} for {{3}} is confirmed and is now being processed for delivery. We will notify you as soon as it ships.',
                        'example' => ['body_text' => [['John', '1001', '$50.00']]],
                    ],
                    ['type' => 'FOOTER', 'text' => 'Thank you for shopping with us!'],
                ],
            ],
            [
                'name' => 'ecommerce_winback',
                'category' => 'MARKETING',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'We miss you!'],
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, it has been a while since your last order! Enjoy {{2}} off your next purchase with code {{3}}. Offer valid until {{4}} — treat yourself today!',
                        'example' => ['body_text' => [['John', '15%', 'WELCOME15', 'Sep 30']]],
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'URL', 'text' => 'Shop Now', 'url' => $shop],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_review_request',
                'category' => 'MARKETING',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'How did we do?'],
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, thank you for your recent purchase! We would love to hear your thoughts on order #{{2}}. It takes only 10 seconds: {{3}} — your feedback helps us improve.',
                        'example' => ['body_text' => [['John', '1001', $review]]],
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'URL', 'text' => 'Leave a Review', 'url' => $review],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'ecommerce_vip_thanks',
                'category' => 'MARKETING',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'A personal thank you'],
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, I am the founder of {{2}} and I personally wanted to thank you for your VIP order #{{3}}. Customers like you make everything we do worthwhile. If you ever need anything, just reply to this message!',
                        'example' => ['body_text' => [['John', 'StoreX', '1001']]],
                    ],
                    ['type' => 'FOOTER', 'text' => 'We read every reply.'],
                ],
            ],
            [
                'name' => 'ecommerce_abandoned_cart',
                'category' => 'MARKETING',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Your cart is waiting'],
                    [
                        'type' => 'BODY',
                        'text' => 'Hi {{1}}, you left {{2}} worth of items in your cart and they are selling fast! Complete your purchase here: {{3}} — your items are reserved for a limited time only.',
                        'example' => ['body_text' => [['John', '$50.00', $cart]]],
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'URL', 'text' => 'Complete Purchase', 'url' => $cart],
                        ],
                    ],
                ],
            ],
        ];
    }
}
