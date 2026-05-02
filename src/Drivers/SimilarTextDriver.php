<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * SimilarText Driver
 *
 * SQL level: prefix LIKE — honest fallback because similar_text() has no
 * SQL equivalent on any supported database. The PHP-side scorer in
 * SearchBuilder::calculateRelevanceScores() uses PHP's similar_text() to
 * compute the actual score after candidates are fetched.
 */
class SimilarTextDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $col = $this->quoteColumn($column);

        if ($this->driver === 'pgsql') {
            $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            return $query->$rawMethod("{$col} ILIKE ?", ['%' . strtolower($value) . '%']);
        }

        return $query->$method($column, 'LIKE', '%' . strtolower($value) . '%');
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $col = $this->quoteColumn($column);

        return match ($this->driver) {
            'mysql'  => "IF(LOWER({$col}) LIKE ?, 50, 0)",
            'pgsql'  => "CASE WHEN {$col} ILIKE ? THEN 50 ELSE 0 END",
            default  => "CASE WHEN {$col} LIKE ? THEN 50 ELSE 0 END",
        };
    }

    public function getRelevanceBindings(string $value): array
    {
        return ['%' . strtolower($value) . '%'];
    }
}
