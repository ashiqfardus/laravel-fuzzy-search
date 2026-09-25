# Ecosystem Integrations

[← Back to the README](../README.md)

---

## Scout Driver

The Scout engine adapter is bundled in this package and registers automatically when `laravel/scout` is installed. No separate driver package is required.

It is a keyword (BM25) engine. Scout's semantic and hybrid search (`->semantic()`, `->hybrid()`, added in Scout 11.6) are not supported: `semantic()` is rejected by Scout itself, and `hybrid()` throws `Laravel\Scout\Exceptions\NotSupportedException` rather than quietly returning a plain keyword ranking.

### Setup

The engine supports Laravel Scout 10.x and 11.x.

```bash
composer require laravel/scout
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
```

In `.env`:

```
SCOUT_DRIVER=fuzzy-search
```

Build the index:

```bash
php artisan fuzzy-search:rebuild "App\Models\User"
```

**SQL Server:** turn on `READ_COMMITTED_SNAPSHOT` for the database (`ALTER DATABASE … SET READ_COMMITTED_SNAPSHOT ON`), or set `scout.after_commit` to `true`, which defers Scout's own save and delete hooks until the commit. The indexer re-reads a row after it claims the row's index entry. Under SQL Server's default locking read committed, that read waits on a row another transaction has updated but not committed. That transaction may be one that indexes before it commits: Scout with `after_commit` off and no queue, or `searchable()` inside `DB::transaction()`. Its index write in turn waits on the claim, so the two deadlock (error 1205). Snapshot reads do not wait. A `searchable()` you call yourself inside `DB::transaction()` still indexes at once, whatever `after_commit` says: call it after the commit, or turn on `READ_COMMITTED_SNAPSHOT`. If you run with `XACT_ABORT ON`, an index write that meets a new word (or a model's first document or meta row) that another write inserted at the same moment fails and is retried by the queue (or reported, when sync) instead of updating that row in place.

### Usage

Add both traits to your model. Both traits declare `bootSearchable()`, so the conflict
resolution below aliases the package's copy and runs Scout's from `booted()`:

```php
use Laravel\Scout\Searchable;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;

class User extends Model
{
    use Searchable, FuzzySearchable {
        // FuzzySearchable::search() wins — it returns the fluent SearchBuilder.
        // Scout's search() stays reachable as scoutSearch().
        FuzzySearchable::search insteadof Searchable;
        Searchable::search as scoutSearch;

        // Both traits boot through bootSearchable() and Laravel calls that name only
        // once, so keep the package's and run Scout's from booted().
        FuzzySearchable::bootSearchable insteadof Searchable;
        Searchable::bootSearchable as bootScoutSearchable;
    }

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}

$users = User::search('john')->get();       // fluent package builder
$users = User::scoutSearch('john')->get();  // Scout's builder, when you need it
```

The model needs no `$searchable` property; add one to choose the columns (and their weights) instead of relying on auto-detection.

### Relevance Scores

Results include `_score` (BM25 relevance, higher = more relevant):

```php
foreach (User::search('laravel')->get() as $user) {
    echo $user->name . ': ' . $user->_score;
}
```

### Authorization

Scout's default behavior bypasses Eloquent global scopes. Apply them explicitly:

```php
User::search('john')
    ->query(fn($q) => $q->withoutTrashed()->where('tenant_id', auth()->user()->tenant_id))
    ->get();
```

With `scout.soft_delete` enabled, trashed models stay in the index as Scout expects; the engine filters them at query time through Scout's `__soft_deleted` constraint.

### How It Works

The Scout engine wraps the same `IndexManager` + `Bm25Scorer` used by `Model::search()->useInvertedIndex()`. There is no separate index — it reads from the same `fuzzy_index_*` tables, with the model's `$searchable['columns']` weights, so a Scout search and `useInvertedIndex()` rank a term the same way. Typo expansion is the one difference: the engine matches exact terms only (see [docs/bm25.md](bm25.md#typo-tolerance-as-you-type-synonyms-and-stop-words-on-the-index)).

