<?php

namespace Ashiqfardus\LaravelFuzzySearch\Observers;

use Illuminate\Database\Eloquent\Model;

class SearchableObserver
{
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

        $columns = $model->getSearchableColumns();

        if (empty($columns)) {
            return;
        }

        $schema  = $model->getConnection()->getSchemaBuilder();
        $table   = $model->getTable();
        $updates = [];

        foreach ($columns as $column) {
            $metaphoneCol = $column . '_metaphone';

            if ($schema->hasColumn($table, $metaphoneCol)) {
                $value                   = $model->getAttribute($column);
                $updates[$metaphoneCol]  = $value !== null ? metaphone((string) $value) : null;
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
