<?php

namespace App\Modules\AI\Services;

/**
 * Root-level emoji intelligence for the AI engine.
 *
 * The LLM understands emoji on its own, but everything AROUND the model is
 * text machinery: keyword retrieval strips punctuation (emoji die at
 * preg_replace('/[^a-z0-9\s]/')), the hybrid ranker never sees them, the
 * system prompt has no signal about them, and run metadata records nothing.
 * This engine closes that gap so the WHOLE pipeline — not just the neural
 * net — understands emoji:
 *
 *   1. normalize()      → "😍" becomes "love it" inside the KB-search query
 *                         and the embedded text, so retrieval matches KB
 *                         entries written in plain words.
 *   2. analyze()        → sentiment, intent and confidence used for the
 *                         prompt layer and the run meta (debugging/eval).
 *   3. promptLayer()    → a deterministic instruction block for the system
 *                         prompt: mirror-emoji rule, strong-emotion handling
 *                         (😤🔥😡 shifts priority toward handover/de-escalation
 *                         even for weak models).
 *   4. expandKeywords() → semantic keywords harvested from emoji join the
 *                         keyword-retrieval expansion set.
 *
 * The map is intentionally curated (high-frequency customer-service emoji,
 * each with a 1-3 word canonical sense + keywords) instead of a giant
 * auto-generated table: coverage of the 80/20 beats bloat here.
 */
