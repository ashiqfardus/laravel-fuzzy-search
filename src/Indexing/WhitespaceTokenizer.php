<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class WhitespaceTokenizer implements TokenizerInterface
{
    public function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');

        // \p{M} keeps combining marks (Bengali/Hindi vowel signs, Thai tone marks, decomposed
        // accents) attached to their base letter. Without it every mark is a word boundary and
        // scripts that write vowels as marks are shredded into single consonants.
        $tokens = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($tokens, fn($t) => mb_strlen($t) >= 2));
    }
}
