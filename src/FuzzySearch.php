<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Illuminate\Database\Query\Builder;
use Ashiqfardus\LaravelFuzzySearch\Drivers\BaseDriver;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidAlgorithmException;

class FuzzySearch
{
    protected array $config;

    protected const DRIVER_MYSQL  = 'mysql';
    protected const DRIVER_PGSQL  = 'pgsql';
    protected const DRIVER_SQLITE = 'sqlite';
    protected const DRIVER_SQLSRV = 'sqlsrv';

    /** Maps algorithm name → driver class */
    protected array $registry = [
        'fuzzy'        => Drivers\FuzzyDriver::class,
        'levenshtein'  => Drivers\LevenshteinDriver::class,
        'soundex'      => Drivers\SoundexDriver::class,
        'metaphone'    => Drivers\MetaphoneDriver::class,
        'trigram'      => Drivers\TrigramDriver::class,
        'similar_text' => Drivers\SimilarTextDriver::class,
        'simple'       => Drivers\SimpleDriver::class,
        'like'         => Drivers\SimpleDriver::class,
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function applyFuzzyWhere(
        Builder $query,
        string $column,
        string $value,
        ?string $algorithm = null,
        ?array $options = [],
        string $boolean = 'and'
    ): Builder {
        $algorithm = $algorithm ?? $this->config['default_algorithm'] ?? 'fuzzy';
        $mergedConfig = $this->mergeOptions($algorithm, $options ?? []);

        // Accent-insensitive: Postgres with unaccent extension wraps the column
        if (($options['accent_insensitive'] ?? false)
            && $this->getDriver($query) === self::DRIVER_PGSQL
            && ($this->config['use_native_functions'] ?? false)
        ) {
            return $this->applyWithUnaccent($query, $column, $value, $boolean);
        }

        $driver = $this->resolveDriver($algorithm, $query, $mergedConfig);
        return $driver->apply($query, $column, $value, $boolean);
    }

    public function applyFuzzyWhereMultiple(
        Builder $query,
        array $columns,
        string $value,
        ?string $algorithm = null,
        ?array $options = []
    ): Builder {
        return $query->where(function ($q) use ($columns, $value, $algorithm, $options) {
            foreach ($columns as $index => $column) {
                $boolean = $index === 0 ? 'and' : 'or';
                $this->applyFuzzyWhere($q, $column, $value, $algorithm, $options, $boolean);
            }
        });
    }

    public function applyFuzzyOrder(Builder $query, string $column, string $value, string $direction = 'asc'): Builder
    {
        $driver = $this->getDriver($query);
        $col = $this->quoteColumnForDriver($column, $driver);

        $expression = match ($driver) {
            self::DRIVER_MYSQL  => "LOCATE(?, {$col})",
            self::DRIVER_PGSQL  => "POSITION(? IN {$col})",
            self::DRIVER_SQLITE => "INSTR({$col}, ?)",
            self::DRIVER_SQLSRV => "CHARINDEX(?, {$col})",
            default             => "CASE WHEN {$col} LIKE ? THEN 0 ELSE 1 END",
        };

        return $query->orderByRaw("{$expression} {$direction}", [$value]);
    }

    public static function levenshteinDistance(string $str1, string $str2, array $options = []): int
    {
        return levenshtein(
            strtolower($str1),
            strtolower($str2),
            $options['cost_insert']  ?? 1,
            $options['cost_replace'] ?? 1,
            $options['cost_delete']  ?? 1
        );
    }

    public static function similarityPercentage(string $str1, string $str2): float
    {
        similar_text(strtolower($str1), strtolower($str2), $percent);
        return $percent;
    }

    protected function resolveDriver(string $algorithm, Builder $query, array $config): BaseDriver
    {
        $dbDriver = $this->getDriver($query);

        if (!isset($this->registry[$algorithm])) {
            if ($config['legacy_dispatch'] ?? false) {
                return new Drivers\LevenshteinDriver($config, $dbDriver);
            }
            throw new InvalidAlgorithmException($algorithm);
        }

        $class = $this->registry[$algorithm];
        return new $class($config, $dbDriver);
    }

    protected function mergeOptions(string $algorithm, array $options): array
    {
        $base = $this->config;
        $base[$algorithm] = array_merge($base[$algorithm] ?? [], $options);
        return $base;
    }

    protected function getDriver(Builder $query): string
    {
        return $query->getConnection()->getDriverName();
    }

    protected function quoteColumnForDriver(string $column, string $driver): string
    {
        return match ($driver) {
            'mysql'  => "`{$column}`",
            'pgsql'  => "\"{$column}\"",
            'sqlsrv' => "[{$column}]",
            default  => $column,
        };
    }

    protected function applyWithUnaccent(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $col = $this->quoteColumnForDriver($column, 'pgsql');
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        return $query->$method("unaccent({$col}) ILIKE unaccent(?)", ['%' . $value . '%']);
    }
}
