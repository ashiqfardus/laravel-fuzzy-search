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
     * Escape LIKE metacharacters in a user-supplied value so % and _ are treated literally.
     */
    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '%_');
    }

    /**
     * Quote column name based on driver
     */
    protected function quoteColumn(string $column): string
    {
        $parts = explode('.', $column);
        $quoted = array_map(fn (string $part) => match ($this->driver) {
            'mysql'  => '`' . str_replace('`', '``', $part) . '`',
            'pgsql'  => '"' . str_replace('"', '""', $part) . '"',
            'sqlsrv' => '[' . str_replace(']', ']]', $part) . ']',
            default  => $part,
        }, $parts);
        return implode('.', $quoted);
    }
}

