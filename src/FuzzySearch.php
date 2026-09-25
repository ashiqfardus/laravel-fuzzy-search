<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Illuminate\Database\Query\Builder;
use Ashiqfardus\LaravelFuzzySearch\Drivers\BaseDriver;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidAlgorithmException;
use Ashiqfardus\LaravelFuzzySearch\InMemorySearch;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;

class FuzzySearch
{
    protected ?array $config;

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

    /**
     * Create an in-memory search over a fixed iterable.
     * Useful for static lists, config arrays, navigation menus.
     *
     * Example:
     *   FuzzySearch::on($items)->search('term')->searchIn(['name'])->get()
     */
    public static function on(iterable $items): InMemorySearch
    {
        return new InMemorySearch($items);
    }

    /** null = built by the container: read config('fuzzy-search') live on every call. */
    public function __construct(?array $config = null)
    {
        $this->config = $config;
    }

    /**
     * The configuration this instance works from. The container-built singleton is resolved
     * once at boot, so it reads the live config on each call and sees runtime overrides
     * (tests, per-tenant config); an explicit constructor array stays a frozen snapshot.
     */
    protected function currentConfig(): array
    {
        return $this->config ?? (array) config('fuzzy-search', []);
    }

    /**
     * @internal The driver-config key applyTermWhere() sets from its own argument, after the
     *           caller's options are merged, so no option can set it.
     */
    public const WHOLE_TERM_LENGTH = 'whole_term_length';

    public function applyFuzzyWhere(
        Builder $query,
        string $column,
        string $value,
        ?string $algorithm = null,
        ?array $options = [],
        string $boolean = 'and'
    ): Builder {
        return $this->applyTermWhere($query, $column, $value, $algorithm, $options ?? [], $boolean, null);
    }

    /**
     * @internal applyFuzzyWhere() for SearchBuilder. $wholeTermLength is the whole search term's
     * length under tokenize(), which similar_text's min_percentage bound measures instead of the
     * token's (ruling ER-59). It is an argument, never an option: whatever a caller passes in
     * $options, options() or a macro cannot widen the bound.
     */
    public function applyTermWhere(
        Builder $query,
        string $column,
        string $value,
        ?string $algorithm,
        array $options,
        string $boolean,
        ?int $wholeTermLength
    ): Builder {
        // $column goes into raw SQL (SQLite leaves a bare column as written), here and in
        // applyFuzzyOrder(): a caller who passes user input as the column gets an exception.
        SearchableColumns::validate([$column], SearchableColumns::INVALID_NAME_BRACKETED);

        $value = $this->term($value);

        $algorithm = $algorithm ?? $this->currentConfig()['default_algorithm'] ?? 'fuzzy';
        $mergedConfig = $this->mergeOptions($algorithm, $options);
        $mergedConfig[$algorithm][self::WHOLE_TERM_LENGTH] = $wholeTermLength;
        $driver = $this->resolveDriver($algorithm, $query, $mergedConfig);

        // $options['accent_insensitive'] is the explicit opt-in: ->accentInsensitive(), the model's
        // $searchable['accent_insensitive'], a preset, or a macro's own option. SearchBuilder never
        // passes the global unicode.accent_insensitive default here — that one only adds the term's
        // folded form as a variant. On PostgreSQL with use_native_functions the opt-in ORs
        // unaccent(col) ILIKE unaccent(?) beside the algorithm's predicate, in one group so an
        // outer AND still binds both; the algorithm (and its typo tolerance) stays, and the
        // alternative carries the driver's matchBound() (similar_text's min_percentage). It needs
        // the unaccent extension.
        if (($options['accent_insensitive'] ?? false)
            && $this->getDriver($query) === self::DRIVER_PGSQL
            && ($this->currentConfig()['use_native_functions'] ?? false)
        ) {
            return $query->{$boolean === 'or' ? 'orWhere' : 'where'}(function (Builder $group) use ($driver, $column, $value) {
                $driver->apply($group, $column, $value);

                if (($bound = $driver->matchBound($group, $column, $value)) === null) {
                    $this->applyWithUnaccent($group, $column, $value, 'or');

                    return;
                }

                $group->orWhere(function (Builder $alternative) use ($column, $value, $bound) {
                    $this->applyWithUnaccent($alternative, $column, $value, 'and');
                    $alternative->whereRaw(...$bound);
                });
            });
        }

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
        SearchableColumns::validate([$column], SearchableColumns::INVALID_NAME_BRACKETED);

        $direction = strtolower(trim($direction));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException("Invalid sort direction [{$direction}]: must be 'asc' or 'desc'.");
        }

        $driver = $this->getDriver($query);
        $col = $this->quoteColumnForDriver($column, $driver, $query);

        $expression = match (true) {
            \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::isMySqlFamily($driver) => "LOCATE(?, {$col})",
            $driver === self::DRIVER_PGSQL  => "POSITION(? IN {$col})",
            $driver === self::DRIVER_SQLITE => "INSTR({$col}, ?)",
            $driver === self::DRIVER_SQLSRV => "CHARINDEX(?, {$col})",
            default                         => "CASE WHEN {$col} LIKE ? THEN 0 ELSE 1 END",
        };

