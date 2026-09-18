<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

use Closure;

/**
 * Shared handling of a model's searchable column list.
 *
 * `names()` accepts either declaration form — `['name' => 10, 'email' => 5]` (weights, names
 * are the keys) and `['name', 'email']` (a plain list, names are the values), mixed included —
 * exactly as SearchBuilder::searchIn() does, so every consumer reads the same names.
 *
 * `detect()` memoises auto-detection per model class for the life of the process: it reads the
 * table's columns, and it is called on every save (shadow columns), every indexed row and every
 * search. Declared columns never reach it. The cache is as long-lived as
 * SearchableObserver::$columnCache and FederatedSearch::$columnListings — a schema change needs
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
     */
    private const TEXT_LIKE_CASTS = [
        'string', 'int', 'integer', 'float', 'double', 'real', 'bool', 'boolean', 'decimal',
        'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp', 'hashed', 'encrypted',
    ];

    /** @var array<class-string, array<string, int>> model class => column => weight */
    private static array $detected = [];

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
     * @param  Closure(): array<string, int> $detect
     * @return array<string, int>
     */
    public static function detect(string $modelClass, Closure $detect): array
    {
        return self::$detected[$modelClass] ??= $detect();
    }

    /** @param string|null $cast the model's cast for the column, or null when it has none */
    public static function isTextLikeCast(?string $cast): bool
    {
        if ($cast === null) {
            return true;
        }

        // 'decimal:2' → 'decimal'; 'encrypted:array' (and :json/:object/:collection) is never text.
        [$base, $argument] = array_pad(explode(':', $cast, 2), 2, null);

        if ($base === 'encrypted') {
            return $argument === null;
        }

        return in_array(strtolower($base), self::TEXT_LIKE_CASTS, true);
    }

    public static function reset(): void
    {
        self::$detected = [];
    }
}
