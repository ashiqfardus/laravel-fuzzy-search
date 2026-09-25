<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Base Search Driver
 */
abstract class BaseDriver
{
    protected array $config;
    protected string $driver;

    public function __construct(array $config, string $driver)
    {
        $this->config = $config;
        $this->driver = $driver;
    }

    /**
     * Apply search to query
     */
    abstract public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder;

    /**
     * A condition every match must meet besides apply()'s pattern, as [sql, bindings], or null
     * for none. FuzzySearch ANDs it onto the unaccent() alternative of an explicit accent opt-in,
     * so that alternative cannot match a row the driver itself rejects (similar_text's
     * min_percentage bound).
     *
     * @return array{0: string, 1: array<int, mixed>}|null
     */
    public function matchBound(Builder $query, string $column, string $value): ?array
    {
        return null;
    }

    /**
     * Get relevance expression for ordering.
     *
     * @deprecated Not called by any internal code path — relevance ordering is handled
     *             by SearchBuilder::applyRelevanceOrdering(). Will be removed in v3.
     */
    public function getRelevanceExpression(string $column, string $value): string
    {
        return '0';
    }

    /**
     * Get relevance bindings.
     *
     * @deprecated Not called by any internal code path — relevance ordering is handled
     *             by SearchBuilder::applyRelevanceOrdering(). Will be removed in v3.
     */
    public function getRelevanceBindings(string $value): array
    {
        return [];
    }

    /**
     * Escape LIKE metacharacters in a user-supplied value so they match literally. The result is
     * only correct inside a LIKE that carries the matching ESCAPE clause: every database but
     * PostgreSQL escapes with `!` and reads it only under `ESCAPE '!'`, so a plain
     * `where($col, 'LIKE', …)` in a subclass would make a `!`, `%` or `_` term match nothing there.
     * Build the predicate the way the bundled drivers do, with DbDialect::whereLike() (or
     * DbDialect::like() for raw SQL).
     */
    protected function escapeLike(string $value): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::escapeLike($value, $this->driver);
    }

    /**
     * Trim a search term and lower-case its ASCII letters only. The term becomes a LIKE pattern,
     * and SQLite's LIKE folds ASCII only: a lower-cased 'Лев' ('%лев%') would no longer match a
     * stored "Лев" there. Every other database folds case in LIKE itself. Multibyte characters
     * stay byte-identical (strtolower() on PHP 8.1 could corrupt them, see Utf8::lowerAscii()).
     */
    protected function normalizeTerm(string $value): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\Support\Utf8::lowerAscii(trim($value));
    }

    /**
     * Split a term into an array of characters (not bytes). Pattern generators must slice
     * this array rather than the string: substr()/strlen()/$value[$i] cut multibyte
     * sequences (Bengali, Hindi, Thai, accented Latin) in half and emit invalid UTF-8.
     *
     * @return string[]
     */
    protected function chars(string $value): array
    {
        return $value === '' ? [] : mb_str_split($value, 1, 'UTF-8');
    }

    /**
     * Join a slice of a character array back into a string (array_slice semantics).
     *
     * @param string[] $chars
     */
    protected function slice(array $chars, int $offset, ?int $length = null): string
    {
        return implode('', array_slice($chars, $offset, $length));
    }

    /**
     * Quote column name based on driver. Pass the query the SQL is for, so a qualified column's
     * table carries the connection's table prefix (see DbDialect::quoteIdentifier()).
     */
    protected function quoteColumn(string $column, ?Builder $query = null): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::quoteIdentifier(
            $column, $this->driver, $query?->getGrammar()->getTablePrefix() ?? ''
        );
    }

    /** True for MySQL and MariaDB (Laravel 11+ reports MariaDB as "mariadb"). */
    protected function isMySqlFamily(): bool
    {
        return \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::isMySqlFamily($this->driver);
    }

    /**
     * Cap the LIKE-pattern list. Order matters: callers append the highest-signal
     * patterns (exact contains, prefix) first, so slicing keeps the best ones.
     */
    protected function capPatterns(array $patterns): array
    {
        return $this->firstPatterns($patterns);
    }

    /**
     * The first max_patterns distinct patterns, in order. The bundled drivers pass a generator
     * (patternCandidates()), which is pulled only until that many are kept: a long term never has
     * its whole pattern set built — O(n²) for levenshtein — just to be sliced.
     *
     * @param iterable<string> $patterns
     * @return string[]
     */
    protected function firstPatterns(iterable $patterns): array
    {
        $max  = max(1, (int) ($this->config['max_patterns'] ?? $this->config['performance']['max_patterns'] ?? 100));
        $kept = [];

        foreach ($patterns as $pattern) {
            if (!in_array($pattern, $kept, true)) {
                $kept[] = $pattern;
                if (count($kept) >= $max) {
                    break;
                }
            }
        }

        return $kept;
    }
}

