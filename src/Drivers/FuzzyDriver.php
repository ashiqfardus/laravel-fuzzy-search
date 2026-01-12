<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Fuzzy Driver - Advanced fuzzy matching with multiple patterns
 * Handles typos, missing characters, transpositions
 */
class FuzzyDriver extends BaseDriver
{
    protected int $maxDistance = 2;

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
        $value = strtolower(trim($value));
        $patterns = [];
        $len = strlen($value);

        // Exact with wildcards
        $patterns[] = '%' . $value . '%';

        // Starts with
        $patterns[] = $value . '%';

        // Single character omissions (typos)
        for ($i = 0; $i < $len; $i++) {
            $patterns[] = '%' . substr($value, 0, $i) . '%' . substr($value, $i + 1) . '%';
        }

        // Single character substitutions (using wildcard)
        for ($i = 0; $i < $len; $i++) {
            $patterns[] = '%' . substr($value, 0, $i) . '_' . substr($value, $i + 1) . '%';
        }

        // Character transpositions (swapped adjacent characters)
        for ($i = 0; $i < $len - 1; $i++) {
            $transposed = substr($value, 0, $i) . $value[$i + 1] . $value[$i] . substr($value, $i + 2);
            $patterns[] = '%' . $transposed . '%';
        }

        // Double character removal (common typo)
        if ($len > 4) {
            for ($i = 0; $i < $len - 1; $i++) {
                if ($value[$i] === $value[$i + 1]) {
                    $patterns[] = '%' . substr($value, 0, $i) . substr($value, $i + 1) . '%';
                }
            }
        }

        // Word boundaries (first and last chars)
        if ($len > 3) {
            $patterns[] = $value[0] . '%' . substr($value, -2);
            $patterns[] = substr($value, 0, 2) . '%' . substr($value, -1);
        }

        // Split on spaces for multi-word search
        $words = explode(' ', $value);
        if (count($words) > 1) {
            foreach ($words as $word) {
                if (strlen($word) > 2) {
                    $patterns[] = '%' . $word . '%';
                }
            }
        }

        return array_unique($patterns);
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

