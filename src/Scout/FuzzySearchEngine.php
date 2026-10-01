<?php

namespace Ashiqfardus\LaravelFuzzySearch\Scout;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\SearchableScope;

// Scout 10.1 added PaginatesEloquentModelsUsingDatabase, the contract that hands the engine
// paginate() and simplePaginate() with their page name (see paginateUsingDatabase()). Scout 10.0
// has none, and paginates every engine itself: an empty stand-in lets the engine load there.
if (interface_exists(PaginatesEloquentModelsUsingDatabase::class)) {
    class_alias(PaginatesEloquentModelsUsingDatabase::class, PaginatesWithItsOwnTotal::class);
} else {
    /** @internal Scout 10.0's stand-in for PaginatesEloquentModelsUsingDatabase, which no Builder checks. */
    interface PaginatesWithItsOwnTotal
    {
    }
}

/**
 * Scout engine adapter — bundled in core, registered conditionally
 * when laravel/scout is installed.
 *
 * Activate with: SCOUT_DRIVER=fuzzy-search in .env
 */
class FuzzySearchEngine extends Engine implements PaginatesWithItsOwnTotal
{
    public function __construct(
        private IndexManager $indexManager,
        private Bm25Scorer   $scorer,
    ) {}

    public function update($models): void
    {
        if ($models->isNotEmpty()) {
            self::requirePackageTrait($models->first());
            $models->each(fn ($model) => self::requirePrimaryScoutKey($model));
        }

        // One write for the collection, re-read with Scout's visibility: no global scopes, and
        // a trashed model kept while scout.soft_delete is on (ER-72). One write per index type when
        // the collection mixes them (Scout's import of a single-table-inheritance parent): each
        // model is indexed under its index type, as delete() removes it and a rebuild writes it
        // (RC-3, SA-1).
        $this->indexManager->indexBatch($models, scout: true);
    }

    public function delete($models): void
    {
        foreach ($models as $model) {
            // A queued delete's model, restored with only its Scout key (forceFill() under
            // getScoutKeyName()): a custom one leaves it without the primary key the index row is
            // under, and the qualified key name (Scout 9's default) holds it in an attribute named
            // "table.id", which getKey() does not read (SA-5).
            $key = $model->getKey();
            if ($key === null) {
                self::requirePrimaryScoutKey($model);
                $key = method_exists($model, 'getScoutKeyName') ? $model->getAttributes()[$model->getScoutKeyName()] ?? null : null;
            }

            if ($key !== null) { // a model without a key was never indexed
                $this->indexManager->removeFromIndex(IndexManager::indexType($model), $key);
            }
        }
    }

    public function search(Builder $builder)
    {
        // A negative take() counts as 0, as on InMemorySearch and FederatedSearch: array_slice()
        // cut it from the end, serving every match but the last few, past the default of 15.
        return $this->results($builder, 0, max(0, (int) ($builder->limit ?? 15)));
    }

    public function paginate(Builder $builder, $perPage, $page)
    {
        // Scout resolves ?page to any whole number of at least 1. Capped as SearchBuilder::resolvePage()
        // caps it, so that no offset (plus one page) overflows into a float: past that, every page is empty.
        $perPage = max(1, (int) $perPage);
        $page    = min(max(1, (int) $page), intdiv(PHP_INT_MAX, $perPage + 1));

        // Scout 10.0 (no PaginatesEloquentModelsUsingDatabase) counts paginate()'s and paginateRaw()'s
        // total itself, after this returns, through this Builder's query() callback and then the
        // matches' keys (Builder::getTotalCount()): an ungrouped or's first branch and a union's other
        // parts counted rows outside the matches (SD-1). The callback is replaced, once, by the
        // engine's reading of it, which the engine's own reads also take. Scout 10.1 and later take
        // the engine's total, and the callback stays as given. A one-to-many join is still counted
        // once per joined row there (documented).
        if (!interface_exists(PaginatesEloquentModelsUsingDatabase::class) && $builder->queryCallback !== null
            && !$builder->queryCallback instanceof GroupedQueryCallback) {
            $builder->queryCallback = new GroupedQueryCallback($builder->queryCallback);
        }

        return $this->results($builder, ($page - 1) * $perPage, $perPage);
    }

