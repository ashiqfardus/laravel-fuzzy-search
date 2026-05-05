<?php

namespace Ashiqfardus\LaravelFuzzySearch\Scout;

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

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
        $terms     = $this->indexManager->processTerms($builder->query);
        $modelType = $builder->model::class;
        $limit     = $builder->limit ?? 15;

        $results = $this->scorer->search($terms, $modelType, $limit);

        return [
            'results' => $results,
            'total'   => $results->count(),
        ];
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        $terms     = $this->indexManager->processTerms($builder->query);
        $modelType = $builder->model::class;
        $offset    = ($page - 1) * $perPage;

        // count() runs a single COUNT(DISTINCT model_id) query for the true total (C13)
        $total   = $this->scorer->count($terms, $modelType);
        $results = $this->scorer->search($terms, $modelType, $offset + $perPage);

        return [
            'results' => $results->slice($offset, $perPage)->values(),
            'total'   => $total,
        ];
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

    public function deleteIndex($name): void
    {
        $this->indexManager->flush($name);
    }
}
