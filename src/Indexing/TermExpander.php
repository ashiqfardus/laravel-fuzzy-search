<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Expands query terms against the fuzzy_index_terms dictionary, or a model's own words in
 * fuzzy_index_model_terms: neighbours within an edit distance (typo-tolerant BM25) and prefix
 * matches (as-you-type). The dictionary lives on the default connection — the one IndexManager
 * writes to — so every query here uses DB::table().
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class TermExpander
{
    /** @var list<int>|null the dotted column ids, read once an expander (see visibleColumnsOnly()) */
    private ?array $dotted = null;

    /**
     * Dictionary terms within $maxDistance edits of $term, most common first.
     *
     * @param  ?string     $modelType   Only the words this model_type posts, by its own document count
     *                                  (see ownTerms()); null: the whole dictionary, by its doc_count,
     *                                  which has no index on term_length: each length of the window
     *                                  reads the whole dictionary, a cost that grows with it. The
     *                                  package itself always passes a model type.
     * @param  bool|string $visibleOnly Leave out the columns the model hides (see visibleColumnsOnly()):
     *                                  on for didYouMean(), which hands the words back; off for the typo
     *                                  expansion, which only matches. True judges them on $modelType's
     *                                  class, a class name on that class: the searched one, which
     *                                  searchIndexType() may index under another type.
     * @return list<array{term: string, doc_count: int, distance: int}>
     */
    public function candidates(string $term, int $maxDistance, int $pool, ?string $modelType = null, bool|string $visibleOnly = true): array
    {
        if ($term === '' || $pool <= 0) {
            return [];
        }

        $term   = Pipeline::capTerm($term); // didYouMean() passes the raw search term
        $length = mb_strlen($term);

        // The $pool most common words of the length window, read one length at a time: each read
        // stops after $pool rows in index order, and the window's top $pool are among the lengths'
        // own. One read of the whole window sorted every word in it, a cost that grew with the
        // dictionary. Separate reads, not a UNION of limited parts, which SQLite and SQL Server
        // do not accept. Equal counts are cut by id, the newest word first, in each read and in the
        // merge: most words share a small count, so the cut usually falls inside a tie, and without
        // the id the plan's order (which a statistics refresh changes) or the merge's length order
        // picked the words a typo search finds.
        //
        // A model's words, and its own counts, come from fuzzy_index_model_terms through its
        // model_terms_length_idx (model_type_id, term_length, doc_count, term_id): each read stops after
        // $pool of them, or at the last of its words of that length, so it never reads another
        // model's. Through the shared dictionary, a length where the model had fewer than $pool words
        // was read whole, whichever model posted them: 1M product SKU codes of six characters made
        // a users typo search read all of them (1.2 s on MySQL at 3M words). Unscoped, the
        // shared dictionary is read, which has no index on term_length any more.
        $from = max(1, $length - $maxDistance);
        $to   = $length + $maxDistance;
        $read = $modelType === null
            ? fn () => DB::table('fuzzy_index_terms')->select('id', 'term', 'doc_count')->where('term', '!=', $term)->orderByDesc('doc_count')->orderByDesc('id')
            : fn () => $this->ownTerms($modelType, $visibleOnly)->select('mt.term_id as id', 'mt.term', 'mt.doc_count')->where('mt.term', '!=', $term)->orderByDesc('mt.doc_count')->orderByDesc('mt.term_id');

        $rows = [];
        for ($l = $from; $l <= $to; $l++) {
            $rows = [...$rows, ...$read()->where($modelType === null ? 'term_length' : 'mt.term_length', $l)->limit($pool)->get()->map(fn ($row) => (object) $row)->all()]; // an app-wide array fetch mode returns arrays
        }
        usort($rows, fn ($a, $b) => [(int) $b->doc_count, (int) $b->id] <=> [(int) $a->doc_count, (int) $a->id]);

        $out = [];
        foreach (array_slice($rows, 0, $pool) as $row) {
            $candidate = (string) $row->term;
            $distance  = self::distance($term, $candidate);
            if ($distance <= $maxDistance) {
                $out[] = ['term' => $candidate, 'doc_count' => (int) $row->doc_count, 'distance' => $distance];
            }
        }

        return $out;
    }

    /**
     * Weighted query terms: every input term at 1.0 plus, for terms of at least $minWordLength
     * characters, up to $maxExpansions dictionary neighbours within $maxDistance edits, closest
     * first. With $damping an expansion contributes 1 - distance / length of what the exact term
     * would, so it always counts for less. Without $damping every expansion is 1.0. A term reached
     * more than once keeps its highest weight. Neighbours come from $modelType's own terms when
     * given (see candidates()).
     *
     * $sources is set to each expansion's input term (the one its weight came from), which
     * Bm25Scorer::rank() caps the expansion's idf at: a misspelling is rare, so its own idf put
     * the misspelled rows of a common word above every exact hit.
     *
     * In an index of more of $modelType's documents than bm25.max_postings_per_term, a neighbour in
     * more than half of them is no expansion: a stop word there, with no signal for a misspelling
     * (its Robertson–Spärck Jones idf is at most 0), yet the ranking read every one of its
     * postings: "smith" expanded into "with", in 999,967 of 1M rows, and its read took 2.0 to
     * 2.5 s. A smaller index keeps every expansion (on 10 rows, "phnoe" still finds "phone" in 6),
     * and so does a word in no more than half of the documents, however many ("jhon" finds "john"
     * in more rows than the cap). The words in $terms keep matching at any frequency.
     *
     * @param  string[]                   $terms
     * @param  array<string, string>|null $sources set to expansion => the input term it expands
     * @return array<string, float>
     */
    public function expand(array $terms, int $maxDistance, int $minWordLength, int $maxExpansions, int $pool, bool $damping, ?string $modelType = null, ?array &$sources = null): array
    {
        $weights = array_fill_keys($terms, 1.0);
        $sources = [];

        if ($maxDistance <= 0 || $maxExpansions <= 0) {
            return $weights;
        }

        // The most documents an expansion may hold: half of the model's, in an index of more than
        // the cap; read once a neighbour needs it (one keyed read of the model's total). A smaller
        // index, and the whole dictionary (no $modelType), keep every expansion.
        $most = function () use ($modelType): int {
            $total = $modelType === null ? 0 : (int) DB::table('fuzzy_index_meta')->where('model_type', $modelType)->value('total_docs');

            return $total > (int) config('fuzzy-search.bm25.max_postings_per_term', 50000) ? intdiv($total, 2) : PHP_INT_MAX;
        };
        $limit = null;

        foreach ($terms as $term) {
            $length = mb_strlen((string) $term);
            if ($length < $minWordLength) {
                continue;
            }

            // Matching keeps hidden columns: the rows a search returns do not depend on the path.
            $candidates = $this->candidates((string) $term, $maxDistance, $pool, $modelType, visibleOnly: false);
            usort($candidates, fn ($a, $b) => [$a['distance'], $b['doc_count']] <=> [$b['distance'], $a['doc_count']]);

            $taken = 0;
            foreach ($candidates as $candidate) {
                if ($taken >= $maxExpansions) {
                    break;
                }
                if ($candidate['doc_count'] > ($limit ??= $most())) {
                    continue;
                }
                $weight = $damping ? 1 - $candidate['distance'] / max($length, 1) : 1.0;
                if ($weight <= 0) {
                    continue;
                }
                if ($weight > ($weights[$candidate['term']] ?? 0.0)) {
                    $weights[$candidate['term']] = $weight;
                    $sources[$candidate['term']] = (string) $term;
                }
                $taken++;
            }
        }

        return $weights;
    }

    /**
     * Dictionary terms that start with $prefix (as-you-type), most common first, at weight 1.0.
     * $prefix is caller-supplied (not necessarily a dictionary token), so the LIKE branch escapes
     * it to match literally (DbDialect::escapeLike()); the byte-range branch compares literally
     * already.
     *
     * Where `term` is byte-ordered (SQLite, and MySQL/MariaDB since the utf8mb4_bin migration)
     * the prefix becomes a half-open range, which a btree index can seek; LIKE 'x%' would be a
     * full scan there. PostgreSQL compares the column under a UCA collation, where the successor
     * character ('{' after 'z', ':' after '9') sorts BELOW letters and digits and the range would
     * silently return nothing, so it keeps LIKE; so does SQL Server, whose column is byte-ordered
     * only once 2026_09_30_000001 has run. On PostgreSQL that costs a scan unless
     * fuzzy_index_terms.term also carries a varchar_pattern_ops index; add one there if
     * as-you-type latency matters on a large dictionary.
     *
     * A model's words are read from fuzzy_index_model_terms through (model_type_id, term), most
     * common by its own document count, so the read is bounded by its words under the prefix: the
     * dictionary's range was read whole, and every word in it probed for the model's postings,
     * whichever model posted it (another model's 30,000 codes under "abc" took 90,000 row reads
     * for a model with one word there, on MySQL). On PostgreSQL that key's
     * varchar_pattern_ops serves the LIKE as a range.
     *
     * @param  ?string     $modelType   Only the words this model_type posts (see ownTerms()); null: the
     *                                  whole dictionary.
     * @param  bool|string $visibleOnly Leave out the columns the model hides (see visibleColumnsOnly()):
     *                                  on for suggest(), which hands the words back; off for asYouType(),
     *                                  which only matches. A class name judges them on that class, as
     *                                  in candidates().
     * @return array<string, float>
     */
    public function prefix(string $prefix, int $max, ?string $modelType = null, bool|string $visibleOnly = true): array
    {
        if ($prefix === '' || $max <= 0) {
            return [];
        }

        $prefix = Pipeline::capTerm($prefix); // suggest() passes the raw last word
        $last   = mb_substr($prefix, -1);
        $next = mb_chr(mb_ord($last, 'UTF-8') + 1, 'UTF-8');

        $driver      = DB::connection()->getDriverName();
        $byteOrdered = $driver === 'sqlite' || \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::isMySqlFamily($driver);

        $column = $modelType === null ? 'term' : 'mt.term';
        $query  = ($modelType === null ? DB::table('fuzzy_index_terms') : $this->ownTerms($modelType, $visibleOnly))->where($column, '!=', $prefix);

        if ($next === false || !$byteOrdered) {
            // PostgreSQL reads escapeLike()'s backslashes by default (where() keeps its
            // "term"::text cast); everywhere else it escapes with !, under like()'s ESCAPE '!'.
            $pattern = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::escapeLike($prefix, $driver) . '%';
            \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::needsLikeEscape($driver)
                ? $query->whereRaw(\Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::like($query->getGrammar()->wrap($column), $driver, 'like'), [$pattern])
                : $query->where($column, 'like', $pattern);
        } else {
            $query->where($column, '>=', $prefix)
                  ->where($column, '<', mb_substr($prefix, 0, -1) . $next);
        }

        // Equal counts by id, the newest word first, as candidates() cuts them.
        $terms = $query->orderByDesc($modelType === null ? 'doc_count' : 'mt.doc_count')->orderByDesc($modelType === null ? 'id' : 'mt.term_id')->limit($max)->pluck($column);

        $weights = [];
        foreach ($terms as $term) {
            $weights[(string) $term] = 1.0;
        }

        return $weights;
    }

    /**
     * Which of $terms are posted under $modelType in a column $modelClass shows (see
     * visibleColumnsOnly(); null: $modelType's class): SearchBuilder::getDebugInfo() hands its
     * index_terms back, so it lists only those expansions, while the search itself matched
     * every column.
     *
     * @param  array<int, string> $terms
     * @return list<string>
     */
    public function visible(array $terms, string $modelType, ?string $modelClass = null): array
    {
        if ($terms === []) {
            return [];
        }

        return $this->ownTerms($modelType, $modelClass ?? true)->whereIn('mt.term', array_map('strval', $terms))
            ->pluck('mt.term')
            ->map(fn ($term) => (string) $term)
            ->all();
    }

    /**
     * The words $modelType posts, as the rows of fuzzy_index_model_terms aliased mt:
     * term_id, term, term_length and the type's own doc_count, kept with its postings by every index
     * write. The table knows no column, so with $visibleOnly (didYouMean(), suggest() and
     * visible(), which hand the words back) an EXISTS on the type's postings in the columns the
     * searched class shows (visibleColumnsOnly()) still decides which of them are offered, in the
     * same read, which stays bounded by the type's own words. Without it (the typo and prefix
     * expansions, which only match) the table alone.
     *
     * MySQL probes the postings once per word, in the read's order, until the read has its rows
     * (FirstMatch), rather than first read every posting of the type in the shown columns: for a
     * model holding most of the index that full read took 10–14 s a term at 1M words, where probing
     * took 20–70 ms. Probing the dictionary walked other models' words too, so a cost model on
     * the meta totals chose between them; the model's own words are nearly all hits, so it always
     * probes. MariaDB ignores the hint (it reaches MariaDB on Laravel 10, whose MariaDB connection
     * is a mysql one); the others plan this read well unhinted.
     */
    private function ownTerms(string $modelType, bool|string $visibleOnly): Builder
    {
        // 0 when the type has no id: it has no row (ids start at 1), so no term is offered.
        $typeId = IndexManager::modelTypeId($modelType) ?? 0;
        $query  = DB::table('fuzzy_index_model_terms as mt')->where('mt.model_type_id', $typeId);

        if ($visibleOnly !== false) {
            $probe = DB::connection()->getDriverName() === \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::MYSQL;
            $query->whereExists(function ($q) use ($modelType, $typeId, $visibleOnly, $probe) {
                $q->selectRaw($probe ? '/*+ SEMIJOIN(FIRSTMATCH) */ 1' : '1')
                  ->from('fuzzy_index_postings as sp')
                  ->whereColumn('sp.term_id', 'mt.term_id')
                  ->where('sp.model_type_id', $typeId);
                $this->visibleColumnsOnly($q, $modelType, $visibleOnly === true ? $modelType : $visibleOnly);
            });
        }

        return $query;
    }

    /**
     * Only postings of columns the model shows: not one in $hidden, nor one left out
     * of a non-empty $visible, so suggest() and didYouMean(), which hand words back, never offer a
     * hidden column's words. The typo and prefix expansions only match, and keep every column, as
     * the LIKE path does. A word that is also in a visible column is still offered. Postings written
     * before 3.0 carry no column name (''), so they are left out too, but only when the model
     * hides one of its searchable columns; rebuilding the index brings those words back.
     *
     * A relation column (author.email, indexed through searchableText()) is posted under its dotted
     * name, which the model never lists: it is judged by pathShown() instead, so a column the
     * related model hides, or a relation the model hides, gives no word either, and one
     * they show is offered, a non-empty $visible on the model notwithstanding. A dotted name the
     * model does not declare in $searchable['columns'] gives no word at all.
     *
     * The columns are judged on $modelClass, the searched class, not on the type its rows are posted
     * under. Where the two differ (searchIndexType(): a single-table-inheritance child
     * searched through its parent's index), the type's postings also hold the other classes' rows,
     * whose columns the searched class may not have or may hide differently: only the columns it
     * declares and shows, and the type's class shows too, are offered, legacy '' postings
     * left out.
     *
     * The postings name a column by its id (IndexManager::columnIds()); a name without one has no
     * posting, so it drops out of each list.
     */
    private function visibleColumnsOnly(Builder $postings, string $modelType, string $modelClass): void
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            return;
        }

        $model   = new $modelClass();
        $hidden  = $model->getHidden();
        $visible = $model->getVisible();
        $columns = method_exists($model, 'getSearchableColumns') ? $model->getSearchableColumns() : [];

        $paths = []; // dotted column, as the indexer cuts its name => shown
        foreach ($columns as $column) {
            if (str_contains((string) $column, '.')) {
                $paths[mb_substr((string) $column, 0, 64)] = self::pathShown($model, (string) $column);
            }
        }
        $shownPaths  = array_map('strval', array_keys(array_filter($paths)));
        $hiddenPaths = array_map('strval', array_keys(array_diff_key($paths, array_filter($paths))));

        $isHidden = fn (string $column) => str_contains($column, '.')
            ? !$paths[mb_substr($column, 0, 64)]
            : !self::shows($model, $column);
        $legacyOk = array_filter(array_map('strval', $columns), $isHidden) === [];

        $ids = fn (array $names) => array_values(IndexManager::columnIds($names));

        if ($model::class !== $modelType) { // as PHP names it: a leading backslash or another case is the class itself
            // The type's class must show it too: its postings hold every class's rows, and a
            // column a sibling hides reached a child that shows it, past the parent's $hidden. A
            // relation only the searched class declares is no method of the type's class, which reads
            // the path as a table-qualified column, by its leaf: it must show the relation's name too
            // (the parent's $hidden = ['owner'] never counted).
            $type  = new $modelType();
            $flat  = array_filter(array_map('strval', $columns), fn (string $column) => !str_contains($column, '.') && self::shows($model, $column) && self::shows($type, $column));
            $typed = [];
            foreach (array_map('strval', $columns) as $column) {
                $head = explode('.', $column)[0];
                if (str_contains($column, '.') && $paths[mb_substr($column, 0, 64)] && self::pathShown($type, $column)
                    && (method_exists($type, $head) || !method_exists($model, $head) || self::shows($type, $head))) {
                    $typed[] = mb_substr($column, 0, 64);
                }
            }
            $postings->whereIn('sp.column_id', $ids([...array_values($flat), ...$typed]));

            return;
        }

        if ($visible !== []) {
            $postings->whereIn('sp.column_id', $ids([...$visible, ...$shownPaths, ...($legacyOk ? [''] : [])]));
        }

        $excluded = $ids([...$hidden, ...$hiddenPaths, ...($legacyOk ? [] : [''])]);
        if ($excluded !== []) {
            $postings->whereNotIn('sp.column_id', $excluded);
        }

        // A dotted name only the searchableText() hook gives (a morphTo path, a relation key the
        // model never declares) has no relation walk to judge it by, and the model never hides a
        // dotted name: it is withheld, as only a declared, shown path is offered. The
        // postings name columns by id, so the dotted ids are read from the column dictionary first,
        // once an expander (SearchBuilder resolves one per call, so a dotted column another process
        // records later is withheld too): as a subquery of each read, on a dictionary no rebuild
        // analyzed, it made PostgreSQL scan every posting instead of probing them.
        $this->dotted ??= DB::table('fuzzy_index_columns')->where('name', 'like', '%.%')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $withheld = array_diff($this->dotted, $ids($shownPaths));
        if ($withheld !== []) {
            $postings->whereNotIn('sp.column_id', array_values($withheld));
        }
    }

    /**
     * Whether the model's toArray() shows the dotted searchable column $column, judged on the
     * classes as SearchBuilder::suggestTargets() judges a relation target for the table scan: each
     * relation on the model that holds it, under the name its method declares, then the leaf on
     * the related model. That walk is private to the builder and runs on its resolved targets, and
     * the dictionary has no builder, so it is written again here. A head that is no method of the
     * model makes a two-part name a table-qualified column, judged by its last part, as
     * SearchBuilder::resolveColumnTarget() reads it. A segment is called only when it may be a
     * relation by SearchBuilder::isReachableRelation()'s rule for a declared path:
     * public, not static, no required parameter, and declared by neither Laravel nor this package;
     * anything else, a call that returns no Relation, or a morphTo (see relation()), hides the column.
     * A belongsToMany pivot column (withPivot()) is no attribute of the related model: toArray() puts
     * it under the related model's pivot accessor, on the pivot model, and it is judged there.
     */
    private static function pathShown(Model $model, string $column): bool
    {
        $segments = explode('.', $column);
        $leaf     = array_pop($segments);

        if (!method_exists($model, $segments[0])) {
            return count($segments) === 1 && self::shows($model, $leaf);
        }

        $current = $model;
        foreach ($segments as $segment) {
            $relation = method_exists($current, $segment) ? self::relation($current, $segment) : null;
            if ($relation === null || !self::shows($current, $relation[0])) {
                return false;
            }
            $current = $relation[1];
        }

        $last = $relation[2];
        if ($last instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany && in_array($leaf, $last->getPivotColumns(), true)) {
            return self::shows($current, $last->getPivotAccessor()) && self::shows($last->newPivot(), $leaf);
        }

        return self::shows($current, $leaf);
    }

    /** @return array{string, Model, \Illuminate\Database\Eloquent\Relations\Relation}|null the relation's declared name, its related model and the relation */
    private static function relation(Model $model, string $segment): ?array
    {
        $method = new \ReflectionMethod($model, $segment);
        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return null;
        }

        // A trait's method reports the class that uses it as its declaring class, so the owners
        // are every parent and every trait, each asked whether it has the method.
        foreach ([...class_parents($model), ...class_uses_recursive($model)] as $owner) {
            if (method_exists($owner, $segment)
                && (str_starts_with($owner, 'Illuminate\\')
                    || str_starts_with((string) (new \ReflectionClass($owner))->getFileName(), dirname(__DIR__) . DIRECTORY_SEPARATOR))) {
                return null;
            }
        }

        $relation = $model->{$segment}();

        // A morphTo's related class is each row's own *_type, which no class-level walk knows (on a
        // fresh instance Laravel relates it to the model itself), and docs/relationships.md calls
        // morphTo paths unsupported: its column is withheld.
        return $relation instanceof \Illuminate\Database\Eloquent\Relations\Relation && !$relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo
            ? [$method->getName(), $relation->getRelated(), $relation]
            : null;
    }

    /** Eloquent's own rule for toArray() (getArrayableItems()): not in getHidden(), and in a non-empty getVisible(). */
    private static function shows(Model $model, string $key): bool
    {
        $visible = $model->getVisible();

        return !in_array($key, $model->getHidden(), true) && ($visible === [] || in_array($key, $visible, true));
    }

    /**
     * Character-based edit distance. PHP's levenshtein() counts bytes, which inflates the
     * distance of any non-ASCII term, so only pure-ASCII pairs take that fast path.
     */
    public static function distance(string $a, string $b): int
    {
        if (!preg_match('/[\x80-\xFF]/', $a . $b)) {
            return levenshtein($a, $b);
        }

        $x = mb_str_split($a, 1, 'UTF-8');
        $y = mb_str_split($b, 1, 'UTF-8');
        $m = count($x);
        $n = count($y);

        if ($m === 0) {
            return $n;
        }
        if ($n === 0) {
            return $m;
        }

        $previous = range(0, $n);
        for ($i = 1; $i <= $m; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $n; $j++) {
                $cost        = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
            }
            $previous = $current;
        }

        return $previous[$n];
    }
}
