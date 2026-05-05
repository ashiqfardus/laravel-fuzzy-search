<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

interface TokenizerInterface
{
    /**
     * Tokenize text into an array of lowercase word tokens.
     * Returns only tokens of at least 2 characters.
     *
     * @return string[]
     */
    public function tokenize(string $text): array;
}
