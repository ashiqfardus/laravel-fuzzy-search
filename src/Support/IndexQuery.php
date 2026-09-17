<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the Eloquent query a bulk rebuild uses to load a model's rows.
 *
 * A model may define `searchIndexQuery(Builder $query): Builder` to customise it — most
 * often to eager-load the relations its searchable accessors read, so that rebuilding
 * 100k products does not run 100k brand queries. Used by RebuildCommand, RebuildIndexJob,
 * and IndexModelJob — single-row reindexes load through it too, so a searchableText() hook
 * that reads a relation is eager-loaded there as well, not just on bulk rebuilds.
 */
final class IndexQuery
{
    public static function for(string $modelClass): Builder
    {
        $model = new $modelClass;
        $query = $model->newQuery();

        return method_exists($model, 'searchIndexQuery')
            ? $model->searchIndexQuery($query)
            : $query;
    }
}