        return $query->orderByRaw("{$expression} {$direction}", [$this->term($value)]);
    }

    /**
     * Every macro, Fuzzy scope and SearchBuilder condition binds its term through here (the two
     * public entry points above): invalid UTF-8 dropped and capped at query.max_term_length, so
     * no driver generates patterns from a longer term than search() would.
     */
    private function term(string $value): string
    {
        return self::capTerm(Utf8::clean($value));
    }

    /**
     * Truncate a term to query.max_term_length characters (never bytes). The one cap every path
     * applies: SearchBuilder::capSearchTerm(), tableSearch(), the applyFuzzy*() entry points,
     * InMemorySearch and the Scout engine. (The Lexer cuts each extended token the same way.)
     *
     * @internal
     */
    public static function capTerm(string $term): string
    {
        return mb_substr($term, 0, (int) config('fuzzy-search.query.max_term_length', 128), 'UTF-8');
    }

    /**
     * A search closure for Filament tables — `TextColumn::make('name')->searchable(query:
     * FuzzySearch::tableSearch(['name']))` on v3, v4 and v5, or
     * `$table->searchUsing(FuzzySearch::tableSearch())` on Filament v4+ (Table::searchUsing()
     * does not exist in v3). Filament calls it as (Builder $query, string $search):
     * `searchable(query:)` evaluates it inside its own where/orWhere group, while v4+
     * `searchUsing()` calls it bare with the whole term — either way the predicate is added as
     * its own group (applyFuzzyWhereMultiple() wraps itself), so it composes with Filament's
     * other constraints.
     * With no columns the model's $searchable columns are used (Searchable trait models only);
     * when that leaves none, a typed search matches nothing.
     *
     * The columns are SQL columns of the table being queried (or already-qualified `table.column`
     * names), not relation paths: each one is passed through qualifyColumn() so the predicate
     * survives a join. For a relation column keep Filament's own `searchable()`.
     *
     * The term is trimmed and capped at query.max_term_length before it reaches a driver — the
     * table search box is unbounded user input (see capTerm()).
     */
    public static function tableSearch(array|string|null $columns = null, ?string $algorithm = null, array $options = []): \Closure
    {
        return function (\Illuminate\Database\Eloquent\Builder $query, string $search) use ($columns, $algorithm, $options): \Illuminate\Database\Eloquent\Builder {
            $cols = match (true) {
                is_array($columns)  => $columns,
                is_string($columns) => [$columns],
                default             => method_exists($query->getModel(), 'getSearchableColumns')
                    ? $query->getModel()->getSearchableColumns()
                    : [],
            };

            $search = self::capTerm(trim(Utf8::clean($search)));

            if ($search === '') {
                return $query;
            }

            if ($cols === []) {
                return $query->whereRaw('0 = 1'); // no column to search matches nothing, as in SearchBuilder
            }

            $cols = array_map(fn ($c) => $query->qualifyColumn($c), array_values($cols));

            app(static::class)->applyFuzzyWhereMultiple($query->getQuery(), $cols, $search, $algorithm, $options);

            return $query;
        };
    }

    /** Compares at most the first 255 characters of each string — see Utf8::scoringInput(). */
    public static function levenshteinDistance(string $str1, string $str2, array $options = []): int
    {
        return levenshtein(
            strtolower(Utf8::scoringInput($str1)),
            strtolower(Utf8::scoringInput($str2)),
            $options['cost_insert']  ?? 1,
            $options['cost_replace'] ?? 1,
            $options['cost_delete']  ?? 1
        );
    }

    /** Compares at most the first 255 characters of each string — see Utf8::scoringInput(). */
    public static function similarityPercentage(string $str1, string $str2): float
    {
        similar_text(strtolower(Utf8::scoringInput($str1)), strtolower(Utf8::scoringInput($str2)), $percent);
        return $percent;
    }

    protected function resolveDriver(string $algorithm, Builder $query, array $config): BaseDriver
    {
        // Guard: only allow lowercase letters and underscores to prevent
        // class-loading attacks via the raw applyFuzzyWhere() API.
        if (!preg_match('/^[a-z_]+$/', $algorithm)) {
            throw new InvalidAlgorithmException($algorithm);
        }

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
        $base = $this->currentConfig();
        $base[$algorithm] = array_merge($base[$algorithm] ?? [], $options);
        if (isset($options['max_patterns'])) {
            $base['max_patterns'] = (int) $options['max_patterns'];
        }
        return $base;
    }

    protected function getDriver(Builder $query): string
    {
        return $query->getConnection()->getDriverName();
    }

    /** $query supplies the table prefix a qualified column's table is written with. */
    protected function quoteColumnForDriver(string $column, string $driver, ?Builder $query = null): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::quoteIdentifier(
            $column, $driver, $query?->getGrammar()->getTablePrefix() ?? ''
        );
    }

    protected function applyWithUnaccent(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $col = $this->quoteColumnForDriver($column, 'pgsql', $query);
        $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        return $query->$method("unaccent({$col}) ILIKE unaccent(?)", ['%' . \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::escapeLike($value, self::DRIVER_PGSQL) . '%']);
    }
}
