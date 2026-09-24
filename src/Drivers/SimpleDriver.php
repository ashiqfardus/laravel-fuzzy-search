<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Query\Builder;

/**
 * Simple Driver - Basic LIKE matching
 * Fast but only matches exact substrings
 */
class SimpleDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        DbDialect::whereLike($query, $column, '%' . $this->escapeLike($value) . '%', $this->driver, $boolean);

        return $query;
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $col = $this->quoteColumn($column);

        return match ($this->driver) {
            'mysql' => "
                IF({$col} = ?, 150, 0) +
                IF({$col} LIKE ?, 50, 0) +
                IF({$col} LIKE ?, 10, 0)
            ",
            'pgsql' => "
                CASE WHEN {$col} = ? THEN 150 ELSE 0 END +
                CASE WHEN {$col} ILIKE ? THEN 50 ELSE 0 END +
                CASE WHEN {$col} ILIKE ? THEN 10 ELSE 0 END
            ",
            default => "
                CASE WHEN {$col} = ? THEN 150 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 50 ELSE 0 END +
                CASE WHEN {$col} LIKE ? THEN 10 ELSE 0 END
            ",
        };
    }

    public function getRelevanceBindings(string $value): array
    {
        return [
            $value,           // Exact match
            $value . '%',     // Starts with
            '%' . $value . '%', // Contains
        ];
    }
}

