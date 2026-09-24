<?php

namespace Ashiqfardus\LaravelFuzzySearch\Observers;

use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Illuminate\Database\Eloquent\Model;

class SearchableObserver
{
    /** @var array<string, bool> */
    protected static array $columnCache = [];

    /** Reset the schema-column cache (call between test cases to prevent cross-test contamination). */
    public static function resetColumnCache(): void
    {
        static::$columnCache = [];
    }

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

    /**
     * Also run by fuzzy-search:rebuild (and each --async batch job) for every row, to fill a
     * shadow column added after the rows were saved. A query update: no model events fire.
     */
    public function populateShadowColumns(Model $model): void
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

            if (static::$columnCache[$cacheKey] && $this->loaded($model, $column)) {
                $value               = SearchableColumns::value($model, $column);
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

    /**
     * False for a column this save never loaded (a partial select): it did not change, so its
     * shadow still holds. Reading it would give NULL, or throw under preventAccessingMissingAttributes().
     * Only a declared column's accessor can supply a value that is not a loaded attribute.
     */
    protected function loaded(Model $model, string $column): bool
    {
        return array_key_exists($column, $model->getAttributes())
            || (SearchableColumns::declared($model) && ($model->hasGetMutator($column) || $model->hasAttributeGetMutator($column)));
    }
}
