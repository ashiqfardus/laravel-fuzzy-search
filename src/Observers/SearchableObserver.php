<?php

namespace Ashiqfardus\LaravelFuzzySearch\Observers;

use Illuminate\Database\Eloquent\Model;

class SearchableObserver
{
    /** @var array<string, bool> */
    protected static array $columnCache = [];

    /**
     * Populate shadow columns when a model is saved.
     * Only populates columns that actually exist on the table
     * (prevents errors for models that haven't run the migration yet).
     */
    public function saved(Model $model): void
    {
        $this->populateShadowColumns($model);
    }

    public function deleted(Model $_model): void
    {
        // Shadow columns are on the same row — deletion removes them automatically.
    }

    protected function populateShadowColumns(Model $model): void
    {
        if (!method_exists($model, 'getSearchableColumns')) {
            return;
        }

        $schema  = $model->getConnection()->getSchemaBuilder();
        $table   = $model->getTable();
        $columns = $model->getSearchableColumns();
        $updates = [];

        foreach ($columns as $column) {
            $metaphoneCol = $column . '_metaphone';
            $cacheKey     = $table . '.' . $metaphoneCol;

            if (!array_key_exists($cacheKey, static::$columnCache)) {
                static::$columnCache[$cacheKey] = $schema->hasColumn($table, $metaphoneCol);
            }

            if (static::$columnCache[$cacheKey]) {
                $value               = $model->getAttribute($column);
                $updates[$metaphoneCol] = $value !== null ? metaphone((string) $value) : null;
            }
        }

        if (!empty($updates)) {
            // Direct DB update to avoid re-triggering the observer
            $model->getConnection()
                  ->table($table)
                  ->where($model->getKeyName(), $model->getKey())
                  ->update($updates);
        }
    }
}
