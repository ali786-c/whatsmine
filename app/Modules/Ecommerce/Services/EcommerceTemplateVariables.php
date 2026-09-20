<?php

namespace App\Modules\Ecommerce\Services;

use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappTemplate;

/**
 * Builds real send-time data for the default ecommerce templates.
 *
 * Template variable meanings (positional) are defined in
 * ReseedDefaultEcommerceTemplatesJob::defaultTemplates() — keep the two
 * in sync when editing template bodies there.
 *
 * Everything degrades gracefully: any missing context value falls back to
 * a sensible string so a send never fails because of incomplete webhook
 * payloads.
 */
class EcommerceTemplateVariables
{
    /**
     * Ordered positional variable values per default template name.
     * The order here MUST match the {{1}}, {{2}}, ... order in the
     * template bodies.
     *
     * @return array<string, list<string>>
     */
    public static function variableMap(): array
    {
        return [
            // Hi {{1}}, your order #{{2}} ... Amount to pay on delivery: {{3}}. Expected delivery: {{4}}.
            'ecommerce_order_cod' => ['first_name', 'order_number', 'order_total', 'delivery_estimate'],
            // Hi {{1}}, ... payment of {{2}} for order #{{3}}.
            'ecommerce_order_paid' => ['first_name', 'order_total', 'order_number'],
            // Good news {{1}}! Order #{{2}} ... arrive by {{3}}. Track ... {{4}}.
            'ecommerce_order_shipped' => ['first_name', 'order_number', 'delivery_estimate', 'tracking_url'],
            // Hi {{1}}, your order #{{2}} has been cancelled...
            'ecommerce_order_cancelled' => ['first_name', 'order_number'],
            // Thank you {{1}}! Your COD order #{{2}} for {{3}}...
            'ecommerce_order_confirmed' => ['first_name', 'order_number', 'order_total'],
            // Hi {{1}}, ... {{2}} off ... code {{3}}. Offer valid until {{4}}.
            'ecommerce_winback' => ['first_name', 'discount', 'discount_code', 'offer_expiry'],
            // Hi {{1}}, ... order #{{2}}. ... {{3}} — your feedback...
            'ecommerce_review_request' => ['first_name', 'order_number', 'review_url'],
            // Hi {{1}}, I am the founder of {{2}} ... VIP order #{{3}}.
            'ecommerce_vip_thanks' => ['first_name', 'store_name', 'order_number'],
            // Hi {{1}}, you left {{2}} worth of items ... {{3}} — your items...
            'ecommerce_abandoned_cart' => ['first_name', 'cart_total', 'recovery_url'],
        ];
    }

    /**
     * Ordered body variable values for a template, sized to the template's
     * actual body variable count (custom templates fall back to a generic
     * context-driven fill so the parameter count always matches).
     *
     * @param  array<string, mixed>  $context  order/cart context from the webhook
     * @return list<string>
     */
    public static function bodyValues(WhatsappTemplate $template, Contact $contact, array $context, EcommerceStore $store): array
    {
        $resolved = self::resolveContext($contact, $context, $store);
        $count = self::bodyVariableCount($template);

        if ($count === 0) {
            return [];
        }

        $map = self::variableMap()[$template->name] ?? null;

        if ($map !== null) {
            $values = [];

            foreach ($map as $key) {
                $values[] = (string) ($resolved[$key]['value'] ?? '');
            }

            // Template body may have been edited to hold more variables
            // than the known map — pad from the generic pool.
            if (count($values) < $count) {
                $values = array_merge($values, array_values(array_diff(
                    array_column(array_values($resolved), 'value'),
                    $values
                )));
            }

            return array_slice(array_pad($values, $count, 'your order'), 0, $count);
        }

        // Unknown/custom template: first_name, then context values in order.
        $pool = array_map(fn ($item) => (string) $item['value'], array_values($resolved));

        return array_pad(array_slice($pool, 0, $count), $count, 'your order');
    }

