<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared handling of a model's searchable column list.
 *
 * `names()` accepts either declaration form — `['name' => 10, 'email' => 5]` (weights, names
 * are the keys) and `['name', 'email']` (a plain list, names are the values), mixed included —
 * exactly as SearchBuilder::searchIn() does, so every consumer reads the same names.
 *
 * `detect()` memoises auto-detection per model class, connection and table for the life of the
 * process (a tenant model that switches either gets its own entry): it reads the
 * table's columns, and it is called on every save (shadow columns), every indexed row and every
 * search. Declared columns never reach it. The cache is as long-lived as
 * SearchableObserver::$columnCache and onTable()'s listings — a schema change needs
 * a fresh process (or reset(), which the test suite calls between cases).
 */
final class SearchableColumns
{
    /**
     * Casts whose attribute still reaches the indexer as text. Anything else — an enum class, a
     * custom cast class, array/json/object/collection and their encrypted forms — is not text,
     * so auto-detection must not pick that column: it is a heuristic, and the indexer rightly
     * refuses a value it cannot turn into a string. A column the caller declared is their choice
     * and still raises that error.
     *
     * `encrypted` and `hashed` are text but never auto-detected: an auto-detected column is
     * indexed as its raw attribute (value()), so the dictionary would fill with ciphertext or hashes, and a
     * declared column is read through getAttribute(), which decrypts. Declaring such a column is
     * the caller's explicit choice.
     */
    private const TEXT_LIKE_CASTS = [
        'string', 'int', 'integer', 'float', 'double', 'real', 'bool', 'boolean', 'decimal',
        'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp',
    ];

    /** @var array<string, array<string, int>> "class|connection|table" => column => weight */
    private static array $detected = [];

    /** @var array<string, string[]> "connection|table" => column names */
    private static array $listings = [];

    /**
     * @param  array<string|int, string|int> $columns
     * @return string[]
     */
    public static function names(array $columns): array
    {
        return array_map(
            fn ($key, $value) => is_int($key) ? (string) $value : $key,
            array_keys($columns),
            $columns
        );
    }

    /**
     * The same two declaration forms as names(), read as weights: a string key keeps its value
     * (cast to int), a list entry weighs 1. This is what SearchBuilder::searchIn() records and
     * what BM25F ranking multiplies a column's contribution by, so every path that ranks — the
     * builder, the Scout engine — can resolve a model's weights the same way.
     *
     * @param  array<string|int, string|int> $columns
     * @return array<string, int>
     */
    public static function weights(array $columns): array
    {
        $weights = [];

        foreach ($columns as $key => $value) {
            is_int($key) ? $weights[(string) $value] = 1 : $weights[$key] = (int) $value;
        }

        return $weights;
    }

    /**
     * @param  string                                      $key    model class, connection and table
     * @param  Closure(): array{array<string, int>, bool} $detect the columns, and whether the
     *                                                            table's column listing was read
     * @return array<string, int>
     */
    public static function detect(string $key, Closure $detect): array
    {
        if (isset(self::$detected[$key])) {
            return self::$detected[$key];
        }

        [$columns, $listed] = $detect();

        // A table that could not be listed (not migrated yet, or its connection is down) must
        // not pin its answer for the life of the process. One that was listed is final, even
        // when every column was filtered out: listing it again changes nothing.
        return $listed ? self::$detected[$key] = $columns : $columns;
    }

    /**
     * The table's column names, memoised per connection and table like detect(). A table that
     * cannot be read gives [] and is not cached.
     *
     * @return string[]
     */
    public static function onTable(Connection $connection, string $table): array
    {
        $key = $connection->getName() . '|' . $table;

        if (isset(self::$listings[$key])) {
            return self::$listings[$key];
        }

        try {
            $columns = $connection->getSchemaBuilder()->getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }

        return $columns === [] ? [] : self::$listings[$key] = $columns;
    }

    /**
     * What the indexer and the shadow columns read for a searchable column. A column the model
     * chose goes through getAttribute() — accessors and casts, a documented feature. An
     * auto-detected one is read as the raw attribute value: nobody chose to expose it, and a get
     * accessor may decrypt it into the dictionary that suggest() serves.
     * The flip side: a masking accessor is bypassed too, and so is anything that swaps the raw
     * attributes in memory (a decrypt-on-retrieved package) — the docs say to $hidden such columns.
     */
    public static function value(Model $model, string $column): mixed
    {
        return self::declared($model) ? $model->getAttribute($column) : $model->getAttributes()[$column] ?? null;
    }

    /** False only for a Searchable model whose columns were auto-detected; a model without the trait chose its own. */
    public static function declared(Model $model): bool
    {
        return !method_exists($model, 'hasDeclaredSearchableColumns') || $model->hasDeclaredSearchableColumns();
    }

    /** @param string|null $cast the model's cast for the column, or null when it has none */
    public static function isTextLikeCast(?string $cast): bool
    {
        if ($cast === null) {
            return true;
        }

        // Laravel matches cast names case-insensitively (getCastType() lower-cases them), so
        // every spelling must be judged the same way.
        // 'decimal:2' → 'decimal'; 'encrypted' in any form is never selected (see TEXT_LIKE_CASTS).
        return in_array(explode(':', strtolower($cast), 2)[0], self::TEXT_LIKE_CASTS, true);
    }

    public static function reset(): void
    {
        self::$detected = [];
        self::$listings = [];
    }
}