class EmojiEngine
{
    /**
     * Canonical customer-service emoji → [semantic phrase, keywords, sentiment, intensity 1-3].
     * Sentiment: positive | negative | neutral | angry | urgent.
     */
    private const MAP = [
        '😀' => ['grinning happy', ['happy', 'glad'], 'positive', 1],
        '😃' => ['very happy', ['happy', 'excited'], 'positive', 2],
        '😄' => ['laughing happy', ['happy', 'joy'], 'positive', 2],
        '😁' => ['beaming happy', ['happy'], 'positive', 2],
        '😊' => ['warm pleased smile', ['happy', 'pleased', 'satisfied'], 'positive', 2],
        '🙂' => ['slight smile', ['ok', 'fine'], 'positive', 1],
        '😉' => ['wink friendly', ['friendly'], 'positive', 1],
        '😍' => ['love it', ['love', 'adore', 'amazing'], 'positive', 3],
        '🥰' => ['feeling loved', ['love', 'wonderful'], 'positive', 3],
        '😘' => ['affectionate kiss', ['love'], 'positive', 2],
        '🤩' => ['amazed excited', ['amazing', 'wow', 'excited'], 'positive', 3],
        '😎' => ['cool satisfied', ['cool', 'great'], 'positive', 2],
        '🤝' => ['deal agreed', ['deal', 'agreed', 'confirm'], 'positive', 2],
        '👍' => ['thumbs up approved', ['ok', 'good', 'approved', 'yes'], 'positive', 2],
        '👏' => ['applause praise', ['great', 'well done'], 'positive', 2],
        '🙏' => ['please or thanks', ['please', 'thanks', 'request'], 'neutral', 1],
        '🫶' => ['heart hands appreciation', ['love', 'thanks'], 'positive', 2],
        '❤' => ['love heart', ['love'], 'positive', 3],
        '❤️' => ['love heart', ['love'], 'positive', 3],
        '💔' => ['heartbroken disappointed', ['disappointed', 'sad'], 'negative', 2],
        '😢' => ['crying sad', ['sad', 'upset'], 'negative', 2],
        '😭' => ['crying loudly very upset', ['crying', 'very upset', 'terrible'], 'negative', 3],
        '😥' => ['sad worried', ['sad', 'worried'], 'negative', 1],
        '😞' => ['disappointed', ['disappointed'], 'negative', 2],
        '😟' => ['worried', ['worried'], 'negative', 1],
        '😕' => ['confused', ['confused', 'unclear'], 'neutral', 1],
        '🤔' => ['thinking unsure', ['thinking', 'unsure', 'maybe'], 'neutral', 1],
        '😐' => ['neutral unimpressed', ['meh', 'unimpressed'], 'neutral', 1],
        '😑' => ['expressionless unimpressed', ['meh'], 'neutral', 1],
        '🙄' => ['eye roll annoyed', ['annoyed', 'frustrated'], 'negative', 2],
        '😒' => ['unamused annoyed', ['annoyed', 'unhappy'], 'negative', 2],
        '😤' => ['huffing frustrated angry', ['frustrated', 'angry'], 'angry', 3],
        '😡' => ['angry rage', ['angry', 'furious'], 'angry', 3],
        '🤬' => ['swearing angry', ['angry', 'abusive'], 'angry', 3],
        '😠' => ['mad angry', ['angry', 'mad'], 'angry', 2],
        '😰' => ['anxious worried', ['worried', 'anxious'], 'negative', 2],
        '😨' => ['fearful worried', ['worried', 'scared'], 'negative', 2],
        '🤯' => ['mind blown overwhelmed', ['overwhelmed', 'shocked'], 'negative', 2],
        '😱' => ['shocked scared', ['shocked', 'scared'], 'negative', 2],
        '🫡' => ['saluting acknowledged', ['ok', 'understood'], 'neutral', 1],
        '🤗' => ['hug supportive', ['thanks', 'friendly'], 'positive', 2],
        '🤦' => ['facepalm frustrated', ['frustrated', 'annoyed'], 'negative', 2],
        '🤷' => ['shrug uncertain', ['unsure', 'no idea'], 'neutral', 1],
        '⏰' => ['alarm clock time', ['time', 'deadline', 'late'], 'urgent', 1],
        '⌚' => ['watch time', ['time'], 'urgent', 1],
        '⏳' => ['hourglass waiting urgent', ['waiting', 'urgent', 'delay'], 'urgent', 2],
        '🚨' => ['alarm urgent emergency', ['urgent', 'emergency', 'asap'], 'urgent', 3],
        '❗' => ['urgent important', ['urgent', 'important'], 'urgent', 2],
        '‼' => ['very urgent important', ['urgent', 'important'], 'urgent', 3],
        '❓' => ['question unsure', ['question', 'confused'], 'neutral', 1],
        '💰' => ['money bag price', ['price', 'cost', 'money'], 'neutral', 1],
        '🛒' => ['shopping cart order', ['order', 'buy', 'cart', 'purchase'], 'neutral', 1],
        '📦' => ['package delivery', ['delivery', 'package', 'parcel', 'shipping'], 'neutral', 1],
        '🚚' => ['delivery truck shipping', ['delivery', 'shipping', 'courier'], 'neutral', 1],
        '🎁' => ['gift present', ['gift', 'present', 'discount'], 'neutral', 1],
        '🔥' => ['fire excellent or urgent', ['great', 'hot', 'urgent'], 'positive', 2],
        '💯' => ['hundred perfect', ['perfect', 'excellent'], 'positive', 2],
        '✅' => ['check mark done', ['done', 'confirmed', 'ok'], 'positive', 1],
        '✔' => ['check mark yes', ['yes', 'ok'], 'positive', 1],
        '☑' => ['checked box agreed', ['agreed', 'ok'], 'positive', 1],
        '✖' => ['cross no', ['no', 'wrong', 'cancel'], 'negative', 2],
        '❌' => ['cross wrong', ['no', 'wrong', 'cancel', 'problem'], 'negative', 2],
        '⚠' => ['warning caution', ['warning', 'problem', 'attention'], 'urgent', 2],
        '⚠️' => ['warning caution', ['warning', 'problem', 'attention'], 'urgent', 2],
        '🆘' => ['rescue help urgent', ['help', 'urgent', 'emergency'], 'urgent', 3],
        '🆗' => ['ok button', ['ok'], 'neutral', 1],
        '🆕' => ['new', ['new'], 'neutral', 1],
        '📞' => ['phone call', ['call', 'phone', 'contact'], 'neutral', 1],
        '💬' => ['chat message', ['chat', 'message', 'talk'], 'neutral', 1],
        '⭐' => ['star quality', ['best', 'quality', 'rating'], 'positive', 1],
        '🌟' => ['glowing star excellent', ['excellent', 'best'], 'positive', 2],
        '🎉' => ['party celebration', ['celebrate', 'congrats', 'excited'], 'positive', 2],
        '💪' => ['strong muscle', ['strong', 'support'], 'positive', 1],
        '👀' => ['looking interested', ['looking', 'interested'], 'neutral', 1],
        '🧐' => ['inspecting curious', ['curious', 'checking'], 'neutral', 1],
        '🤑' => ['money face deal', ['cheap', 'deal', 'discount'], 'neutral', 1],
        '😷' => ['mask sick', ['sick', 'ill'], 'neutral', 1],
        '🤒' => ['sick fever', ['sick', 'fever'], 'neutral', 1],
        '🤕' => ['hurt injured', ['hurt', 'injured'], 'negative', 1],
        '🍔' => ['food burger', ['food', 'burger', 'meal'], 'neutral', 1],
        '🍕' => ['pizza food', ['food', 'pizza'], 'neutral', 1],
        '☕' => ['coffee drink', ['coffee', 'drink'], 'neutral', 1],
        '🍰' => ['cake dessert', ['cake', 'dessert', 'sweet'], 'neutral', 1],
        '👗' => ['dress clothing', ['dress', 'clothes', 'fashion'], 'neutral', 1],
        '👟' => ['shoes footwear', ['shoes', 'footwear'], 'neutral', 1],
        '📱' => ['phone device', ['phone', 'mobile', 'device'], 'neutral', 1],
        '💻' => ['laptop computer', ['laptop', 'computer'], 'neutral', 1],
        '🎧' => ['headphones audio', ['headphones', 'audio'], 'neutral', 1],
        '📷' => ['camera photo', ['camera', 'photo'], 'neutral', 1],
        '🚗' => ['car vehicle', ['car', 'vehicle'], 'neutral', 1],
        '🏍' => ['motorcycle', ['motorcycle', 'bike'], 'neutral', 1],
        '🏠' => ['house home', ['house', 'home'], 'neutral', 1],
        '🔑' => ['key access', ['key', 'access'], 'neutral', 1],
        '⚡' => ['lightning fast power', ['fast', 'power', 'energy'], 'neutral', 1],
        '🕐' => ['clock one oclock', ['time', 'oclock'], 'urgent', 1],
    ];

