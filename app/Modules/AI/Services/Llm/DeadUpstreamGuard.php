<?php

namespace App\Modules\AI\Services\Llm;

/**
 * Detects "dead" OmniRoute-style upstreams from an OpenAI-compatible response.
 *
 * Scraped/free upstreams (DuckDuckGo "ddgw/*" models, Antigravity IDE sessions,
 * etc.) fail in two ways that a plain HTTP status check cannot catch:
 *
 *  1. They answer every prompt with the SAME canned greeting ("Hello! How can
 *     I assist you today?") while completely ignoring the system prompt —
 *     so the bot greets customers who asked about prices or products.
 *  2. They return upstream failures (418 anti-abuse, rate limits) wrapped in
 *     an HTTP 200 body with an "error" key instead of a completion.
 *
 * Both must be treated as failures so callers fall back instead of sending
 * garbage to customers.
 */
class DeadUpstreamGuard
{
    /**
     * Greetings that dead scraped upstreams emit regardless of the prompt.
     * Matched case-insensitively on a trimmed reply.
     */
    public const CANNED_GREETINGS = [
        'hello! how can i assist you today?',
        'hello! how can i help you today?',
        'hello! welcome! how can i help you today?',
        "hello! i'm here to help. how can i assist you today?",
        'hi! how can i assist you today?',
        'hi there! how can i help you today?',
        'sorry to hear that. how can i assist you better?',
        'i apologize for the inconvenience. how can i assist you further?',
    ];

    /**
     * Inspect a decoded chat.completion response body.
     *
     * @param  array|null  $json  Decoded response body.
     * @param  string|null  $content  Extracted assistant content.
     * @param  string|null  $lastUserMessage  The user's actual message. A canned
     *      greeting is only "dead" when the user did NOT just greet — if the
     *      customer said "hi", a greeting reply is healthy behaviour.
     * @return string|null Failure reason, or null when the response is healthy.
     */
    public static function inspect(?array $json, ?string $content, ?string $lastUserMessage = null): ?string
    {
        $content = trim((string) $content);

        if ($content === '') {
            return 'empty_content';
        }

        // 1. Upstream failure wrapped as HTTP 200 ("error" key instead of choices)
        if (isset($json['error']) && ! isset($json['choices'])) {
            $msg = $json['error']['message'] ?? json_encode($json['error']);

            return 'upstream_error: '.mb_substr((string) $msg, 0, 160);
        }

        // 2. Canned greeting loop — reply is an exact canned greeting AND the
        //    customer asked something real (not a greeting of their own).
        $userGreeted = $lastUserMessage !== null && self::isSimpleGreeting($lastUserMessage);

        if (! $userGreeted && self::isCannedGreeting($content)) {
            return 'canned_greeting_loop';
        }

        return null;
    }

    /** True when the reply is (only) a known canned greeting from a dead upstream. */
    public static function isCannedGreeting(string $content): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $content) ?? $content));
        // Strip a trailing markdown emphasis if the upstream dressed it up
        $normalized = trim($normalized, "*_ \t");

        return in_array($normalized, self::CANNED_GREETINGS, true);
    }

    /**
     * True when the user's message is itself a short greeting/small-talk opener,
     * making a greeting-style reply legitimate rather than a dead-upstream loop.
     */
    public static function isSimpleGreeting(string $message): bool
    {
        $m = mb_strtolower(trim($message));

        if ($m === '' || mb_strlen($m) > 30) {
            return false;
        }

        return (bool) preg_match(
            '/^(hi+|hello+|hey+|aoa|as+salam+|assalamu?a+laikum+|salam+|good\s*(morning|evening|afternoon)|salaam|namaste|hy)\b[!., ]*$/i',
            $m
        );
    }

    /** Extract the last user-role message content from a chat messages array. */
    public static function lastUserMessage(array $messages): ?string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return $messages[$i]['content'] ?? null;
            }
        }

        return null;
    }

    /** True when a non-2xx HTTP body looks like a real upstream failure we should surface. */
    public static function isUpstreamErrorBody(string $body): bool
    {
        return str_contains($body, 'anti-abuse challenge')
            || str_contains($body, 'ERR_BN_LIMIT')
            || (str_contains($body, '"error"') && str_contains($body, 'No active credentials'));
    }
}
