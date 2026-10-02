<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Expands query terms against the fuzzy_index_terms dictionary: neighbours within an edit
 * distance (typo-tolerant BM25) and prefix matches (as-you-type). The dictionary lives on the
 * default connection — the one IndexManager writes to — so every query here uses DB::table().
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class TermExpander
{
    /**
     * TC-4: candidates() reads a length at a time (S1) when the model's total_tokens squared passes
     * this many times the pool times the index's total_tokens, and the whole window once otherwise.
     * Measured at 1M dictionary words, a pool of 500 and models that hold every k-th word, the two
     * reads crossed between 1.3 and 2.1 on MySQL, 7.6 and 11 on PostgreSQL, and 19 and 37 on
     * MariaDB and on SQL Server. SQLite reads a length at a time for every model: its read of the
     * window scanned the whole window whatever the share (120–230 ms there), never faster.
     *
     * ponytail: the same even-spread cost model as probes(), on the meta totals; a model whose words
     * cluster in a few lengths, or in the dictionary's tail, can sit on the wrong side of it.
     */
    private const PER_LENGTH_ABOVE = [
        \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::MYSQL   => 2.0,
        \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::PGSQL   => 10.0,
        \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::SQLSRV  => 25.0,
        \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::MARIADB => 25.0,
    ];

    /**
     * Dictionary terms within $maxDistance edits of $term, most common first.
     *
     * @param  ?string $modelType   Restrict to terms posted under this model_type (see postedUnder());
     *                              null leaves the dictionary unscoped.
     * @param  bool    $visibleOnly Leave out the columns the model hides (see visibleColumnsOnly()):
     *                              on for didYouMean(), which hands the words back; off for the typo
     *                              expansion, which only matches.
     * @return list<array{term: string, doc_count: int, distance: int}>
     */
    public function candidates(string $term, int $maxDistance, int $pool, ?string $modelType = null, bool $visibleOnly = true): array
    {
        if ($term === '' || $pool <= 0) {
            return [];
        }

        $term   = Pipeline::capTerm($term); // didYouMean() passes the raw search term
        $length = mb_strlen($term);

        // The $pool most common words of the length window, read one length at a time through
        // (term_length, doc_count, id): each read stops after $pool rows in index order, and the
        // window's top $pool are among the lengths' own. One read of the whole window sorted every
        // word in it, a cost that grew with the dictionary (S1). Separate reads, not a UNION of
        // limited parts, which SQLite and SQL Server do not accept. Equal counts are cut by id, the
        // newest word first, in each read and in the merge: most words share a small count, so the
        // cut usually falls inside a tie, and without the id the plan's order (which a statistics
        // refresh changes) or the merge's length order picked the words a typo search finds.
        //
        // That is the read for a model holding a large share of the dictionary. For a small share
        // each length's read was planned as a full read of the model's postings and of every word of
        // that length, once per length (TC-4: 0.75–1.6 s a term on SQL Server at 1M words, where one
        // read of the window took 50 ms): such a model reads the window once, through its own
        // postings, as before S1 (see PER_LENGTH_ABOVE).
        $from   = max(1, $length - $maxDistance);
        $to     = $length + $maxDistance;
        $above  = self::perLengthAbove();
        $totals = $modelType === null || $above <= 0.0 ? null : $this->totals($modelType);
        $read   = fn (bool $probe) => $this->postedUnder(DB::table('fuzzy_index_terms'), $modelType, $visibleOnly, $probe)
            ->select('id', 'term', 'doc_count')
            ->where('term', '!=', $term)
            ->orderByDesc('doc_count')
            ->orderByDesc('id')
            ->limit($pool);

        if ($totals === null || $totals[0] * $totals[0] > $above * $pool * $totals[1]) {
            $probe = $this->probes($modelType, $pool, $totals);
            $rows  = [];
            for ($l = $from; $l <= $to; $l++) {
                $rows = [...$rows, ...$read($probe)->where('term_length', $l)->get()->all()];
            }
            usort($rows, fn ($a, $b) => [(int) $b->doc_count, (int) $b->id] <=> [(int) $a->doc_count, (int) $a->id]);
        } else {
            $rows = $read(false)->whereBetween('term_length', [$from, $to])->get()->all();
        }

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
     * would, so it always counts for less — but it is not outranked automatically: BM25 weighs
     * rarity (idf), so a rare expansion can still outscore a common exact term. Without $damping
     * every expansion is 1.0. A term reached more than once keeps its highest weight.
     * Neighbours come from $modelType's own terms when given (see candidates()).
     *
     * @param  string[] $terms
     * @return array<string, float>
     */
    public function expand(array $terms, int $maxDistance, int $minWordLength, int $maxExpansions, int $pool, bool $damping, ?string $modelType = null): array
    {
        $weights = array_fill_keys($terms, 1.0);

        if ($maxDistance <= 0 || $maxExpansions <= 0) {
            return $weights;
        }

        foreach ($terms as $term) {
            $length = mb_strlen((string) $term);
            if ($length < $minWordLength) {
                continue;
            }

            // Matching keeps hidden columns (ER-66): the rows a search returns do not depend on the path.
            $candidates = $this->candidates((string) $term, $maxDistance, $pool, $modelType, visibleOnly: false);
            usort($candidates, fn ($a, $b) => [$a['distance'], $b['doc_count']] <=> [$b['distance'], $a['doc_count']]);

            $taken = 0;
            foreach ($candidates as $candidate) {
                if ($taken >= $maxExpansions) {
                    break;
                }
                $weight = $damping ? 1 - $candidate['distance'] / max($length, 1) : 1.0;
                if ($weight <= 0) {
                    continue;
                }
                $weights[$candidate['term']] = max($weights[$candidate['term']] ?? 0.0, $weight);
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
     * @param  ?string $modelType   Restrict to terms posted under this model_type (see postedUnder());
     *                              null leaves the dictionary unscoped.
     * @param  bool    $visibleOnly Leave out the columns the model hides (see visibleColumnsOnly()):
     *                              on for suggest(), which hands the words back; off for asYouType(),
     *                              which only matches.
     * @return array<string, float>
     */
    public function prefix(string $prefix, int $max, ?string $modelType = null, bool $visibleOnly = true): array
    {
        if ($prefix === '' || $max <= 0) {
            return [];
        }

        $prefix = Pipeline::capTerm($prefix); // suggest() passes the raw last word
        $last   = mb_substr($prefix, -1);
        $next = mb_chr(mb_ord($last, 'UTF-8') + 1, 'UTF-8');

        $driver      = DB::connection()->getDriverName();
        $byteOrdered = $driver === 'sqlite' || \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::isMySqlFamily($driver);

        $query = DB::table('fuzzy_index_terms')->where('term', '!=', $prefix);

        if ($next === false || !$byteOrdered) {
            // PostgreSQL reads escapeLike()'s backslashes by default (where() keeps its
            // "term"::text cast); everywhere else it escapes with !, under like()'s ESCAPE '!'.
            $pattern = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::escapeLike($prefix, $driver) . '%';
            \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::needsLikeEscape($driver)
                ? $query->whereRaw(\Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::like($query->getGrammar()->wrap('term'), $driver, 'like'), [$pattern])
                : $query->where('term', 'like', $pattern);
        } else {
            $query->where('term', '>=', $prefix)
                  ->where('term', '<', mb_substr($prefix, 0, -1) . $next);
        }

        // Equal counts by id, the newest word first, as candidates() cuts them.
        $terms = $this->postedUnder($query, $modelType, $visibleOnly, $this->probes($modelType, $max))->orderByDesc('doc_count')->orderByDesc('id')->limit($max)->pluck('term');

        $weights = [];
        foreach ($terms as $term) {
            $weights[(string) $term] = 1.0;
        }

        return $weights;
    }

    /**
     * Which of $terms are posted under a column $modelType shows (see visibleColumnsOnly()):
     * SearchBuilder::getDebugInfo() hands its index_terms back, so it lists only those expansions
     * (ER-87), while the search itself matched every column (ER-66).
     *
     * @param  array<int, string> $terms
     * @return list<string>
     */
    public function visible(array $terms, string $modelType): array
    {
        if ($terms === []) {
            return [];
        }

        return $this->postedUnder(DB::table('fuzzy_index_terms')->whereIn('term', array_map('strval', $terms)), $modelType, true, $this->probes($modelType, count($terms)))
            ->pluck('term')
            ->map(fn ($term) => (string) $term)
            ->all();
    }

    /**
     * Restrict a fuzzy_index_terms query to terms posted under $modelType: a whereExists
     * semi-join against fuzzy_index_postings, which postings_unique_idx (term_id, model_type,
     * model_id, column_name) covers. The dictionary is shared by every indexed model, so an
     * unscoped lookup offers other models' terms. Null leaves the query unscoped. $probe: see
     * probes().
     */
    private function postedUnder(Builder $query, ?string $modelType, bool $visibleOnly, bool $probe = false): Builder
    {
        if ($modelType !== null) {
            $query->whereExists(function ($q) use ($modelType, $visibleOnly, $probe) {
                $q->selectRaw($probe ? '/*+ SEMIJOIN(FIRSTMATCH) */ 1' : '1')
                  ->from('fuzzy_index_postings as sp')
                  ->whereColumn('sp.term_id', 'fuzzy_index_terms.id')
                  ->where('sp.model_type', $modelType);

                if ($visibleOnly) {
                    $this->visibleColumnsOnly($q, $modelType);
                }
            });
        }

        return $query;
    }

    /**
     * MySQL: whether postedUnder() makes the database probe the postings once per dictionary word,
     * in the read's order, until $rows words of the model turn up (FirstMatch), rather than first
     * read every posting of the model. MySQL chose the full read for a model holding most of the
     * index, once per length of candidates(): 10–14 s a term at 1M words, where probing took
     * 20–70 ms (S1). For a small model the full read is the cheap one, and MySQL picks it: probing
     * walked the whole length for its few words (3 s at 1M words, where reading them took 6 ms).
     * Probing reads about $rows × (the index's postings / the model's) words, the full read the
     * model's postings, and the meta totals stand in for postings (total_tokens counts a model's
     * word occurrences): probe when the model's total_tokens squared passes $rows times the index's.
     * MariaDB, PostgreSQL and SQL Server plan a large model's reads well unhinted; MariaDB ignores
     * the hint, which reaches it on Laravel 10, whose MariaDB connection is a mysql one. A model
     * below PER_LENGTH_ABOVE reads the window once, unhinted (see candidates()).
     *
     * ponytail: a cost model on the meta totals that assumes a model's words spread evenly over the
     * dictionary, so a model whose words are all rare can still probe long; replace it if MySQL
     * learns to cost a semi-join under a LIMIT.
     */
    private function probes(?string $modelType, int $rows, ?array $totals = null): bool
    {
        if ($modelType === null || DB::connection()->getDriverName() !== \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::MYSQL) {
            return false;
        }

        [$own, $total] = $totals ?? $this->totals($modelType);

        return $own * $own > $rows * $total;
    }

    /** @return array{float, float} $modelType's total_tokens and the whole index's, from fuzzy_index_meta */
    private function totals(string $modelType): array
    {
        $tokens = DB::table('fuzzy_index_meta')->pluck('total_tokens', 'model_type');

        return [(float) ($tokens[$modelType] ?? 0), (float) $tokens->sum()];
    }

    /** PER_LENGTH_ABOVE for this connection; 0 reads a length at a time whatever the share (SQLite). */
    private static function perLengthAbove(): float
    {
        $connection = DB::connection();
        $driver     = $connection->getDriverName();
        // Laravel 10 connects to MariaDB as mysql; the server tells them apart, without a query.
        if ($driver === \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::MYSQL
            && stripos((string) $connection->getReadPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'mariadb') !== false) {
            $driver = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::MARIADB;
        }

        return self::PER_LENGTH_ABOVE[$driver] ?? 0.0;
    }

    /**
     * Only postings of columns the model shows (ER-51, ER-66): not one in $hidden, nor one left out
     * of a non-empty $visible, so suggest() and didYouMean(), which hand words back, never offer a
     * hidden column's words. The typo and prefix expansions only match, and keep every column, as
     * the LIKE path does. A word that is also in a visible column is still offered. Postings written
     * before 2.1 carry no column name (''), so they are left out too, but only when the model
     * hides one of its searchable columns; rebuilding the index brings those words back.
     *
     * A relation column (author.email, indexed through searchableText()) is posted under its dotted
     * name, which the model never lists: it is judged by pathShown() instead, so a column the
     * related model hides, or a relation the model hides, gives no word either (SF-5), and one
     * they show is offered, a non-empty $visible on the model notwithstanding.
     */
    private function visibleColumnsOnly(Builder $postings, string $modelType): void
    {
        if (!is_subclass_of($modelType, Model::class)) {
            return;
        }

        $model   = new $modelType();
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

        if ($visible !== []) {
            $postings->whereIn('sp.column_name', [...$visible, ...$shownPaths, ...($legacyOk ? [''] : [])]);
        }

        $excluded = [...$hidden, ...$hiddenPaths, ...($legacyOk ? [] : [''])];
        if ($excluded !== []) {
            $postings->whereNotIn('sp.column_name', $excluded);
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
     * relation by SearchBuilder::isReachableRelation()'s rule for a declared path (ruling ER-50):
     * public, not static, no required parameter, and declared by neither Laravel nor this package;
     * anything else, or a call that returns no Relation, hides the column.
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

        return self::shows($current, $leaf);
    }

    /** @return array{string, Model}|null the relation's declared name and its related model */
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

        return $relation instanceof \Illuminate\Database\Eloquent\Relations\Relation ? [$method->getName(), $relation->getRelated()] : null;
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
