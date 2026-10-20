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
     * a plain query builder's searchIn()) matches those alone, as on the LIKE paths (TE-12).
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
     * rank()'s max_postings_per_term too, without binding their ids (ruling ER-82): those with one of
     * their postings under a column that is not weighted out, matched on $qualifiedKey. The bindings
     * are the model type and the terms, so an ordered index page reads only matches, however many
     * there are. model_id is a string column, so the key is compared as a string (PostgreSQL has no
     * integer = varchar operator); only for a query on the connection the index lives on, the
     * default one.
     *
     * On MySQL and MariaDB, given the $model whose key $qualifiedKey names, the read is driven from
     * the postings (S3): an inner join to the distinct model_ids of the matched terms (a range of the
     * postings' primary key per term), as the derived table MATCHES, looked up on the key's index (see
     * keyLookup()), one lookup per match. As an EXISTS per row of the model table, the ordered page
     * and its COUNT read the table whole on both servers (8.7–11 s at 6M rows on MySQL for a word in
     * 1% of them; the join takes 0.2 s). The join also compares the two byte-wise, as the subquery
     * does, so a model_id that matches the key only under the key's collation (aBc for a row ABC) is
     * no match, and a model is joined once. But it reads every matched posting, however few rows the
     * query accepts, so RankedCandidates::whereMatches() gives $model only for a read with no
     * constraint and a sparse word (ruling ER-162). Without $model, or with a key no index serves,
     * the read stays the EXISTS below. PostgreSQL and SQL Server keep it too: they read the table
     * whole in tens of milliseconds at 1M rows (a hash semi-join), where the join measured slower.
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
     * @param \Illuminate\Database\Eloquent\Model|null $model        the model whose key $qualifiedKey names, to join on (MySQL, MariaDB)
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

        $query->whereExists(fn ($subquery) => $postings($subquery->selectRaw($select))
            ->whereRaw($subquery->getGrammar()->wrap('fzr.model_id') . " = {$cast}"));
    }

    /**
     * SQL Server: the dictionary ids of $terms, for whereRanked() to inline (ruling ER-224). From the
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
     * once per connection, database, table prefix, table and column for the process; null for a
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
     * read of the same search (UD-1: a memo on the scorer, read back from the container, missed under
     * Octane, where a Scout engine keeps the scorer of the request it was made in); null when it read
     * no postings:
     *  - share: the terms' document frequencies summed over the model's total_docs (a document that
     *    holds two of the terms counts twice);
     *  - cut: whether its read reached bm25.max_postings_per_term, so that the ranking may lack
     *    matches (TF-9).
     *
     * @param  array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param  array<string, int|float>                $columnWeights column => weight; '' and unknown names weigh 1
     * @param  array{share: float, cut: bool}|null     $read          set to what the ranking read (see above)
     * @return array<int|string, float>
     */
    public function rank(array $terms, string $modelType, array $columnWeights = [], ?array &$read = null): array
    {
        $read    = null;
        $weights = $this->weights($terms);
        if ($weights === []) {
            return [];
        }

        $meta = DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->first();

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
            ->keyBy('id');

        if ($termData->isEmpty()) {
            return [];
        }

        $termIds = $termData->keys()->toArray();

        // Document frequency within $modelType, the population N counts. The dictionary's
        // doc_count spans every model, so a word another model used more often than this one
        // has rows gave a negative idf and ranked the best match last. The type's own count of its
        // documents holding the word (fuzzy_index_model_terms, ruling ER-197), which every index
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


        // One range of the postings' primary key per term (S2, S8): a term's postings are
        // contiguous, and each carries its document's length, so no other table is read; a
        // document's postings of a term all carry the same length, MAX() takes it.
        // One row per (document, term), weighted in SQL, ordered by that weighted frequency DESC
        // and capped at max_postings_per_term so that a high-frequency term (e.g. "john" with 50k
        // hits) cannot pull the entire posting list into PHP. The cap is over (document, term)
        // rows — exactly what v2.0 capped, which ordered by raw frequency — so a document is
        // never partially cut across its columns. High-frequency rows are prioritised globally
        // across all matched terms; in a pathological corpus a single dominant term could consume
        // the cap, but at the default 50k the bound is never reached for normal workloads. Rows of
        // equal weight are cut by model_id, then term_id (SE-3): the weight takes few values, so the
        // cap usually falls inside a group of equal rows, and which of them the database kept changed
        // with its plan (a statistics refresh, the ANALYZE after a rebuild): a user paging a capped
        // search was served pages cut from different rankings.
        $maxPostings = (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        $postings = DB::table('fuzzy_index_postings as p')
            ->where('p.model_type_id', $typeId)
            ->whereIn('p.term_id', $termIds)
            ->tap(fn ($q) => $this->readColumns($q, 'p.column_id', $columnWeights))
            ->groupBy('p.model_id', 'p.term_id')
            ->select('p.model_id', 'p.term_id')
            ->selectRaw('MAX(' . DbDialect::rawIdentifier('p.doc_length') . ') as doc_len')
            ->selectRaw($this->weightedFrequencySql($columnWeights) . ' as wf')
            ->orderByDesc('wf')
            ->orderBy('p.model_id')
            ->orderBy('p.term_id')
            ->limit($maxPostings)
            ->get();

        $read = ['share' => array_sum(array_map('intval', $df)) / $N, 'cut' => $postings->count() >= $maxPostings];

        $scores = [];
        foreach ($postings as $row) {
            $f = (float) $row->wf;
            if ($f <= 0) {
                continue;
            }
            $td     = $termData[$row->term_id];
            $weight = (float) ($weights[$td->term] ?? 1.0);
            $n      = min((float) ($df[$row->term_id] ?? 1), $N); // never above N: the idf stays positive
            $idf    = log(($N - $n + 0.5) / ($n + 0.5) + 1);
            $tf     = ($f * ($this->k1 + 1))
                    / ($f + $this->k1 * (1 - $this->b + $this->b * (float) $row->doc_len / $avgdl));

            $scores[$row->model_id] = ($scores[$row->model_id] ?? 0) + $weight * $idf * $tf;
        }

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
