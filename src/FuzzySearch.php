<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FuzzySearch
{
    protected array $config;

    /**
     * Supported database drivers
     */
    protected const DRIVER_MYSQL = 'mysql';
    protected const DRIVER_PGSQL = 'pgsql';
    protected const DRIVER_SQLITE = 'sqlite';
    protected const DRIVER_SQLSRV = 'sqlsrv';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get the database driver name
     */
    protected function getDriver(Builder $query): string
    {
        return $query->getConnection()->getDriverName();
    }

    /**
     * Apply fuzzy where clause to query
     */
    public function applyFuzzyWhere(
        Builder $query,
        string $column,
        string $value,
        ?string $algorithm = null,
        ?array $options = [],
        string $boolean = 'and'
    ): Builder {
        $algorithm = $algorithm ?? $this->config['default_algorithm'];
        $options = array_merge($this->config[$algorithm] ?? [], $options);

        return match ($algorithm) {
            'levenshtein' => $this->applyLevenshtein($query, $column, $value, $options, $boolean),
            'soundex' => $this->applySoundex($query, $column, $value, $boolean),
            'metaphone' => $this->applyMetaphone($query, $column, $value, $boolean),
            'similar_text' => $this->applySimilarText($query, $column, $value, $options, $boolean),
            'like' => $this->applyLike($query, $column, $value, $options, $boolean),
            default => $this->applyLevenshtein($query, $column, $value, $options, $boolean),
        };
    }

    /**
     * Apply fuzzy where clause to multiple columns
     */
    public function applyFuzzyWhereMultiple(
        Builder $query,
        array $columns,
        string $value,
        ?string $algorithm = null,
        ?array $options = []
    ): Builder {
        $algorithm = $algorithm ?? $this->config['default_algorithm'];

        return $query->where(function ($q) use ($columns, $value, $algorithm, $options) {
            foreach ($columns as $index => $column) {
                $boolean = $index === 0 ? 'and' : 'or';
                $this->applyFuzzyWhere($q, $column, $value, $algorithm, $options, $boolean);
            }
        });
    }

    /**
     * Apply fuzzy ordering by relevance score
     */
    public function applyFuzzyOrder(Builder $query, string $column, string $value, string $direction = 'asc'): Builder
    {
        $driver = $this->getDriver($query);

        switch ($driver) {
            case self::DRIVER_MYSQL:
                // MySQL: Order by LOCATE (position of substring)
                $query->orderByRaw("LOCATE(?, `{$column}`) {$direction}", [$value]);
                break;

            case self::DRIVER_PGSQL:
                // PostgreSQL: Use similarity function from pg_trgm or POSITION
                if ($this->config['use_native_functions']) {
                    $query->orderByRaw("similarity(\"{$column}\", ?) {$direction}", [$value]);
                } else {
                    $query->orderByRaw("POSITION(? IN \"{$column}\") {$direction}", [$value]);
                }
                break;

            case self::DRIVER_SQLITE:
                // SQLite: Use INSTR for position
                $query->orderByRaw("INSTR(\"{$column}\", ?) {$direction}", [$value]);
                break;

            case self::DRIVER_SQLSRV:
                // SQL Server: Use CHARINDEX
                $query->orderByRaw("CHARINDEX(?, [{$column}]) {$direction}", [$value]);
                break;

            default:
                // Fallback: Order by whether the column contains the value
                $query->orderByRaw("CASE WHEN {$column} LIKE ? THEN 0 ELSE 1 END {$direction}", ["%{$value}%"]);
        }

        return $query;
    }

    /**
     * Apply Levenshtein distance matching
     * Note: This is a PHP-based implementation for broader compatibility
     */
    protected function applyLevenshtein(
        Builder $query,
        string $column,
        string $value,
        array $options,
        string $boolean
    ): Builder {
        $maxDistance = $options['max_distance'] ?? 3;
        $driver = $this->getDriver($query);

        // Check if native functions should be used
        if ($this->config['use_native_functions']) {
            switch ($driver) {
                case self::DRIVER_MYSQL:
                    return $this->applyNativeLevenshtein($query, $column, $value, $maxDistance, $boolean);

                case self::DRIVER_PGSQL:
                    return $this->applyPostgresTrigramSimilarity($query, $column, $value, $boolean);
            }
        }

        // PHP-based fallback: Generate fuzzy patterns (works with all databases)
        return $this->applyFuzzyPatterns($query, $column, $value, $maxDistance, $boolean);
    }

    /**
     * Apply native MySQL Levenshtein (requires UDF)
     */
    protected function applyNativeLevenshtein(
        Builder $query,
        string $column,
        string $value,
        int $maxDistance,
        string $boolean
    ): Builder {
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        return $query->$method("LEVENSHTEIN({$column}, ?) <= ?", [$value, $maxDistance]);
    }

    /**
     * Apply PostgreSQL trigram similarity
     */
    protected function applyPostgresTrigramSimilarity(
        Builder $query,
        string $column,
        string $value,
        string $boolean
    ): Builder {
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $minSimilarity = ($this->config['similar_text']['min_percentage'] ?? 70) / 100;
        return $query->$method("similarity({$column}, ?) > ?", [$value, $minSimilarity]);
    }

    /**
     * Apply fuzzy patterns for PHP-based matching
     */
    protected function applyFuzzyPatterns(
        Builder $query,
        string $column,
        string $value,
        int $maxDistance,
        string $boolean
    ): Builder {
        $patterns = $this->generateFuzzyPatterns($value, $maxDistance);

        $method = $boolean === 'or' ? 'orWhere' : 'where';

        return $query->$method(function ($q) use ($column, $patterns) {
            foreach ($patterns as $index => $pattern) {
                if ($index === 0) {
                    $q->where($column, 'LIKE', $pattern);
                } else {
                    $q->orWhere($column, 'LIKE', $pattern);
                }
            }
        });
    }

    /**
     * Generate fuzzy LIKE patterns for a given value
     */
    protected function generateFuzzyPatterns(string $value, int $maxDistance): array
    {
        $patterns = [];
        $value = strtolower(trim($value));

        // Exact match with wildcards
        $patterns[] = "%{$value}%";

        // Generate patterns with character omissions
        if ($maxDistance >= 1) {
            for ($i = 0; $i < strlen($value); $i++) {
                $pattern = substr($value, 0, $i) . '%' . substr($value, $i + 1);
                $patterns[] = "%{$pattern}%";
            }
        }

        // Generate patterns with character insertions
        if ($maxDistance >= 2) {
            for ($i = 0; $i <= strlen($value); $i++) {
                $pattern = substr($value, 0, $i) . '_' . substr($value, $i);
                $patterns[] = "%{$pattern}%";
            }
        }

        // First and last character patterns (common typos)
        if (strlen($value) > 2) {
            $patterns[] = $value[0] . '%' . substr($value, -1);
            $patterns[] = '%' . substr($value, 1);
            $patterns[] = substr($value, 0, -1) . '%';
        }

        return array_unique($patterns);
    }

    /**
     * Apply Soundex matching
     */
    protected function applySoundex(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $soundex = soundex($value);
        $driver = $this->getDriver($query);
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        switch ($driver) {
            case self::DRIVER_MYSQL:
                // MySQL has native SOUNDEX
                $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
                return $query->$rawMethod("SOUNDEX(`{$column}`) = ?", [$soundex]);

            case self::DRIVER_PGSQL:
                // PostgreSQL requires fuzzystrmatch extension for SOUNDEX
                if ($this->config['use_native_functions']) {
                    $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
                    return $query->$rawMethod("SOUNDEX(\"{$column}\") = ?", [$soundex]);
                }
                // Fallback to LIKE patterns
                return $this->applyPhoneticLikePatterns($query, $column, $value, $boolean);

            case self::DRIVER_SQLITE:
            case self::DRIVER_SQLSRV:
            default:
                // SQLite and others: Use PHP soundex with LIKE patterns fallback
                return $this->applyPhoneticLikePatterns($query, $column, $value, $boolean);
        }
    }

    /**
     * Apply phonetic-based LIKE patterns for databases without native SOUNDEX
     */
    protected function applyPhoneticLikePatterns(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        // Generate phonetic-based patterns
        $patterns = $this->generatePhoneticPatterns($value);

        return $query->$method(function ($q) use ($column, $patterns) {
            foreach ($patterns as $index => $pattern) {
                if ($index === 0) {
                    $q->where($column, 'LIKE', $pattern);
                } else {
                    $q->orWhere($column, 'LIKE', $pattern);
                }
            }
        });
    }

    /**
     * Generate phonetic-based patterns for fuzzy matching
     */
    protected function generatePhoneticPatterns(string $value): array
    {
        $patterns = [];
        $value = strtolower(trim($value));

        // Original with wildcards
        $patterns[] = "%{$value}%";

        // First letter + wildcards (phonetic similarity often shares first letter)
        if (strlen($value) > 0) {
            $patterns[] = $value[0] . '%';
        }

        // First two letters + wildcards
        if (strlen($value) > 1) {
            $patterns[] = substr($value, 0, 2) . '%';
        }

        // First three letters + wildcards
        if (strlen($value) > 2) {
            $patterns[] = substr($value, 0, 3) . '%';
        }

        // Common phonetic substitutions
        $phoneticSubs = [
            'ph' => 'f', 'f' => 'ph',
            'c' => 'k', 'k' => 'c',
            'j' => 'g', 'g' => 'j',
            'z' => 's', 's' => 'z',
            'v' => 'w', 'w' => 'v',
        ];

        foreach ($phoneticSubs as $from => $to) {
            if (str_contains($value, $from)) {
                $patterns[] = '%' . str_replace($from, $to, $value) . '%';
            }
        }

        return array_unique($patterns);
    }

    /**
     * Apply Metaphone matching
     */
    protected function applyMetaphone(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $driver = $this->getDriver($query);
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        // For databases with SOUNDEX support (MySQL), use it
        if ($driver === self::DRIVER_MYSQL) {
            return $query->$method(function ($q) use ($column, $value) {
                $q->whereRaw("SOUNDEX(`{$column}`) = SOUNDEX(?)", [$value])
                    ->orWhere($column, 'LIKE', substr($value, 0, 2) . '%');
            });
        }

        // For PostgreSQL with fuzzystrmatch extension
        if ($driver === self::DRIVER_PGSQL && $this->config['use_native_functions']) {
            return $query->$method(function ($q) use ($column, $value) {
                $q->whereRaw("SOUNDEX(\"{$column}\") = SOUNDEX(?)", [$value])
                    ->orWhere($column, 'LIKE', substr($value, 0, 2) . '%');
            });
        }

        // Fallback for SQLite and others: Use phonetic LIKE patterns only
        return $this->applyPhoneticLikePatterns($query, $column, $value, $boolean);
    }

    /**
     * Apply similar_text matching (PHP-based)
     */
    protected function applySimilarText(
        Builder $query,
        string $column,
        string $value,
        array $options,
        string $boolean
    ): Builder {
        // This requires fetching data and filtering in PHP
        // For database-level, we fall back to LIKE with high tolerance
        $minPercentage = $options['min_percentage'] ?? 70;

        // Generate patterns based on similarity threshold
        $patternLength = (int) ceil(strlen($value) * ($minPercentage / 100));
        $pattern = substr($value, 0, $patternLength);

        $method = $boolean === 'or' ? 'orWhere' : 'where';
        return $query->$method($column, 'LIKE', "%{$pattern}%");
    }

    /**
     * Apply LIKE pattern matching
     */
    protected function applyLike(Builder $query, string $column, string $value, array $options, string $boolean): Builder
    {
        $caseInsensitive = $options['case_insensitive'] ?? true;
        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $driver = $this->getDriver($query);

        if ($caseInsensitive) {
            switch ($driver) {
                case self::DRIVER_MYSQL:
                    // MySQL LIKE is case-insensitive by default with utf8 collation
                    return $query->$method($column, 'LIKE', '%' . $value . '%');

                case self::DRIVER_PGSQL:
                    // PostgreSQL: Use ILIKE for case-insensitive
                    $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
                    return $query->$rawMethod("\"{$column}\" ILIKE ?", ['%' . $value . '%']);

                case self::DRIVER_SQLITE:
                    // SQLite: LIKE is case-insensitive for ASCII by default
                    return $query->$method($column, 'LIKE', '%' . $value . '%');

                case self::DRIVER_SQLSRV:
                    // SQL Server: Use LOWER for case-insensitive
                    $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
                    return $query->$rawMethod("LOWER([{$column}]) LIKE ?", ['%' . strtolower($value) . '%']);

                default:
                    return $query->$method(DB::raw("LOWER({$column})"), 'LIKE', '%' . strtolower($value) . '%');
            }
        }

        return $query->$method($column, 'LIKE', "%{$value}%");
    }

    /**
     * Calculate Levenshtein distance in PHP (for collection filtering)
     */
    public static function levenshteinDistance(string $str1, string $str2, array $options = []): int
    {
        $costInsert = $options['cost_insert'] ?? 1;
        $costReplace = $options['cost_replace'] ?? 1;
        $costDelete = $options['cost_delete'] ?? 1;

        return levenshtein(
            strtolower($str1),
            strtolower($str2),
            $costInsert,
            $costReplace,
            $costDelete
        );
    }

    /**
     * Calculate similarity percentage in PHP
     */
    public static function similarityPercentage(string $str1, string $str2): float
    {
        similar_text(strtolower($str1), strtolower($str2), $percent);
        return $percent;
    }
}

