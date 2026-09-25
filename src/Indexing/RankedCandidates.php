<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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

    /** The alias an ordered read selects the key under, beside the caller's select list (see orderedKeys()). */
    private const KEY_ALIAS = 'fuzzy_walk_key';

    /** The scope matches() restricts a query with; among() adds its own, so the two never replace each other. */
    private const MATCHES = 'fuzzy-search:matches';

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
     * How many of the ranked ids satisfy $base (see countModels()).
     *
     * @param  array<int|string> $rankedIds
     */
    public static function count(Builder $base, array $rankedIds): int
    {
        $key   = $base->getModel()->getQualifiedKeyName();
        $total = 0;

        foreach (array_chunk($rankedIds, self::COUNT_CHUNK) as $chunk) {
            $total += self::countModels(self::among($base, $key, $chunk));
        }

        return $total;
    }

    /**
     * How many models $query returns: models, not rows — a one-to-many join repeats a model once per
     * joined row, so an ungrouped query counts its distinct keys (COUNT(DISTINCT key) on every
     * driver). A grouped query is counted as a subquery, where the key is out of scope and its groups
     * are the rows. ORDER BY / LIMIT on the query are dropped for the count (PostgreSQL rejects an
     * ORDER BY on a bare aggregate).
     */
    public static function countModels(Builder $query): int
    {
        $key   = $query->getModel()->getQualifiedKeyName();
        $query = (clone $query)->toBase();

        return (int) ($query->groups || $query->havings
            ? $query->getCountForPagination()
            : $query->distinct()->getCountForPagination([$key]));
    }

    /**
     * $base restricted to the matches of $ranked, for a read in an order of the caller's (ruling
     * ER-82); every row it returns is a match. A ranking of at most one bm25.candidate_chunk that
     * holds every match is listed by id. Otherwise, on the index's own connection, a subquery on the
     * postings restricts it (Bm25Scorer::whereRanked()): it binds no id, so no list can pass SQL
     * Server's 2,100-parameter limit, and it lets every match through, those rank() left out past
     * bm25.max_postings_per_term too. On another connection, where that subquery cannot run, the
     * top max_candidates ranked ids (at least one chunk) are listed, and deeper matches are not served.
     *
     * @param array<int|string, float>                $ranked        model_id => score, as rank() returned it
     * @param array<int, string>|array<string, float> $terms         the terms it was ranked for
     * @param array<string, int|float>                $columnWeights the column weights it was ranked with
     */
    public static function matches(Builder $base, array $ranked, array $terms, string $modelType, array $columnWeights): Builder
    {
        $model = $base->getModel();
        $ids   = array_keys($ranked);
        $chunk = self::chunkSize(null);

        // rank() reads one row per document and term, best first, up to max_postings_per_term: fewer
        // than that many pairs in the ranking mean it read them all, and it holds every match.
        $whole = count($ids) * count($terms) < (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        if ((count($ids) > $chunk || !$whole) && $base->getQuery()->getConnection() === DB::connection()) {
            return (clone $base)->withGlobalScope(self::MATCHES, fn (Builder $query) => app(Bm25Scorer::class)
                ->whereRanked($query->getQuery(), $model->getQualifiedKeyName(), $terms, $modelType, $columnWeights));
        }

        $ids = self::keysFor($model, array_slice($ids, 0, max($chunk, (int) config('fuzzy-search.max_candidates', 1000))));

        return (clone $base)->withGlobalScope(self::MATCHES, fn (Builder $query) => $query->whereKey($ids));
    }

    /**
     * The keys of rows [$offset, $offset + $limit) of $query, a read of matches (see matches()) in an
     * order that ends on the key, so it is total and no row moves between pages. The caller's select
     * list stays — an order may name its alias (withCount()'s posts_count, a selectRaw() column) — and
     * the key is read through an alias of its own. The page is one offset/limit read, however deep. A
     * join may repeat a model, which is served once, at its first row: a joined query is read 1,000
     * rows at a time from its first row until the page is complete (lazy(), never one buffered
     * result: pdo_mysql and pdo_pgsql fetch a whole result set before its first row).
     *
     * @return array<int|string>
     */
    public static function orderedKeys(QueryBuilder $query, string $qualifiedKey, int $offset, int $limit): array
    {
        $query->columns ??= ['*'];
        $query->addSelect($qualifiedKey . ' as ' . self::KEY_ALIAS);

        if (empty($query->joins)) {
            return $query->offset($offset)->limit($limit)->pluck(self::KEY_ALIAS)->all();
        }

        $keys = [];

        foreach ($query->lazy(1000) as $row) {
            $keys[$row->{self::KEY_ALIAS}] = true;

            if (count($keys) >= $offset + $limit) {
                break;
            }
        }

        return array_slice(array_keys($keys), $offset, $limit);
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
        $chunk = self::keysFor($base->getModel(), $chunk);

        return (clone $base)->withGlobalScope(self::class, fn (Builder $query) => $query->whereIn($key, $chunk));
    }

    /**
     * $ids as $model's key column compares them. A ranking is keyed by model_id, and PHP turns an
     * all-digit array key ('42') into an int. Bound as an int against a string key column, SQL
     * Server converts the whole nvarchar column to int and fails on its first other key (22018,
     * "Conversion failed when converting the nvarchar value 'abc-1' to data type int"), so a string
     * key is bound as a string.
     *
     * @param  array<int|string> $ids
     * @return array<int|string>
     */
    public static function keysFor(\Illuminate\Database\Eloquent\Model $model, array $ids): array
    {
        return $model->getKeyType() === 'string' ? array_map('strval', $ids) : $ids;
    }

    private static function chunkSize(?int $override): int
    {
        return max(1, $override ?? (int) config('fuzzy-search.bm25.candidate_chunk', 200));
    }
}
