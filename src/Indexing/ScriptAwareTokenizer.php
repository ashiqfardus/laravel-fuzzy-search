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
    public const CJK = '\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}';

    private NgramTokenizer $ngram;
    private WhitespaceTokenizer $words;

    public function __construct(int $n = 2)
    {
        $this->ngram = new NgramTokenizer($n);
        $this->words = new WhitespaceTokenizer();
    }

    public function tokenize(string $text): array
    {
        preg_match_all('/[' . self::CJK . ']+|[^' . self::CJK . ']+/u', $text, $m);

        $tokens = [];
        foreach ($m[0] as $segment) {
            $isCjk  = preg_match('/^[' . self::CJK . ']/u', $segment) === 1;
            $tokens = array_merge($tokens, $isCjk ? $this->ngram->tokenize($segment) : $this->words->tokenize($segment));
        }

        return $tokens;
    }
}
