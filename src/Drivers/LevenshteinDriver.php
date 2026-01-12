<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Levenshtein Driver - Edit distance based matching
 * Best for typo tolerance
 */
class LevenshteinDriver extends BaseDriver
{
    protected int $maxDistance = 3;

    public function __construct(array $config, string $driver)
    {
        parent::__construct($config, $driver);
        $this->maxDistance = $config['levenshtein']['max_distance'] ?? 3;
    }

    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        // Check for native Levenshtein support
        if ($this->driver === 'mysql' && ($this->config['use_native_functions'] ?? false)) {
            return $this->applyNativeMySQL($query, $column, $value, $boolean);
        }

        if ($this->driver === 'pgsql' && ($this->config['use_native_functions'] ?? false)) {
            return $this->applyNativePostgres($query, $column, $value, $boolean);
        }

        // Fallback to pattern-based matching
        return $this->applyPatternBased($query, $column, $value, $boolean);
    }

    /**
     * Apply native MySQL Levenshtein (requires UDF)
     */
    protected function applyNativeMySQL(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $col = $this->quoteColumn($column);

        return $query->$method("LEVENSHTEIN({$col}, ?) <= ?", [$value, $this->maxDistance]);
    }

    /**
     * Apply native PostgreSQL using pg_trgm similarity
     */
    protected function applyNativePostgres(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $col = $this->quoteColumn($column);
        $minSimilarity = 1 - ($this->maxDistance / max(strlen($value), 1));

        return $query->$method("similarity({$col}, ?) > ?", [$value, max(0.3, $minSimilarity)]);
    }

    /**
     * Apply pattern-based Levenshtein approximation
     */
    protected function applyPatternBased(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $patterns = $this->generateLevenshteinPatterns($value);
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
     * Generate patterns that approximate Levenshtein distance
     */
    protected function generateLevenshteinPatterns(string $value): array
    {
        $value = strtolower(trim($value));
        $patterns = [];
        $len = strlen($value);

        // Distance 0: Exact match
        $patterns[] = '%' . $value . '%';

        if ($this->maxDistance >= 1) {
            // Distance 1: Single deletion
            for ($i = 0; $i < $len; $i++) {
                $patterns[] = '%' . substr($value, 0, $i) . substr($value, $i + 1) . '%';
            }

            // Distance 1: Single insertion (wildcard)
            for ($i = 0; $i <= $len; $i++) {
                $patterns[] = '%' . substr($value, 0, $i) . '_' . substr($value, $i) . '%';
            }

            // Distance 1: Single substitution (wildcard)
            for ($i = 0; $i < $len; $i++) {
                $patterns[] = '%' . substr($value, 0, $i) . '_' . substr($value, $i + 1) . '%';
            }
        }

        if ($this->maxDistance >= 2) {
            // Distance 2: Double deletion
            for ($i = 0; $i < $len - 1; $i++) {
                for ($j = $i + 1; $j < $len; $j++) {
                    $str = substr($value, 0, $i) . substr($value, $i + 1, $j - $i - 1) . substr($value, $j + 1);
                    if (strlen($str) >= 2) {
                        $patterns[] = '%' . $str . '%';
                    }
                }
            }

            // Distance 2: Transposition + deletion
            for ($i = 0; $i < $len - 1; $i++) {
                $transposed = substr($value, 0, $i) . $value[$i + 1] . $value[$i] . substr($value, $i + 2);
                $patterns[] = '%' . $transposed . '%';
            }
        }

        if ($this->maxDistance >= 3 && $len > 3) {
            // Distance 3: Prefix matching with wildcards
            $patterns[] = substr($value, 0, 2) . '%';
            $patterns[] = '%' . substr($value, -2);

            // Keep first and last char with wildcard in between
            $patterns[] = $value[0] . '%' . substr($value, -1);
        }

        return array_unique($patterns);
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $col = $this->quoteColumn($column);
        $value = strtolower(trim($value));
        $len = strlen($value);

        // Generate patterns for different distances
        $dist0 = '%' . $value . '%';
        $dist1 = strlen($value) > 1 ? '%' . substr($value, 0, -1) . '%' : $dist0;
        $dist2 = strlen($value) > 2 ? '%' . substr($value, 0, -2) . '%' : $dist1;

        return match ($this->driver) {
            'mysql' => "
                IF(LOWER({$col}) LIKE ?, 100, 0) +
                IF(LOWER({$col}) LIKE ?, 50, 0) +
                IF(LOWER({$col}) LIKE ?, 25, 0)
            ",
            'pgsql' => "
                CASE WHEN {$col} ILIKE ? THEN 100 ELSE 0 END +
                CASE WHEN {$col} ILIKE ? THEN 50 ELSE 0 END +
                CASE WHEN {$col} ILIKE ? THEN 25 ELSE 0 END
            ",
            default => "
                CASE WHEN {$col} LIKE ? THEN 100 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 50 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 25 ELSE 0 END
            ",
        };
    }

    public function getRelevanceBindings(string $value): array
    {
        $value = strtolower(trim($value));

        return [
            '%' . $value . '%',
            strlen($value) > 1 ? '%' . substr($value, 0, -1) . '%' : '%' . $value . '%',
            strlen($value) > 2 ? '%' . substr($value, 0, -2) . '%' : '%' . $value . '%',
        ];
    }
}

