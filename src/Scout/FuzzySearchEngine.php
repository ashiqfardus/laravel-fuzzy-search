<?php

namespace Ashiqfardus\LaravelFuzzySearch\Scout;

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Exceptions\NotSupportedException;

/**
 * Scout engine adapter — bundled in core, registered conditionally
 * when laravel/scout is installed.
 *
 * Activate with: SCOUT_DRIVER=fuzzy-search in .env
 */
class FuzzySearchEngine extends Engine
{
    public function __construct(
        private IndexManager $indexManager,
        private Bm25Scorer   $scorer,
    ) {}

    public function update($models): void
    {
        foreach ($models as $model) {
            $this->indexManager->indexModel($model);
        }
    }

    public function delete($models): void
    {
        foreach ($models as $model) {
            $this->indexManager->removeFromIndex($model::class, $model->getKey());
        }
    }

    public function search(Builder $builder)
    {
        $modelType = $builder->model::class;
        $terms     = $this->terms($builder);
        $limit     = $builder->limit ?? 15;

        $ranked = $this->scorer->rank($terms, $modelType, $this->columnWeights($builder));
        $query  = $this->constrainedQuery($builder);

        // 'total' is the match count — the ranked ids (that satisfy the constraints) — not
        // the size of the page cut from them.
        if ($query === null || empty($ranked)) {
            $total   = count($ranked);
            $results = $this->hydrate(array_slice($ranked, 0, $limit, true));
        } else {
            // Constraints first, then the cut — otherwise a selective where() returns a
            // short or empty page while matches exist further down the ranking.
            $ids     = array_keys($ranked);
            $total   = RankedCandidates::count($query, $ids);
            $keys    = RankedCandidates::keys($query, $ids, $limit);
            $results = $this->hydrate($this->pick($ranked, array_slice($keys, 0, $limit)));
        }

        return [
            'results' => $results,
            'total'   => $total,
        ];
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $modelType = $builder->model::class;
        $terms     = $this->terms($builder);
        $offset    = ($page - 1) * $perPage;
        $weights   = $this->columnWeights($builder);

        $ranked = $this->scorer->rank($terms, $modelType, $weights);
        $query  = $this->constrainedQuery($builder);

        if ($query === null || empty($ranked)) {
            // count() runs a single COUNT(DISTINCT model_id) query for the true total (C13)
            $total   = $this->scorer->count($terms, $modelType, $weights);
            $results = $this->hydrate(array_slice($ranked, $offset, $perPage, true));
        } else {
            $ids     = array_keys($ranked);
            $total   = RankedCandidates::count($query, $ids);
            $keys    = RankedCandidates::keys($query, $ids, $offset + $perPage);
            $results = $this->hydrate($this->pick($ranked, array_slice($keys, $offset, $perPage)));
        }

        return [
            'results' => $results,
            'total'   => $total,
        ];
    }

    /**
     * The query's index terms. A query below min_search_length has none, so it matches nothing
     * (a total of 0), as Model::search() does — see SearchBuilder::belowMinSearchLength().
     *
     * @return string[]
     */
    private function terms(Builder $builder): array
    {
        // Scout 11.6+ hybrid() asks for a full-text + semantic ranking this engine cannot give;
        // without this check it would quietly return a plain BM25 page. (semantic() alone is
        // rejected by Scout itself, which checks SupportsSemanticSearch; Scout 10 has neither.)
        if (($builder->hybridSearch ?? null) !== null) {
            throw new NotSupportedException('The fuzzy-search Scout engine does not support hybrid (semantic) search.');
        }

        $query = Utf8::clean($builder->query);

        return SearchBuilder::belowMinSearchLength(trim($query))
            ? []
            : $this->indexManager->processTerms($query, null, $builder->model::class);
    }

    /**
     * The model's BM25F column weights, resolved exactly as Model::search() resolves them, so a
     * Scout search ranks identically to Model::search()->useInvertedIndex(). Empty for a model
     * that does not use the package's Searchable trait — rank() then weighs every column 1.
     *
     * @return array<string, int>
     */
    private function columnWeights(Builder $builder): array
    {
        return method_exists($builder->model, 'getSearchableColumnWeights')
            ? $builder->model->getSearchableColumnWeights()
            : [];
    }

