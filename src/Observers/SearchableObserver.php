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

    protected function populateShadowColumns(Model $model): void
    {
        $updates = $this->shadowValues($model);

        if (!empty($updates)) {
            // Direct DB update to avoid re-triggering the observer
            $model->getConnection()
                  ->table($model->getTable())
                  ->where($model->getKeyName(), $model->getKey())
                  ->update($updates);

            // The instance holds what the row now holds: shadowValues() compares against it, so a
            // stale code would let a later save back to the old value skip its update. The save
            // syncs the originals after this 'saved' listener.
            $model->setRawAttributes(array_merge($model->getAttributes(), $updates));
        }
    }

    /**
     * fuzzy-search:rebuild's backfill (and each --async batch job's): fill the shadow columns of
     * rows saved before a column existed, or changed without model events. One UPDATE ... CASE
     * per shadow column and 600 rows (1,800 bindings, under SQL Server's 2,100), none for a row
     * whose shadow value is already right, and no model events.
     *
     * @param iterable<Model> $models one model class
     */
    public function backfillShadowColumns(iterable $models): void
    {
        $byColumn = []; // shadow column => [key => value]
        $first    = null;
        foreach ($models as $model) {
            $first ??= $model;
            foreach ($this->shadowValues($model) as $shadow => $value) {
                $byColumn[$shadow][$model->getKey()] = $value;
            }
        }

        if ($first === null) {
            return;
        }

        $connection = $first->getConnection();
        $grammar    = $connection->getQueryGrammar();
        $table      = $grammar->wrapTable($first->getTable());
        $key        = $grammar->wrap($first->getKeyName());

        foreach ($byColumn as $shadow => $values) {
            foreach (array_chunk($values, 600, true) as $chunk) {
                $bindings = [];
                foreach ($chunk as $id => $value) {
                    array_push($bindings, $id, $value);
                }
                array_push($bindings, ...array_keys($chunk));

                $connection->update(
                    "update {$table} set {$grammar->wrap($shadow)} = case {$key}"
                    . str_repeat(' when ? then ?', count($chunk))
                    . " end where {$key} in (" . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    $bindings
                );
            }
        }
    }

    /**
     * The shadow values $model needs written: [shadow column => metaphone code] for each loaded
     * searchable column whose `{column}_metaphone` column exists, leaving out one whose loaded
     * shadow value is already that code.
     *
     * @return array<string, ?string>
     */
    protected function shadowValues(Model $model): array
    {
        if (!method_exists($model, 'getSearchableColumns')) {
            return [];
        }

        $schema     = $model->getConnection()->getSchemaBuilder();
        $table      = $model->getTable();
        $attributes = $model->getAttributes();
        $updates    = [];

        foreach ($model->getSearchableColumns() as $column) {
            $metaphoneCol = $column . '_metaphone';
            $cacheKey     = $table . '.' . $metaphoneCol;

            if (!array_key_exists($cacheKey, static::$columnCache)) {
                static::$columnCache[$cacheKey] = $schema->hasColumn($table, $metaphoneCol);
            }

            if (static::$columnCache[$cacheKey] && $this->loaded($model, $column)) {
                $value = SearchableColumns::value($model, $column);
                $code  = $value !== null ? metaphone((string) $value) : null;

                if (!array_key_exists($metaphoneCol, $attributes) || $attributes[$metaphoneCol] !== $code) {
                    $updates[$metaphoneCol] = $code;
                }
            }
        }

        return $updates;
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