    /** Emoji that directly signal escalation/handover when intense. */
    private const ANGER_EMOJI = ['😡', '🤬', '😤', '😠', '🤦'];
    private const URGENT_EMOJI = ['🚨', '🆘', '‼', '❗', '⏳'];

    /** Match any emoji class broadly (symbols, pictographs, dingbats, CJK-adjacent blocks). */
    private const EMOJI_REGEX =
        '/[\x{1F300}-\x{1FAFF}\x{1F000}-\x{1F0FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{1F900}-\x{1F9FF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}]/u';

    /**
     * Replace every known emoji with its semantic phrase so downstream text
     * machinery (embeddings, LIKE keyword search) sees words, not dead
     * codepoints. Unknown emoji are stripped from the returned query — they
     * are noise for retrieval but their signals still flow through analyze().
     *
     * "Package 📦 kab aayega?" → "Package package delivery kab aayega?"
     */
    public function normalize(string $text): string
    {
        $normalized = preg_replace_callback(self::EMOJI_REGEX, function (array $m) {
            $entry = self::MAP[$m[0]] ?? null;

            return $entry !== null ? ' '.$entry[0].' ' : ' ';
        }, $text) ?? $text;

        // Collapse the whitespace the substitutions introduced.
        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    /**
     * Full emoji analysis of a message.
     *
     * @return array{
     *   has_emoji: bool, count: int,
     *   sentiment: ?string, intensity: int, confident: bool,
     *   intents: list<string>, labels: list<string>, keywords: list<string>
     * }
     */
    public function analyze(string $text): array
    {
        preg_match_all(self::EMOJI_REGEX, $text, $matches);
        $found = $matches[0];

        if ($found === []) {
            return [
                'has_emoji' => false, 'count' => 0,
                'sentiment' => null, 'intensity' => 0, 'confident' => false,
                'intents' => [], 'labels' => [], 'keywords' => [],
            ];
        }

        $sentimentScores = [];
        $intents = [];
        $labels = [];
        $keywords = [];
        $maxIntensity = 0;

        foreach ($found as $emoji) {
            $entry = self::MAP[$emoji] ?? null;
            if ($entry === null) {
                continue;
            }

            [$phrase, $kws, $sentiment, $intensity] = $entry;
            $labels[] = $phrase;
            foreach ($kws as $kw) {
                $keywords[$kw] = true;
            }
            $sentimentScores[$sentiment] = ($sentimentScores[$sentiment] ?? 0) + $intensity;
            $maxIntensity = max($maxIntensity, $intensity);

            if (in_array($emoji, self::ANGER_EMOJI, true)) {
                $intents[] = 'escalation_risk';
            }
            if (in_array($emoji, self::URGENT_EMOJI, true)) {
                $intents[] = 'urgency';
            }
            if ($phrase === 'deal agreed' || $phrase === 'check mark done') {
                $intents[] = 'agreement';
            }
            if (in_array($phrase, ['money bag price', 'shopping cart order', 'package delivery', 'delivery truck shipping'], true)) {
                $intents[] = 'purchase_or_delivery';
            }
        }

        arsort($sentimentScores);
        $dominant = array_key_first($sentimentScores);
        $total = array_sum($sentimentScores);

        // Confidence: single-sentiment emoji with meaningful intensity beat
        // mixed signals (😂😡 is ambiguous, 😡😡 is not).
        $confident = $total > 0 && ($sentimentScores[$dominant] / $total) >= 0.7 && $maxIntensity >= 2;

        return [
            'has_emoji' => true,
            'count' => count($found),
            'sentiment' => $dominant,
            'intensity' => $maxIntensity,
            'confident' => $confident,
            'intents' => array_values(array_unique($intents)),
            'labels' => array_values(array_unique($labels)),
            'keywords' => array_keys($keywords),
        ];
    }

    /**
     * Deterministic emoji guidance for the system prompt — only emitted when
     * the message actually carries emoji, so prompt tokens stay clean.
     * Written to steer even weak local models (Ollama-class) that underuse
     * emoji context.
     *
     * @param  array<string, mixed>  $analysis
     */
    public function promptLayer(array $analysis): ?string
    {
        if (! ($analysis['has_emoji'] ?? false)) {
            return null;
        }

        $lines = ['EMOJI SIGNAL (from engine, not the model — treat as ground truth about this message):'];

        if ($analysis['confident'] && in_array($analysis['sentiment'], ['angry', 'negative'], true)) {
            $lines[] = '- The customer is visibly UPSET (negative emoji, intensity '.$analysis['intensity'].'/3). Lead with empathy, apologize once, give one concrete next step. If intensity is 3/3, treat as a handover candidate.';
        } elseif ($analysis['confident'] && $analysis['sentiment'] === 'positive') {
            $lines[] = '- The customer is in a GOOD mood (positive emoji). Match their energy briefly; this is a good moment to suggest the next step or an add-on.';
        }

        $intents = (array) ($analysis['intents'] ?? []);

        if (in_array('urgency', $intents, true)) {
            $lines[] = '- URGENT emoji detected (🚨/❗/⏳ class). Acknowledge the time pressure explicitly and answer the core question first.';
        }
        if (in_array('escalation_risk', $intents, true)) {
            $lines[] = '- ANGER emoji detected. Never mirror anger; de-escalate and prepare for human handover.';
        }
        if (in_array('purchase_or_delivery', $intents, true)) {
            $lines[] = '- Shopping/delivery emoji detected — the message likely concerns an order, delivery or price; check the order/product context first.';
        }
        $labels = (array) ($analysis['labels'] ?? []);
        if ($labels !== []) {
            $lines[] = '- Emoji meanings per this engine: '.implode('; ', array_slice($labels, 0, 5)).'.';
        }

        return count($lines) > 1 ? implode("\n", $lines) : null;
    }

    /**
     * Emoji-derived keywords for the retrieval expansion set — the twin of
     * ChatbotRunner::expandKeywords() so "😍" can surface "amazing"-keyword
     * KB chunks even though LIKE never sees the emoji itself.
     *
     * @param  array<string, mixed>  $analysis
     * @return list<string>
     */
    public function expandKeywords(array $analysis): array
    {
        $keywords = $analysis['keywords'] ?? [];

        return is_array($keywords) ? array_values($keywords) : [];
    }

    /**
     * Compact meta block for run diagnostics / eval.
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    public function metaFor(array $analysis): array
    {
        if (! ($analysis['has_emoji'] ?? false)) {
            return [];
        }

        return [
            'emoji_count' => $analysis['count'],
            'emoji_sentiment' => $analysis['sentiment'],
            'emoji_intensity' => $analysis['intensity'],
            'emoji_intents' => $analysis['intents'],
        ];
    }
}
