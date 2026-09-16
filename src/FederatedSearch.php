<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * FederatedSearch - Search across multiple models simultaneously
 *
 * Usage:
 *
 * $results = FederatedSearch::across([User::class, Product::class, Post::class])
 *     ->search('laptop')
 *     ->searchIn(['name' => 10, 'title' => 10, 'description' => 5])
 *     ->get();
 */
class FederatedSearch
{
    /** @var array<string, array<int, string>> Schema column listings cached per "connection.table". */
    protected static array $columnListings = [];

    protected array $models = [];
    protected string $searchTerm = '';
    protected array $searchableColumns = [];
    protected array $columnWeights = [];
    protected ?string $algorithm = null;
    protected array $options = [];
    protected int $limit = 15;
    protected bool $withRelevance = true;
    protected int $typoTolerance = 2;

    /**
     * Create a new federated search across multiple models
     *
     * @param array $models Array of model class names
     */
    public static function across(array $models): self
    {
        $instance = new self();
        $instance->models = $models;
        return $instance;
    }

    /**
     * Set the search term
     */
    public function search(string $term): self
    {
        $this->searchTerm = trim($term);
        return $this;
    }

    /**
     * Set searchable columns with optional weights
     */
    public function searchIn(array $columns): self
    {
        foreach ($columns as $key => $value) {
            $column = is_string($key) ? $key : $value;
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
                throw new \InvalidArgumentException(
                    "Invalid column name: '{$column}'. Column names must match [a-zA-Z_][a-zA-Z0-9_.]* ."
                );
            }
            if (is_string($key)) {
                $this->searchableColumns[] = $key;
                $this->columnWeights[$key] = (int) $value;
            } else {
                $this->searchableColumns[] = $value;
                $this->columnWeights[$value] = 1;
            }
        }
        return $this;
    }

    /**
     * Set search algorithm
     */
    public function using(string $algorithm): self
    {
        $this->algorithm = $algorithm;
        return $this;
    }

    /**
     * Set typo tolerance
     */
    public function typoTolerance(int $level): self
    {
        $this->typoTolerance = max(0, min(5, $level));
        return $this;
    }

    /**
     * Set limit
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Include relevance score
     */
    public function withRelevance(bool $include = true): self
    {
        $this->withRelevance = $include;
        return $this;
    }

    /**
     * Set algorithm options
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Execute search across all models
     *
     * @return Collection Collection of results with model type
     */
    public function get(): Collection
    {
        if (empty($this->searchTerm) && !config('fuzzy-search.allow_empty_search', false)) {
            throw new EmptySearchTermException();
        }

        $allResults = collect();

        foreach ($this->models as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            // Check if model uses Searchable trait
            $modelTraits = class_uses_recursive($modelClass);
            $hasSearchable = in_array(Traits\Searchable::class, $modelTraits);

            if ($hasSearchable) {
                $results = $this->searchModel($modelClass);
            } else {
                // Fall back to query builder approach
                $instance = new $modelClass();
                $weighted = $this->weightedColumnsExistingOn($instance);
                $columns = !empty($weighted)
                    ? array_keys($weighted)
                    : (empty($this->columnWeights) ? $this->getColumnsForModel($instance) : []);

                if (empty($columns)) {
                    continue;
                }

                $results = $modelClass::query()
                    ->whereFuzzyMultiple($columns, $this->searchTerm, $this->algorithm ?? 'like')
                    ->limit($this->limit)
                    ->get();
            }

            // Add model type to each result
            $results = $results->map(function ($item) use ($modelClass) {
                $item->_model_type = class_basename($modelClass);
                $item->_model_class = $modelClass;
                return $item;
            });

            $allResults = $allResults->merge($results);
        }

        // Sort by score if relevance is enabled
        if ($this->withRelevance) {
            $allResults = $allResults->sortByDesc(function ($item) {
                return $item->_score ?? 0;
            })->values();
        }

        // Apply limit to combined results
        return $allResults->take($this->limit);
    }

    /**
     * Search a model using the Searchable trait, honouring searchIn() columns.
     *
     * searchIn() appends to a builder rather than replacing its defaults, so
     * $modelClass::search()->searchIn() cannot narrow the columns the model's own
     * `$searchable['columns']` already applied. When the caller restricted the columns
     * (and at least one of them exists on this table), build from a bare query instead
     * so only the requested columns are searched. Otherwise fall back to the model's
     * own defaults exactly as before.
     */
    protected function searchModel(string $modelClass): Collection
    {
        $instance = new $modelClass();
        $weighted = $this->weightedColumnsExistingOn($instance);

        if (!empty($weighted)) {
            return (new SearchBuilder($modelClass::query(), app(FuzzySearch::class)))
                ->search($this->searchTerm)
                ->searchIn($weighted)
                ->using($this->algorithm ?? 'fuzzy')
                ->typoTolerance($this->typoTolerance)
                ->withRelevance($this->withRelevance)
                ->limit($this->limit)
                ->get();
        }

        return $modelClass::search($this->searchTerm)
            ->using($this->algorithm ?? 'fuzzy')
            ->typoTolerance($this->typoTolerance)
            ->withRelevance($this->withRelevance)
            ->limit($this->limit)
            ->get();
    }

    /**
     * searchIn() columns that actually exist on the model's table, with their weights.
     * Callers pass one column list for many models (e.g. ['name', 'title']); a column a
     * table lacks would otherwise raise a SQL error. Schema listing is cached per
     * connection+table; call resetColumnCache() between tests to avoid stale listings
     * leaking across databases/schemas.
     */
    protected function weightedColumnsExistingOn(Model $instance): array
    {
        if (empty($this->columnWeights)) {
            return [];
        }

        $table = $instance->getTable();
        $cacheKey = ($instance->getConnectionName() ?? $instance->getConnection()->getName()) . '.' . $table;

        if (!isset(static::$columnListings[$cacheKey])) {
            try {
                static::$columnListings[$cacheKey] = $instance->getConnection()->getSchemaBuilder()->getColumnListing($table);
            } catch (\Throwable) {
                static::$columnListings[$cacheKey] = [];
            }
        }

        return array_filter(
            $this->columnWeights,
            fn ($weight, $column) => in_array($column, static::$columnListings[$cacheKey], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** Reset the schema-column cache (call between test cases to prevent cross-test contamination). */
    public static function resetColumnCache(): void
    {
        static::$columnListings = [];
    }

    /**
     * Get searchable columns for a model
     */
    protected function getColumnsForModel(Model $instance): array
    {
        // If custom columns specified, use those
        if (!empty($this->searchableColumns)) {
            return $this->searchableColumns;
        }

        // Try to get from model's searchable property
        if (isset($instance->searchable['columns'])) {
            return $this->validateColumns(array_keys($instance->searchable['columns']));
        }

        // Try to get from fuzzySearchable property
        if (isset($instance->fuzzySearchable)) {
            return $this->validateColumns($instance->fuzzySearchable);
        }

        // Default fallback columns
        return ['name', 'title'];
    }

    private function validateColumns(array $columns): array
    {
        foreach ($columns as $column) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
                throw new \InvalidArgumentException(
                    "Invalid column name: '{$column}'. Column names must match [a-zA-Z_][a-zA-Z0-9_.]* ."
                );
            }
        }
        return $columns;
    }

    /**
     * Get results grouped by model type
     *
     * @return Collection Grouped collection
     */
    public function getGrouped(): Collection
    {
        return $this->get()->groupBy('_model_type');
    }

    /**
     * Get count per model type
     *
     * @return array ['User' => 5, 'Product' => 3, ...]
     */
    public function getCounts(): array
    {
        return $this->get()->groupBy('_model_type')->map->count()->toArray();
    }
}