    /**
     * Full send-payload components for a template: body parameters sized to
     * the template plus URL-button parameters when the button URL contains
     * a variable.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public static function sendComponents(WhatsappTemplate $template, Contact $contact, array $context, EcommerceStore $store): array
    {
        $components = [];

        $bodyValues = self::bodyValues($template, $contact, $context, $store);

        if ($bodyValues !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    $bodyValues
                ),
            ];
        }

        $urlButtonParams = self::urlButtonParameters($template, $resolved ?? self::resolveContext($contact, $context, $store));

        if ($urlButtonParams !== []) {
            $components[] = $urlButtonParams;
        }

        return $components;
    }

    /**
     * Base URL used for default template button links. Prefers the
     * workspace's first connected store domain, falling back to the app URL.
     */
    public static function defaultBaseUrl(int $workspaceId): string
    {
        $domain = EcommerceStore::where('workspace_id', $workspaceId)
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->orderBy('id')
            ->value('domain');

        if (is_string($domain) && $domain !== '') {
            return 'https://'.preg_replace('#^https?://#i', '', rtrim($domain, '/'));
        }

        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, array{value: string}>
     */
    private static function resolveContext(Contact $contact, array $context, EcommerceStore $store): array
    {
        $domainUrl = self::storeUrl($store);

        return [
            'first_name' => ['value' => $contact->first_name ?: 'there'],
            'order_number' => ['value' => $context['order_number'] ?? $context['order_id'] ?? 'your order'],
            'order_total' => ['value' => $context['order_total'] ?? $context['cart_total'] ?? 'your total'],
            'cart_total' => ['value' => $context['cart_total'] ?? $context['order_total'] ?? 'your items'],
            'recovery_url' => ['value' => self::url($context['recovery_url'] ?? null, $domainUrl.'/cart')],
            'tracking_url' => ['value' => self::url($context['tracking_url'] ?? null, $domainUrl)],
            'review_url' => ['value' => self::url($context['review_url'] ?? null, $domainUrl)],
            'delivery_estimate' => ['value' => $context['delivery_estimate'] ?? now()->addDays(5)->format('D, d M')],
            'discount' => ['value' => $context['discount'] ?? '15%'],
            'discount_code' => ['value' => $context['discount_code'] ?? 'WELCOME15'],
            'offer_expiry' => ['value' => $context['offer_expiry'] ?? now()->addDays(7)->format('M j')],
            'store_name' => ['value' => $store->name ?: 'our store'],
        ];
    }

    /** Count of positional variables declared in the template's body component. */
    private static function bodyVariableCount(WhatsappTemplate $template): int
    {
        foreach ((array) $template->components ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                preg_match_all('/\{\{\d+\}\}/', (string) ($component['text'] ?? ''), $m);

                return count($m[0]);
            }
        }

        return 0;
    }

    /**
     * Build a URL button parameter component when a URL button's URL
     * contains a variable; static URLs need no send-time parameter.
     */
    private static function urlButtonParameters(WhatsappTemplate $template, array $resolved): ?array
    {
        foreach ((array) $template->components ?? [] as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) !== 'BUTTONS') {
                continue;
            }

            foreach ((array) ($component['buttons'] ?? []) as $index => $button) {
                $url = (string) ($button['url'] ?? '');

                if (strtoupper((string) ($button['type'] ?? '')) !== 'URL' || ! str_contains($url, '{{')) {
                    continue;
                }

                $value = str_contains($url, 'track')
                    ? $resolved['tracking_url']['value']
                    : ($resolved['recovery_url']['value']);

                return [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => (string) $index,
                    'parameters' => [['type' => 'text', 'text' => (string) $value]],
                ];
            }
        }

        return null;
    }

    private static function storeUrl(EcommerceStore $store): string
    {
        $domain = trim((string) ($store->domain ?? ''));

        if ($domain === '') {
            return rtrim((string) config('app.url'), '/');
        }

        return 'https://'.preg_replace('#^https?://#i', '', rtrim($domain, '/'));
    }

    private static function url(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return $fallback;
        }

        return preg_match('#^https?://#i', $value) === 1 ? $value : 'https://'.$value;
    }
}