    /**
     * Scout's paginate(), built as Scout's Builder builds it (the same paginator binding, page,
     * path and page name; the Builder then appends the query), with the total results() counted.
     * Scout's own total re-counts a query() callback's matches: it re-reads the key of every
     * match and binds them all in one whereIn() (Builder::getTotalCount()), past SQL Server's
     * 2,100 bindings on a string key. The engine has already applied the callback
     * (constrainedQuery()), so its total counts only what the callback accepts.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateUsingDatabase(Builder $builder, $perPage, $pageName, $page)
    {
        $page    = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $builder->model->getPerPage();
        $results = $this->paginate($builder, $perPage, $page);

        return Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items'       => $this->pageModels($builder, $results),
            'total'       => $results['total'],
            'perPage'     => $perPage,
            'currentPage' => $page,
            'options'     => ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        ]);
    }

    /**
     * Scout's simplePaginate(), built as Scout's Builder builds it; see paginateUsingDatabase().
     *
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginateUsingDatabase(Builder $builder, $perPage, $pageName, $page)
    {
        $page    = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $builder->model->getPerPage();
        $results = $this->paginate($builder, $perPage, $page);

        return Container::getInstance()->makeWith(Paginator::class, [
            'items'       => $this->pageModels($builder, $results),
            'perPage'     => $perPage,
            'currentPage' => $page,
            'options'     => ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        ])->hasMorePagesWhen(($perPage * $page) < $results['total']);
    }

    /** A page's models, after Scout's withRawResults() callback (Scout 10.13+) has seen the raw page. */
    private function pageModels(Builder $builder, array $results): EloquentCollection
    {
        if (method_exists($builder, 'applyAfterRawSearchCallback')) {
            $results = $builder->applyAfterRawSearchCallback($results);
        }

        return $builder->model->newCollection($this->map($builder, $results, $builder->model)->all());
    }

    /**
     * The page [$offset, $offset + $limit) of the builder's matches, scored, and their total: the
     * match count, not the size of the page cut from them, and what the pages serve, as on
     * SearchBuilder's index path. In rank order it is the ranked matches the builder's constraints
     * and the model's global scopes accept (RankedCandidates::accepted()), cut after the constraints
     * (otherwise a selective where() returns a short or empty page while matches exist further down
     * the ranking), and none past bm25.max_postings_per_term, where the ranking ends. With orderBy()
     * it is every match they accept, in that order (orderedPage()). A page past the total reads no row.
     *
     * @return array{results: Collection, total: int}
     */
    private function results(Builder $builder, int $offset, int $limit): array
    {
        self::requirePackageTrait($builder->model);
        self::requirePrimaryScoutKey($builder->model);

        $orders = $this->orders($builder);
        $terms  = $this->terms($builder);
        $ranked = $this->scorer->rank($terms, IndexManager::indexType($builder->model), $this->columnWeights($builder));
        $query  = $this->constrainedQuery($builder);

        if ($ranked !== [] && $orders !== []) {
            ['total' => $total, 'keys' => $keys] = $this->orderedPage($builder, $query, $orders, $ranked, $terms, $offset, $limit);
        } else {
            $accepted = $query === null || $ranked === [] ? $ranked : RankedCandidates::accepted($query, $ranked, $terms, IndexManager::indexType($builder->model), $this->columnWeights($builder));
            $total    = count($accepted);
            $keys     = array_slice(array_keys($accepted), $offset, $limit);
        }

        return [
            'results' => $this->hydrate($this->pick($ranked, $keys), $builder->model),
            'total'   => $total,
        ];
    }