    /**
     * The Eloquent query the ranking must be checked against, or null when the Scout
     * builder carries no constraints (the ranking is then used as-is).
     */
    private function constrainedQuery(Builder $builder): ?EloquentBuilder
    {
        $whereIns    = $builder->whereIns ?? [];
        $whereNotIns = $builder->whereNotIns ?? [];
        $softDeleted = null;
        $wheres      = [];

        // Scout 10 stores wheres as [field => value]; Scout 11+ as a list of
        // ['field', 'operator', 'value']. Soft-delete state travels as a pseudo where on
        // __soft_deleted: 0 = live rows (default when scout.soft_delete is on),
        // 1 = onlyTrashed(); withTrashed() removes it.
        foreach ($builder->wheres as $key => $where) {
            [$field, $operator, $value] = is_array($where) && array_key_exists('field', $where)
                ? [$where['field'], $where['operator'] ?? '=', $where['value'] ?? null]
                : [$key, '=', $where];

            if ($field === '__soft_deleted') {
                $softDeleted = (int) $value;
                continue;
            }

            $wheres[] = [$field, $operator, $value];
        }

        if (empty($wheres) && empty($whereIns) && empty($whereNotIns)
            && $builder->queryCallback === null && $softDeleted === null) {
            return null;
        }

        $model = $builder->model;
        $query = $model->newQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            if ($softDeleted === 1) {
                $query->onlyTrashed();
            } elseif ($softDeleted === null) {
                $query->withTrashed();
            }
            // 0: the SoftDeletes global scope already excludes trashed rows.
        }

        foreach ($wheres as [$field, $operator, $value]) {
            $query->where($field, $operator, $value);
        }
        foreach ($whereIns as $field => $values) {
            $query->whereIn($field, $values);
        }
        foreach ($whereNotIns as $field => $values) {
            $query->whereNotIn($field, $values);
        }

        if ($builder->queryCallback !== null) {
            call_user_func($builder->queryCallback, $query);
        }

        return $query;
    }

    /** @param array<int|string, float> $ranked */
    private function pick(array $ranked, array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            $picked[$key] = $ranked[$key];
        }
        return $picked;
    }

    /** @param array<int|string, float> $scores model_id => score, in rank order */
    private function hydrate(array $scores): Collection
    {
        return collect($scores)
            ->map(fn($score, $modelId) => (object) ['model_id' => $modelId, 'score' => round($score, 6)])
            ->values();
    }

    public function mapIds($results): Collection
    {
        return collect($results['results'])->pluck('model_id');
    }

    public function map(Builder $builder, $results, $model): EloquentCollection
    {
        if ($results['total'] === 0) {
            return $model->newCollection();
        }

        $ids      = $this->mapIds($results)->toArray();
        $scoreMap = collect($results['results'])->pluck('score', 'model_id');

        $models = $model->getScoutModelsByIds($builder, $ids);

        return $models->sortByDesc(fn($m) => $scoreMap[$m->getKey()] ?? 0)
            ->map(function ($m) use ($scoreMap) {
                $m->_score = round((float) ($scoreMap[$m->getKey()] ?? 0), 6);
                return $m;
            })
            ->values();
    }

    public function lazyMap(Builder $builder, $results, $model): \Illuminate\Support\LazyCollection
    {
        return \Illuminate\Support\LazyCollection::make($this->map($builder, $results, $model));
    }

    public function getTotalCount($results): int
    {
        return $results['total'] ?? 0;
    }

    public function flush($model): void
    {
        $this->indexManager->flush($model::class);
    }

    public function createIndex($name, array $options = []): void
    {
        // The inverted index tables are created via migrations — no runtime creation needed.
    }

    /**
     * `scout:delete-index {name}` passes an index NAME — the model's indexableAs() (by default
     * scout.prefix + table) — but the inverted index is keyed by model class. Flush every
     * indexed class whose Scout index carries that name; several models on one table share
     * it, exactly as they would share an Algolia or Meilisearch index.
     */
    public function deleteIndex($name): void
    {
        foreach (DB::table('fuzzy_index_meta')->pluck('model_type') as $class) {
            if (!class_exists($class) || !method_exists($class, 'searchableAs')) {
                continue;
            }

            $model = new $class;
            $index = method_exists($model, 'indexableAs') ? $model->indexableAs() : $model->searchableAs();

            if ($index === $name) {
                $this->indexManager->flush($class);
            }
        }
    }
}
