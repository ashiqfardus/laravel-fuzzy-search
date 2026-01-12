<?php

namespace Ashiqfardus\LaravelFuzzySearch\Traits;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Jobs\ReindexModelJob;
use Illuminate\Support\Facades\Schema;

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
     * Start a new search query
     */
    public static function search(string $term): SearchBuilder
    {
        $instance = new static;
        $fuzzySearch = app(FuzzySearch::class);
        $query = $instance->newQuery();

        $builder = new SearchBuilder($query, $fuzzySearch);
        $builder->search($term);

        // Apply searchable configuration if available
        $config = $instance->getSearchableConfig();

        if (!empty($config['columns'])) {
            $builder->searchIn($config['columns']);
        }

        if (!empty($config['algorithm'])) {
            $builder->using($config['algorithm']);
        }

        if (isset($config['typo_tolerance'])) {
            $builder->typoTolerance($config['typo_tolerance']);
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
            // Get actual table columns
            $tableColumns = Schema::getColumnListing($table);

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
     * Reindex the model for search
     */
    public static function reindex(): void
    {
        $config = config('fuzzy-search.indexing', []);

        if ($config['async'] ?? false) {
            dispatch(new ReindexModelJob(static::class))
                ->onQueue($config['queue'] ?? 'default');
        } else {
            static::performReindex();
        }
    }

    /**
     * Perform the actual reindexing
     */
    public static function performReindex(): void
    {
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

        $builder = new SearchBuilder($query, $fuzzySearch);

        return $builder
            ->search($term)
            ->searchIn(array_fill_keys($columns, 1))
            ->using($algorithm ?? ($config['algorithm'] ?? 'fuzzy'));
    }
}