    /**
     * The engine indexes what the package's Searchable trait declares: getSearchableColumns() (or a
     * searchableText() hook), which IndexManager::indexesModel() reads. A model with Scout's trait
     * alone has neither, and was indexed as nothing and searched as nothing, silently, where v2.0.1
     * threw on the missing method (ruling D3). Indexing and searching it throw again, naming the
     * fix; delete() and flush() only remove rows, as in v2.0.1, so deleting a record still works.
     *
     * @throws \LogicException
     */
    private static function requirePackageTrait(\Illuminate\Database\Eloquent\Model $model): void
    {
        if (!method_exists($model, 'getSearchableColumns') && !method_exists($model, 'searchableText')) {
            throw new \LogicException(sprintf(
                '%s is on the fuzzy-search Scout driver without the package\'s trait, so it has nothing to index or search. Add `use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;` to the model, beside Scout\'s trait (see the Scout section of the README).',
                $model::class
            ));
        }
    }

    /**
     * The index is keyed by the model's primary key, where Scout looks a page's models up, and
     * restores a queued delete's model, by getScoutKeyName(). A model with a custom Scout key was
     * indexed, a search then found none of its models, and its queued delete threw a TypeError
     * (round 10, L10): indexing or searching one throws, naming the fix. A model without its
     * primary key (the builder's, a restored one) is judged by the key's name alone.
     *
     * @throws \LogicException
     */
    private static function requirePrimaryScoutKey(\Illuminate\Database\Eloquent\Model $model): void
    {
        // Without Scout's trait (a Builder made by hand) a model has no Scout key of its own.
        if (!method_exists($model, 'getScoutKeyName')) {
            return;
        }

        $named = in_array($model->getScoutKeyName(), [$model->getKeyName(), $model->getQualifiedKeyName()], true);

        if (!$named || ($model->getKey() !== null && $model->getScoutKey() != $model->getKey())) {
            throw new \LogicException(sprintf(
                '%s has a custom Scout key, which the fuzzy-search Scout driver does not support: it keys the index by the primary key (%s). Remove getScoutKey() and getScoutKeyName() from the model (see the Scout section of docs/integrations.md).',
                $model::class,
                $model->getKeyName()
            ));
        }
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
     * @param  string[]                                             $terms  the terms $ranked was scored for
     * @return array{total: int, keys: array<int|string>}
     */
    private function orderedPage(Builder $builder, ?EloquentBuilder $query, array $orders, array $ranked, array $terms, int $offset, int $limit): array
    {
        $model = $builder->model;

        // No constraint means no global scope that hides a row, and no __soft_deleted where either:
        // withTrashed(), or scout.soft_delete off, when no trashed row is indexed. constrainedQuery()
        // reads that state as withTrashed() too; newQuery()'s SoftDeletes scope dropped the trashed
        // matches that total() counted.
        $query ??= in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model->newQuery()->withTrashed() : $model->newQuery();
        $query   = RankedCandidates::matches($query, $ranked, $terms, IndexManager::indexType($model), $this->columnWeights($builder));
        $total   = RankedCandidates::countModels($query);

        if ($offset >= $total || $limit < 1) {
            return ['total' => $total, 'keys' => []];
        }

        $key   = RankedCandidates::keyColumn($query);
        $query = $query->toBase();

        foreach ($orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }

        // SQL Server rejects a column named twice in ORDER BY.
        if (array_intersect(array_column($orders, 'column'), [$model->getKeyName(), $model->getQualifiedKeyName(), $key]) === []) {
            $query->orderBy($key, 'desc');
        }

        return ['total' => $total, 'keys' => RankedCandidates::orderedKeys($query, $key, $offset, $limit)];
    }

    /**
     * The query's index terms. A query below min_search_length (a null or empty one too) has none,
     * so it matches nothing (a total of 0), as Model::search() does — see
     * SearchBuilder::belowMinSearchLength(). A longer one is searched on its first
     * query.max_term_length characters.
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

        // Scout's search() takes null, and Laravel's ConvertEmptyStringsToNull middleware turns an
        // empty ?q= into one: an empty query, which matches nothing (ruling D17).
        $query = trim(Utf8::clean((string) $builder->query));

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
     * that declares its columns without the package's trait — rank() then weighs every column 1.
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
     * The Eloquent query the ranking must be checked against, or null when neither the Scout
     * builder nor a global scope of the model constrains it (the ranking is then used as-is).
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

        // A global scope counts as a constraint (ruling D12). Scout indexes the rows it hides (its
        // jobs read without global scopes) and loads the page's models through it, so unchecked,
        // a hidden row counted in the relevance-order total while orderBy() skipped it. Two scopes
        // are not constraints: SoftDeletes' (the __soft_deleted where decides trashed rows, as
        // below) and Scout's own SearchableScope, which only adds builder macros.
        $scopes = array_diff_key($builder->model->getGlobalScopes(), [SoftDeletingScope::class => true, SearchableScope::class => true]);

        if (empty($wheres) && empty($whereIns) && empty($whereNotIns)
            && $builder->queryCallback === null && $softDeleted === null && $scopes === []) {
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

        // The callback's wheres as one group when one is an or, so the builder's where()s above and
        // the ranking hold for all of them, as a scope's are grouped (RE-1).
        if ($builder->queryCallback !== null) {
            $wheres = count($query->getQuery()->wheres);
            call_user_func($builder->queryCallback, $query);
            RankedCandidates::groupOrWheres($query, $wheres);
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

        $scores = collect($results['results'])->pluck('score', 'model_id');
        $models = $this->modelsById($builder, $model, $this->mapIds($results)->all());
        $page   = [];

        // The order search()/paginate() chose — relevance, or the builder's orderBy() — not the
        // order the database happened to return the rows in.
        foreach ($scores as $id => $score) {
            if (isset($models[$id])) {
                $models[$id]->_score = round((float) $score, 6);
                $page[]              = $models[$id];
            }
        }

        return $model->newCollection($page);
    }

    /**
     * The models the model's getScoutModelsByIds() reads for $ids (an app's override of it, or of
     * queryScoutModelsByIds(), as every Scout engine's map() calls it), keyed by their ids. Where a
     * select() may leave the key out or a join may shadow it (RankedCandidates::needsKeyAlias()),
     * the key is read under RankedCandidates::KEY_ALIAS beside the select list, through the query()
     * callback that queryScoutModelsByIds() applies, in a scope applied after the model's own (which
     * may select()), so a row is matched to its id (ruling D13); RankedCandidates::takeKey() takes the
     * alias out of the model's attributes and original after it is hydrated, so a retrieved listener
     * sees it there. Every other model is matched by its own key, and never has the alias (ruling
     * ER-135). A union is read as one derived table, and an override that reads without the callback
     * selects no alias: its models are matched by their own key.
     *
     * @param  array<int|string> $ids
     * @return array<int|string, \Illuminate\Database\Eloquent\Model>
     */
    private function modelsById(Builder $builder, \Illuminate\Database\Eloquent\Model $model, array $ids): array
    {
        $callback               = $builder->queryCallback;
        $builder                = clone $builder;
        $builder->queryCallback = function ($query) use ($callback) {
            if ($callback !== null) {
                call_user_func($callback, $query);
                RankedCandidates::groupOrWheres($query); // the page's ids, which Scout adds next, hold for every branch (RE-1)
            }

            // A union is read as one derived table (RankedCandidates::rows()), so the ids Scout then
            // restricts the query to restrict every part of it, not only its first. Its scopes apply
            // inside that table already.
            if (($rows = RankedCandidates::rows($query)) !== $query) {
                $query->setQuery($rows->getQuery())->withoutGlobalScopes();
            }

            $query->withGlobalScope(RankedCandidates::KEY_ALIAS, fn (EloquentBuilder $query) => RankedCandidates::needsKeyAlias($query->getQuery(), $query->getModel())
                ? RankedCandidates::selectKey($query->getQuery(), RankedCandidates::keyColumn($query))
                : null);
        };

        $models = [];

        foreach ($model->getScoutModelsByIds($builder, $ids) as $found) {
            $id = RankedCandidates::takeKey($found);

            if ($id !== null) {
                $models[$id] = $found;
            }
        }

        return $models;
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
        $this->indexManager->flush(IndexManager::indexType($model));
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
