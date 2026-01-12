<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Trigram Driver - N-gram based similarity matching
 * Best for handling typos and partial matches
 * Similar to PostgreSQL's pg_trgm
 */
class TrigramDriver extends BaseDriver
{
    protected float $minSimilarity = 0.3;

    public function __construct(array $config, string $driver)
    {
        parent::__construct($config, $driver);
        $this->minSimilarity = ($config['trigram']['min_similarity'] ?? 30) / 100;
    }

    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        // PostgreSQL with pg_trgm extension
        if ($this->driver === 'pgsql' && ($this->config['use_native_functions'] ?? false)) {
            return $this->applyNativePostgres($query, $column, $value, $boolean);
        }

        // Fallback: Use trigram-inspired pattern matching
        return $this->applyPatternBased($query, $column, $value, $boolean);
    }

    /**
     * Apply native PostgreSQL pg_trgm
     */
    protected function applyNativePostgres(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $col = $this->quoteColumn($column);

        return $query->$method("similarity({$col}, ?) > ?", [$value, $this->minSimilarity]);
    }

    /**
     * Apply trigram-inspired pattern matching for other databases
     */
    protected function applyPatternBased(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $trigrams = $this->generateTrigrams($value);
        $patterns = $this->trigramsToPatterns($trigrams);
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
     * Generate trigrams from a string
     */
    protected function generateTrigrams(string $value): array
    {
        $value = strtolower(trim($value));
        $value = '  ' . $value . ' '; // Pad with spaces (PostgreSQL style)
        $trigrams = [];

        for ($i = 0; $i < strlen($value) - 2; $i++) {
            $trigrams[] = substr($value, $i, 3);
        }

        return array_unique($trigrams);
    }

    /**
     * Convert trigrams to LIKE patterns
     */
    protected function trigramsToPatterns(array $trigrams): array
    {
        $patterns = [];

        foreach ($trigrams as $trigram) {
            $trigram = trim($trigram);
            if (!empty($trigram)) {
                $patterns[] = '%' . $trigram . '%';
            }
        }

        // Also add the original value
        $combined = trim(str_replace('  ', '', implode('', array_map('trim', $trigrams))));
        if (!empty($combined)) {
            array_unshift($patterns, '%' . $combined . '%');
        }

        return array_slice(array_unique($patterns), 0, 10); // Limit patterns
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $col = $this->quoteColumn($column);

        if ($this->driver === 'pgsql' && ($this->config['use_native_functions'] ?? false)) {
            return "similarity({$col}, ?) * 100";
        }

        // Fallback: Count matching trigrams
        $trigrams = array_slice($this->generateTrigrams($value), 0, 5);
        $expressions = [];

        foreach ($trigrams as $i => $trigram) {
            $trigram = trim($trigram);
            if (!empty($trigram)) {
                $expressions[] = match ($this->driver) {
                    'mysql' => "IF(LOWER({$col}) LIKE ?, 20, 0)",
                    default => "CASE WHEN {$col} LIKE ? THEN 20 ELSE 0 END",
                };
            }
        }

        return empty($expressions) ? '0' : implode(' + ', $expressions);
    }

    public function getRelevanceBindings(string $value): array
    {
        if ($this->driver === 'pgsql' && ($this->config['use_native_functions'] ?? false)) {
            return [$value];
        }

        $trigrams = array_slice($this->generateTrigrams($value), 0, 5);
        $bindings = [];

        foreach ($trigrams as $trigram) {
            $trigram = trim($trigram);
            if (!empty($trigram)) {
                $bindings[] = '%' . $trigram . '%';
            }
        }

        return $bindings;
    }
}

