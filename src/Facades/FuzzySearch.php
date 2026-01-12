<?php

namespace Ashiqfardus\LaravelFuzzySearch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Database\Query\Builder applyFuzzyWhere(\Illuminate\Database\Query\Builder $query, string $column, string $value, ?string $algorithm = null, ?array $options = [], string $boolean = 'and')
 * @method static \Illuminate\Database\Query\Builder applyFuzzyWhereMultiple(\Illuminate\Database\Query\Builder $query, array $columns, string $value, ?string $algorithm = null, ?array $options = [])
 * @method static \Illuminate\Database\Query\Builder applyFuzzyOrder(\Illuminate\Database\Query\Builder $query, string $column, string $value, string $direction = 'asc')
 * @method static int levenshteinDistance(string $str1, string $str2, array $options = [])
 * @method static float similarityPercentage(string $str1, string $str2)
 *
 * @see \Ashiqfardus\LaravelFuzzySearch\FuzzySearch
 */
class FuzzySearch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class;
    }
}

