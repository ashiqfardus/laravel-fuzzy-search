<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

/**
 * Whitespace tokenization for scripts with word boundaries, character n-grams for CJK runs,
 * in one pass — "Tokyo 東京 tower" → tokyo, 東京, tower. Runs are split by script so the same
 * string can hold both; order is preserved. Thai, Bengali, Devanagari and friends keep the
 * whitespace rule (their combining marks stay attached, as WhitespaceTokenizer guarantees).
 *
 * Opt-in: config('fuzzy-search.indexing.tokenizer') or $searchable['tokenizer'].
 */
class ScriptAwareTokenizer implements TokenizerInterface
{
    /**
     * U+3099/U+309A (combining dakuten/handakuten) are Script=Inherited and U+30FC/U+FF70 (the
     * prolonged sound marks) are Script=Common: \p{Katakana} only matches them on PCRE2 >= 10.40,
     * which resolves script extensions. Listing them keeps ソニー → ソニ, ニー on every PHP build.
     */
    public const CJK = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{3099}\x{309A}\x{30FC}\x{FF70}';

    private NgramTokenizer $ngram;
    private WhitespaceTokenizer $words;

    public function __construct(int $n = 2)
    {
        $this->ngram = new NgramTokenizer($n);
        $this->words = new WhitespaceTokenizer();
    }

    public function tokenize(string $text): array
    {
        // Invalid UTF-8 makes preg_match_all() return false and $m empty — the sibling tokenizers
        // lower-case first, which scrubs bad bytes; do the same so a stray byte never empties a row.
        preg_match_all('/[' . self::CJK . ']+|[^' . self::CJK . ']+/u', mb_scrub($text, 'UTF-8'), $m);

        $tokens = [];
        foreach ($m[0] as $segment) {
            $isCjk  = preg_match('/^[' . self::CJK . ']/u', $segment) === 1;
            $tokens = array_merge($tokens, $isCjk ? $this->ngram->tokenize($segment) : $this->words->tokenize($segment));
        }

        return $tokens;
    }
}
