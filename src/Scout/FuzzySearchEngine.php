<?php

namespace Ashiqfardus\LaravelFuzzySearch\Scout;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
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
        // One write for the collection, re-read with Scout's visibility: no global scopes, and
        // a trashed model kept while scout.soft_delete is on (ER-72).
        $this->indexManager->indexBatch($models, scout: true);
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
        $orders    = $this->orders($builder);
        $terms     = $this->terms($builder);
        $limit     = $builder->limit ?? 15;

        $ranked = $this->scorer->rank($terms, $modelType, $this->columnWeights($builder));
        $query  = $this->constrainedQuery($builder);

        if ($orders !== [] && $ranked !== []) {
            ['total' => $total, 'keys' => $keys] = $this->orderedPage($builder, $query, $orders, $ranked, 0, $limit);
        } else {
            // 'total' is the match count — the ranked ids (that satisfy the constraints) — not
            // the size of the page cut from them.
            $total = $query === null || empty($ranked) ? count($ranked) : RankedCandidates::count($query, array_keys($ranked));
            $keys  = $this->resultKeys($query, $ranked, $limit);
        }

        return [
            'results' => $this->hydrate($this->pick($ranked, $keys), $builder->model),
            'total'   => $total,
        ];
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        // Scout resolves ?page to any whole number of at least 1. Capped as SearchBuilder::resolvePage()
        // caps it, so that no offset (plus one page) overflows into a float: past that, every page is empty.
        $perPage   = max(1, (int) $perPage);
        $page      = min(max(1, (int) $page), intdiv(PHP_INT_MAX, $perPage + 1));
        $modelType = $builder->model::class;
        $orders    = $this->orders($builder);
        $terms     = $this->terms($builder);
        $offset    = ($page - 1) * $perPage;
        $weights   = $this->columnWeights($builder);

        $ranked = $this->scorer->rank($terms, $modelType, $weights);
        $query  = $this->constrainedQuery($builder);

        if ($orders !== [] && $ranked !== []) {
            ['total' => $total, 'keys' => $keys] = $this->orderedPage($builder, $query, $orders, $ranked, $offset, $perPage);
        } else {
            // count() runs a single COUNT(DISTINCT model_id) query for the true total (C13)
            $total = $query === null || empty($ranked)
                ? $this->scorer->count($terms, $modelType, $weights)
                : RankedCandidates::count($query, array_keys($ranked));
            // A page past every ranked id is empty: never walk to it.
            $keys  = $offset < count($ranked) ? array_slice($this->resultKeys($query, $ranked, $offset + $perPage), $offset, $perPage) : [];
        }

        return [
            'results' => $this->hydrate($this->pick($ranked, $keys), $builder->model),
            'total'   => $total,
        ];
    }

    /**
     * The first $needed matches in rank order, the ranking cut after the builder's constraints
     * (otherwise a selective where() returns a short or empty page while matches exist further down
     * the ranking).
     *
     * @param  array<int|string, float> $ranked model_id => score, best first
     * @return array<int|string>
     */
    private function resultKeys(?EloquentBuilder $query, array $ranked, int $needed): array
    {
        if (empty($ranked)) {
            return [];
        }

        return array_slice($query === null ? array_keys($ranked) : RankedCandidates::keys($query, array_keys($ranked), $needed), 0, $needed);
    }

    /**
     * The builder's orderBy() clauses. An explicit order replaces the relevance order, as it
     * does on Scout's database engine; each column is checked like every other column name the
     * package writes into SQL.
     *
     * @return array<int, array{column: string, direction: string}>
     */
    private function orders(Builder $builder): array
    {
        $orders = $builder->orders ?? [];

        SearchableColumns::validate(array_column($orders, 'column'));

        return $orders;
    }

    /**
     * With orderBy(): the total and the keys of the page [$offset, $offset + $limit) of the matches
     * the builder's constraints accept, in the builder's order, then by key descending for ties
     * (Scout's database engine breaks them the same way, and a tie must not move between one page's
     * query and the next). As on SearchBuilder's ordered page, the query reads only matches
     * (RankedCandidates::matches(): on the index's connection every one, past
     * bm25.max_postings_per_term too), so its count is the total, a page past it reads no row, and
     * the page is one offset/limit read however deep (RankedCandidates::orderedKeys()). A match past
     * the cap has no BM25 score: its score is 0.
     *
     * @param  array<int, array{column: string, direction: string}> $orders
     * @param  array<int|string, float>                             $ranked model_id => score
     * @return array{total: int, keys: array<int|string>}
     */
    private function orderedPage(Builder $builder, ?EloquentBuilder $query, array $orders, array $ranked, int $offset, int $limit): array
    {
        $model = $builder->model;
        $query = RankedCandidates::matches($query ?? $model->newQuery(), $ranked, $this->terms($builder), $model::class, $this->columnWeights($builder));
        $total = RankedCandidates::countModels($query);

        if ($offset >= $total || $limit < 1) {
            return ['total' => $total, 'keys' => []];
        }

        $query = $query->toBase();

        foreach ($orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        // SQL Server rejects a column named twice in ORDER BY.
        if (array_intersect(array_column($orders, 'column'), [$model->getKeyName(), $model->getQualifiedKeyName()]) === []) {
            $query->orderBy($model->getQualifiedKeyName(), 'desc');
        }

        return ['total' => $total, 'keys' => RankedCandidates::orderedKeys($query, $model->getQualifiedKeyName(), $offset, $limit)];
    }

    /**
     * The query's index terms. A query below min_search_length has none, so it matches nothing
     * (a total of 0), as Model::search() does — see SearchBuilder::belowMinSearchLength(). A
     * longer one is searched on its first query.max_term_length characters.
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

        $query = trim(Utf8::clean($builder->query));

        if (SearchBuilder::belowMinSearchLength($query)) {
            return [];
        }

        // query.max_term_length characters (never bytes), the cap SearchBuilder::capSearchTerm()
        // applies on the index path. It also bounds the term count: rank() binds one parameter per
        // term, and an uncapped query of a few thousand words passed SQL Server's 2,100 limit.
        $query = FuzzySearch::capTerm($query);

        return $this->indexManager->processTerms($query, null, $builder->model::class);
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
            $picked[$key] = $ranked[$key] ?? 0.0; // an ordered page's match past the ranking's cap
        }
        return $picked;
    }

    /**
     * The results' model_id is the model's own key: a string key stays a string (see
     * RankedCandidates::keysFor()), so map() binds it as one and keys() returns it as stored.
     *
     * @param array<int|string, float> $scores model_id => score, in rank order
     */
    private function hydrate(array $scores, \Illuminate\Database\Eloquent\Model $model): Collection
    {
        $ids = RankedCandidates::keysFor($model, array_keys($scores));

        return collect(array_values($scores))
            ->map(fn ($score, $i) => (object) ['model_id' => $ids[$i], 'score' => round($score, 6)]);
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
        $position = array_flip($ids);

        $models = $model->getScoutModelsByIds($builder, $ids);

        // The order search()/paginate() chose — relevance, or the builder's orderBy() — not the
        // order the database happened to return the rows in.
        return $models->sortBy(fn($m) => $position[$m->getKey()] ?? PHP_INT_MAX)
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
