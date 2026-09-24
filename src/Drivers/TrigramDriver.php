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
        $patterns = $this->firstPatterns($this->patternCandidates($value));
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
        return array_unique(iterator_to_array($this->trigrams($value), false));
    }

    /**
     * The term's trigrams in order, padded with spaces (PostgreSQL style), built lazily.
     *
     * @return \Generator<int, string>
     */
    private function trigrams(string $value): \Generator
    {
        $chars = $this->chars('  ' . $this->normalizeTerm($value) . ' ');

        for ($i = 0; $i < count($chars) - 2; $i++) {
            yield $this->slice($chars, $i, 3);
        }
    }

    /**
     * The whole term, then each trigram's pattern, built lazily: firstPatterns() stops pulling at
     * max_patterns. The whole term goes first so it is always kept. (It was once rebuilt by
     * concatenating the trigrams — "jjojohohnhn" for "john" — and never matched anything.)
     *
     * @return \Generator<int, string>
     */
    protected function patternCandidates(string $value): \Generator
    {
        $value = $this->normalizeTerm($value);

        if ($value !== '') {
            yield '%' . $this->escapeLike($value) . '%';
        }

        foreach ($this->trigrams($value) as $trigram) {
            $trigram = trim($trigram);
            if (!empty($trigram)) {
                yield '%' . $this->escapeLike($trigram) . '%';
            }
        }
    }

    /**
     * Convert trigrams to LIKE patterns
     *
     * @deprecated Not called by any internal code path since 2.1 — apply() builds its patterns
     *             lazily through patternCandidates(). Will be removed in v3.
     */
    protected function trigramsToPatterns(array $trigrams, string $value = ''): array
    {
        $patterns = [];

        foreach ($trigrams as $trigram) {
            $trigram = trim($trigram);
            if (!empty($trigram)) {
                $patterns[] = '%' . $this->escapeLike($trigram) . '%';
            }
        }

        if ($value !== '') {
            array_unshift($patterns, '%' . $this->escapeLike($value) . '%');
        }

        return $this->capPatterns($patterns);
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

