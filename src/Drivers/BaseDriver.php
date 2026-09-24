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
     * Lower-case and trim a search term without corrupting multibyte characters.
     */
    protected function normalizeTerm(string $value): string
    {
        return mb_strtolower(trim($value), 'UTF-8');
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
        $max = (int) ($this->config['max_patterns']
            ?? $this->config['performance']['max_patterns']
            ?? 100);

        return array_slice(array_values(array_unique($patterns)), 0, max(1, $max));
    }
}

