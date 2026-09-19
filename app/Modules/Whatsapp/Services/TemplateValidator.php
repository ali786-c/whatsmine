<?php

namespace App\Modules\Whatsapp\Services;

use Illuminate\Support\Str;

/**
 * Validates template payloads against Meta's Template Fundamentals rules
 * before submission, so predictable mistakes never reach Meta review:
 *
 * - Names: lowercase alphanumeric + underscores, max 512 chars.
 * - Positional parameters: {{1}}, {{2}}, ... sequential, no gaps.
 * - Body/header text must not start or end with a variable.
 * - QUICK_REPLY button text: max 25 chars, no variables/emojis/formatting.
 * - URL button: domain must match a URL present in the body text.
 * - example.body_text must supply one example per parameter, in order.
 */
class TemplateValidator
{
    /** @return string[] list of human-readable problems; empty = valid */
    public static function problems(array $template): array
    {
        $problems = [];

        $name = $template['name'] ?? '';
        if ($name === '' || ! preg_match('/^[a-z0-9_]{1,512}$/', $name)) {
            $problems[] = "Name must be lowercase alphanumeric/underscores (got: {$name}).";
        }

        $language = $template['language'] ?? '';
        if ($language === '' || ! preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $language)) {
            $problems[] = "Language must be a valid code like en or en_US (got: {$language}).";
        }

        if (! in_array(strtoupper($template['category'] ?? ''), ['MARKETING', 'UTILITY', 'AUTHENTICATION'], true)) {
            $problems[] = 'Category must be MARKETING, UTILITY or AUTHENTICATION.';
        }

        $bodyText = null;
        $buttons = [];

        foreach ($template['components'] ?? [] as $component) {
            switch (strtoupper($component['type'] ?? '')) {
                case 'BODY':
                    $bodyText = $component['text'] ?? '';
                    $problems = array_merge($problems, self::bodyProblems($component));
                    break;

                case 'HEADER':
                    $problems = array_merge($problems, self::headerProblems($component));
                    break;

                case 'BUTTONS':
                    $buttons = $component['buttons'] ?? [];
                    $problems = array_merge($problems, self::buttonProblems($component));
                    break;
            }
        }

        $problems = array_merge(
            $problems,
            self::urlButtonDomainProblems($buttons, $bodyText),
            self::exampleProblems($template, $bodyText),
        );

