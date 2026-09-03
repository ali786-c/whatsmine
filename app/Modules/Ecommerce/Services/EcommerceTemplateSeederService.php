<?php

namespace App\Modules\Ecommerce\Services;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EcommerceTemplateSeederService
{
    /**
     * Seeds default E-commerce templates and submits them to Meta for approval.
     */
    public function seedAndSubmit(WhatsappBusinessAccount $waba): void
    {
        $templates = [
            [
                'name' => 'ecommerce_order_cod',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "Hello {{1}}! Your order {{2}} has been confirmed. Thank you for shopping with us! You will pay cash on delivery.",
                        'example' => ['body_text' => [['John', 'ORD-123']]]
                    ]
                ]
            ],
            [
                'name' => 'ecommerce_abandoned_cart_1',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "Hi {{1}}, we noticed you left something in your cart! Complete your purchase here: {{2}}",
                        'example' => ['body_text' => [['John', 'https://store.com/cart']]]
                    ]
                ]
            ]
        ];

        foreach ($templates as $tplData) {
            $existing = WhatsappTemplate::where('waba_id', $waba->waba_id)->where('name', $tplData['name'])->first();
            if ($existing) continue;

            try {
                // Submit to Meta
                $url = "https://graph.facebook.com/v19.0/{$waba->waba_id}/message_templates";
                $response = Http::withToken($waba->access_token)->post($url, $tplData);

                if ($response->successful()) {
                    $metaId = $response->json('id');
                    WhatsappTemplate::create([
                        'workspace_id' => $waba->workspace_id,
                        'waba_id' => $waba->waba_id,
                        'name' => $tplData['name'],
                        'language' => $tplData['language'],
                        'category' => $tplData['category'],
                        'components' => $tplData['components'],
                        'status' => 'PENDING',
                        'meta_template_id' => $metaId,
                    ]);
                } else {
                    Log::error("Failed to seed template {$tplData['name']}", ['response' => $response->body()]);
                }
            } catch (\Exception $e) {
                Log::error("Exception seeding template {$tplData['name']}: " . $e->getMessage());
            }
        }
    }
}
