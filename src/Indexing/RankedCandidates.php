<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;

/**
 * Checks a BM25 ranking against a (possibly constrained) Eloquent query without losing
 * rank order, so a selective filter fills its page from lower-ranked matches instead of
 * coming back short, and totals reflect the constraints. On the index's own connection the
 * matches a query accepts are read through a subquery on the postings (accepted(),
 * matches()); otherwise ids are visited best-first in chunks and only rows the query
 * returns are kept.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class RankedCandidates
{
    /** The alias an ordered read selects the key under, beside the caller's select list (see orderedKeys()). */
    private const KEY_ALIAS = 'fuzzy_walk_key';

    /** The scope whereMatches() restricts a query with; among() adds its own, so the two never replace each other. */
    private const MATCHES = 'fuzzy-search:matches';

    /**
     * Models satisfying $base, in rank order, stopping once $needed have been collected.
     *
     * @param  array<int|string> $rankedIds best first
     */
    public static function models(Builder $base, array $rankedIds, ?int $needed = null, ?int $chunkSize = null): EloquentCollection
    {
        $key       = self::keyColumn($base);
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
        $key       = self::keyColumn($base);
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
     * How many models $query returns: models, not rows — a one-to-many join repeats a model once per
     * joined row, so an ungrouped query counts its distinct keys (COUNT(DISTINCT key) on every
     * driver). A grouped query is counted as a subquery, where the key is out of scope and its groups
     * are the rows. ORDER BY / LIMIT on the query are dropped for the count (PostgreSQL rejects an
     * ORDER BY on a bare aggregate).
     */
    public static function countModels(Builder $query): int
    {
        $key   = self::keyColumn($query);
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

        // rank() reads one row per document and term, best first, up to max_postings_per_term. Its
        // documents times the terms bound the rows it read: under the cap, it read them all, and
        // the ranking holds every match.
        $whole = count($ids) * count($terms) < (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        if ((count($ids) > $chunk || !$whole) && self::subqueryRuns($base)) {
            return self::whereMatches($base, $terms, $modelType, $columnWeights);
        }

        $ids = self::keysFor($model, array_slice($ids, 0, max($chunk, (int) config('fuzzy-search.max_candidates', 1000))));
        $key = self::keyColumn($base);

        // whereKey()'s shape, on the key as the FROM names it: an integer list inlined, not bound.
        return (clone $base)->withGlobalScope(self::MATCHES, fn (Builder $query) => in_array($model->getKeyType(), ['int', 'integer'], true)
            ? $query->whereIntegerInRaw($key, $ids)
            : $query->whereIn($key, $ids));
    }

    /**
     * $ranked cut to the documents $base accepts, in rank order: what a page in rank order serves and
     * its total counts, so a constrained search serves the rows of the unconstrained one that the
     * constraint accepts, and none past the ranking's cap. On the index's own connection one query
     * reads the keys of every match $base accepts, through the postings subquery
     * (Bm25Scorer::whereRanked()), however long the ranking is: the key is selected, and the rows
     * are streamed (cursor()), keeping those the ranking holds. On another connection, where that
     * subquery cannot run, the ranked ids are checked a chunk at a time.
     *
     * @param  array<int|string, float>                $ranked        model_id => score, best first
     * @param  array<int, string>|array<string, float> $terms         the terms it was ranked for
     * @param  array<string, int|float>                $columnWeights the column weights it was ranked with
     * @return array<int|string, float>
     */
    public static function accepted(Builder $base, array $ranked, array $terms, string $modelType, array $columnWeights): array
    {
        if (!self::subqueryRuns($base)) {
            return array_intersect_key($ranked, array_flip(self::keys($base, array_keys($ranked))));
        }

        $query = self::whereMatches($base, $terms, $modelType, $columnWeights)->toBase()->reorder();
        $key   = self::keyColumn($base) . ' as ' . self::KEY_ALIAS;

        // The key alone, unless a HAVING may name an alias of the select list (withCount()'s
        // posts_count, which MySQL and MariaDB accept there).
        if ($query->havings) {
            $query->columns ??= ['*'];
            $query->addSelect($key);
        } else {
            $query->columns = [$key];
        }

        $accepted = [];

        foreach ($query->cursor() as $row) {
            if (isset($ranked[$row->{self::KEY_ALIAS}])) {
                $accepted[$row->{self::KEY_ALIAS}] = true;
            }
        }

        return array_intersect_key($ranked, $accepted);
    }

    /**
     * The keys of rows [$offset, $offset + $limit) of $query, a read of matches (see matches()) in an
     * order that ends on the key, so it is total and no row moves between pages. The caller's select
     * list stays — an order may name its alias (withCount()'s posts_count, a selectRaw() column) — and
     * the key is read through an alias of its own. The page is one offset/limit read, however deep.
     *
     * A join may repeat a model, which is served once, at its first row (ruling ER-108):
     *  - an order on the model's own columns only reads the model's table, with no join, restricted
     *    to the joined query's keys: one offset/limit read;
     *  - an order on a joined column keeps each model's first row by the order, ROW_NUMBER() over
     *    the key (every supported database has window functions): one offset/limit read;
     *  - an order on a select alias, or a raw order, which a window's ORDER BY cannot name, or a
     *    joined query with a HAVING, which may need the select list, is read 1,000 rows at a time
     *    from its first row until the page is complete (lazy(), never one buffered result:
     *    pdo_mysql and pdo_pgsql fetch a whole result set before its first row). Filtering with
     *    whereHas() instead of a join keeps such an order on one read.
     *
     * @return array<int|string>
     */
    public static function orderedKeys(QueryBuilder $query, string $qualifiedKey, int $offset, int $limit): array
    {
        $order = empty($query->joins) || !empty($query->groups) ? 'rows' : self::joinedOrder($query);
        $key   = $qualifiedKey . ' as ' . self::KEY_ALIAS;

        if ($order === 'own') {
            $keys = (clone $query)->reorder()->select($qualifiedKey);
            $page = $query->newQuery()->from($query->from)->select($key)->whereIn($qualifiedKey, $keys);

            foreach ($query->orders as $sort) {
                $page->orderBy($sort['column'], $sort['direction']);
            }

            return $page->offset($offset)->limit($limit)->pluck(self::KEY_ALIAS)->all();
        }

        if ($order === 'joined') {
            $grammar = $query->getGrammar();
            $columns = [$key];
            $over    = [];

            foreach ($query->orders as $i => $sort) {
                $columns[] = $sort['column'] . ' as fuzzy_order_' . $i;
                $over[]    = $grammar->wrap($sort['column']) . ' ' . $sort['direction'];
            }

            $rows = (clone $query)->reorder()->select($columns)
                ->selectRaw('row_number() over (partition by ' . $grammar->wrap($qualifiedKey) . ' order by ' . implode(', ', $over) . ') as fuzzy_row');
            $page = $query->newQuery()->fromSub($rows, 'fuzzy_rows')->where('fuzzy_row', 1);

            foreach ($query->orders as $i => $sort) {
                $page->orderBy('fuzzy_order_' . $i, $sort['direction']);
            }

            return $page->offset($offset)->limit($limit)->pluck(self::KEY_ALIAS)->all();
        }

        $query->columns ??= ['*'];
        $query->addSelect($key);

        if ($order === 'rows') {
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
     * Where a joined query's order comes from (see orderedKeys()): 'own' when every order column is
     * a column of the model's table, 'alias' when one is a select alias or no plain column (a raw
     * order), or the query has a HAVING, and 'joined' otherwise. A column named without its table is
     * the model's when its table has it; under fromSub() no column counts as the model's own.
     */
    private static function joinedOrder(QueryBuilder $query): string
    {
        if (!empty($query->havings)) {
            return 'alias';
        }

        $grammar = $query->getGrammar();
        $aliases = [];

        foreach ($query->columns ?? [] as $column) {
            $sql = $column instanceof \Illuminate\Contracts\Database\Query\Expression ? (string) $column->getValue($grammar) : (string) $column;

            if (preg_match('/\s+as\s+(\S+)\s*$/i', $sql, $alias) === 1) {
                $aliases[trim($alias[1], '"`[]')] = true;
            }
        }

        $from = DbDialect::fromTable($query->from);
        $own  = $from === null ? null : $from[1] ?? $from[0];
        $all  = true;

        foreach ($query->orders ?? [] as $sort) {
            if (($sort['type'] ?? 'Basic') !== 'Basic' || !is_string($sort['column'])) {
                return 'alias';
            }

            $dot = strrpos($sort['column'], '.');

            if ($dot === false && isset($aliases[$sort['column']])) {
                return 'alias';
            }

            $all = $all && $own !== null && ($dot === false
                ? in_array($sort['column'], SearchableColumns::onTable($query->getConnection(), $from[0]), true)
                : substr($sort['column'], 0, $dot) === $own);
        }

        return $all ? 'own' : 'joined';
    }

    /** $base restricted to the documents that hold $terms (Bm25Scorer::whereRanked()), added as a global scope. */
    private static function whereMatches(Builder $base, array $terms, string $modelType, array $columnWeights): Builder
    {
        $key = self::keyColumn($base);

        return (clone $base)->withGlobalScope(self::MATCHES, fn (Builder $query) => app(Bm25Scorer::class)
            ->whereRanked($query->getQuery(), $key, $terms, $modelType, $columnWeights));
    }

    /**
     * The key as $base's FROM names it (ruling ER-107): the alias's under from('users as u') or
     * fromSub(…, 'u'), the table's otherwise. "users"."id" under an alias failed on every database.
     * A FROM expression without an alias leaves the bare key.
     */
    public static function keyColumn(Builder $base): string
    {
        $model = $base->getModel();
        $query = $base->getQuery();
        $from  = $query->from;

        if (is_string($from)) {
            $alias = DbDialect::fromTable($from)[1];

            return $alias === null ? $model->getQualifiedKeyName() : $alias . '.' . $model->getKeyName();
        }

        // fromSub() writes "(…) as <the alias, wrapped and prefixed>": read it back as the alias
        // the grammar wraps and prefixes again.
        $sql = $from instanceof \Illuminate\Contracts\Database\Query\Expression ? (string) $from->getValue($query->getGrammar()) : '';

        if (preg_match('/\)\s+as\s+([^\s.]+)\s*$/i', $sql, $alias) !== 1) {
            return $model->getKeyName();
        }

        $alias  = trim($alias[1], '"`[]');
        $prefix = $query->getConnection()->getTablePrefix();

        return ($prefix !== '' && str_starts_with($alias, $prefix) ? substr($alias, strlen($prefix)) : $alias) . '.' . $model->getKeyName();
    }

    /**
     * Whether the postings subquery can restrict $base: on the connection the index lives on, the
     * default one, and with a key it can name. A bare key inside the subquery would name the
     * postings' own id.
     */
    private static function subqueryRuns(Builder $base): bool
    {
        return $base->getQuery()->getConnection() === DB::connection() && str_contains(self::keyColumn($base), '.');
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
