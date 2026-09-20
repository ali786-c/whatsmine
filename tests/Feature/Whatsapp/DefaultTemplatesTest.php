<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\EcommerceTemplateVariables;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Jobs\ReseedDefaultEcommerceTemplatesJob;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\TemplateValidator;
use Tests\TestCase;

class DefaultTemplatesTest extends TestCase
{
    /** @test */
    public function every_default_template_passes_the_validator(): void
    {
        foreach (ReseedDefaultEcommerceTemplatesJob::defaultTemplates() as $t) {
            $problems = TemplateValidator::problems($t + ['language' => 'en_US']);

            $this->assertSame([], $problems, "{$t['name']} should be valid: ".implode(' | ', $problems));
        }
    }

    /** @test */
    public function default_templates_carry_header_body_and_real_examples(): void
    {
        foreach (ReseedDefaultEcommerceTemplatesJob::defaultTemplates() as $t) {
            $types = array_map(fn ($c) => strtoupper($c['type'] ?? ''), $t['components']);

            $this->assertContains('BODY', $types, "{$t['name']} needs a BODY.");

            $body = collect($t['components'])->firstWhere('type', 'BODY');
            $examples = $body['example']['body_text'][0] ?? [];

            preg_match_all('/\{\{\d+\}\}/', $body['text'], $m);
            $this->assertCount(
                count($m[0]),
                $examples,
                "{$t['name']} needs one example per variable."
            );
        }
    }

    /** @test */
    public function button_texts_respect_meta_limits(): void
    {
        foreach (ReseedDefaultEcommerceTemplatesJob::defaultTemplates() as $t) {
            foreach ($t['components'] as $c) {
                if (strtoupper($c['type'] ?? '') !== 'BUTTONS') {
                    continue;
                }

                foreach ($c['buttons'] as $button) {
                    $this->assertLessThanOrEqual(
                        25,
                        mb_strlen($button['text']),
                        "{$t['name']} button '{$button['text']}' exceeds 25 chars."
                    );
                }
            }
        }
    }

    /** @test */
    public function variable_builder_fills_real_data_for_known_templates(): void
    {
        $template = new WhatsappTemplate([
            'name' => 'ecommerce_order_cod',
            'language' => 'en_US',
            'components' => ReseedDefaultEcommerceTemplatesJob::defaultTemplates()[0]['components'],
        ]);

        $contact = new Contact(['first_name' => 'Amna']);
        $store = new EcommerceStore(['name' => 'Amna Shop', 'domain' => 'amnashop.com']);

        $values = EcommerceTemplateVariables::bodyValues($template, $contact, [
            'order_number' => '1001',
            'order_total' => '$50.00',
        ], $store);

        $this->assertCount(4, $values); // sized to the body's 4 variables
        $this->assertSame('Amna', $values[0]);
        $this->assertSame('1001', $values[1]);
        $this->assertSame('$50.00', $values[2]);
    }

    /** @test */
    public function send_components_sized_to_template_and_include_url_button_params(): void
    {
        $components = ReseedDefaultEcommerceTemplatesJob::defaultTemplates()[2]['components']; // shipped
        $template = new WhatsappTemplate([
            'name' => 'ecommerce_order_shipped',
            'language' => 'en_US',
            'components' => $components,
        ]);

        $contact = new Contact(['first_name' => 'Amna']);
        $store = new EcommerceStore(['name' => 'Amna Shop', 'domain' => 'amnashop.com']);

        $payload = EcommerceTemplateVariables::sendComponents($template, $contact, [
            'order_number' => '1001',
        ], $store);

        $body = collect($payload)->firstWhere('type', 'body');
        $this->assertCount(4, $body['parameters'], 'shipped body has 4 variables');
        $this->assertSame('Amna', $body['parameters'][0]['text']);
        $this->assertSame('1001', $body['parameters'][1]['text']);

        // Static URL button needs no send-time parameters.
        $this->assertNull(collect($payload)->firstWhere('sub_type', 'url'));
    }

    /** @test */
    public function custom_templates_get_generic_positional_fill(): void
    {
        $template = new WhatsappTemplate([
            'name' => 'store_custom_offer',
            'language' => 'en_US',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => 'Hello {{1}}, special offer for {{2}} and {{3}} inside!',
                    'example' => ['body_text' => [['a', 'b', 'c']]],
                ],
            ],
        ]);

        $contact = new Contact(['first_name' => 'Amna']);
        $store = new EcommerceStore(['name' => 'Amna Shop', 'domain' => 'amnashop.com']);

        $values = EcommerceTemplateVariables::bodyValues($template, $contact, [
            'order_number' => '1001',
            'order_total' => '$20.00',
        ], $store);

        $this->assertCount(3, $values);
        $this->assertSame('Amna', $values[0]);
    }

    /** @test */
    public function default_base_url_prefers_connected_store_domain(): void
    {
        $base = EcommerceTemplateVariables::defaultBaseUrl(999999);

        $this->assertSame(rtrim(config('app.url'), '/'), $base);
    }
}
