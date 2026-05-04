<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class NullStemmer implements StemmerInterface
{
    public function stem(string $word): string
    {
        return $word;
    }
}
