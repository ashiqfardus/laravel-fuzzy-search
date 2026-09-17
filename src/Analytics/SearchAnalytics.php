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

    private static function since(int $days): \Illuminate\Support\Carbon
    {
        return now()->subDays(max(0, $days))->startOfDay();
    }

    /** @return array<int, array{term: string, searches: int, avg_results: float}> */
    public static function popular(int $days = 30, int $limit = 10): array
    {
        return DB::table(static::table())
            ->where('created_at', '>=', static::since($days))
            ->groupBy('normalized_term')
            ->selectRaw('normalized_term, COUNT(*) as searches, AVG(result_count) as avg_results')
            ->orderByDesc('searches')->orderBy('normalized_term')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['term' => (string) $r->normalized_term, 'searches' => (int) $r->searches, 'avg_results' => (float) $r->avg_results])
            ->all();
    }

    /** Terms whose every search in the window returned nothing. @return array<int, array{term: string, searches: int}> */
    public static function zeroResults(int $days = 30, int $limit = 10): array
    {
        return DB::table(static::table())
            ->where('created_at', '>=', static::since($days))
            ->groupBy('normalized_term')
            ->selectRaw('normalized_term, COUNT(*) as searches, MAX(result_count) as best')
            ->havingRaw('MAX(result_count) = 0')
            ->orderByDesc('searches')->orderBy('normalized_term')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['term' => (string) $r->normalized_term, 'searches' => (int) $r->searches])
            ->all();
    }

    /** @return array<string, float> path => average latency in ms */
    public static function averageLatency(int $days = 30): array
    {
        return DB::table(static::table())
            ->where('created_at', '>=', static::since($days))
            ->groupBy('path')
            ->selectRaw('path, AVG(latency_ms) as avg_ms')
            ->orderBy('path')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->path => round((float) $r->avg_ms, 2)])
            ->all();
    }

    /** @return array<string, int> 'YYYY-MM-DD' => searches, ascending */
    public static function volume(int $days = 30): array
    {
        return DB::table(static::table())
            ->where('created_at', '>=', static::since($days))
            ->groupBy('day')
            ->selectRaw('day, COUNT(*) as searches')
            ->orderBy('day')
            ->get()
            ->mapWithKeys(fn ($r) => [substr((string) $r->day, 0, 10) => (int) $r->searches])
            ->all();
    }

    public static function prune(?int $days = null): int
    {
        $days ??= (int) config('fuzzy-search.analytics.retention_days', 30);

        return DB::table(static::table())->where('created_at', '<', now()->subDays($days))->delete();
    }
}
