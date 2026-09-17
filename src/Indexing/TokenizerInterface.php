<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

interface TokenizerInterface
{
    /**
     * Tokenize text into an array of lowercase word tokens.
     * WhitespaceTokenizer returns only tokens of at least 2 characters; n-gram tokenizers may return single characters for runs shorter than n.
     *
     * @return string[]
     */
    public function tokenize(string $text): array;
}
