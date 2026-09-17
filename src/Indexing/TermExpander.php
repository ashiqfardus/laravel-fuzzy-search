<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

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
     * @return list<array{term: string, doc_count: int, distance: int}>
     */
    public function candidates(string $term, int $maxDistance, int $pool): array
    {
        if ($term === '' || $pool <= 0) {
            return [];
        }

        $length = mb_strlen($term);

        $rows = DB::table('fuzzy_index_terms')
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
     * characters, up to $maxExpansions dictionary neighbours within $maxDistance edits. With
     * $damping an expansion weighs 1 - distance / length (exact terms outrank typo matches);
     * without it 1.0. A term reached more than once keeps its highest weight.
     *
     * @param  string[] $terms
     * @return array<string, float>
     */
    public function expand(array $terms, int $maxDistance, int $minWordLength, int $maxExpansions, int $pool, bool $damping): array
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

            $candidates = $this->candidates((string) $term, $maxDistance, $pool);
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
     * Tokens contain only letters, marks and digits (WhitespaceTokenizer), so LIKE needs no escaping.
     *
     * @return array<string, float>
     */
    public function prefix(string $prefix, int $max): array
    {
        if ($prefix === '' || $max <= 0) {
            return [];
        }

        $last = mb_substr($prefix, -1);
        $next = mb_chr(mb_ord($last, 'UTF-8') + 1, 'UTF-8');

        $query = DB::table('fuzzy_index_terms')->where('term', '!=', $prefix);

        if ($next === false) {
            $query->where('term', 'like', $prefix . '%');
        } else {
            $query->where('term', '>=', $prefix)
                  ->where('term', '<', mb_substr($prefix, 0, -1) . $next);
        }

        $terms = $query->orderByDesc('doc_count')->limit($max)->pluck('term');

        $weights = [];
        foreach ($terms as $term) {
            $weights[(string) $term] = 1.0;
        }

        return $weights;
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
