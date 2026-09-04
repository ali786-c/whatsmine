<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SeedDefaultEcommerceTemplatesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $wabaId) {}

    public function handle(): void
    {
        $waba = WhatsappBusinessAccount::find($this->wabaId);
        if (! $waba) {
            return;
        }

        $templates = [
            [
                'name' => 'ecommerce_order_cod', 
                'category' => 'UTILITY', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Hi {{1}}, your order #{{2}} is placed! The total amount of {{3}} will be collected on delivery. Please confirm your order by clicking the button below.', 
                        'example' => ['body_text' => [['John', '1001', '$50.00']]]
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'QUICK_REPLY', 'text' => '✅ Confirm Order'],
                            ['type' => 'QUICK_REPLY', 'text' => '❌ Cancel Order']
                        ]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_order_paid', 
                'category' => 'UTILITY', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Hi {{1}}, we received your payment of {{2}} for order #{{3}}. Thank you for your purchase! We will notify you when it ships.', 
                        'example' => ['body_text' => [['John', '$50.00', '1001']]]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_order_shipped', 
                'category' => 'UTILITY', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Great news {{1}}! Your order #{{2}} is on the way. Track it here: {{3}}', 
                        'example' => ['body_text' => [['John', '1001', 'https://track.com/123']]]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_order_cancelled', 
                'category' => 'UTILITY', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'We understand, {{1}}. Your order #{{2}} has been cancelled as requested. Let us know if you need any help!', 
                        'example' => ['body_text' => [['John', '1001']]]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_order_confirmed', 
                'category' => 'UTILITY', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Thank you {{1}}! Your COD order #{{2}} for {{3}} has been confirmed and is now being processed for delivery.', 
                        'example' => ['body_text' => [['John', '1001', '$50.00']]]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_winback', 
                'category' => 'MARKETING', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Hi {{1}}, it\'s been a while! We miss you. Use code {{2}} for {{3}} off your next purchase.', 
                        'example' => ['body_text' => [['John', 'WELCOMEBACK15', '15%']]]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_review_request', 
                'category' => 'MARKETING', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Hope you\'re loving your recent purchase! Could you take 10 seconds to leave a review? {{1}}', 
                        'example' => ['body_text' => [['https://store.com/review']]]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_vip_thanks', 
                'category' => 'MARKETING', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Hi {{1}}, I\'m the founder. I personally wanted to thank you for your VIP order #{{2}}! We truly appreciate your support.', 
                        'example' => ['body_text' => [['John', '1001']]]
                    ]
                ],
            ],
            [
                'name' => 'ecommerce_abandoned_cart', 
                'category' => 'MARKETING', 
                'components' => [
                    [
                        'type' => 'BODY', 
                        'text' => 'Hi {{1}}, we noticed you left something in your cart! Complete your purchase of {{2}} easily here: {{3}}', 
                        'example' => ['body_text' => [['John', '$50.00', 'https://store.com/cart/recovery']]]
                    ],
                    [
                        'type' => 'BUTTONS',
                        'buttons' => [
                            ['type' => 'URL', 'text' => 'Complete Purchase', 'url' => 'https://example.com']
                        ]
                    ]
                ],
            ],
        ];

        foreach ($templates as $t) {
            WhatsappTemplate::firstOrCreate(
                ['waba_id' => $waba->waba_id, 'name' => $t['name'], 'language' => 'en'],
                [
                    'workspace_id' => $waba->workspace_id,
                    'category' => $t['category'],
                    'status' => 'PENDING',
                    'components' => $t['components'],
                ]
            );
        }

        Log::info('Default E-Commerce templates seeded', ['workspace_id' => $waba->workspace_id, 'waba_id' => $waba->waba_id]);
    }
}
