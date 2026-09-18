<?php

namespace Ashiqfardus\LaravelFuzzySearch\Traits;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Jobs\ReindexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * Searchable Trait - Provides zero-config fluent search API
 *
 * Usage:
 *
 * class User extends Model
 * {
 *     use Searchable;
 *
 *     // Optional: customize searchable configuration
 *     protected array $searchable = [
 *         'columns' => [
 *             'name' => 10,
 *             'email' => 5,
 *         ],
 *         'algorithm' => 'fuzzy',
 *         'typo_tolerance' => 2,
 *     ];
 * }
 *
 * // Zero-config search
 * User::search('john')->get();
 */
trait Searchable
{
    /**
     * Boot the Searchable trait — register the observer so shadow columns
     * are populated whenever the model is saved.
     */
    public static function bootSearchable(): void
    {
        // Register event listeners directly instead of using static::observe().
        //
        // observe() internally does `new static`, which invokes the model
        // constructor → bootIfNotBooted(). Laravel 13's bootIfNotBooted()
        // throws a LogicException if it is called while the model is still
        // inside its boot phase (the `$booted[class]` flag is set only
        // *after* the booted event fires, so deferring with static::booted()
        // does not help — the flag is still false when the listener runs).
        //
        // Both observers expose only `saved` and `deleted`. Registering
        // those four events with the "Class@method" string callback avoids
        // `new static` entirely and works on Laravel 10–13.
        $observer = \Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver::class;
        $indexing = \Ashiqfardus\LaravelFuzzySearch\Observers\SearchableIndexingObserver::class;

        static::saved($observer . '@saved');
        static::deleted($observer . '@deleted');
        static::saved($indexing . '@saved');
        static::deleted($indexing . '@deleted');
    }

    /**
     * Return the searchable column names — the same columns a plain search() runs on.
     *
     * Both declaration forms are accepted, exactly as searchIn() accepts them:
     * `['name' => 10, 'email' => 5]` (weights) yields the keys, `['name', 'email']` (a list)
     * yields the values. A model that declares no columns falls back to the auto-detected
     * ones, so a zero-config model (`use Searchable;` and nothing else) is indexed, gets its
     * shadow columns maintained and works with FuzzySearch::tableSearch() instead of being
     * silently skipped by every consumer except search() itself.
     *
     * Consumers: IndexManager (postings), SearchableObserver (shadow columns),
     * SearchableIndexingObserver (reindex on save), FuzzySearch::tableSearch(),
     * SearchBuilder::autoDetectColumnsForExtended().
     */
    public function getSearchableColumns(): array
    {
        $columns = $this->searchable['columns'] ?? [];

        if (empty($columns)) {
            $columns = $this->getAutoDetectedColumns();
        }

        return SearchableColumns::names($columns);
    }

    /**
     * The searchable columns with their BM25F weights — the map `Model::search()` hands to
     * SearchBuilder::searchIn(), so anything that ranks this model's index outside the builder
     * (the Scout engine) can weigh the columns exactly as `useInvertedIndex()` does.
     *
     * @return array<string, int>
     */
    public function getSearchableColumnWeights(): array
    {
        return SearchableColumns::weights($this->getSearchableConfig()['columns'] ?? []);
    }

    /**
     * True when the model declared $searchable['columns'] itself, false when the list was
     * auto-detected. IndexManager reads it to decide whether a value it cannot index as text is
     * the caller's mistake (an informative throw) or the heuristic's (skip the column).
     */
    public function hasDeclaredSearchableColumns(): bool
    {
        return !empty($this->searchable['columns']);
    }

    /**
     * Real columns whose change should reindex the model even though they are not
     * searchable themselves — typically the foreign key behind a computed searchable
     * field (`brand_id` for a `brand_name` accessor). Declared as
     * `$searchable['reindex_on' => ['brand_id']]`. Read by SearchableIndexingObserver.
     *
     * @return string[]
     */
    public function getReindexTriggers(): array
    {
        return array_values((array) ($this->searchable['reindex_on'] ?? []));
    }

