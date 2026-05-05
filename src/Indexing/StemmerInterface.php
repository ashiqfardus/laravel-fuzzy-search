<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

interface StemmerInterface
{
    public function stem(string $word): string;
}