        return $problems;
    }

    /** @return string[] */
    private static function bodyProblems(array $component): array
    {
        $text = $component['text'] ?? '';
        $problems = [];

        if (trim($text) === '') {
            return ['Body text is empty.'];
        }

        // Meta rejects bodies that start or end with a variable.
        if (preg_match('/^\s*\{\{\d+\}\}/', $text) || preg_match('/\{\{\d+\}\}\s*$/u', $text)) {
            $problems[] = "Body starts or ends with a variable — add words around it: \"{$text}\"";
        }

        $variables = self::extractPositionalVariables($text);
        $expected = range(1, count($variables));
        if ($variables !== $expected) {
            $problems[] = 'Positional variables must be {{1}}, {{2}}, ... sequential with no gaps.';
        }

        return $problems;
    }

    /** @return string[] */
    private static function headerProblems(array $component): array
    {
        $format = strtoupper($component['format'] ?? 'TEXT');

        if ($format === 'TEXT') {
            $text = $component['text'] ?? '';
            if (preg_match('/^\s*\{\{\d+\}\}/', $text) || preg_match('/\{\{\d+\}\}\s*$/u', $text)) {
                return ["Header text starts or ends with a variable: \"{$text}\""];
            }
            return [];
        }

        // MEDIA headers need an example media handle.
        if (empty($component['example']['header_handle'])) {
            return ['MEDIA header requires example.header_handle (upload via Resumable Upload API).'];
        }

        return [];
    }

    /** @return string[] */
    private static function buttonProblems(array $component): array
    {
        $problems = [];
        $buttons = $component['buttons'] ?? [];

        if (count($buttons) > 10) {
            $problems[] = 'A template supports at most 10 buttons.';
        }
        if (count($buttons) === 1 && strtoupper($buttons[0]['type'] ?? '') === 'QUICK_REPLY') {
            // allowed
        }

        foreach ($buttons as $i => $button) {
            $type = strtoupper($button['type'] ?? '');
            $text = trim($button['text'] ?? '');
            $label = $text !== '' ? $text : "(button #".($i + 1).")";

            if ($text === '') {
                $problems[] = "Button #".($i + 1)." has empty text.";
                continue;
            }

            if ($type === 'QUICK_REPLY') {
                if (mb_strlen($text) > 25) {
                    $problems[] = "Quick reply \"{$label}\" exceeds 25 characters (".mb_strlen($text).").";
                }
                // Meta: buttons can't have variables, newlines, emojis or formatting characters.
                if (preg_match('/\{\{?\d*\}\}/', $text) || str_contains($text, '{{')) {
                    $problems[] = "Quick reply \"{$label}\" can't contain variables.";
                }
                if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', $text)) {
                    $problems[] = "Quick reply \"{$label}\" can't contain emojis or formatting characters.";
                }
                if (preg_match('/[\r\n*_~`]/', $text)) {
                    $problems[] = "Quick reply \"{$label}\" can't contain newlines or formatting characters.";
                }
            }

            if ($type === 'URL' && ! empty($button['url']) && Str::contains($button['url'], '{{')) {
                // Variable URLs are allowed but must have an example; validated below via domain rule.
                if (empty($button['example'])) {
                    $problems[] = "URL button \"{$label}\" with a variable URL requires an example URL.";
                }
            }
        }

        return $problems;
    }

    /** Meta: URL button domain must match a domain used in the body text. */
    private static function urlButtonDomainProblems(array $buttons, ?string $bodyText): array
    {
        $problems = [];
        $bodyHosts = self::hostsIn($bodyText ?? '');

        foreach ($buttons as $i => $button) {
            if (strtoupper($button['type'] ?? '') !== 'URL') {
                continue;
            }
            $url = $button['url'] ?? '';
            if ($url === '' || Str::contains($url, '{{')) {
                continue; // variable URL — domain rule applies to the example instead
            }
            $host = self::hostOf($url);
            if ($host === null) {
                $problems[] = "URL button #".($i + 1)." has an invalid URL: {$url}";
                continue;
            }
            if ($bodyHosts !== [] && ! in_array($host, $bodyHosts, true)) {
                $problems[] = "URL button domain \"{$host}\" doesn't match any domain in the body (".implode(', ', $bodyHosts).').';
            }
        }

        return $problems;
    }

    /** @return string[] */
    private static function exampleProblems(array $template, ?string $bodyText): array
    {
        $problems = [];

        $bodyComponent = null;
        foreach ($template['components'] ?? [] as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                $bodyComponent = $component;
                break;
            }
        }

        if ($bodyComponent === null) {
            return $problems; // bodyProblems already flags empty body
        }

        $count = count(self::extractPositionalVariables($bodyText ?? ''));
        $examples = $bodyComponent['example']['body_text'][0] ?? [];

        if ($count > 0 && count($examples) < $count) {
            $problems[] = "Body has {$count} variables but only ".count($examples).' example value(s) — one example per variable, in order.';
        }

        if ($count === 0 && ! empty($bodyComponent['example'])) {
            $problems[] = 'Body has no variables but an example block is present — remove it.';
        }

        return $problems;
    }

    /** @return int[] e.g. [1, 2, 3] for "{{1}} ... {{2}} ... {{3}}" */
    private static function extractPositionalVariables(string $text): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', $text, $m);

        return array_map('intval', $m[1] ?? []);
    }

    /** @return string[] hosts of every http(s) URL found in the text */
    private static function hostsIn(string $text): array
    {
        preg_match_all('/https?:\/\/[^\s<>"]+/i', $text, $m);
        $hosts = [];
        foreach ($m[0] ?? [] as $url) {
            $host = self::hostOf($url);
            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
