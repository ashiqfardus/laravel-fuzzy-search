<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;
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
    protected array $models = [];
    protected string $searchTerm = '';
    /** Only invalid UTF-8 (`?q=%FF`): cleaned to '' but not empty, so it matches nothing — see SearchBuilder. */
    protected bool $invalidBytesOnly = false;
    protected array $searchableColumns = [];
    protected array $columnWeights = [];
    protected ?string $algorithm = null;
    protected array $options = [];
    protected int $limit = 15;
    protected bool $withRelevance = true;
    /** null: each model's own $searchable typo tolerance. */
    protected ?int $typoTolerance = null;
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
        $this->searchTerm       = trim(Utf8::clean($term));
        $this->invalidBytesOnly = $this->searchTerm === '' && trim($term) !== '';
        return $this;
    }

    /**
     * Set searchable columns with optional weights
     */
    public function searchIn(array $columns): self
    {
        foreach ($columns as $key => $value) {
            self::validateColumns([is_string($key) ? $key : $value]);
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
     * Set search algorithm for every model. Without it, each model uses its own
     * $searchable['algorithm'] (models without the Searchable trait: LIKE).
     */
    public function using(string $algorithm): self
    {
        $this->algorithm = $algorithm;
        return $this;
    }

    /**
     * Set typo tolerance for every model. Without it, each model uses its own
     * $searchable['typo_tolerance'].
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

    /** A term below min_search_length or made only of invalid UTF-8 matches nothing — see SearchBuilder::matchesNothing(). */
    protected function matchesNothing(): bool
    {
        return $this->invalidBytesOnly || SearchBuilder::belowMinSearchLength($this->searchTerm);
    }

    /**
     * Fetch up to $perModelCeiling rows from every model, tag them, and return the merged,
     * ranked collection (score DESC, then orderByModel() rank, then model type, then key).
     */
    protected function fetchRanked(int $perModelCeiling): Collection
    {
        if ($this->matchesNothing()) {
            return collect();
        }

        if ($this->searchTerm === '' && !config('fuzzy-search.allow_empty_search', false)) {
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
     * (and at least one of them exists on this table), build with Searchable::searchOn()
     * and those columns instead, so only the requested columns are searched. Otherwise use
     * the model's own defaults. Returns null when a non-Searchable model has no matching
     * columns — the caller should skip it, not query a nonexistent column.
     */
    private function queryFor(string $modelClass): SearchBuilder|EloquentBuilder|null
    {
        $modelTraits = class_uses_recursive($modelClass);
        $hasSearchable = in_array(Traits\Searchable::class, $modelTraits);

        if ($hasSearchable) {
            $weighted = $this->weightedColumnsExistingOn(new $modelClass());

            // searchOn() applies the model's own $searchable configuration — algorithm, typo
            // tolerance, stop words, synonyms, accents, options — with the narrowed columns
            // replacing the configured ones. An algorithm or tolerance set on this federated
            // search overrides the model's; unset, each model searches the way it is configured.
            $builder = empty($weighted)
                ? $modelClass::search($this->searchTerm)
                : $modelClass::searchOn($modelClass::query(), $this->searchTerm, $weighted);

            if ($this->algorithm !== null) {
                $builder->using($this->algorithm);
            }

            if ($this->typoTolerance !== null) {
                $builder->typoTolerance($this->typoTolerance);
            }

            return $builder;
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

        return $modelClass::query()->whereFuzzyMultiple(
            $columns,
            $this->searchTerm,
            $this->algorithm ?? 'like',
            $this->typoTolerance === null ? [] : ['max_distance' => $this->typoTolerance]
        );
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
     * Sum of the per-model reachable counts — the true total for paginate().
     */
    protected function countAll(): int
    {
        return array_sum($this->countPerModel());
    }

    /**
     * How many rows each model can actually contribute to this search: its match count, capped
     * at limitPerModel() when set and at max_candidates on the SearchBuilder path (a ranked
     * search reads at most that many candidates and slices the page out of them, so no model
     * can ever hand over more). getCounts() reports these numbers and paginate()'s total() is
     * their sum, so the two can never disagree and no page is promised that cannot be filled.
     *
     * Keyed by class, not by basename: across([A\User::class, B\User::class]) is two models
     * and must be counted twice.
     *
     * @return array<class-string, int> model class => reachable count
     */
    protected function countPerModel(): array
    {
        if ($this->searchTerm === '' && !$this->invalidBytesOnly && !config('fuzzy-search.allow_empty_search', false)) {
            throw new EmptySearchTermException();
        }

        $counts = [];

        foreach ($this->models as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            $query = $this->queryFor($modelClass);

            if ($query === null) {
                continue;
            }

            $counts[$modelClass] = $this->matchesNothing() ? 0 : min(
                $query->count(),
                $this->limitPerModel ?? PHP_INT_MAX,
                // The plain whereFuzzyMultiple() fallback has no candidate window.
                $query instanceof SearchBuilder ? (int) config('fuzzy-search.max_candidates', 1000) : PHP_INT_MAX
            );
        }

        return $counts;
    }

    /**
     * searchIn() columns that actually exist on the model's table, with their weights.
     * Callers pass one column list for many models (e.g. ['name', 'title']); a column a
     * table lacks would otherwise raise a SQL error. Schema listing is cached per
     * connection+table (SearchableColumns::onTable()); call resetColumnCache() between tests
     * to avoid stale listings leaking across databases/schemas.
     */
    protected function weightedColumnsExistingOn(Model $instance): array
    {
        if (empty($this->columnWeights)) {
            return [];
        }

        $listing = SearchableColumns::onTable($instance->getConnection(), $instance->getTable());

        return array_filter(
            $this->columnWeights,
            fn ($weight, $column) => in_array($column, $listing, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** Reset the schema-column cache (call between test cases to prevent cross-test contamination). */
    public static function resetColumnCache(): void
    {
        SearchableColumns::reset();
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
            return self::validateColumns(Support\SearchableColumns::names($instance->searchable['columns']));
        }

        // Try to get from fuzzySearchable property
        if (isset($instance->fuzzySearchable)) {
            return self::validateColumns($instance->fuzzySearchable);
        }

        // Default fallback columns
        return ['name', 'title'];
    }

    /**
     * Throws for any column that is not an identifier (dotted table.column allowed) — the rule every
     * column name the package writes into SQL follows. Also used by the Scout engine for orderBy().
     *
     * @internal
     */
    public static function validateColumns(array $columns): array
    {
        foreach ($columns as $column) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/D', $column)) {
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
     * Get count per model type.
     *
     * These are match counts, not page sizes: a model that matches 40 rows reports 40 even when
     * limit() asked for 10. They are the same numbers paginate()'s total() adds up, so they are
     * capped where the search itself is — see countPerModel(). A model that matches nothing
     * reports 0; one that has no searchable column here is left out entirely.
     *
     * Keys are class basenames, like `_model_type` and getGrouped(): two models sharing a short
     * name report one combined count here, while total() still counts them separately.
     *
     * @return array ['User' => 5, 'Product' => 3, ...]
     */
    public function getCounts(): array
    {
        $counts = [];

        foreach ($this->countPerModel() as $modelClass => $count) {
            $name = class_basename($modelClass);
            $counts[$name] = ($counts[$name] ?? 0) + $count;
        }

        return $counts;
    }
}
