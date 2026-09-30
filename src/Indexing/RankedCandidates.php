<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;

/**
 * Checks a BM25 ranking against a (possibly constrained) Eloquent query without losing
 * rank order, so a selective filter fills its page from lower-ranked matches instead of
 * coming back short, and totals reflect the constraints. The ranking is checked by id (in one read
 * for an integer key, see accepted() and matches()); on the index's own connection an ordered read
 * of a capped ranking, and on SQL Server a long ranking, is restricted by a subquery on the postings
 * (matches(), accepted()); otherwise ids are visited best-first in chunks and only rows the query
 * returns are kept.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class RankedCandidates
{
    /** The alias a read selects the key under, beside the caller's select list (see models(), orderedKeys() and the Scout engine's map()). */
    public const KEY_ALIAS = 'fuzzy_walk_key';

    /** The scope whereMatches() restricts a query with; among() adds its own, so the two never replace each other. */
    private const MATCHES = 'fuzzy-search:matches';

    /**
     * The models among $rankedIds that $base returns, in rank order and keyed by their ids,
     * $rankedIds read a chunk at a time. A row the query hides, or whose id is stale, is skipped. The
     * key is read under an alias of its own beside the select list, so a row is matched to its id when
     * the select leaves the key out (ruling D13) or a join's id replaces it; the alias is then taken
     * out of the model, so it never reaches its attributes. A union is read as one table (rows()).
     *
     * @param  array<int|string> $rankedIds best first
     * @return EloquentCollection<int|string, \Illuminate\Database\Eloquent\Model>
     */
    public static function models(Builder $base, array $rankedIds): EloquentCollection
    {
        $base      = self::rows($base);
        $key       = self::keyColumn($base);
        $collected = [];

        foreach (array_chunk($rankedIds, self::chunkSize(null)) as $chunk) {
            $read = self::among($base, $key, $chunk)->withGlobalScope(self::KEY_ALIAS, fn (Builder $query) => self::selectKey($query->getQuery(), $key));
            $found = [];

            // A model's first row: a join may repeat it, and MariaDB gives a ROLLUP's summary row the
            // last group's key under the alias, where its own key is NULL. A row without one is skipped.
            foreach ($read->get() as $model) {
                if (($id = self::takeKey($model)) !== null) {
                    $found[$id] ??= $model;
                }
            }

            foreach ($chunk as $id) {
                if (isset($found[$id])) {
                    $collected[$id] = $found[$id];
                }
            }
        }

        return $base->getModel()->newCollection($collected);
    }

    /**
     * The key a read selected under KEY_ALIAS, taken out of $model's attributes and original (Model
     * has no public way to drop an original attribute), or $model's own key when the read selected
     * none (an app's getScoutModelsByIds() that reads without the query() callback). Null when the
     * row has no key (a ROLLUP's summary row): the caller skips it.
     */
    public static function takeKey(Model $model): int|string|null
    {
        $alias = self::KEY_ALIAS;

        return (function () use ($alias) {
            if (!array_key_exists($alias, $this->attributes)) {
                return $this->getKey();
            }

            $key = $this->attributes[$alias];
            unset($this->attributes[$alias], $this->original[$alias]);

            return $key;
        })->call($model);
    }

    /**
     * The keys $read selected under KEY_ALIAS, in its order, less any NULL: a row without a key
     * (a ROLLUP's summary row; the ids a read is restricted to keep out every other) matches no id.
     *
     * @return array<int|string>
     */
    private static function keysOf(QueryBuilder $read): array
    {
        return $read->pluck(self::KEY_ALIAS)->whereNotNull()->values()->all();
    }

    /** $query's select list, every column when it names none, with $key beside it under KEY_ALIAS. */
    public static function selectKey(QueryBuilder $query, string $key): QueryBuilder
    {
        $query->columns ??= ['*'];

        return $query->addSelect($key . ' as ' . self::KEY_ALIAS);
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
        $base      = self::rows($base);
        $key       = self::keyColumn($base);
        $collected = [];

        foreach (array_chunk($rankedIds, self::chunkSize($chunkSize)) as $chunk) {
            $found = array_flip(self::keysOf(self::keyRead(self::among($base, $key, $chunk))));

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
     * $base restricted to the matches of $ranked, for a read in an order of the caller's (rulings
     * ER-82, ER-132); every row it returns is a match. A ranking that holds every match is listed by
     * key, as accepted() checks one, so the rows read are bounded by the ranking, however large the
     * table:
     *  - an integer key, on every database but SQL Server: the whole ranking, inlined;
     *  - a string key on MySQL or MariaDB, or on SQLite: the whole ranking, bound, while the
     *    statement stays under the database's placeholder limit (65,535; SQLite's 32,766, or 999
     *    before 3.32);
     *  - any key: a ranking of at most one bm25.candidate_chunk.
     * Otherwise, on the index's own connection, a subquery on the postings restricts it
     * (Bm25Scorer::whereRanked()): it binds no id, and it lets every match through, those rank()
     * left out past bm25.max_postings_per_term too, which a capped ranking needs. It compares the
     * postings with a cast of the key, so it reads every row the query accepts (a SoftDeletes table
     * whole). SQL Server keeps it for a longer ranking: it compiles every new inlined list slowly,
     * and at 200k rows a bound list of 2,000 integer ids took 3.4 s where the subquery takes 70 to
     * 110 ms. So does a string key on PostgreSQL, where it costs what a listed read does. On another
     * connection, where the subquery cannot run, the top max_candidates ranked ids (at least one
     * chunk) are listed, and deeper matches are not served.
     *
     * @param array<int|string, float>                $ranked        model_id => score, as rank() returned it
     * @param array<int, string>|array<string, float> $terms         the terms it was ranked for
     * @param array<string, int|float>                $columnWeights the column weights it was ranked with
     */
    public static function matches(Builder $base, array $ranked, array $terms, string $modelType, array $columnWeights): Builder
    {
        $base  = self::rows($base);
        $model = $base->getModel();
        $ids   = array_keys($ranked);
        $chunk = self::chunkSize(null);
        $int   = in_array($model->getKeyType(), ['int', 'integer'], true);

        // rank() reads one row per document and term, best first, up to max_postings_per_term. Its
        // documents times the terms bound the rows it read: under the cap, it read them all, and
        // the ranking holds every match.
        $whole = count($ids) * count($terms) < (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        if ((count($ids) > $chunk || !$whole) && self::subqueryRuns($base)) {
            if (!$whole || !self::listsWhole($base, $int, count($ids))) {
                return self::whereMatches($base, $terms, $modelType, $columnWeights);
            }
        } else {
            $ids = array_slice($ids, 0, max($chunk, (int) config('fuzzy-search.max_candidates', 1000)));
        }

        $ids = self::keysFor($model, $ids);
        $key = self::keyColumn($base);

        // whereKey()'s shape, on the key as the FROM names it: an integer list inlined, not bound.
        return (clone $base)->withGlobalScope(self::MATCHES, fn (Builder $query) => $int
            ? $query->whereIntegerInRaw($key, $ids)
            : $query->whereIn($key, $ids));
    }

    /**
     * Whether matches() lists a ranking of $count ids that holds every match, past one chunk: an
     * integer key but on SQL Server, or a string key whose bound list keeps the statement under the
     * placeholder limit, with the query's own bindings and the one orderedKeys() may add.
     */
    private static function listsWhole(Builder $base, bool $int, int $count): bool
    {
        $connection = $base->getQuery()->getConnection();
        $driver     = $connection->getDriverName();

        if ($int) {
            return $driver !== DbDialect::SQLSRV;
        }

        $limit = match (true) {
            DbDialect::isMySqlFamily($driver) => 65535,
            $driver === DbDialect::SQLITE     => version_compare((string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), '3.32.0', '>=') ? 32766 : 999,
            default                           => 0,
        };

        return $count + count($base->toBase()->getBindings()) < $limit;
    }

    /**
     * $ranked cut to the documents $base accepts, in rank order: what a page in rank order serves and
     * its total counts, so a constrained search serves the rows of the unconstrained one that the
     * constraint accepts, and none past the ranking's cap. The ranking is checked by key, so the rows
     * read are bounded by the ranking, however large the table (rulings D10 and ER-124):
     *  - an integer key, on every database but SQL Server: the whole ranking in one read, its ids
     *    inlined as matches() lists them (about 1 ms per 1,000 ids, no binding limit);
     *  - a string key on MySQL or MariaDB: 10,000 bound ids per read (they allow 65,535 bindings);
     *  - otherwise a ranking of at most max(bm25.candidate_chunk, max_candidates) ids, a chunk at a
     *    time (keys()). A longer one, on the index's own connection, is read in one query through the
     *    postings subquery (Bm25Scorer::whereRanked()), however many rows match, streamed (cursor())
     *    and kept where the ranking holds them. That subquery compares the postings with a cast of the
     *    key, so no database can drive the read from them: it reads every row the constraint accepts
     *    (a SoftDeletes table whole). SQL Server compiles every new inlined list (8.7 s for 50,000
     *    ids) and caps a statement's expressions, and its 2,100 bindings allow no long bound list;
     *    there, and for a string key on PostgreSQL and SQLite, this is D10's shape as it was. On
     *    another connection, where the subquery cannot run, the ranked ids are checked a chunk at a
     *    time.
     *
     * @param  array<int|string, float>                $ranked        model_id => score, best first
     * @param  array<int, string>|array<string, float> $terms         the terms it was ranked for
     * @param  array<string, int|float>                $columnWeights the column weights it was ranked with
     * @return array<int|string, float>
     */
    public static function accepted(Builder $base, array $ranked, array $terms, string $modelType, array $columnWeights): array
    {
        $base   = self::rows($base);
        $driver = $base->getQuery()->getConnection()->getDriverName();
        $int    = in_array($base->getModel()->getKeyType(), ['int', 'integer'], true);

        if ($int && $driver !== DbDialect::SQLSRV) {
            $key  = self::keyColumn($base);
            $ids  = array_keys($ranked);
            $read = (clone $base)->withGlobalScope(self::class, fn (Builder $query) => $query->whereIntegerInRaw($key, $ids));

            return array_intersect_key($ranked, array_flip(self::keysOf(self::keyRead($read))));
        }

        $bound = !$int && DbDialect::isMySqlFamily($driver);

        if ($bound || count($ranked) <= max(self::chunkSize(null), (int) config('fuzzy-search.max_candidates', 1000)) || !self::subqueryRuns($base)) {
            return array_intersect_key($ranked, array_flip(self::keys($base, array_keys($ranked), null, $bound ? 10000 : null)));
        }

        $accepted = [];

        foreach (self::keyRead(self::whereMatches($base, $terms, $modelType, $columnWeights))->cursor() as $row) {
            if ($row->{self::KEY_ALIAS} !== null && isset($ranked[$row->{self::KEY_ALIAS}])) {
                $accepted[$row->{self::KEY_ALIAS}] = true;
            }
        }

        return array_intersect_key($ranked, $accepted);
    }

    /**
     * $query's read of its keys, under KEY_ALIAS, in no order: the key alone, unless a HAVING may name
     * an alias of the select list (withCount()'s posts_count, which MySQL and MariaDB accept there).
     * select(), not the column list alone: it drops the dropped columns' bindings (a constrained
     * withCount()) too.
     */
    private static function keyRead(Builder $query): QueryBuilder
    {
        $key   = self::keyColumn($query);
        $query = $query->toBase()->reorder();

        return $query->havings ? self::selectKey($query, $key) : $query->select($key . ' as ' . self::KEY_ALIAS);
    }

    /**
     * The keys of rows [$offset, $offset + $limit) of $query, a read of matches (see matches()) in an
     * order that ends on the key, so it is total and no row moves between pages. The caller's select
     * list stays — an order may name its alias (withCount()'s posts_count, a selectRaw() column) — and
     * the key is read through an alias of its own. The page is one offset/limit read, however deep.
     *
     * A join may repeat a model, which is served once, at its first row (ruling ER-108), and so may a
     * derived FROM (a fromSub() holding a join, a union whose parts overlap), whose order is never
     * on the model's own table:
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
        $order = (empty($query->joins) && is_string($query->from)) || !empty($query->groups) ? 'rows' : self::joinedOrder($query);
        $key   = $qualifiedKey . ' as ' . self::KEY_ALIAS;

        if ($order === 'own') {
            $keys = (clone $query)->reorder()->select($qualifiedKey);
            $page = $query->newQuery()->from($query->from)->select($key)->whereIn($qualifiedKey, $keys);

            foreach ($query->orders as $sort) {
                $page->orderBy($sort['column'], $sort['direction']);
            }

            return self::keysOf($page->offset($offset)->limit($limit));
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

            return self::keysOf($page->offset($offset)->limit($limit));
        }

        self::selectKey($query, $qualifiedKey);

        if ($order === 'rows') {
            return self::keysOf($query->offset($offset)->limit($limit));
        }

        $keys = [];

        foreach ($query->lazy(1000) as $row) {
            if ($row->{self::KEY_ALIAS} !== null) {
                $keys[$row->{self::KEY_ALIAS}] = true;
            }

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
     * $base, with a union read as one derived table named as the model's table (rulings ER-125,
     * ER-127): every read here restricts the union's rows to ranked ids, adds the key to the select
     * list or orders the rows, and on a union each of those reached only its first part (the other
     * parts were read whole, or their select lists no longer matched). The model's scopes already
     * apply inside that part, and the eager loads are carried over. The union's own order is dropped
     * when it has no limit or offset of its own: it cannot change which rows the union holds, and SQL
     * Server rejects an ORDER BY in a derived table without TOP or OFFSET (a union with a limit stays
     * unsupported there, as on the LIKE path). $base itself when it has no union, so a second call
     * changes nothing.
     */
    public static function rows(Builder $base): Builder
    {
        $query = $base->toBase();

        if (!$query->unions) {
            return $base;
        }

        if ($query->unionLimit === null && $query->unionOffset === null) {
            $query->unionOrders            = null;
            $query->bindings['unionOrder'] = [];
        }

        $model = $base->getModel();

        return $model->newQueryWithoutScopes()->fromSub($query, $model->getTable())->setEagerLoads($base->getEagerLoads());
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
