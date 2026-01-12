<?php

namespace Ashiqfardus\LaravelFuzzySearch\Exceptions;

class InvalidAlgorithmException extends LaravelFuzzySearchException
{
    public function __construct(string $algorithm)
    {
        parent::__construct(
            "Invalid search algorithm '{$algorithm}'. Supported algorithms are: 'fuzzy', 'levenshtein', 'soundex', 'trigram', 'simple'."
        );
    }
}

