<?php

namespace Ashiqfardus\LaravelFuzzySearch\Analytics;

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Persisted search analytics over the fuzzy_search_logs table. record()/rowFor() are used by
 * the listener and job; the query methods (popular(), zeroResults(), averageLatency(),
 * volume(), prune()) are the public API behind the SearchAnalytics facade.
 */
class SearchAnalytics
{
    public static function table(): string
    {
        return (string) config('fuzzy-search.analytics.table', 'fuzzy_search_logs');
    }

    /** Lower-case and collapse whitespace so "  JoHn   Doe " and "john doe" count as one term. */
    public static function normalize(string $term): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $term) ?? $term), 'UTF-8');
    }

    /** The row a FuzzySearchExecuted event becomes (hashing applied here, once). */
    public static function rowFor(FuzzySearchExecuted $event): array
    {
        $normalized = static::normalize($event->searchTerm);
        $hash       = (bool) config('fuzzy-search.analytics.hash_terms', false);
        $now        = now();

        return [
            'term'            => $hash ? '' : mb_substr($event->searchTerm, 0, 255),
            'normalized_term' => $hash ? hash('sha256', $normalized) : mb_substr($normalized, 0, 255),
            'model_type'      => $event->modelClass,
            'algorithm'       => mb_substr($event->algorithm, 0, 32),
            'path'            => $event->path,
            'result_count'    => max(0, $event->resultCount),
            'latency_ms'      => $event->latencyMs,
            'day'             => $now->toDateString(),
            'created_at'      => $now,
        ];
    }

    public static function record(array $row): void
    {
        DB::table(static::table())->insert($row);
    }
}
