<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

/**
 * Character n-grams for scripts without word boundaries (Chinese, Japanese, Korean).
 * Each run of letters/marks/digits is lower-cased and cut into every n-character window;
 * a run shorter than n is emitted whole. Duplicates are kept so term frequencies are right.
 *
 * Opt-in: config('fuzzy-search.indexing.tokenizer') or $searchable['tokenizer'].
 */
class NgramTokenizer implements TokenizerInterface
{
    public function __construct(private readonly int $n = 2)
    {
        if ($n < 1) {
            throw new \InvalidArgumentException("NgramTokenizer needs n >= 1, got {$n}.");
        }
    }

    public function tokenize(string $text): array
    {
        $runs   = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($runs as $run) {
            $chars = mb_str_split($run, 1, 'UTF-8');
            $count = count($chars);
            if ($count <= $this->n) {
                $tokens[] = $run;
                continue;
            }
            for ($i = 0; $i + $this->n <= $count; $i++) {
                $tokens[] = implode('', array_slice($chars, $i, $this->n));
            }
        }

        return $tokens;
    }
}
