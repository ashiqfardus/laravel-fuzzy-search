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
     * Dictionary terms within $maxDistance edits of $term, most common first.
     *
     * @param  ?string $modelType Restrict to terms posted under this model_type (see postedUnder());
     *                            null leaves the dictionary unscoped.
     * @return list<array{term: string, doc_count: int, distance: int}>
     */
    public function candidates(string $term, int $maxDistance, int $pool, ?string $modelType = null): array
    {
        if ($term === '' || $pool <= 0) {
            return [];
        }

        $term   = Pipeline::capTerm($term); // didYouMean() passes the raw search term
        $length = mb_strlen($term);

        $rows = $this->postedUnder(DB::table('fuzzy_index_terms'), $modelType)
            ->select('term', 'doc_count')
            ->where('term', '!=', $term)
            ->whereBetween('term_length', [max(1, $length - $maxDistance), $length + $maxDistance])
            ->orderByDesc('doc_count')
            ->limit($pool)
            ->get();

        $out = [];
        foreach ($rows as $row) {
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

            $candidates = $this->candidates((string) $term, $maxDistance, $pool, $modelType);
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
     * full scan there. Everywhere else the column is compared under a UCA collation, where the
     * successor character ('{' after 'z', ':' after '9') sorts BELOW letters and digits and the
     * range would silently return nothing — those drivers keep LIKE. On PostgreSQL that costs a
     * scan unless fuzzy_index_terms.term also carries a varchar_pattern_ops index; add one there
     * if as-you-type latency matters on a large dictionary.
     *
     * @param  ?string $modelType Restrict to terms posted under this model_type (see postedUnder());
     *                            null leaves the dictionary unscoped.
     * @return array<string, float>
     */
    public function prefix(string $prefix, int $max, ?string $modelType = null): array
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

        $terms = $this->postedUnder($query, $modelType)->orderByDesc('doc_count')->limit($max)->pluck('term');

        $weights = [];
        foreach ($terms as $term) {
            $weights[(string) $term] = 1.0;
        }

        return $weights;
    }

    /**
     * Restrict a fuzzy_index_terms query to terms posted under $modelType: a whereExists
     * semi-join against fuzzy_index_postings, which postings_term_model_idx (term_id,
     * model_type) covers. The dictionary is shared by every indexed model, so an unscoped
     * lookup offers other models' terms. Null leaves the query unscoped.
     */
    private function postedUnder(Builder $query, ?string $modelType): Builder
    {
        if ($modelType !== null) {
            $query->whereExists(function ($q) use ($modelType) {
                $q->selectRaw('1')
                  ->from('fuzzy_index_postings as sp')
                  ->whereColumn('sp.term_id', 'fuzzy_index_terms.id')
                  ->where('sp.model_type', $modelType);

                $this->visibleColumnsOnly($q, $modelType);
            });
        }

        return $query;
    }

    /**
     * Only postings of columns the model shows (ER-51): not one in $hidden, nor one left out of a
     * non-empty $visible, so suggest(), didYouMean() and the expansions never offer a hidden
     * column's words. A word that is also in a visible column is still offered. Postings written
     * before 2.1 carry no column name (''), so they are left out too, but only when the model
     * hides one of its searchable columns; rebuilding the index brings those words back.
     */
    private function visibleColumnsOnly(Builder $postings, string $modelType): void
    {
        if (!is_subclass_of($modelType, Model::class)) {
            return;
        }

        $model    = new $modelType();
        $hidden   = $model->getHidden();
        $visible  = $model->getVisible();
        $isHidden = fn (string $column) => in_array($column, $hidden, true) || ($visible !== [] && !in_array($column, $visible, true));
        $legacyOk = !method_exists($model, 'getSearchableColumns')
            || array_filter($model->getSearchableColumns(), $isHidden) === [];

        if ($visible !== []) {
            $postings->whereIn('sp.column_name', $legacyOk ? [...$visible, ''] : $visible);
        }

        $excluded = $legacyOk ? $hidden : [...$hidden, ''];
        if ($excluded !== []) {
            $postings->whereNotIn('sp.column_name', $excluded);
        }
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
