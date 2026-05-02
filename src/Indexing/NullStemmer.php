<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

class NullStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return $word;
    }
}
