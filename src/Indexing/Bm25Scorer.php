<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class Bm25Scorer
{
    public function __construct(
        private float $k1 = 1.5,
        private float $b  = 0.75,
    ) {}

    /**
     * Normalise the two accepted term shapes — a plain list (every term at 1.0) or
     * term => weight — into term => weight. Keys are cast back to strings for SQL bindings
     * because PHP turns numeric-string keys ('2024') into ints and SQL Server refuses to
     * compare an nvarchar column with an int binding.
     *
     * @param  array<int, string>|array<string, float> $terms
     * @return array<string, float>
     */
    private function weights(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        // A weight map always has numeric values; a term list never does.
        $first = $terms[array_key_first($terms)];

        return (is_int($first) || is_float($first))
            ? $terms
            : array_fill_keys(array_map('strval', array_values($terms)), 1.0);
    }

    /** @param array<string, float> $weights */
    private function termBindings(array $weights): array
    {
        return array_map('strval', array_keys($weights));
    }

    /**
     * In $columnWeights, the weight of every column it does not name: 0 restricts a read to the
     * named columns weighted above 0, and to postings written before column weighting ('', weight
     * 1): a search the builder restricted to some of the model's columns (searchOn() with columns,
     * a plain query builder's searchIn()) matches those alone, as on the LIKE paths.
     */
    public const OTHER_COLUMNS = '*';

    /**
     * Restrict a postings read to the columns $columnWeights lets through, by their ids
     * (IndexManager::columnIds(); a column without one has no posting): not one weighted <= 0, which
     * contributes nothing to rank(), so count() and the ordered read skip it too; and, with
     * OTHER_COLUMNS at 0, only the named ones.
     */
    private function readColumns(\Illuminate\Database\Query\Builder $postings, string $column, array $columnWeights): void
    {
        $excluded = array_values(IndexManager::columnIds(array_keys(array_filter($columnWeights, fn ($w) => (float) $w <= 0))));
        if ($excluded !== []) {
            $postings->whereNotIn($column, $excluded);
        }

        if (array_key_exists(self::OTHER_COLUMNS, $columnWeights) && (float) $columnWeights[self::OTHER_COLUMNS] <= 0) {
            $named = array_keys(array_filter($columnWeights, fn ($w, $name) => (string) $name !== self::OTHER_COLUMNS && (float) $w > 0, ARRAY_FILTER_USE_BOTH));
            $postings->whereIn($column, array_values(IndexManager::columnIds([...array_map('strval', $named), ''])));
        }
    }

    /**
     * Weighted frequency per posting row as SQL: SUM(CASE p.column_id WHEN id THEN p.frequency * w … ELSE p.frequency END).
     * Weights are inlined as floats (they come from code, never from user strings), and the columns' ids
     * (IndexManager::columnIds()) as integers. '' (legacy) and unknown columns fall to the ELSE branch
     * (weight 1); a column without an id has no posting.
     */
    private function weightedFrequencySql(array $columnWeights): string
    {
        // The builder writes the alias p with the connection's table prefix: pfx_p.
        $frequency = DbDialect::rawIdentifier('p.frequency');
        $ids       = IndexManager::columnIds(array_keys($columnWeights));
        $cases     = '';
        foreach ($columnWeights as $column => $weight) {
            $w = (float) $weight;
            if ($w <= 0 || $w == 1.0 || !isset($ids[(string) $column])) {
                continue; // excluded by whereNotIn() / default branch / no posting
            }
            $cases .= ' WHEN ' . (int) $ids[(string) $column] . " THEN {$frequency} * " . sprintf('%.6F', $w);
        }

        return $cases === '' ? "SUM({$frequency})" : 'SUM(CASE ' . DbDialect::rawIdentifier('p.column_id') . "{$cases} ELSE {$frequency} END)";
    }

    /**
     * Count the number of distinct models that contain at least one query term: every match, those
     * past rank()'s max_postings_per_term too.
     *
     * @param array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param array<string, int|float>                $columnWeights column => weight; a weight <= 0 removes the column
     */
    public function count(array $terms, string $modelType, array $columnWeights = []): int
    {
        $weights = $this->weights($terms);
        if ($weights === []) {
            return 0;
        }

        $termIds = DB::table('fuzzy_index_terms')
            ->whereIn('term', $this->termBindings($weights))
            ->pluck('id');
        $typeId  = IndexManager::modelTypeId($modelType);

        if ($termIds->isEmpty() || $typeId === null) {
            return 0;
        }

        return (int) DB::table('fuzzy_index_postings')
            ->where('model_type_id', $typeId)
            ->whereIn('term_id', $termIds)
            ->tap(fn ($q) => $this->readColumns($q, 'column_id', $columnWeights))
            ->distinct('model_id')
            ->count('model_id');
    }

    /** The derived table of matched ids whereRanked() joins on MySQL and MariaDB, named apart from a caller's own joins (see RankedCandidates::orderedKeys()). */
    public const MATCHES = 'fuzzy_matches';

    /**
     * Restrict $query, a query on $modelType's table, to the documents that hold $terms, those past
     * rank()'s max_postings_per_term too, without binding their ids: those with one of
     * their postings under a column that is not weighted out, matched on $qualifiedKey. The bindings
     * are the model type and the terms, so an ordered index page reads only matches, however many
     * there are. model_id is a string column, so the key is compared as a string (PostgreSQL has no
     * integer = varchar operator); only for a query on the connection the index lives on, the
     * default one.
     *
     * On MySQL and MariaDB, given the $model whose key $qualifiedKey names, the read is driven from
     * the postings: an inner join to the distinct model_ids of the matched terms (a range of the
     * postings' primary key per term), as the derived table MATCHES, looked up on the key's index (see
     * keyLookup()), one lookup per match. As an EXISTS per row of the model table, the ordered page
     * and its COUNT read the table whole on both servers (8.7–11 s at 6M rows on MySQL for a word in
     * 1% of them; the join takes 0.2 s). The join also compares the two byte-wise, as the subquery
     * does, so a model_id that matches the key only under the key's collation (aBc for a row ABC) is
     * no match, and a model is joined once. But it reads every matched posting, however few rows the
     * query accepts, so RankedCandidates::whereMatches() gives $model there only for a read with no
     * constraint and a sparse word. Without $model, or with a key no index serves,
     * the read stays the EXISTS below. PostgreSQL keeps it too, where the join measured slower (a
     * hash semi-join reads the table whole in tens of milliseconds at 1M rows, and
     * RankedCandidates::walkToPage() keeps a page's read a walk of the order column's index), and
     * RankedCandidates::matches() lists a sparse word's string ranking, whose key the cast may keep
     * off its index (a native uuid). SQL Server keeps it as well, but given $model it compares
     * model_id with the key in the key's own type too (sqlServerMatch()): with the cast of the key
     * alone no plan could look a matched posting up by key, and the table was read whole, a cast and
     * a hash probe per row (4.7 s for a page at 3M rows for a word in 395 of them; 1.5 ms looked up
     * by key).
     *
     * On MySQL and MariaDB a string cast takes the connection's collation, and a connection whose
     * collation differs from model_id's failed with 1267 "Illegal mix of collations". The key is
     * cast into model_id's own character set and collation (see columnCollation()), explicitly, so
     * the comparison is the column's own whatever the connection says, and the index on model_id is
     * still used. A binary comparison (CAST AS BINARY, COLLATE utf8mb4_bin) is also
     * collation-free, but MySQL then reads every posting of the term for each row. On SQL Server
     * the cast keeps the key column's collation, and a key collated unlike the database failed with
     * "Cannot resolve the collation conflict": it takes the database's default, model_id's own, on
     * the key's side, so the index on model_id is still used.
     *
     * @param array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param array<string, int|float>                $columnWeights column => weight; a weight <= 0 removes the column
     * @param \Illuminate\Database\Eloquent\Model|null $model        the model whose key $qualifiedKey names, to join on (MySQL, MariaDB) or to compare model_id in its key's type (SQL Server)
     */
    public function whereRanked(\Illuminate\Database\Query\Builder $query, string $qualifiedKey, array $terms, string $modelType, array $columnWeights = [], ?\Illuminate\Database\Eloquent\Model $model = null): void
    {
        $terms   = $this->termBindings($this->weights($terms));
        $driver  = $query->getConnection()->getDriverName();
        $grammar = $query->getGrammar();
        $key     = $grammar->wrap($qualifiedKey);
        $cast    = match (true) {
            DbDialect::isMySqlFamily($driver) => self::castToModelId($query->getConnection(), $key),
            $driver === DbDialect::SQLSRV     => "CAST({$key} AS NVARCHAR(191)) COLLATE DATABASE_DEFAULT",
            $driver === DbDialect::SQLITE     => "CAST({$key} AS TEXT)",
            default                           => "CAST({$key} AS VARCHAR)",
        };

        // 0 when the type has no id: no posting has it (ids start at 1), so nothing matches.
        $typeId   = IndexManager::modelTypeId($modelType) ?? 0;
        $termIds  = $driver === DbDialect::SQLSRV ? $this->termIds($query, $terms) : null;
        $postings = fn (\Illuminate\Database\Query\Builder $postings) => $postings
            ->from('fuzzy_index_postings as fzr')
            ->where('fzr.model_type_id', $typeId)
            ->when(
                $termIds !== null,
                fn ($q) => $q->whereIntegerInRaw('fzr.term_id', $termIds),
                fn ($q) => $q->whereIn('fzr.term_id', fn ($ids) => $ids->select('id')->from('fuzzy_index_terms')->whereIn('term', $terms))
            )
            ->tap(fn ($q) => $this->readColumns($q, 'fzr.column_id', $columnWeights));

        $lookup = $model !== null && DbDialect::isMySqlFamily($driver) ? self::keyLookup($query, $model) : null;

        if ($lookup !== null) {
            $id = $grammar->wrap(self::MATCHES . '.fuzzy_match_id');

            $query->joinSub(
                $postings($query->newQuery())->select('fzr.model_id as fuzzy_match_id')->distinct(),
                self::MATCHES,
                fn ($join) => $join->whereRaw("{$key} = " . sprintf($lookup, $id) . " and {$id} = {$cast}")
            );

            return;
        }

        // MySQL (a MariaDB server ignores the hint): FirstMatch, one index probe per row. With
        // model_id in a binary collation, and an index on the order column, MySQL chose a hash semi-join it
        // could not key on the cast key, whose cost grew with the square of the rows: 3.6 s at 100k
        // matches, where FirstMatch takes 0.16 s. On MySQL the subquery is left for the reads the
        // join above does not take: a union, a string key of a derived table or of a column whose
        // collation cannot be read. RankedCandidates::accepted() reads through it on MySQL only for a
        // string key over a union (it checks the ranking by key otherwise), because for a constrained
        // search's few ranked ids the hint forced a scan of the table (0.3 s at 200k rows for 5 ids,
        // where a hash join driven by the postings took 0.04 s).
        $select = $driver === DbDialect::MYSQL ? '/*+ SEMIJOIN(FIRSTMATCH) */ 1' : '1';

        // SQL Server, given $model: model_id is compared with the key in the key's own type too, so the
        // optimizer can read the matched postings first and look each up by key (see sqlServerMatch()).
        $match = $model !== null && $driver === DbDialect::SQLSRV ? self::sqlServerMatch($query, $model) : null;

        $query->whereExists(function ($subquery) use ($postings, $select, $cast, $match, $key) {
            $modelId = $subquery->getGrammar()->wrap('fzr.model_id');

            $postings($subquery->selectRaw($select))
                ->whereRaw(sprintf($match ?? '%1$s = %3$s', $modelId, $key, $cast));
        });
    }

    /**
     * SQL Server: how whereRanked() compares model_id with $model's key, as a sprintf() format of
     * model_id (1), the key (2) and its cast (3), or null for the cast alone. The cast of the key
     * keeps the key's index out, so no plan could look a matched posting up by key: an ordered page
     * and its COUNT probed the postings for every row of the table, or hashed the table whole
     * against them, a cast per row (4.7 s for a page at 3M rows for a word in 395 of them; 1.5 ms
     * looked up by key). model_id is compared in the key's own type as well:
     *  - an integer key, and a native uuid (uniqueidentifier): model_id TRY_CAST to it, which is NULL
     *    for another model's model_id, beside the cast. The cast stays, as the probe of the
     *    postings per row it allows is the faster read for a denser word's first page, which walks
     *    the order column's index (1.3 ms at 300k rows for a word in 8% of them, 1.4 s without it);
     *  - an nchar or nvarchar key in model_id's collation: the two columns as they are, in place of
     *    the cast (beside it, or with a COLLATE on either side, the plan read the table whole still:
     *    44 ms where this takes 0.7 at 200k rows for a word in 266 of them). RankedCandidates gives
     *    $model for a string key only for a sparse word, as a denser word's first page walks the order
     *    column's index probing the postings by the key's cast.
     * The key column's type and collation are read from the table the FROM names (sqlServerColumn());
     * another key keeps the cast alone.
     */
    private static function sqlServerMatch(\Illuminate\Database\Query\Builder $query, \Illuminate\Database\Eloquent\Model $model): ?string
    {
        if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return '%1$s = %3$s and TRY_CAST(%1$s AS BIGINT) = %2$s';
        }

        $connection = $query->getConnection();
        $table      = DbDialect::fromTable($query->from)[0] ?? null;
        $key        = $table === null ? null : self::sqlServerColumn($connection, $table, $model->getKeyName());

        if ($key !== null && in_array($key[0], ['nvarchar', 'nchar'], true)) {
            $modelId = self::sqlServerColumn($connection, 'fuzzy_index_postings', 'model_id');

            return $key[1] !== null && $key[1] === ($modelId[1] ?? null) ? '%1$s = %2$s' : null;
        }

        return ($key[0] ?? null) === 'uniqueidentifier' ? '%1$s = %3$s and TRY_CAST(%1$s AS UNIQUEIDENTIFIER) = %2$s' : null;
    }

    /** @var array<string, array{string, ?string}|null> connection, table and column => [type, collation] */
    private static array $sqlServerColumns = [];

    /**
     * Forget the column types and collations read from information_schema (sqlServerColumn(),
     * columnCollation()). IndexManager::resetPipelineCache() calls it, which the compact postings
     * migration and the service provider (after migrations ran in the process) call, so a process
     * that migrated does not compare the key with model_id as the old collations allowed.
     *
     * @internal
     */
    public static function resetColumnCache(): void
    {
        self::$sqlServerColumns = [];
        self::$collations       = [];
    }

    /**
     * SQL Server: the type and collation of $table.$column in the default schema, read from
     * information_schema once per connection, database, table prefix, table and column, until
     * resetColumnCache() (restart long-running workers after changing either outside the package's
     * migrations); null for a column it cannot find.
     *
     * @return array{string, ?string}|null
     */
    private static function sqlServerColumn(\Illuminate\Database\ConnectionInterface $connection, string $table, string $column): ?array
    {
        $id = \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::connectionKey($connection) . "|{$table}|{$column}";

        if (!array_key_exists($id, self::$sqlServerColumns)) {
            $row = $connection->selectOne(
                'select data_type as type, collation_name as collation from information_schema.columns'
                . ' where table_schema = schema_name() and table_name = ? and column_name = ?',
                [$connection->getTablePrefix() . $table, $column]
            );
            $row = $row === null ? null : (object) $row; // an app-wide array fetch mode returns arrays

            self::$sqlServerColumns[$id] = $row === null ? null : [strtolower((string) $row->type), $row->collation === null ? null : (string) $row->collation];
        }

        return self::$sqlServerColumns[$id];
    }

    /**
     * SQL Server: the dictionary ids of $terms, for whereRanked() to inline. From the
     * subquery's terms the optimizer can only estimate their postings at the average (804 for 13,305
     * at 1M rows), and an ordered page past the first one then read the model table whole through a
     * hash semi-join: 1.6 to 2.2 s for pages 30 and 500, where page 1 walked the order column's
     * index in 12 ms. Each inlined id is estimated from the postings' own statistics, and the walk
     * holds: 0.4 s for those pages, and the COUNT of a word in a quarter of the rows 70 ms for 286.
     * Inlined, not bound: a plan compiled for one word's ids is not reused for another's.
     *
     * @param  array<int, string> $terms
     * @return array<int, int>
     */
    private function termIds(\Illuminate\Database\Query\Builder $query, array $terms): array
    {
        return $query->newQuery()->from('fuzzy_index_terms')->whereIn('term', $terms)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * MySQL/MariaDB: how whereRanked()'s join looks a matched model_id up on $model's key, as a
     * sprintf() format of it, or null where no index can serve the lookup. An integer key is compared
     * with the string as a number, and looked up on its index (model_id holds strval() of the key, so
     * no leading zeros). A string key is looked up with the model_id cast into the key column's own
     * character set and collation, so that the key's index is used; only on a table the FROM names
     * (a derived table's column may be of another character set).
     */
    private static function keyLookup(\Illuminate\Database\Query\Builder $query, \Illuminate\Database\Eloquent\Model $model): ?string
    {
        if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return '%s';
        }

        $table     = DbDialect::fromTable($query->from)[0] ?? null;
        $collation = $table === null ? null : self::columnCollation($query->getConnection(), $table, $model->getKeyName());

        return $collation === null ? null : "CAST(%s AS CHAR CHARACTER SET {$collation[0]}) COLLATE {$collation[1]}";
    }

    /** @var array<string, array{string, string}|null> connection, table and column => [charset, collation] */
    private static array $collations = [];

    /** MySQL/MariaDB: $key cast into model_id's character set and collation, or plain CAST AS CHAR if they cannot be read. */
    private static function castToModelId(\Illuminate\Database\ConnectionInterface $connection, string $key): string
    {
        $collation = self::columnCollation($connection, 'fuzzy_index_postings', 'model_id');

        return $collation === null ? "CAST({$key} AS CHAR)" : "CAST({$key} AS CHAR CHARACTER SET {$collation[0]}) COLLATE {$collation[1]}";
    }

    /**
     * MySQL/MariaDB: the character set and collation of $table.$column, read from information_schema
     * once per connection, database, table prefix, table and column, until resetColumnCache(); null for a
     * column without one (a number, a binary string) or one it cannot find. Names that are not plain
     * identifiers are never written into SQL.
     *
     * @return array{string, string}|null
     */
    private static function columnCollation(\Illuminate\Database\ConnectionInterface $connection, string $table, string $column): ?array
    {
        $id = \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::connectionKey($connection) . "|{$table}|{$column}";

        if (!array_key_exists($id, self::$collations)) {
            $row = $connection->selectOne(
                'select character_set_name as charset, collation_name as collation from information_schema.columns'
                . ' where table_schema = database() and table_name = ? and column_name = ?',
                [$connection->getTablePrefix() . $table, $column]
            );
            $row = $row === null ? null : (object) $row; // an app-wide array fetch mode returns arrays

            self::$collations[$id] = $row !== null && preg_match('/^\w+$/', (string) $row->charset) && preg_match('/^\w+$/', (string) $row->collation)
                ? [(string) $row->charset, (string) $row->collation]
                : null;
        }

        return self::$collations[$id];
    }

    /**
     * Run BM25 over the inverted index and return the top scored model IDs.
     *
     * @param  array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param  string                                  $modelType     Fully-qualified model class name
     * @param  int                                     $limit
     * @param  array<string, int|float>                $columnWeights column => weight; '' and unknown names weigh 1
     * @return Collection<object{model_id: int|string, score: float}>
     */
    public function search(array $terms, string $modelType, int $limit = 15, array $columnWeights = []): Collection
    {
        return collect(array_slice($this->rank($terms, $modelType, $columnWeights), 0, $limit, true))
            ->map(fn($score, $modelId) => (object) ['model_id' => $modelId, 'score' => $score]);
    }

    /**
     * Run BM25 over the inverted index and return the complete ranking as
     * [model_id => score], best first. Callers that must apply Eloquent constraints
     * (filters, scopes) walk this list so the cut happens after constraining, not before.
     *
     * BM25F-lite: for each (document, term), sums columnWeight × frequency across that
     * term's column postings, then applies the BM25 saturation once. With no weights this
     * equals the merged frequency, so unweighted calls and legacy (column '') postings
     * score exactly as before column weights existed.
     *
     * $read is set to what the ranking read, for RankedCandidates::matches() to choose the ordered
     * read of the same search (a memo on the scorer, read back from the container, missed under
     * Octane, where a Scout engine keeps the scorer of the request it was made in); null when it read
     * no postings:
     *  - share: the terms' document frequencies summed over the model's total_docs (a document that
     *    holds two of the terms counts twice);
     *  - cut: whether a term's read reached bm25.max_postings_per_term, so that the ranking may
     *    lack matches.
     *
     * A typo expansion in $sources counts the documents of the word it expands, when the type holds
     * that word and more of them (the blended document frequency Lucene's FuzzyQuery scores with):
     * scored with its own, a common word's rare misspellings outranked every exact hit of it (two
     * misspelled rows over 200 exact ones, at more than twice the best exact score). Its idf is then
     * the word's, and its weight (1 - distance / length, bm25.fuzzy.damping) puts it below them. A
     * word the type lacks (the user typed the misspelling) leaves its expansions their own idf.
     *
     * @param  array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param  array<string, int|float>                $columnWeights column => weight; '' and unknown names weigh 1
     * @param  array{share: float, cut: bool}|null     $read          set to what the ranking read (see above)
     * @param  array<string, string>                   $sources       typo expansion => the word among $terms it expands (TermExpander::expand())
     * @return array<int|string, float>
     */
    public function rank(array $terms, string $modelType, array $columnWeights = [], ?array &$read = null, array $sources = []): array
    {
        $read    = null;
        $weights = $this->weights($terms);
        if ($weights === []) {
            return [];
        }

        $meta = DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->first();
        $meta = $meta === null ? null : (object) $meta; // an app-wide array fetch mode returns arrays

        if (!$meta || $meta->total_docs == 0) {
            return [];
        }

        $N      = (float) $meta->total_docs;
        $avgdl  = (float) $meta->avg_doc_length ?: 1.0;
        $typeId = IndexManager::modelTypeId($modelType);

        if ($typeId === null) {
            return [];
        }

        $termData = DB::table('fuzzy_index_terms')
            ->whereIn('term', $this->termBindings($weights))
            ->select('id', 'term')
            ->get()
            ->map(fn ($row) => (object) $row) // an app-wide array fetch mode returns arrays
            ->keyBy('id');

        if ($termData->isEmpty()) {
            return [];
        }

        $termIds = $termData->keys()->toArray();

        // Document frequency within $modelType, the population N counts. The dictionary's
        // doc_count spans every model, so a word another model used more often than this one
        // has rows gave a negative idf and ranked the best match last. The type's own count of its
        // documents holding the word (fuzzy_index_model_terms), which every index
        // write keeps with the postings in its transaction and a rebuild derives again: the
        // COUNT(DISTINCT model_id) of the word's postings read every one of them (554 ms for a word
        // in 1M rows on MySQL). Read by its primary key, chunked under SQL Server's 2,100
        // bindings; a word the type has no row for has no posting of it either: 0.
        $df = [];
        foreach (array_chunk($termIds, 1000) as $chunk) {
            $df += DB::table('fuzzy_index_model_terms')
                ->where('model_type_id', $typeId)
                ->whereIn('term_id', $chunk)
                ->pluck('doc_count', 'term_id')
                ->all();
        }


        // One range of the postings' primary key per term: a term's postings are
        // contiguous, and each carries its document's length, so no other table is read; a
        // document's postings of a term all carry the same length, MAX() takes it.
        // One row per (document, term), weighted in SQL. A term in more of the type's documents than
        // max_postings_per_term is ordered by that weighted frequency DESC and capped there, on its
        // own, so that a high-frequency term (e.g. "john" with 50k hits) cannot pull its entire
        // posting list into PHP. One cap shared by every term let a common term fill it and push a
        // rarer term's rows out, the best match holding both included. A term in no more documents
        // than the cap is read whole, unordered. Each read is one part of a UNION ALL, so the
        // ranking stays one statement, and its rows are scored as they arrive: PHP holds a score
        // per document, not the rows. They come from the connection's own cursor, as cheap a row as
        // get(): the query builder's cursor() wraps each row in a Collection and two closures, 20 ms
        // of PHP for a common word's 50,000 rows. The cap is over (document, term) rows — exactly what v2.0
        // capped, which ordered by raw frequency — so a document is never partially cut across its
        // columns. Rows of equal weight are cut by model_id: the weight takes few values, so
        // the cap usually falls inside a group of equal rows, and which of them the database kept
        // changed with its plan (a statistics refresh, the ANALYZE after a rebuild): a user paging a
        // capped search was served pages cut from different rankings.
        $maxPostings = (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        $termRows = fn (array $ids) => DB::table('fuzzy_index_postings as p')
            ->where('p.model_type_id', $typeId)
            ->whereIn('p.term_id', $ids)
            ->tap(fn ($q) => $this->readColumns($q, 'p.column_id', $columnWeights))
            ->groupBy('p.model_id', 'p.term_id')
            ->select('p.model_id', 'p.term_id')
            ->selectRaw('MAX(' . DbDialect::rawIdentifier('p.doc_length') . ') as doc_len')
            ->selectRaw($this->weightedFrequencySql($columnWeights) . ' as wf');

        $capped = array_values(array_filter($termIds, fn ($id) => (int) ($df[$id] ?? 0) > $maxPostings));
        $whole  = array_values(array_diff($termIds, $capped));
        $parts  = array_map(fn ($id) => $termRows([$id])->orderByDesc('wf')->orderBy('p.model_id')->limit($maxPostings), $capped);
        $query  = $whole === [] ? array_shift($parts) : $termRows($whole);
        foreach ($parts as $part) {
            $query->unionAll($part);
        }
        $postings = $query->getConnection()->cursor($query->toSql(), $query->getBindings());

        // The idf's document frequency: an expansion's, at least its word's (see above).
        $idfDf = $df;
        $ids   = [];
        foreach ($termData as $id => $term) {
            $ids[(string) $term->term] = $id;
        }
        foreach ($sources as $term => $source) {
            if (isset($ids[(string) $term], $ids[(string) $source], $df[$ids[(string) $source]])) {
                $idfDf[$ids[(string) $term]] = max((int) ($df[$ids[(string) $term]] ?? 0), (int) $df[$ids[(string) $source]]);
            }
        }

        $scores = [];
        $rows   = []; // term_id => the rows read of it
        foreach ($postings as $row) {
            $row = (object) $row; // an app-wide array fetch mode returns arrays
            $rows[$row->term_id] = ($rows[$row->term_id] ?? 0) + 1;
            $f   = (float) $row->wf;
            if ($f <= 0) {
                continue;
            }
            $td     = $termData[$row->term_id];
            $weight = (float) ($weights[$td->term] ?? 1.0);
            $n      = min((float) ($idfDf[$row->term_id] ?? 1), $N); // never above N: the idf stays positive
            $idf    = log(($N - $n + 0.5) / ($n + 0.5) + 1);
            $tf     = ($f * ($this->k1 + 1))
                    / ($f + $this->k1 * (1 - $this->b + $this->b * (float) $row->doc_len / $avgdl));

            $scores[$row->model_id] = ($scores[$row->model_id] ?? 0) + $weight * $idf * $tf;
        }

        $read = ['share' => array_sum(array_map('intval', $df)) / $N, 'cut' => array_filter($capped, fn ($id) => ($rows[$id] ?? 0) >= $maxPostings) !== []];

        // Rounded before sorting: the sums' last bits depend on the order the database returned
        // the postings in. Best first; a tie goes to the lower model key, so equal scores come
        // back in one order on every database. A total order over mixed keys: integer keys first,
        // as numbers, then string keys byte-wise (numbers-or-strings alone made 9 < 10 < "5x" < 9).
        $scores = array_map(fn($score) => round($score, 6), $scores);
        uksort($scores, fn ($a, $b) => ($scores[$b] <=> $scores[$a]) ?: match (true) {
            is_int($a) && is_int($b) => $a <=> $b,
            is_int($a)               => -1,
            is_int($b)               => 1,
            default                  => strcmp($a, $b),
        });

        return $scores;
    }
}
