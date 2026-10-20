<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

/**
 * Leaves every word as it is: the default stemmer (config('fuzzy-search.indexing.stemmer')).
 */
class NullStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return $word;
    }
}
