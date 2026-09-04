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
                        'text' => 'Hi {{1}}, your order #{{2}} is confirmed! The total amount of {{3}} will be collected on delivery. We will notify you when it ships.', 
                        'example' => ['body_text' => [['John', '1001', '$50.00']]]
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
                            ['type' => 'URL', 'text' => 'Complete Purchase', 'url' => 'https://example.com'] // Note: URL must be valid domain, users will edit this.
                        ]
                    ]
                ],
            ],
        ];

        foreach ($templates as $t) {
            WhatsappTemplate::firstOrCreate(
                ['workspace_id' => $waba->workspace_id, 'name' => $t['name'], 'language' => 'en'],
                [
                    'waba_id' => $waba->waba_id,
                    'category' => $t['category'],
                    'status' => 'PENDING',
                    'components' => $t['components'],
                ]
            );
        }

        Log::info('Default E-Commerce templates seeded', ['workspace_id' => $waba->workspace_id, 'waba_id' => $waba->waba_id]);
    }
}
