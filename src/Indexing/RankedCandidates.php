<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Checks a BM25 ranking against a (possibly constrained) Eloquent query without losing
 * rank order. Ids are visited best-first in chunks and only rows the query returns are
 * kept, so a selective filter fills its page from lower-ranked matches instead of
 * coming back short, and totals reflect the constraints.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class RankedCandidates
{
    /** COUNT chunk — stays under SQLite's 999 and SQL Server's 2100 bind-parameter limits. */
    private const COUNT_CHUNK = 500;

    /**
     * Models satisfying $base, in rank order, stopping once $needed have been collected.
     *
     * @param  array<int|string> $rankedIds best first
     */
    public static function models(Builder $base, array $rankedIds, ?int $needed = null, ?int $chunkSize = null): EloquentCollection
    {
        $key       = $base->getModel()->getQualifiedKeyName();
        $collected = [];

        foreach (array_chunk($rankedIds, self::chunkSize($chunkSize)) as $chunk) {
            $found = self::among($base, $key, $chunk)->get()->keyBy(fn ($model) => $model->getKey());

            foreach ($chunk as $id) {
                if (isset($found[$id])) {
                    $collected[] = $found[$id];
                }
            }

            if ($needed !== null && count($collected) >= $needed) {
                break;
            }
        }

        return $base->getModel()->newCollection($collected);
    }

    /**
     * Primary keys satisfying $base, in rank order — for engines that hydrate models
     * separately (Scout's map()).
     *
     * @param  array<int|string> $rankedIds best first
     * @return array<int|string>
     */
    public static function keys(Builder $base, array $rankedIds, ?int $needed = null, ?int $chunkSize = null): array
    {
        $key       = $base->getModel()->getQualifiedKeyName();
        $collected = [];

        foreach (array_chunk($rankedIds, self::chunkSize($chunkSize)) as $chunk) {
            $found = array_flip(self::among($base, $key, $chunk)->pluck($key)->all());

            foreach ($chunk as $id) {
                if (isset($found[$id])) {
                    $collected[] = $id;
                }
            }

            if ($needed !== null && count($collected) >= $needed) {
                break;
            }
        }

        return $collected;
    }

    /**
     * How many of the ranked ids satisfy $base. ORDER BY / LIMIT on the base query are
     * dropped for the count (PostgreSQL rejects an ORDER BY on a bare aggregate).
     *
     * @param  array<int|string> $rankedIds
     */
    public static function count(Builder $base, array $rankedIds): int
    {
        $key   = $base->getModel()->getQualifiedKeyName();
        $total = 0;

        foreach (array_chunk($rankedIds, self::COUNT_CHUNK) as $chunk) {
            $total += (int) self::among($base, $key, $chunk)->toBase()->getCountForPagination();
        }

        return $total;
    }

    /**
     * $base restricted to the ids in $chunk. The whereIn is added the way Eloquent adds a global
     * scope, so a caller's ungrouped where(A)->orWhere(B) is wrapped in parentheses first:
     * appended plainly, the whereIn bound to B alone (A OR (B AND id IN …)), which counted every
     * row matching A and fetched them all for each chunk.
     *
     * @param  array<int|string> $chunk
     */
    private static function among(Builder $base, string $key, array $chunk): Builder
    {
        return (clone $base)->withGlobalScope(self::class, fn (Builder $query) => $query->whereIn($key, $chunk));
    }

    private static function chunkSize(?int $override): int
    {
        return max(1, $override ?? (int) config('fuzzy-search.bm25.candidate_chunk', 200));
    }
}
