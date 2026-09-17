<?php

namespace Ashiqfardus\LaravelFuzzySearch\Events;

/**
 * Fired once per executed search (get(), paginate(), in-memory). The first five parameters are
 * the v2.0 shape; the rest were appended in v2.1 with defaults so existing listeners and
 * third-party dispatchers keep working.
 */
class FuzzySearchExecuted
{
    public function __construct(
        public readonly string  $searchTerm,
        public readonly array   $columns,
        public readonly string  $algorithm,
        public readonly int     $candidateCount,
        public readonly float   $latencyMs,
        public readonly int     $resultCount = -1,      // rows returned to the caller; -1 = unknown
        public readonly string  $path = 'like',         // like | bm25 | extended | in_memory
        public readonly ?string $modelClass = null,     // Eloquent model searched, null for query-builder/in-memory searches
    ) {}
}
