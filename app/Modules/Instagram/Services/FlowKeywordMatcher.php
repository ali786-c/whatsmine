<?php

namespace App\Modules\Instagram\Services;

use App\Modules\Instagram\Services\KeywordMatcher;

/**
 * Keyword matching for visual flows — delegates to the classic funnel matcher
 * so both systems share identical semantics (exact/contains, case-insensitive).
 */
class FlowKeywordMatcher
{
    /**
     * @param  array<int, string>  $keywords
     */
    public static function matches(array $keywords, string $text): bool
    {
        $keywords = array_values(array_filter(array_map(
            fn ($k) => trim((string) $k),
            $keywords,
        )));

        if ($keywords === [] || trim($text) === '') {
            return false;
        }

        return KeywordMatcher::matches($keywords, 'contains', $text);
    }
}
