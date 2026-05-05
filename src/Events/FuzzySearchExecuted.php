<?php

namespace Ashiqfardus\LaravelFuzzySearch\Events;

class FuzzySearchExecuted
{
    public function __construct(
        public readonly string $searchTerm,
        public readonly array  $columns,
        public readonly string $algorithm,
        public readonly int    $candidateCount,
        public readonly float  $latencyMs,
    ) {}
}