`raw()['total']` is the number of matches (after the builder's `where()`/`whereIn()`/`query()` constraints), not the size of the page returned. `scout:flush "App\Models\User"` clears that model's rows from the shared tables; `scout:delete-index {name}` clears every indexed model whose `indexableAs()` (by default `scout.prefix` + table) equals the name Scout resolves: a model class becomes its `indexableAs()`, and a bare name gets `scout.prefix` prepended unless it already starts with it. With `SCOUT_PREFIX=app_`, `scout:delete-index users` and `scout:delete-index "App\Models\User"` both target `app_users`. `scout:index` is a no-op — the tables come from the package migrations.

**Ordering.** `orderBy()`, `orderByDesc()`, `latest()` and `oldest()` replace the relevance order, as they do on Scout's database engine. The matches that satisfy the builder's `where()`/`whereIn()`/`whereNotIn()`/`query()` constraints come back in that order on `get()`, `first()`, `paginate()` and `simplePaginate()`, ties broken by the primary key, descending; `_score` still carries each match's BM25 score. An order column must be a column name — letters, digits and underscores, optionally table-qualified (`users.name`); anything else throws `InvalidArgumentException`. Up to `bm25.candidate_chunk` matches (default 200), the database orders exactly those rows in one query. Beyond that, it reads the constrained table's keys in that order, 1,000 per query, until the page is full: each of those queries sorts the constrained rows, so an ordered search that matches more than one chunk of a large table costs one sorted query per 1,000 rows it passes. Give such a search a selective `where()`, or an index on the order column.

**Query length.** The engine searches the first `query.max_term_length` characters of the query (default 128), as `Model::search()->useInvertedIndex()` does; longer input is cut, never rejected. A page past the last match is empty, however large its number.

**Indexing.** `$model->searchable()` and Scout's import go through the engine's `update()`, which writes a collection of models in one transaction and indexes each row as it is stored, read the way Scout's own jobs read it: without global scopes, and keeping a trashed row while `scout.soft_delete` is on. Unsaved changes on an instance are not indexed. An error on one model (a `searchableText()` value that cannot be indexed, or a write that loses all three deadlock attempts) leaves none of that collection indexed.

**Caching.** Neither Scout's builder nor this engine caches results. Wrap the Scout call in Laravel's cache, with a key that covers everything that changes the result — the term, the page, the constraints, the tenant:

```php
$users = Cache::remember(
    'users.search.' . md5(json_encode([$term, $page, auth()->user()->tenant_id])),
    now()->addMinutes(10),
    fn () => User::scoutSearch($term)->paginate(15, 'page', $page)
);
```

The package's own builder can run the same BM25 ranking with its `cache()`, which caches `get()` — and `first()` and `simplePaginate()`, which run through it — but not `paginate()`:

```php
User::search($term)->useInvertedIndex()->typoTolerance(0)->cache(10)->get();          // cached for 10 minutes
User::search($term)->useInvertedIndex()->typoTolerance(0)->cache(10)->simplePaginate(15); // cached
User::search($term)->useInvertedIndex()->typoTolerance(0)->cache(10)->paginate(15);   // not cached
```

`typoTolerance(0)` keeps the engine's exact-term matching; without it the builder also expands typos.

---

## Filament Integration

`HasFuzzyGlobalSearch` replaces a Filament Resource's LIKE-based global search with the package's fuzzy search — typo tolerance, relevance ordering and highlighted details — while everything else about the Resource (its Eloquent query, title, URL, actions, results limit) stays exactly as you defined it. `FuzzySearch::tableSearch()` does the same for individual table columns and table-wide search. Filament is not a dependency of this package; both only work once the class already extends Filament's `Resource` / applies to a Filament `Table`.

### Setup

```bash
composer require filament/filament
```

Nothing else to install or publish — the trait and `tableSearch()` ship with this package.

### Usage

```php
use Ashiqfardus\LaravelFuzzySearch\Integrations\Filament\HasFuzzyGlobalSearch;
use Filament\Resources\Resource;

class UserResource extends Resource
{
    use HasFuzzyGlobalSearch;

    protected static ?string $model = User::class;

    // All three are optional. Omit $fuzzyTypoTolerance and the builder's own default (2)
    // applies; $fuzzySearchAlgorithm and $fuzzyHighlightTag default to null and 'mark'
    // inside the trait (null = the model's $searchable['algorithm'], then the config default).
    protected static ?int $fuzzyTypoTolerance = 2;
    protected static ?string $fuzzySearchAlgorithm = null; // e.g. 'levenshtein'
    protected static string $fuzzyHighlightTag = 'mark';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }
}
```

For each searchable attribute that matched, a highlighted entry is added to `details`, keyed by `Str::headline()` of the attribute (`email` → `Email`). Only attributes listed in the record's `_matches` are promoted — a column that did not match is never sniffed for the highlight tag, so a record's own literal `<mark>…</mark>` text is never rendered as HTML just because a *different* column matched (ruling P8-R10 — see [Highlighted Results](../README.md#highlighted-results)).

> A promoted highlighted attribute replaces a plain `details` entry with the same label. If `getGlobalSearchResultDetails()` already returns `'Email' => $record->email` and `email` is both searchable and a match, the highlighted version overwrites it — pick a different label, or a different searchable attribute, to keep both.

### How It Works

Filament's own global search applies a `LIKE %term%` constraint per attribute in `getGloballySearchableAttributes()`. The trait replaces just that matching step with the package's `SearchBuilder`, so a typo ("jonh") still finds "John Doe" and results come back ordered by relevance instead of insertion order. The model's own `$searchable` configuration (algorithm, typo tolerance, as-you-type, stop words, synonyms, accents, options) is applied to that search, and the resource's static knobs above override it; the resource's attributes replace `$searchable['columns']` (nested attribute groups are flattened). The trait gets that builder from `Model::searchOn($query, $term, $columns)` — `Searchable`'s public way to start a configured search from an existing Eloquent query, with `$columns` replacing the configured column list rather than adding to it. `getGlobalSearchEloquentQuery()`, `modifyGlobalSearchQuery()` (tenant scopes), `getGlobalSearchResultTitle()`, `getGlobalSearchResultUrl()`, `getGlobalSearchResultActions()` and `getGlobalSearchResultsLimit()` are all still called exactly as Filament defines them.

### Tables

```php
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Filament\Tables\Columns\TextColumn;

// Per column — the v3-compatible way, and still the way to do it on v4/v5:
TextColumn::make('name')->searchable(query: FuzzySearch::tableSearch(['name']));

// Or fuzzy-search the whole table at once. Table::searchUsing() is Filament v4+; on v3 use
// the per-column form above on every column you want searched fuzzily:
$table->searchUsing(FuzzySearch::tableSearch(['name', 'email']));

// With no columns, it falls back to the model's Searchable::getSearchableColumns()
// (a model without the trait, or with no searchable column, matches nothing for a typed
// search; a blank search adds nothing):
$table->searchUsing(FuzzySearch::tableSearch());
```

`tableSearch()` returns the `(Builder $query, string $search): Builder` closure Filament's `Column::searchable(query: ...)` (v3, v4, v5) and `Table::searchUsing()` (**Filament v4+ only** — the method does not exist in v3) expect. The columns are SQL columns of the table being queried: each one is qualified with the table name so the predicate survives a join, which means `author.name` becomes `author`.`name` and not a `whereHas` — keep Filament's built-in `searchable()` for relation columns. Called with no columns it searches the model's `$searchable` columns, declared or auto-detected; when there are none, a typed search matches nothing. The typed term is trimmed and capped at `query.max_term_length` before it reaches a driver. Like the `whereFuzzy` macros, it is a constraint helper that `min_search_length` does not govern: a one-character term filters the table.

### Versions

| Filament | PHP | Laravel |
|---|---|---|
| v3.3 | ^8.1 | ^10.45\|^11\|^12\|^13 |
| v4 | ^8.2 | ^11.28\|^12\|^13 |
| v5 | ^8.2 | ^11.28\|^12\|^13 |

---

## JSON API Resources

`FuzzySearchResource` and `FuzzySearchCollection` turn a search into a normal Laravel API response.

### `FuzzySearchResource`

Wraps one result row (Eloquent model or array) and adds the package's underscore-prefixed fields alongside the plain attributes:

```php
use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchResource;

Route::get('/search', function (Request $request) {
    $user = User::search($request->query('q', ''))->highlight('mark')->first();
    abort_unless($user, 404); // ->first() can return null; the resource would render {} for it

    return new FuzzySearchResource($user);
});
```

### `FuzzySearchCollection`

Build it from a builder instead of a collection — pass a `$perPage` to paginate:

```php
use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchCollection;

Route::get('/search', function (Request $request) {
    return FuzzySearchCollection::fromBuilder(
        User::search($request->query('q', ''))->highlight('mark'),
        perPage: 20,
    );
});
```

A non-paginated response looks like:

```json
{
    "data": [
        {
            "name": "John Doe",
            "email": "john@example.com",
            "_score": 1,
            "_raw_score": 92.5,
            "_highlighted": {
                "name": "<mark>John</mark> Doe",
                "email": "john@example.com"
            },
            "_matches": [
                {"column": "name", "value": "John Doe", "indices": [[0, 3]]}
            ],
            "_model_type": "User"
        }
    ],
    "meta": {
        "query": "john",
        "algorithm": "fuzzy",
        "latency_ms": 3.21,
        "suggestions": []
    }
}
```

`_score` is scaled against the best row the query can see; `_raw_score` counts every row in the model's index, other tenants' included (see [docs/bm25.md](bm25.md#how-it-works)).

Pass `perPage` and the response also carries Laravel's usual pagination `meta` (`current_page`, `per_page`, `total`, …) and `links`, with the fields above merged into that same `meta` object. `suggestions` — `didYouMean()` terms — is only populated when the page is empty; otherwise it stays `[]`. They are the searched model's own dictionary terms. Under a `where()`, a `join()` or a global scope, only terms posted for a row that query can see are kept. `SoftDeletes` is not checked: the index drops a trashed row's terms, which with `indexing.async` (the default) happens once the queued index job has run. `filter()` does not narrow them. See "What scopes a suggestion" in the README.

### `lastExecution()`

`SearchBuilder::lastExecution(): ?FuzzySearchExecuted` returns the event built by the builder's most recent `get()`/`paginate()` call — `null` before either has run, and `count()` never sets it (with `fallback()`, the last attempt's event wins). It also stays `null` — or stale from an earlier run on the same builder — when a run never executed: a term shorter than `min_search_length` matches nothing without building an event, and a cache hit (`cache()`, or any search while `cache.enabled` is on) serves `get()` from the cache. `FuzzySearchCollection` reads it to fill `meta.algorithm` and `meta.latency_ms` (both `null` for such a run); call it directly for anything else you want to report:

```php
$builder = User::search('john');
$builder->get();

$builder->lastExecution()->algorithm;  // 'fuzzy'
$builder->lastExecution()->latencyMs;  // e.g. 3.21
```

---

## Livewire Recipe

A search-as-you-type box as a Livewire v3 component. This is documentation only — no such component ships with the package or the demo app.

```php
<?php

namespace App\Livewire;

use App\Models\Product;
use Livewire\Component;

class ProductSearch extends Component
{
    public string $query = '';
    public array $results = [];
    public array $suggestions = [];

    public function updatedQuery(): void
    {
        if ($this->query === '') {
            $this->results     = [];
            $this->suggestions = [];
            return;
        }

        $builder = Product::search($this->query)
            ->asYouType()
            ->highlight('mark')
            ->limit(10);

        $this->results     = $builder->get()->toArray();
        $this->suggestions = $this->results === [] ? $builder->suggest(5) : [];
    }

    public function render()
    {
        return view('livewire.product-search');
    }
}
```

```blade
<div>
    <input type="text" wire:model.live.debounce.300ms="query" placeholder="Search products…">

    @if ($suggestions !== [])
        <p>Did you mean: {{ implode(', ', $suggestions) }}?</p>
    @endif

    <ul>
        @foreach ($results as $result)
            <li wire:key="product-{{ $result['id'] }}">
                {!! $result['_highlighted']['name'] ?? e($result['name']) !!}
            </li>
        @endforeach
    </ul>
</div>
```

`updatedQuery()` is a Livewire lifecycle hook: it fires automatically whenever `$query` changes, so no separate search action or button is needed. `wire:model.live.debounce.300ms` debounces on the client, before a request is even sent — `SearchBuilder::debounce()` is deprecated since v2.1.0 for the same reason: by the time the builder runs, the request has already arrived, so a server-side debounce cannot do anything, and the call is now a no-op (removed in v3.0.0). `->asYouType()` only affects the inverted index (`useInvertedIndex()`) — it widens the last typed token to dictionary terms that start with it; it has no effect on the LIKE path used above. `->suggest(5)` returns up to 5 plain completion strings; this recipe only shows them once the page comes back empty. Calling it on the builder that just ran `get()` is safe: `suggest()` and `didYouMean()` run beside the search that just returned nothing, not inside it, so the search's own conditions (including `filter()`) do not narrow them. Constraints you applied yourself (`where()`, a `join()`, a scope, a global scope) do. The table scan runs on the base query. On an indexed model, `suggest()`'s default `'auto'` mode switches from the dictionary to that table scan whenever such a constraint is present, and `suggestFrom('index')` ignores them. `didYouMean()` offers only the model's own terms and, under such a constraint, only terms posted for a row the query can see. The `SoftDeletes` scope is the exception on both: the index drops a deleted row's terms, once the queued index job has run when `indexing.async` is on (the default). On a model without a BM25 index, `suggest()` proposes values that *start with* what was typed, so a page emptied by a typo has nothing to complete. Index the model (`useInvertedIndex()` plus `fuzzy-search:rebuild`) to get `didYouMean()` alternatives, because `didYouMean()` needs the model's BM25 dictionary.

---