    /**
     * Reindex every row of this model whose $foreignKey equals $id — for the related
     * side of a relation-backed searchable field (renaming an Author must reindex its
     * Posts; Eloquent does not do that for you). Queued per row when indexing.async is on,
     * run in-process otherwise. Returns the number of rows scheduled.
     *
     *   // in Author::saved (or an observer):
     *   Post::reindexRelated('author_id', $author->id);
     */
    public static function reindexRelated(string $foreignKey, int|string $id): int
    {
        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');
        $count = 0;

        static::query()->where($foreignKey, $id)->select((new static)->getKeyName())
            ->chunkById(500, function ($rows) use ($async, $queue, &$count) {
                foreach ($rows as $row) {
                    if ($async) {
                        IndexModelJob::dispatch(static::class, $row->getKey())->onQueue($queue);
                    } else {
                        (new IndexModelJob(static::class, $row->getKey()))
                            ->handle(app(IndexManager::class));
                    }
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Start a new search query
     */
    public static function search(string $term): SearchBuilder
    {
        return static::searchOn((new static)->newQuery(), $term);
    }

    /**
     * Build a SearchBuilder on an existing Eloquent query, applying this model's $searchable
     * configuration. $columns, when given, replaces the configured column list (searchIn()
     * accumulates, so the configured columns are NOT applied when $columns is passed) — that is
     * what the Filament trait needs for getGloballySearchableAttributes().
     */
    public static function searchOn(EloquentBuilder $query, string $term, ?array $columns = null): SearchBuilder
    {
        $instance = $query->getModel();
        $builder  = new SearchBuilder($query, app(FuzzySearch::class));
        $builder->search($term);

        // Apply searchable configuration if available
        $config = $instance->getSearchableConfig();

        if ($columns !== null) {
            if ($columns !== []) {
                $builder->searchIn($columns);
            }
        } elseif (!empty($config['columns'])) {
            $builder->searchIn($config['columns']);
        }

        if (!empty($config['algorithm'])) {
            $builder->using($config['algorithm']);
        }

        if (isset($config['typo_tolerance'])) {
            $builder->typoTolerance($config['typo_tolerance']);
        }

        if (!empty($config['as_you_type'])) {
            $builder->asYouType();
        }

        if (!empty($config['stop_words'])) {
            $builder->ignoreStopWords($config['stop_words']);
        }

        if (!empty($config['synonyms'])) {
            $builder->withSynonyms($config['synonyms']);
        }

        if (!empty($config['accent_insensitive'])) {
            $builder->accentInsensitive();
        }

        if (!empty($config['options'])) {
            $builder->options($config['options']);
        }

        return $builder;
    }

    /**
     * The four $searchable extras that search() applies on top of columns/algorithm/
     * typo_tolerance. Public so FederatedSearch::queryFor() can apply them to the bare
     * SearchBuilder it builds for its searchIn()-narrowed path (getSearchableConfig() itself
     * stays protected).
     */
    public function getSearchableExtras(): array
    {
        $config = $this->getSearchableConfig();

        return [
            'stop_words'         => $config['stop_words'] ?? null,
            'synonyms'           => $config['synonyms'] ?? null,
            'accent_insensitive' => $config['accent_insensitive'] ?? null,
            'options'            => $config['options'] ?? null,
        ];
    }

    /**
     * Per-model index pipeline overrides (Phase 7): tokenizer, stemmer, stemmer_language,
     * locale. Only the keys declared in $searchable are returned; IndexManager fills the rest
     * from config. Changing any of them requires `fuzzy-search:rebuild --fresh`.
     *
     * @return array{tokenizer?: string, stemmer?: string, stemmer_language?: string, locale?: string}
     */
    public function getSearchablePipeline(): array
    {
        $config = $this->searchable ?? [];

        return array_filter([
            'tokenizer'        => $config['tokenizer'] ?? null,
            'stemmer'          => $config['stemmer'] ?? null,
            'stemmer_language' => $config['stemmer_language'] ?? null,
            'locale'           => $config['locale'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Get searchable configuration
     */
    protected function getSearchableConfig(): array
    {
        // Check if custom config is defined
        if (isset($this->searchable)) {
            $config = $this->searchable;

            // Ensure columns are set
            if (empty($config['columns'])) {
                $config['columns'] = $this->getAutoDetectedColumns();
            }

            return $config;
        }

        // Zero-config: auto-detect columns
        return [
            'columns' => $this->getAutoDetectedColumns(),
            'algorithm' => config('fuzzy-search.default_algorithm', 'fuzzy'),
            'typo_tolerance' => config('fuzzy-search.typo_tolerance.max_distance', 2),
        ];
    }

    /**
     * Auto-detect searchable columns
     */
    protected function getAutoDetectedColumns(): array
    {
        // Keyed by connection and table as well as class: a tenant model that switches either
        // must not be answered from the first tenant's schema.
        return SearchableColumns::detect(
            static::class . '|' . $this->getConnectionName() . '|' . $this->getTable(),
            fn () => $this->detectSearchableColumns()
        );
    }

    /**
     * The uncached auto-detection itself — see getAutoDetectedColumns(). Columns whose cast is
     * not text (an enum, an array, a custom cast class) are never selected: detection is a
     * heuristic, and the indexer cannot turn such a value into text.
     */
    private function detectSearchableColumns(): array
    {
        $table = $this->getTable();
        $columns = [];

        // Priority columns to check
        $priorityColumns = [
            'name' => 10,
            'title' => 10,
            'email' => 8,
            'username' => 8,
            'first_name' => 7,
            'last_name' => 7,
            'description' => 5,
            'content' => 5,
            'body' => 5,
            'bio' => 3,
            'summary' => 3,
            'excerpt' => 3,
            'slug' => 2,
            'sku' => 6,
            'code' => 6,
        ];

        try {
            // Get actual table columns, minus any the indexer could not read as text. Read
            // through the model's own connection, not the default one.
            $tableColumns = array_values(array_filter(
                $this->getConnection()->getSchemaBuilder()->getColumnListing($table),
                fn (string $column) => SearchableColumns::isTextLikeCast($this->getCasts()[$column] ?? null)
            ));

            // Check which priority columns exist
            foreach ($priorityColumns as $col => $weight) {
                if (in_array($col, $tableColumns)) {
                    $columns[$col] = $weight;
                }
            }

            // If no priority columns found, use fillable
            if (empty($columns) && !empty($this->fillable)) {
                $stringColumns = array_filter($this->fillable, function ($col) use ($tableColumns) {
                    // Only include if column exists and is likely a string column
                    return in_array($col, $tableColumns) &&
                           !in_array($col, ['id', 'password', 'remember_token', 'created_at', 'updated_at', 'deleted_at']);
                });

                foreach (array_slice($stringColumns, 0, 5) as $col) {
                    $columns[$col] = 1;
                }
            }

            // Ultimate fallback
            if (empty($columns)) {
                if (in_array('name', $tableColumns)) {
                    $columns['name'] = 1;
                } elseif (in_array('title', $tableColumns)) {
                    $columns['title'] = 1;
                } else {
                    // Just use first string-like column
                    foreach ($tableColumns as $col) {
                        if (!in_array($col, ['id', 'created_at', 'updated_at', 'deleted_at'])) {
                            $columns[$col] = 1;
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback if schema check fails
            $columns = ['name' => 1];
        }

        return $columns;
    }

    /**
     * Custom scoring method (can be overridden in model)
     */
    public function getSearchScore(float $baseScore): float
    {
        return $baseScore;
    }

    /**
     * @deprecated since v2.0.0 — writes to the legacy v1 `search_index` table which v2 does not read.
     *   Use `php artisan fuzzy-search:rebuild "App\Models\ModelName"` or `IndexModelJob::dispatch()` instead.
     */
    public static function reindex(): void
    {
        trigger_error(static::class . '::reindex() is deprecated since v2.0.0. Use php artisan fuzzy-search:rebuild instead.', E_USER_DEPRECATED);
        $config = config('fuzzy-search.indexing', []);

        if ($config['async'] ?? false) {
            dispatch(new ReindexModelJob(static::class))
                ->onQueue($config['queue'] ?? 'default');
        } else {
            static::performReindex();
        }
    }

    /**
     * @deprecated since v2.0.0 — writes to the legacy v1 `search_index` table which v2 does not read.
     *   Use `php artisan fuzzy-search:rebuild "App\Models\ModelName"` or `IndexModelJob::dispatch()` instead.
     */
    public static function performReindex(): void
    {
        trigger_error(static::class . '::performReindex() is deprecated since v2.0.0. Use php artisan fuzzy-search:rebuild instead.', E_USER_DEPRECATED);
        $instance = new static;
        $config = $instance->getSearchableConfig();
        $columns = array_keys($config['columns'] ?? []);

        if (empty($columns)) {
            return;
        }

        $chunkSize = config('fuzzy-search.indexing.chunk_size', 500);
        $table = config('fuzzy-search.indexing.table', 'search_index');

        // Clear existing index for this model
        \DB::table($table)->where('model', static::class)->delete();

        // Reindex in chunks
        static::query()->select(['id', ...$columns])->chunk($chunkSize, function ($models) use ($columns, $table) {
            $records = [];

            foreach ($models as $model) {
                $content = '';
                foreach ($columns as $column) {
                    $content .= ' ' . ($model->$column ?? '');
                }

                $records[] = [
                    'model' => static::class,
                    'model_id' => $model->id,
                    'content' => trim($content),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            \DB::table($table)->insert($records);
        });
    }

    /**
     * Scope for simple fuzzy search (backward compatibility)
     */
    public function scopeSearchFuzzy($query, string $term, ?array $columns = null, ?string $algorithm = null): mixed
    {
        $fuzzySearch = app(FuzzySearch::class);
        $config = $this->getSearchableConfig();
        $columns = $columns ?? array_keys($config['columns'] ?? ['name' => 1]);

        // Use configured weights from $searchable['columns'] when columns aren't overridden.
        $configColumns = $config['columns'] ?? [];
        $weightedColumns = $columns !== null
            ? array_fill_keys($columns, 1)
            : (empty($configColumns) ? array_fill_keys(['name'], 1) : $configColumns);

        $builder = new SearchBuilder($query, $fuzzySearch);

        return $builder
            ->search($term)
            ->searchIn($weightedColumns)
            ->using($algorithm ?? ($config['algorithm'] ?? 'fuzzy'));
    }
}

