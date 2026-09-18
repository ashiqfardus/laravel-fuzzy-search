<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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
    protected ?int $limitPerModel = null;
    protected array $modelOrder = [];

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
     * Cap how many rows each model may contribute before merging (default: limit()).
     */
    public function limitPerModel(int $limit): self
    {
        $this->limitPerModel = max(1, $limit);
        return $this;
    }

    /** Tie-break order for equal scores (and the whole order when withRelevance(false)). */
    public function orderByModel(array $classes): self
    {
        $this->modelOrder = array_values($classes);
        return $this;
    }

    /**
     * Execute search across all models
     *
     * @return Collection Collection of results with model type
     */
    public function get(): Collection
    {
        return $this->fetchRanked($this->limit)->take($this->limit)->values();
    }

    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $page   = max(1, (int) ($page ?: request()->input($pageName, 1)));
        $offset = ($page - 1) * $perPage;

        $total  = $this->countAll();
        $ranked = $this->fetchRanked($offset + $perPage);
        $items  = $ranked->slice($offset, $perPage)->values();

        return new LengthAwarePaginator(
            $items, $total, $perPage, $page,
            ['path' => request()->url(), 'pageName' => $pageName]
        );
    }

    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): Paginator
    {
        $page   = max(1, (int) ($page ?: request()->input($pageName, 1)));
        $offset = ($page - 1) * $perPage;

        $ranked = $this->fetchRanked($offset + $perPage + 1); // +1 lets Paginator detect a next page
        $items  = $ranked->slice($offset, $perPage + 1)->values();

        return new Paginator(
            $items, $perPage, $page,
            ['path' => request()->url(), 'pageName' => $pageName]
        );
    }

    /**
     * Fetch up to $perModelCeiling rows from every model, tag them, and return the merged,
     * ranked collection (score DESC, then orderByModel() rank, then model type, then key).
     */
    protected function fetchRanked(int $perModelCeiling): Collection
    {
        if (empty($this->searchTerm) && !config('fuzzy-search.allow_empty_search', false)) {
            throw new EmptySearchTermException();
        }

        $perModel   = min($this->limitPerModel ?? PHP_INT_MAX, max(1, $perModelCeiling));
        $allResults = collect();

        foreach ($this->models as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            $results = $this->searchModel($modelClass, $perModel);

            $results = $results->map(function ($item) use ($modelClass) {
                $item->_model_type  = class_basename($modelClass);
                $item->_model_class = $modelClass;
                return $item;
            });

            $allResults = $allResults->merge($results);
        }

        // Tie-break order: orderByModel() when set, otherwise the across() order — so
        // withRelevance(false) callers still get a deterministic, across()-ordered result
        // instead of whatever order the merge happened to produce.
        $modelOrder = $this->modelOrder ?: $this->models;
        $rank = fn ($item) => ($i = array_search($item->_model_class, $modelOrder, true)) === false ? PHP_INT_MAX : $i;

        return $allResults->sort(function ($a, $b) use ($rank) {
            if ($this->withRelevance) {
                $cmp = ($b->_score ?? 0) <=> ($a->_score ?? 0);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return [$rank($a), $a->_model_type, (string) $a->getKey()]
                <=> [$rank($b), $b->_model_type, (string) $b->getKey()];
        })->values();
    }

    /**
     * Build (but do not finish) one model's search query, honouring searchIn() columns.
     * searchModel() and countAll() each finish it their own way (get() vs count()), so
     * ordering/relevance/limit are intentionally left to the caller.
     *
     * searchIn() appends to a builder rather than replacing its defaults, so
     * $modelClass::search()->searchIn() cannot narrow the columns the model's own
     * `$searchable['columns']` already applied. When the caller restricted the columns
     * (and at least one of them exists on this table), build from a bare query instead
     * so only the requested columns are searched. Otherwise fall back to the model's
     * own defaults exactly as before. Returns null when a non-Searchable model has no
     * matching columns — the caller should skip it, not query a nonexistent column.
     */
    private function queryFor(string $modelClass): SearchBuilder|EloquentBuilder|null
    {
        $modelTraits = class_uses_recursive($modelClass);
        $hasSearchable = in_array(Traits\Searchable::class, $modelTraits);

        if ($hasSearchable) {
            $instance = new $modelClass();
            $weighted = $this->weightedColumnsExistingOn($instance);

            if (!empty($weighted)) {
                $builder = (new SearchBuilder($modelClass::query(), app(FuzzySearch::class)))
                    ->search($this->searchTerm)
                    ->searchIn($weighted)
                    ->using($this->algorithm ?? 'fuzzy')
                    ->typoTolerance($this->typoTolerance);

                // A bare SearchBuilder skips the extras Searchable::search() applies from
                // $searchable — apply them here too, so narrowing with searchIn() doesn't
                // silently drop the model's configured stop words/synonyms/accent handling.
                $extras = $instance->getSearchableExtras();

                if (!empty($extras['stop_words'])) {
                    $builder->ignoreStopWords($extras['stop_words']);
                }

                if (!empty($extras['synonyms'])) {
                    $builder->withSynonyms($extras['synonyms']);
                }

                if (!empty($extras['accent_insensitive'])) {
                    $builder->accentInsensitive();
                }

                if (!empty($extras['options'])) {
                    $builder->options($extras['options']);
                }

                return $builder;
            }

            return $modelClass::search($this->searchTerm)
                ->using($this->algorithm ?? 'fuzzy')
                ->typoTolerance($this->typoTolerance);
        }

        // Fall back to query builder approach
        $instance = new $modelClass();
        $weighted = $this->weightedColumnsExistingOn($instance);
        $columns = !empty($weighted)
            ? array_keys($weighted)
            : (empty($this->columnWeights) ? $this->getColumnsForModel($instance) : []);

        if (empty($columns)) {
            return null;
        }

        return $modelClass::query()
            ->whereFuzzyMultiple($columns, $this->searchTerm, $this->algorithm ?? 'like');
    }

    /**
     * Run one model's search capped at $limit rows. stableRanking()/orderBy(key) gives
     * equal-score rows a consistent order across the growing LIMIT that paginate()
     * re-fetches on each page, so no row is duplicated or skipped between pages.
     */
    protected function searchModel(string $modelClass, int $limit): Collection
    {
        $query = $this->queryFor($modelClass);

        if ($query === null) {
            return collect();
        }

        if ($query instanceof SearchBuilder) {
            return $query->withRelevance($this->withRelevance)
                ->stableRanking()
                ->limit($limit)
                ->get();
        }

        return $query->orderBy($query->getModel()->getKeyName())
            ->limit($limit)
            ->get();
    }

    /**
     * Sum of per-model match counts, each capped at limitPerModel() when set — the true
     * reachable total for paginate(), since fetchRanked() never returns more than
     * limitPerModel() rows from any one model.
     */
    protected function countAll(): int
    {
        if (empty($this->searchTerm) && !config('fuzzy-search.allow_empty_search', false)) {
            throw new EmptySearchTermException();
        }

        $total = 0;

        foreach ($this->models as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            $query = $this->queryFor($modelClass);

            if ($query === null) {
                continue;
            }

            $count = $query->count();
            $total += $this->limitPerModel !== null ? min($count, $this->limitPerModel) : $count;
        }

        return $total;
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
            return $this->validateColumns(Support\SearchableColumns::names($instance->searchable['columns']));
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
