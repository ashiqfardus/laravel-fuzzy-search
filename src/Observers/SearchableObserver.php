<?php

namespace Ashiqfardus\LaravelFuzzySearch\Observers;

use Ashiqfardus\LaravelFuzzySearch\Drivers\MetaphoneDriver;
use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Illuminate\Database\Eloquent\Model;

class SearchableObserver
{
    private const SUFFIX = '_metaphone';

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

        // Direct DB updates, to avoid re-triggering the observer: one per shadow column, each only
        // while its source column still holds the text the code encodes (SB-3), compared exactly
        // (TB-2: under an accent-insensitive collation an accent-only edit has another code). This
        // save's own UPDATE has committed already (a plain save() is not in a transaction), so
        // another save of the row can land in between; its code is then the right one, and this
        // write must not replace it.
        foreach ($updates as $shadow => $code) {
            $column = substr($shadow, 0, -strlen(self::SUFFIX));
            $query  = $model->getConnection()->table($model->getTable())->where($model->getKeyName(), $model->getKey());

            if ($this->guarded($model, $column)) {
                $text = $model->getAttributes()[$column];
                $text === null
                    ? $query->whereNull($column)
                    : $query->whereRaw(DbDialect::sameText($query->getGrammar()->wrap($column), $model->getConnection()->getDriverName()), [(string) $text]);
            }

            $query->update([$shadow => $code]);
        }

        if ($updates !== []) {
            // The instance holds what the row now holds: shadowValues() compares against it, so a
            // stale code would let a later save back to the old value skip its update. The save
            // syncs the originals after this 'saved' listener.
            $model->setRawAttributes(array_merge($model->getAttributes(), $updates));
        }
    }

    /**
     * fuzzy-search:rebuild's backfill (and each --async batch job's): fill the shadow columns of
     * rows saved before a column existed, or changed without model events. One UPDATE ... CASE
     * per shadow column and 500 rows (at most 2,000 bindings, under SQL Server's 2,100), none for
     * a row whose shadow value is already right, and no model events. Each row's code is written
     * only while its source column still holds exactly the text the code encodes (SB-3, TB-2: see
     * DbDialect::sameText()): the rebuild
     * indexes a chunk before it backfills the rows it read, and a save in between has written the
     * right code already. The values are keyed by model key, and PHP turns an all-digit key ('12')
     * into an int: a string key is bound as a string (RankedCandidates::keysFor()), or SQL Server
     * converts the key column to int and fails on its first other key (22018).
     *
     * @param iterable<Model> $models one model class
     */
    public function backfillShadowColumns(iterable $models): void
    {
        $byColumn = []; // shadow column => [key => [code, the text it encodes]], [code] unguarded
        $first    = null;
        foreach ($models as $model) {
            $first ??= $model;
            foreach ($this->shadowValues($model) as $shadow => $code) {
                $column = substr($shadow, 0, -strlen(self::SUFFIX));
                $byColumn[$shadow][$model->getKey()] = $this->guarded($model, $column) ? [$code, $model->getAttributes()[$column]] : [$code];
            }
        }

        if ($first === null) {
            return;
        }

        $connection = $first->getConnection();
        $grammar    = $connection->getQueryGrammar();
        $table      = $grammar->wrapTable($first->getTable());
        $key        = $grammar->wrap($first->getKeyName());

        foreach ($byColumn as $shadow => $rows) {
            $target = $grammar->wrap($shadow);
            $source = $grammar->wrap(substr($shadow, 0, -strlen(self::SUFFIX)));
            $same   = DbDialect::sameText($source, $connection->getDriverName());

            foreach (array_chunk($rows, 500, true) as $chunk) {
                $ids      = RankedCandidates::keysFor($first, array_keys($chunk));
                $cases    = [];
                $bindings = [];
                foreach (array_values($chunk) as $i => $row) {
                    if (count($row) === 1) {
                        $cases[] = "when {$key} = ? then ?";
                        array_push($bindings, $ids[$i], $row[0]);
                    } elseif ($row[1] === null) {
                        $cases[] = "when {$key} = ? and {$source} is null then ?";
                        array_push($bindings, $ids[$i], $row[0]);
                    } else {
                        $cases[] = "when {$key} = ? and {$same} then ?";
                        array_push($bindings, $ids[$i], (string) $row[1], $row[0]);
                    }
                }
                array_push($bindings, ...$ids);

                $connection->update(
                    "update {$table} set {$target} = case " . implode(' ', $cases) . " else {$target} end"
                    . " where {$key} in (" . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                    $bindings
                );
            }
        }
    }

    /**
     * The shadow values $model needs written: [shadow column => metaphone code] for each loaded
     * searchable column whose `{column}_metaphone` column exists, leaving out one whose loaded
     * shadow value is already that code, unless this save changed the column: the loaded shadow
     * can be stale, another save having written its own code since this instance was read (SB-3).
     *
     * @return array<string, ?string>
     */
    protected function shadowValues(Model $model): array
    {
        if (!method_exists($model, 'getSearchableColumns')) {
            return [];
        }

        $connection = $model->getConnection();
        $schema     = $connection->getSchemaBuilder();
        $table      = $model->getTable();
        $attributes = $model->getAttributes();
        $updates    = [];

        foreach ($model->getSearchableColumns() as $column) {
            $metaphoneCol = $column . self::SUFFIX;
            // Per connection, as connectionKey() locates it: another connection's, or another
            // tenant's (database, server or schema), table of this name may not have the column (SD-2, TC-2).
            $cacheKey     = SearchableColumns::connectionKey($connection) . '|' . $table . '.' . $metaphoneCol;

            if (!array_key_exists($cacheKey, static::$columnCache)) {
                static::$columnCache[$cacheKey] = $schema->hasColumn($table, $metaphoneCol);
            }

            if (static::$columnCache[$cacheKey] && $this->loaded($model, $column)) {
                $value = SearchableColumns::value($model, $column);
                $code  = $value !== null ? MetaphoneDriver::code((string) $value) : null;

                if (!array_key_exists($metaphoneCol, $attributes) || $attributes[$metaphoneCol] !== $code || $model->isDirty($column)) {
                    $updates[$metaphoneCol] = $code;
                }
            }
        }

        return $updates;
    }

    /**
     * Whether $column's shadow write can be guarded by the text stored in it: its code is computed
     * from that text, a loaded attribute read as stored. Not a declared column's accessor, cast or
     * date: the code comes from what those return, which the stored text cannot vouch for (an
     * accessor may read other columns or relations), and a virtual column, an accessor with no
     * column behind it, has no stored text to compare at all. Those are written unguarded.
     */
    protected function guarded(Model $model, string $column): bool
    {
        return array_key_exists($column, $model->getAttributes())
            && (!SearchableColumns::declared($model)
                || !($model->hasGetMutator($column) || $model->hasAttributeGetMutator($column) || $model->hasCast($column) || in_array($column, $model->getDates(), true)));
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
