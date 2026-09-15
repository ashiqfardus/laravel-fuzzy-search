<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Fuzzy Driver - Advanced fuzzy matching with multiple patterns
 * Handles typos, missing characters, transpositions
 */
class FuzzyDriver extends BaseDriver
{
    protected int $maxDistance;
    protected int $minWordLength;

    public function __construct(array $config, string $driver)
    {
        parent::__construct($config, $driver);
        $this->maxDistance   = (int) ($config['fuzzy']['max_distance']
            ?? $config['typo_tolerance']['max_distance'] ?? 2);
        $this->minWordLength = (int) ($config['typo_tolerance']['min_word_length'] ?? 4);
    }

    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $patterns = $this->generatePatterns($value);
        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $col = $this->quoteColumn($column);

        return $query->$method(function ($q) use ($col, $column, $patterns) {
            foreach ($patterns as $index => $pattern) {
                if ($this->driver === 'pgsql') {
                    if ($index === 0) {
                        $q->whereRaw("{$col} ILIKE ?", [$pattern]);
                    } else {
                        $q->orWhereRaw("{$col} ILIKE ?", [$pattern]);
                    }
                } else {
                    if ($index === 0) {
                        $q->where($column, 'LIKE', $pattern);
                    } else {
                        $q->orWhere($column, 'LIKE', $pattern);
                    }
                }
            }
        });
    }

    /**
     * Generate fuzzy patterns for matching
     */
    protected function generatePatterns(string $value): array
    {
        $value    = strtolower(trim($value));
        $patterns = [];
        $len      = strlen($value);

        // Distance 0 — always
        $patterns[] = '%' . $this->escapeLike($value) . '%';
        $patterns[] = $this->escapeLike($value) . '%';

        // Split on spaces for multi-word search (distance 0 signal)
        $words = explode(' ', $value);
        if (count($words) > 1) {
            foreach ($words as $word) {
                if (strlen($word) > 2) {
                    $patterns[] = '%' . $this->escapeLike($word) . '%';
                }
            }
        }

        $distance = $len < $this->minWordLength ? 0 : $this->maxDistance;

        if ($distance >= 1) {
            for ($i = 0; $i < $len; $i++) { // omissions
                $patterns[] = '%' . $this->escapeLike(substr($value, 0, $i)) . '%' . $this->escapeLike(substr($value, $i + 1)) . '%';
            }
            for ($i = 0; $i < $len; $i++) { // substitutions
                $patterns[] = '%' . $this->escapeLike(substr($value, 0, $i)) . '_' . $this->escapeLike(substr($value, $i + 1)) . '%';
            }
            for ($i = 0; $i < $len - 1; $i++) { // transpositions
                $transposed = substr($value, 0, $i) . $value[$i + 1] . $value[$i] . substr($value, $i + 2);
                $patterns[] = '%' . $this->escapeLike($transposed) . '%';
            }
        }

        if ($distance >= 2) {
            if ($len > 4) { // double-character removal
                for ($i = 0; $i < $len - 1; $i++) {
                    if ($value[$i] === $value[$i + 1]) {
                        $patterns[] = '%' . $this->escapeLike(substr($value, 0, $i) . substr($value, $i + 1)) . '%';
                    }
                }
            }
            if ($len > 3) { // word boundaries
                $patterns[] = $this->escapeLike($value[0]) . '%' . $this->escapeLike(substr($value, -2));
                $patterns[] = $this->escapeLike(substr($value, 0, 2)) . '%' . $this->escapeLike(substr($value, -1));
            }
        }

        return $this->capPatterns($patterns);
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $col = $this->quoteColumn($column);
        $value = strtolower(trim($value));

        $expression = match ($this->driver) {
            'mysql' => "
                IF(LOWER({$col}) = ?, 300, 0) +
                IF(LOWER({$col}) LIKE ?, 200, 0) +
                IF(LOWER({$col}) LIKE ?, 100, 0) +
                IF(LOWER({$col}) LIKE ?, 50, 0) +
                IF(LOCATE(?, LOWER({$col})) > 0, 25, 0)
            ",
            'pgsql' => "
                CASE WHEN LOWER({$col}) = ? THEN 300 ELSE 0 END +
                CASE WHEN {$col} ILIKE ? THEN 200 ELSE 0 END +
                CASE WHEN {$col} ILIKE ? THEN 100 ELSE 0 END +
                CASE WHEN {$col} ILIKE ? THEN 50 ELSE 0 END +
                CASE WHEN POSITION(? IN LOWER({$col})) > 0 THEN 25 ELSE 0 END
            ",
            default => "
                CASE WHEN LOWER({$col}) = ? THEN 300 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 200 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 100 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 50 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 25 ELSE 0 END
            ",
        };

        return $expression;
    }

    public function getRelevanceBindings(string $value): array
    {
        $value = strtolower(trim($value));

        return [
            $value,              // Exact
            $value . '%',        // Starts with
            '% ' . $value . '%', // Word boundary start
            '%' . $value . ' %', // Word boundary end
            $value,              // Contains (for LOCATE/POSITION)
        ];
    }
}

