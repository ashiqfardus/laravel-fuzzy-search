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
 * of a capped ranking, and on SQL Server a long ranking, is restricted through the postings
 * (matches(), accepted(); Bm25Scorer::whereRanked()); otherwise ids are visited best-first in chunks
 * and only rows the query returns are kept.
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
     * the select leaves the key out (ruling D13) or a join's id replaces it. Each chunk is read as
     * Eloquent's get() reads (its scopes applied, then hydrate(), eagerLoadRelations() and, from
     * Laravel 11, the afterQuery() callbacks), but the alias is taken off each row before it is
     * hydrated, so it never reaches a model: not its attributes, a retrieved listener, a cast or an
     * afterQuery() callback (ruling ER-135). Only a model's first row is hydrated. A union is read as
     * one table (rows()).
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
            $read = self::among($base, $key, $chunk)->applyScopes();
            $rows = [];

            // A model's first row: a join may repeat it, and MariaDB gives a ROLLUP's summary row the
            // last group's key under the alias, where its own key is NULL. A row without one is skipped.
            foreach (self::selectKey($read->getQuery(), $key)->get() as $row) {
                $id = $row->{self::KEY_ALIAS};
                unset($row->{self::KEY_ALIAS});

                if ($id !== null) {
                    $rows[$id] ??= $row;
                }
            }

            $models = $rows === [] ? [] : $read->eagerLoadRelations($read->hydrate(array_values($rows))->all());
            $found  = $rows === [] ? [] : array_combine(array_keys($rows), $models);

            // The afterQuery() callbacks see the chunk's models, as they see get()'s, and what they
            // return is served, as get() serves it: a model they were given under its id, and another
            // instance (withoutRelations() and replicate() return copies, SA-6) under its own key,
            // where a given model's own key is the id it was read under, else under the id of a given
            // model whose attributes it has (a copy under a join's id or a select() without the key,
            // TA-3). Any other model is dropped: it cannot be placed in the ranking (a replicate(),
            // which has no key, unless the callback gives it one). An item that is no model (an
            // array, a DTO) throws: it emptied every page, silently (TA-6). Ruling ER-167 preferred
            // running the callbacks once on the ranked, scored page, which SearchBuilder assembles
            // (the page, its scores, the cache's re-read, highlighting), not this read.
            if (method_exists($read, 'applyAfterQueryCallbacks')) {
                $given = array_flip(array_map('spl_object_id', $found));
                $byKey = [];
                foreach ($found as $id => $model) {
                    if ($model->getKey() !== null && (string) $model->getKey() === (string) $id) {
                        $byKey[(string) $id] = $id;
                    }
                }

                $kept = [];
                foreach ($read->applyAfterQueryCallbacks($read->getModel()->newCollection($models)) as $model) {
                    if (!$model instanceof Model) {
                        throw new \LogicException(sprintf(
                            '%s: an index search places each row its afterQuery() callbacks return in the ranking by its key, and one returned %s: return the models, and map them after get().',
                            $read->getModel()::class,
                            get_debug_type($model)
                        ));
                    }

                    $own = $model->getKey();
                    $id  = $given[spl_object_id($model)] ?? ($own === null ? null : $byKey[(string) $own] ?? null) ?? self::copied($model, $found, $kept);

                    if ($id !== null) {
                        $kept[$id] ??= $model;
                    }
                }
                $found = $kept;
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
     * The id of the first model of $given, not yet $kept, whose attributes $copy has, all of them and
     * no other (a clone, withoutRelations()), or null.
     *
     * @param array<int|string, Model> $given id => the model read under it
     * @param array<int|string, Model> $kept  id => the model served under it
     */
    private static function copied(Model $copy, array $given, array $kept): int|string|null
    {
        foreach ($given as $id => $model) {
            if (!isset($kept[$id]) && $model->getAttributes() === $copy->getAttributes()) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The key a read selected under KEY_ALIAS, taken out of $model's attributes and original (Model
     * has no public way to drop an original attribute), or $model's own key when the read selected
     * none (see needsKeyAlias(); an app's getScoutModelsByIds() that reads without the query()
     * callback). Null when the row has no key (a ROLLUP's summary row): the caller skips it.
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
     * are the rows; with a join and no select list Laravel selects its FROM's every column there, as
     * "users as u".* under an alias, which failed, and failed under a fromSub() (R11-L3): it selects
     * those of the table the key is named through. ORDER BY / LIMIT on the query are dropped for the
     * count (PostgreSQL rejects an ORDER BY on a bare aggregate).
     */
    public static function countModels(Builder $query): int
    {
        $key   = self::keyColumn($query);
        $query = (clone $query)->toBase();

        if ($query->groups || $query->havings) {
            if (!empty($query->joins) && str_contains($key, '.')) {
                $query->columns ??= [substr($key, 0, strrpos($key, '.')) . '.*'];
            }

            return (int) $query->getCountForPagination();
        }

        return (int) $query->distinct()->getCountForPagination([$key]);
    }

    /**
     * $base restricted to the matches of $ranked, for a read in an order of the caller's (rulings
     * ER-82, ER-132); every row it returns is a match. A ranking that holds every match is listed by
     * key, as accepted() checks one, so the rows read are bounded by the ranking, however large the
     * table:
     *  - an integer key, on every database but SQL Server: the whole ranking, inlined;
     *  - a string key on MySQL or MariaDB, but over a union (see listsWhole()), or on SQLite: the
     *    whole ranking, bound, while the statement stays under the database's placeholder limit
     *    (65,535; SQLite's 32,766, or 999 before 3.32);
     *  - any key: a ranking of at most one bm25.candidate_chunk.
     * Otherwise, on the index's own connection, the postings restrict it (Bm25Scorer::whereRanked()):
     * they bind no id, and let every match through, those rank() left out past
     * bm25.max_postings_per_term too, which a capped ranking needs. On MySQL and MariaDB, for a read
     * with no constraint and a sparse word, the matched ids are joined on the key, one key lookup per
     * match (whereMatches()); the subquery used elsewhere compares the postings with a cast of the
     * key, so it reads every row the query accepts (a SoftDeletes table whole). SQL Server keeps
     * it for a longer ranking: it compiles every new inlined list slowly, and at 200k rows a bound
     * list of 2,000 integer ids took 3.4 s where the subquery takes 70 to 110 ms. So does a string
     * key on PostgreSQL, where it costs what a listed read does. On another connection, where the
     * postings cannot be read, the top max_candidates ranked ids (at least one chunk) are listed,
     * and deeper matches are not served.
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

        // rank() reads one row per document and term, best first, up to max_postings_per_term: the
        // ranking holds every match unless its read reached the cap (TF-9: its documents times the
        // terms, typo and prefix expansions and synonyms included, reached it from a sixth of the cap
        // for a word with five neighbours). That bound stands in for a ranking rank() did not just make.
        $read  = app(Bm25Scorer::class)->lastRead($terms, $modelType, $columnWeights);
        $whole = $read === null ? count($ids) * count($terms) < (int) config('fuzzy-search.bm25.max_postings_per_term', 50000) : !$read['cut'];

        if ((count($ids) > $chunk || !$whole) && self::subqueryRuns($base)) {
            if (!$whole || !self::listsWhole($base, $int, count($ids))) {
                return self::whereMatches($base, $terms, $modelType, $columnWeights, $read !== null && $read['share'] <= self::JOIN_SHARE);
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
     * placeholder limit, with the query's own bindings and the one orderedKeys() may add. Not a
     * string key over a union on MySQL or MariaDB (see readsUnion()).
     */
    private static function listsWhole(Builder $base, bool $int, int $count): bool
    {
        $connection = $base->getQuery()->getConnection();
        $driver     = $connection->getDriverName();

        if ($int) {
            return $driver !== DbDialect::SQLSRV;
        }

        $limit = match (true) {
            DbDialect::isMySqlFamily($driver) => self::readsUnion($base) ? 0 : 65535,
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
     *  - a string key on MySQL or MariaDB: 10,000 bound ids per read (they allow 65,535 bindings),
     *    but over a union (see readsUnion());
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

        $bound = !$int && DbDialect::isMySqlFamily($driver) && !self::readsUnion($base);

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
        $order = (!self::joinsTables($query) && is_string($query->from)) || !empty($query->groups) ? 'rows' : self::joinedOrder($query);
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

    /**
     * The largest share of the model's documents (fuzzy_index_meta.total_docs) the words' postings
     * may reach (Bm25Scorer::lastRead()) for an ordered read with no constraint to be joined to them
     * (whereMatches(); R11-M4, ruling ER-162). Measured on the COUNT and a page of 20 by an indexed
     * column, at 200k and 1M rows:
     *  - MySQL 9.6: the join read a word in 5 to 10% of the rows 6 to 14 times faster than the
     *    subquery (0.5 s for 3.0 s at 1M rows), and kept a lead to about 60% while it read the
     *    word's postings through postings_unique_idx. But once a word holds about an eighth of the
     *    model's postings, MySQL reads them through postings_model_idx, every posting of the model,
     *    and the join lost 2 to 10 times (30.7 s for 3.2 s at 1M rows, a word in 25% of rows of
     *    about one posting each). A tenth of the rows keeps an index of one posting a row below that.
     *  - MariaDB 11.4, at 1M rows: 3.4 to 5.9 times faster to 10%, even between 12 and 20%, and 1.8
     *    to 18 times slower from 25% (it materializes the matched ids before it looks them up).
     */
    private const JOIN_SHARE = 0.1;

    /**
     * $base restricted to the documents that hold $terms (Bm25Scorer::whereRanked()), added as a
     * global scope: on MySQL and MariaDB joined to the matched ids of the postings on the model's key
     * only for a $sparse word, its postings at most JOIN_SHARE of the model's documents, and a read
     * with no constraint (ruling ER-162). The join reads every matched posting, and the servers
     * materialize it for the COUNT and again for the page (R11-M4). Under a selective where() the
     * subquery reads only the rows the where accepts: 2 ms for a tenant of 100 rows at 200k rows,
     * where the join took 3.3 s for a word in every row; and for a dense word it reads the table
     * once (0.7 s, where the join took 4.3 s on MySQL). A union, which the servers materialize
     * without an index on the key, read slower through the join too (4.8 s for 3.7 s at 1M rows on
     * MariaDB).
     */
    private static function whereMatches(Builder $base, array $terms, string $modelType, array $columnWeights, bool $sparse = false): Builder
    {
        $key   = self::keyColumn($base);
        $model = $sparse && self::unconstrained($base) ? $base->getModel() : null;

        return (clone $base)->withGlobalScope(self::MATCHES, fn (Builder $query) => app(Bm25Scorer::class)
            ->whereRanked($query->getQuery(), $key, $terms, $modelType, $columnWeights, $model));
    }

    /**
     * Whether $base reads its model's table with no constraint: no where, join, group, having or
     * union, the caller's or a global scope's, but the SoftDeletes scope's (it hides few rows), and a
     * FROM that names a table (an alias of it too).
     */
    private static function unconstrained(Builder $base): bool
    {
        $query = (clone $base)->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->toBase();

        return empty($query->wheres) && empty($query->joins) && empty($query->groups) && empty($query->havings) && empty($query->unions) && is_string($query->from);
    }

    /**
     * Whether $query joins a table of the caller's: a join but the matched ids Bm25Scorer::whereRanked()
     * joins (Bm25Scorer::MATCHES), which holds one row per model, so that a read without a join of
     * its own keeps its shape.
     */
    private static function joinsTables(QueryBuilder $query): bool
    {
        $grammar = $query->getGrammar();
        $matches = ') as ' . $grammar->wrapTable(Bm25Scorer::MATCHES);

        foreach ($query->joins ?? [] as $join) {
            if (!($join->table instanceof \Illuminate\Contracts\Database\Query\Expression) || !str_ends_with((string) $join->table->getValue($grammar), $matches)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The wheres of $query from the $from-th on as one group when one of them is an or (RE-1), as
     * Eloquent groups a scope's wheres: a search predicate, a filter() or a Scout where() beside
     * them then holds for all of them, and search($term)->where(A)->orWhere(B) reads
     * (A or B) and <search>, not A or (B and <search>). Wheres with no or stay as they are, so
     * their SQL does not change, and the bindings keep their order.
     */
    public static function groupOrWheres(Builder|QueryBuilder $query, int $from = 0): void
    {
        $query    = $query instanceof Builder ? $query->getQuery() : $query;
        $slice    = array_slice($query->wheres, $from);
        $booleans = array_column($slice, 'boolean');

        if (array_filter($booleans, fn (string $boolean) => str_contains($boolean, 'or')) === []) {
            return;
        }

        $group         = $query->forNestedWhere();
        $group->wheres = $slice;
        $query->wheres = [...array_slice($query->wheres, 0, $from), ['type' => 'Nested', 'query' => $group, 'boolean' => str_replace(' not', '', $booleans[0])]];
    }

    /**
     * $base, with a union read as one derived table named as the model's table (rulings ER-125,
     * ER-127; unionAsTable()): every read here restricts the union's rows to ranked ids, adds the key
     * to the select list or orders the rows, and on a union each of those reached only its first part
     * (the other parts were read whole, or their select lists no longer matched). $base itself when
     * it has no union, so a second call changes nothing.
     *
     * Every read then names the key through that table, so a union whose parts select no key has
     * none to name, and failed with the database's unknown-column error: it throws a LogicException
     * that names the fix instead (see selectsKey()).
     *
     * @throws \LogicException
     */
    public static function rows(Builder $base): Builder
    {
        $query = $base->toBase();

        if (!$query->unions) {
            return $base;
        }

        $model = $base->getModel();

        if (self::selectsKey($query, $model->getKeyName()) === false) {
            throw new \LogicException(sprintf(
                "%s: an index search reads a union as one table by its key, and this union's first part selects no key: select the key ('%s') in every part of the union.",
                $model::class,
                $model->getKeyName()
            ));
        }

        return self::unionAsTable($base, $model->getTable());
    }

    /**
     * $query, a query with a union, as one derived table named $as, read as the query was (SE-2, the
     * index path's rows() and the LIKE and extended paths' SearchBuilder::unionAsTable()):
     *  - an Eloquent query's scopes already apply inside each part, and its eager loads are carried
     *    over;
     *  - its afterQuery() callbacks (Laravel 11+), the Eloquent query's and its query builder's, run
     *    on what the outer read returns: a derived table's own never run;
     *  - the union's own order, when it has no limit or offset of its own, orders the outer read, as
     *    an orderBy() on a query without a union does, its bindings with it: it cannot change which
     *    rows the union holds, and SQL Server rejects an ORDER BY in a derived table without TOP or
     *    OFFSET (a union with a limit stays unsupported there).
     */
    public static function unionAsTable(Builder|QueryBuilder $query, string $as): Builder|QueryBuilder
    {
        $union  = clone ($query instanceof Builder ? $query->toBase() : $query); // toBase() is the query itself without scopes
        $orders = null;

        if ($union->unionLimit === null && $union->unionOffset === null) {
            [$orders, $bindings]           = [$union->unionOrders, $union->bindings['unionOrder']];
            $union->unionOrders            = null;
            $union->bindings['unionOrder'] = [];
        }

        $table = $query instanceof Builder
            ? $query->getModel()->newQueryWithoutScopes()->fromSub($union, $as)->setEagerLoads($query->getEagerLoads())
            : $union->newQuery()->fromSub($union, $as);
        $outer = $table instanceof Builder ? $table->getQuery() : $table;

        if ($orders) {
            $outer->orders            = $orders;
            $outer->bindings['order'] = $bindings;
        }

        if (method_exists($outer, 'afterQuery')) {
            $outer->afterQuery(fn ($result) => $union->applyAfterQueryCallbacks($result));
        }

        if ($query instanceof Builder && method_exists($query, 'afterQuery')) {
            $table->afterQuery(fn ($result) => $query->applyAfterQueryCallbacks($result));
        }

        return $table;
    }

    /**
     * Whether $query's rows carry a column named $key (a union's first part names the union's
     * columns): true when the select list is every column (none, * or table.*) or holds a column
     * so named, or aliased so; null when it holds a raw column that is not one name (a call, a list:
     * selectRaw('name, email')), which only the database can tell; false otherwise.
     *
     * $strict asks whether that column is the model's own key, to hydrate by (needsKeyAlias()): a
     * column aliased as the key may be another one (email as id), and the key in another letter
     * case is named so by some databases (MySQL returns ID), so either makes the answer null, and
     * only every column, or the key's own name in its own case, makes it true.
     */
    private static function selectsKey(QueryBuilder $query, string $key, bool $strict = false): ?bool
    {
        $unknown = false;
        $found   = false;

        foreach ($query->columns ?? ['*'] as $column) {
            $sql = trim($column instanceof \Illuminate\Contracts\Database\Query\Expression ? (string) $column->getValue($query->getGrammar()) : (string) $column);

            if (preg_match('/\s+as\s+(\S+)$/i', $sql, $alias) === 1) {
                $sql = $alias[1];

                if ($strict) {
                    $unknown = $unknown || strcasecmp(trim($sql, '"`[]'), $key) === 0;
                    continue;
                }
            } elseif (preg_match('/[\s,(]/', $sql) === 1) {
                $unknown = true;
                continue;
            }

            $name = trim(substr($sql, (int) strrpos('.' . $sql, '.')), '"`[]');

            if ($name === '*' || ($strict ? $name === $key : strcasecmp($name, $key) === 0)) {
                if (!$strict) {
                    return true;
                }
                $found = true;
            } elseif ($strict && strcasecmp($name, $key) === 0) {
                $unknown = true;
            }
        }

        return $found && !$unknown ? true : ($unknown ? null : false);
    }

    /**
     * Whether a read of $model's rows must select the key under KEY_ALIAS to know each row's key:
     * when a join may shadow it with another table's column of that name, or the select list is not
     * provably the model's own key (ruling D13; selectsKey()'s strict answer: another column aliased
     * as the key, or the key in another case, is not, RA-2). Otherwise each model is matched by its
     * own key, and the alias never reaches it (ruling ER-135; the Scout engine's map()).
     */
    public static function needsKeyAlias(QueryBuilder $query, Model $model): bool
    {
        return !empty($query->joins) || self::selectsKey($query, $model->getKeyName(), true) !== true;
    }

    /**
     * Whether $base reads a union: its own, or one in a derived FROM (rows()'s wrap, or a fromSub()
     * of the caller's). MySQL and MariaDB materialize a union with no index on the key, and MySQL
     * then compares a bound list of string keys with it row by row, so a string ranking is not read
     * through a long bound list there: at 34,000 rows an ordered page took 54 s (0.5 s through the
     * postings subquery) and a constraint check 5.2 s (0.3 s); MariaDB's check 1.4 s (0.7 s). A word
     * "union" elsewhere in a derived FROM (a column so named) only chooses the subquery.
     */
    private static function readsUnion(Builder $base): bool
    {
        $query = $base->getQuery();

        return !empty($query->unions) || ($query->from instanceof \Illuminate\Contracts\Database\Query\Expression
            && preg_match('/\bunion\b/i', (string) $query->from->getValue($query->getGrammar())) === 1);
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
