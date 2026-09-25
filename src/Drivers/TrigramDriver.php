<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
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
        $col = $this->quoteColumn($column, $query);

        return $query->$method("similarity({$col}, ?) > ?", [$value, $this->minSimilarity]);
    }

    /**
     * Apply trigram-inspired pattern matching for other databases
     */
    protected function applyPatternBased(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $patterns = $this->trigramsToPatterns($this->generateTrigrams($value), $this->normalizeTerm($value));
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        return $query->$method(function ($q) use ($column, $patterns) {
            foreach ($patterns as $index => $pattern) {
                DbDialect::whereLike($q, $column, $pattern, $this->driver, $index === 0 ? 'and' : 'or');
            }
        });
    }

    /**
     * Generate trigrams from a string
     */
    protected function generateTrigrams(string $value): array
    {
        $value    = '  ' . $this->normalizeTerm($value) . ' '; // Pad with spaces (PostgreSQL style)
        $chars    = $this->chars($value);
        $trigrams = [];

        for ($i = 0; $i < count($chars) - 2; $i++) {
            $trigrams[] = $this->slice($chars, $i, 3);
        }

        return array_unique($trigrams);
    }

    /**
     * Convert trigrams to LIKE patterns: the whole term first, so it is always kept (it was once
     * rebuilt by concatenating the trigrams — "jjojohohnhn" for "john" — and never matched
     * anything), then each trigram's. Built lazily: firstPatterns() stops at max_patterns.
     * apply() goes through this and generateTrigrams(), so a subclass can override either.
     */
    protected function trigramsToPatterns(array $trigrams, string $value = ''): array
    {
        return $this->firstPatterns($this->trigramPatterns($trigrams, $value));
    }

    /** @return \Generator<int, string> */
    private function trigramPatterns(array $trigrams, string $value): \Generator
    {
        if ($value !== '') {
            yield '%' . $this->escapeLike($value) . '%';
        }

        foreach ($trigrams as $trigram) {
            $trigram = trim($trigram);
            if (!empty($trigram)) {
                yield '%' . $this->escapeLike($trigram) . '%';
            }
        }
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

