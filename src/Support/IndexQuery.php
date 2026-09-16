<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the Eloquent query a bulk rebuild uses to load a model's rows.
 *
 * A model may define `searchIndexQuery(Builder $query): Builder` to customise it — most
 * often to eager-load the relations its searchable accessors read, so that rebuilding
 * 100k products does not run 100k brand queries. Used by RebuildCommand and
 * RebuildIndexJob; single-row reindexing (IndexModelJob) loads the model directly.
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
