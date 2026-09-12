<?php

namespace App\Modules\Instagram\Services;

/**
 * Keyword matching for triggers and follow-gate replies.
 *
 * All matching is case-insensitive on normalized text (trimmed, whitespace
 * collapsed, zero-width characters stripped). Modes: contains (default),
 * exact, starts_with, regex — regex is evaluated defensively so a bad pattern
 * can never throw into the webhook pipeline.
 */
class KeywordMatcher
{
    /**
     * @param  array<int, string>|null  $keywords
     */
    public static function matches(?array $keywords, string $mode, string $text): bool
    {
        $keywords = array_values(array_filter(array_map(
            fn ($k) => mb_strtolower(trim((string) $k)),
            (array) $keywords,
        )));

        if ($keywords === []) {
            return false;
        }

        $haystack = self::normalize($text);

        foreach ($keywords as $needle) {
            $ok = match ($mode) {
                'exact' => $haystack === $needle,
                'starts_with' => str_starts_with($haystack, $needle),
                'regex' => self::safePregMatch($needle, $haystack),
                default => str_contains($haystack, $needle), // contains
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(string $text): string
    {
        // Strip zero-width chars + variation selectors, lowercase, collapse whitespace.
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FE0F}\x{FEFF}]/u', '', $text) ?? $text;
        $text = mb_strtolower(trim($text));

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    private static function safePregMatch(string $pattern, string $subject): bool
    {
        $delimited = '#'.str_replace('#', '\#', $pattern).'#u';

        return @preg_match($delimited, $subject) === 1;
    }
}
