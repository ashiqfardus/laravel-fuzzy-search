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
     * Get relevance expression for ordering
     */
    abstract public function getRelevanceExpression(string $column, string $value): string;

    /**
     * Get relevance bindings
     */
    abstract public function getRelevanceBindings(string $value): array;

    /**
     * Quote column name based on driver
     */
    protected function quoteColumn(string $column): string
    {
        return match ($this->driver) {
            'mysql' => "`{$column}`",
            'pgsql' => "\"{$column}\"",
            'sqlsrv' => "[{$column}]",
            default => $column,
        };
    }
}

