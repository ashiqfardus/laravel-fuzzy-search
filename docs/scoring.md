# Scoring, suggestions and federation

Field weights, relevance scoring and its hooks, suggestions, "did you mean", multi-model federation and search analytics.

## Field Weighting & Scoring

### Weighted Columns

```php
User::search('john')
    ->searchIn([
        'name' => 10,       // Highest priority
        'username' => 8,
        'email' => 5,
        'bio' => 1,         // Lowest priority
    ])
    ->get();
```

A weight of `0` takes a column out of the search on every path (LIKE, `extended()` and `useInvertedIndex()`): a row that holds the term only there is not matched or counted, `suggest()`'s table scan offers no word from it, and an `extended()` field scope on it (`email:john`) is an unknown field. Weights are integers, so a fraction below 1 is `0`. A search whose every column weighs `0` matches nothing. Dictionary completions are model-wide and still offer its words; hide the column (`$hidden`) to keep them out.

### Relevance Scoring

```php
$users = User::search('john')
    ->withRelevance()
    ->get();

foreach ($users as $user) {
    echo "{$user->name}: {$user->_score}";
}
```

Search results are read models. The values a search adds are real attributes on each row: `_score` and `_raw_score`; `_highlighted` and `_matches` with `highlight()` (or `highlighting.enabled`); `_debug` with `debugScore()`; `_model_type` and `_model_class` on a `FederatedSearch` result; and `_score` on every Scout result (`get()`, `paginate()`, `cursor()`). So `save()` on a result tries to write them as columns and fails with an unknown-column error. To change a row you found, re-fetch it by key (`User::find($user->getKey())`), or `unset()` those attributes before saving. `withRelevance(false)` adds no `_score` or `_raw_score`, except on the index path (`useInvertedIndex()`), which adds both whatever `withRelevance()` says.

### Prefix Boosting

```php
// Boost results that start with the search term
User::search('john')
    ->prefixBoost(2.5)  // 2.5x score for prefix matches
    ->get();
```

### Partial Match Support

Substring matching is always on: every pattern-based algorithm searches for `%term%`, so a
partial term matches without any extra call.

```php
User::search('joh')->get();   // matches "john", "johnny", "johanna"
```

`partialMatch()` is kept as a no-op for API compatibility — there is nothing to switch on.
`minMatchLength()` is deprecated since v3.0.0 and does nothing; set the minimum term length with
the `min_search_length` config key; `typo_tolerance.min_word_length` is the shortest word the
`fuzzy` algorithm and the BM25 typo expansion give typo tolerance to (shorter words still match,
exactly).
A plain term shorter than `min_search_length` characters (default 2) matches nothing on every
search API — `get()`, `first()`, `paginate()` (total 0), `simplePaginate()`, `count()`,
`getFacets()`, `FederatedSearch`, `FuzzySearch::on()` and the Scout engine — and fires no event.
`extended()`/`searchBoolean()` queries are not measured, and neither are the query-builder
helpers (the `whereFuzzy`-style macros, the `Fuzzy` scopes and `tableSearch()`). `suggest()` and
`didYouMean()` keep their own two-character floor.

### Custom Scoring Hooks

```php
User::search('john')
    ->customScore(function ($item, $baseScore) {
        // Boost verified users
        if ($item->is_verified) {
            return $baseScore * 1.5;
        }
        // Penalize inactive users
        if (!$item->is_active) {
            return $baseScore * 0.5;
        }
        return $baseScore;
    })
    ->get();
```

The callback runs once per row on every path, LIKE, extended and `useInvertedIndex()`, before normalisation, and results are ranked by what it returns. It receives the row's column score on the LIKE and extended paths, and its BM25 raw score on the index path.

A model that uses `Searchable` can also override `getSearchScore(float $baseScore): float` (see the model example under [Per-Model Customization](configuration.md#per-model-customization)). It runs once per row on every path that scores in PHP, with the same score, before `customScore()` and `boostRecent()` and before normalisation, and results are ranked by what it returns. On the index path these three hooks re-rank the first `max_candidates` matches, just as the LIKE path rescores its first `max_candidates` rows, so a boosted row among them can reach page 1. The Scout engine runs `getSearchScore()` the same way (the other two belong to the builder), so a Scout search ranks the model as `useInvertedIndex()` does. Pages past that window keep the BM25 order, and every match is on exactly one page. Each page then also reads the window's rows, at most `max_candidates` of them. The trait's own method returns the score unchanged.

### Recency Boost

Boost newer records in search results. The boost decays linearly across the window: a row
created this instant gets the full multiplier, one at the end of the window gets none, and the
multiplier in between is

```
1 + (multiplier - 1) x (1 - age_in_days / days)
```

so with `boostRecent(1.5, 'created_at', 30)` a row created today scores x1.5, a 15-day-old row
x1.25 and a 29-day-old row x1.0167. Rows older than the window are untouched (x1.0). A date in
the future (a scheduled `published_at`, or clock skew) counts as age 0: the full multiplier.

```php
// Newest rows get 1.5x, decaying to 1.0x at 30 days old
User::search('john')
    ->boostRecent(1.5, 'created_at', 30)
    ->get();

// With defaults: 1.5x at age 0, created_at column, 30-day window
User::search('john')
    ->boostRecent()
    ->get();
```

The boost multiplies the score after `getSearchScore()` and `customScore()`, on every path; on `useInvertedIndex()` it multiplies the BM25 raw score and re-ranks the first `max_candidates` matches (see [Custom Scoring Hooks](#custom-scoring-hooks)). The column is read as an attribute, cast or accessor, or a relation you eager-loaded (`'author.published_at'`), never by calling a model method of that name, so it is safe to take from a request. A value that is no date (a string that does not parse, an array or JSON cast, an object without `__toString()`) gives no boost.

### Search Suggestions / Autocomplete

Get autocomplete suggestions based on search term:

```php
$suggestions = User::search('joh')
    ->searchIn(['name', 'email'])
    ->suggest(5);

// Returns: ['John', 'Johnny', 'Johanna', ...]
```

**Indexed models complete from the dictionary.** When the model has been indexed (a `fuzzy_index_meta` row exists for it — see [BM25 Inverted Index](../README.md#bm25-inverted-index)), `suggest()` completes the *last whitespace-separated word* of the term against that model's own words, ordered by how many of this model's rows hold each word (`fuzzy_index_model_terms`), equal counts newest word first; any earlier words are kept as typed and prefixed back. Completions are the dictionary's lower-case terms, not the value as stored in the column:

```php
// Product is indexed; only "mo" is completed, "wireless " is kept as typed
Product::search('wireless mo')->suggest(5);

// Returns: ['wireless monitor', 'wireless mouse', 'wireless modem', ...]
```

`searchIn()` does **not** narrow dictionary completions: they are scoped to the model, not to its columns, so a name box on an indexed model can be offered a fragment that only occurs in an email column. Use the table scan when the column matters, on a builder whose only column is that one: `User::searchOn(User::query(), $term, ['name'])->suggestFrom('table')->suggest(5)`. `searchIn()` adds to the model's columns, so `User::search($term)->searchIn(['name'])` still scans every column `$searchable` declares (or auto-detection found); weigh one `0` to leave it out (`searchIn(['email' => 0])`).

**Hidden columns are never offered.** A column the model hides (`$hidden`, or one outside a non-empty `$visible`) gives no word to `suggest()`, from the table scan or the dictionary, or to `didYouMean()`. Neither does a relation column the related model hides, or one under a relation the model hides, also where `searchableText()` indexes it under its dotted name. Declare that name in `$searchable['columns']`, as `'author.email'`, so the check knows it: a dotted name the hook gives undeclared gives the dictionary no word. A `morphTo` path's column (`commentable.title`) gives the dictionary no word at all: the class it reaches is each row's own. A `belongsToMany` pivot column (`'tags.note'` with `withPivot('note')`) gives words only where the related model shows its `pivot` and the pivot model shows the column, as `toArray()` serialises it. Searches still match it, including their typo and as-you-type expansions, and return only the row's visible attributes. Dictionary postings written before 3.0 carry no column name, so for a model that hides one of its searchable columns they are left out of suggestions too until you run `fuzzy-search:rebuild --fresh`.

**Un-indexed models keep the table scan** — the v2.0 behaviour, proposing column values as stored:

```php
// User has no fuzzy_index_meta row
User::search('joh')->searchIn(['name'])->suggest(5);

// Returns: ['John', 'Johnny', ...] — the value as stored, not lower-cased
```

The scan reads at most `limit × 3` matching rows: those whose first searched column holds the shortest values (then by value), so long values do not crowd the short completions out; to find them, the database sorts every matching row. A base query with an order, a `distinct()` or a `groupBy()` of its own, a global scope's included, is read in its own order instead.

**What scopes a suggestion.** `filter()` and `filterIn()` narrow suggestions like a `where()`: they narrow the table scan and the `didYouMean()` check, and under one `'auto'` uses the table scan. The constraints you put on the builder's base query (`where()`, `join()`, `query()` and Eloquent calls forwarded through the builder, plus the model's global scopes) are honoured as follows. What counts as a constraint is a WHERE, a JOIN, a HAVING or a GROUP BY clause, a union or a FROM subquery (`fromSub()`); an aliased table (`from('users as u')`) alone is not one.

- **The table scan** (an un-indexed model, `suggestFrom('table')`, or `'auto'` under a constraint) runs on the base query, so every one of those constraints narrows it.
- **`suggest()` in `'auto'` mode on an indexed model** completes from the dictionary only when the base query is unconstrained. A `where()`, a `join()`, a forwarded scope or a global scope switches it to the table scan. The `SoftDeletes` scope does not count, because with `indexing.enabled` on, deleting a row drops its terms from the index. With `indexing.async` (the default) that happens when the queued `IndexModelJob` runs, so until then a trashed row's terms can still be offered; a query-builder `delete()` fires no model events, so its rows' terms stay until you rebuild. Use `suggestFrom('table')` or sync indexing if that matters.
- **`suggestFrom('index')`** always completes from the model's dictionary. It is model-wide and ignores `where()`, `filter()`, `filterIn()` and every scope, so use it only where every caller may see every row's terms.
- **`didYouMean()`** always offers the model's own dictionary terms only. Under a `where()`, a `join()` or a global scope (`SoftDeletes` excepted, with the same queue lag) it keeps only terms posted for at least one row the query can see. It checks up to `max_candidates` of each term's rows, and a term whose visible rows fall outside those is dropped rather than shown. It checks at most `max($limit * 3, 10)` terms, so a narrow query can get fewer alternatives than `$limit`. The `$limit` of `suggest()` and `didYouMean()` is cut to 50.
- **A plain query builder** given `useInvertedIndex(Model::class)` follows the same rules with its own `where()`s and joins, which its index search honours too. The model's global scopes apply to its index search and to `didYouMean()`, but not to its table scan (a query builder has no model scopes), so `'auto'` completes model-wide from the dictionary when the builder carries no constraint of its own.

`suggestFrom('auto'|'index'|'table')` overrides which source `suggest()` uses; `'auto'` (the default) picks the dictionary when the model is indexed and the base query is unconstrained (see above), and the table scan otherwise:

```php
User::search('joh')->suggestFrom('index')->suggest(5); // dictionary only, model-wide (ignores where() and scopes) — [] if the model isn't indexed
User::search('joh')->suggestFrom('table')->suggest(5); // table scan only, even on an indexed model
```

### "Did You Mean" Spell Correction

Get alternative spellings when search has typos:

```php
$alternatives = User::search('jonh')->didYouMean(3);  // Typo

// Returns, for example with users named Jon, Jane and John: [
//     ['term' => 'jon', 'distance' => 1, 'confidence' => 0.75],
//     ['term' => 'jane', 'distance' => 2, 'confidence' => 0.5],
//     ['term' => 'john', 'distance' => 2, 'confidence' => 0.5],   // a transposition is two edits
// ]
```

The closest term comes first, then the most common one. How far it reaches scales with the term's length: 1 edit for 2–3 characters, 2 for 4–5, 3 from 6. Alternatives come from the searched model's own terms in the BM25 dictionary (`fuzzy_index_terms`), so the model must be indexed; another model's terms are never offered. `searchIn()` does not narrow them: like dictionary completions, they are the model's terms from every indexed column. A builder with no Eloquent model (a plain query builder, without `useInvertedIndex(Model::class)`) gets `[]`.

A phrase is corrected word by word: `User::search('charli brwn')->didYouMean(1)` returns `charlie brown` with a distance of 2 (the edits of each word added up). A word the model's dictionary holds stays as typed, and a phrase with no word to correct, or none with an alternative, returns `[]`. The first phrase takes every misspelled word's closest alternative, the second the next closest, and so on.

### Multi-Model Federation Search

Search across multiple models simultaneously:

```php
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;

$results = FederatedSearch::across([User::class, Product::class, Post::class])
    ->search('laptop')
    ->using('fuzzy')
    ->limit(20)
    ->get();

// Each result includes _model_type and _model_class
foreach ($results as $result) {
    echo $result->_model_type;  // 'User', 'Product', or 'Post'
}

// Get grouped results
$grouped = FederatedSearch::across([User::class, Product::class])
    ->search('test')
    ->getGrouped();

// Get counts per model — match counts, not page sizes: limit() does not shrink them
// (limitPerModel() and max_candidates do, see the note below), and they add up to exactly
// what paginate()->total() reports
$counts = FederatedSearch::across([User::class, Product::class])
    ->search('test')
    ->getCounts();  // ['User' => 5, 'Product' => 3]

// Cap how many rows each model may contribute before merging (default: limit())
$results = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->limitPerModel(5)
    ->limit(20)
    ->get();

// Tie-break order for equal scores (and the whole order when withRelevance(false))
$results = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->orderByModel([Product::class, User::class])
    ->get();

// Page across all models with a real, globally ranked total
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->paginate(15);

// paginate()'s total() counts only reachable rows. One rule: each model contributes the
// smaller of its match count, limitPerModel() and max_candidates (default 1000, see
// "max_candidates Tuning" below — a ranked search reads at most that many candidates per
// model). So the page count never promises more rows than the search can return, and
// getCounts() reports the same per-model numbers total() adds up.
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->limitPerModel(5)
    ->paginate(15);

// Cursor-less "load more" pagination — on LIKE, cheaper than paginate() when you only need hasMorePages()
// (it skips the count); on the index both rank each model once, and paginate()'s total comes with it
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->simplePaginate(15);

// Search every Searchable model through its BM25 inverted index instead of LIKE
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->useInvertedIndex()
    ->paginate(15);
```

Each model is searched the way `Model::search()` searches it, with its own `$searchable` configuration — algorithm, typo tolerance, stop words, synonyms, accent handling and options. `using()`, `typoTolerance()` and `options()` on the federated search override them for every model (`options()` takes the same driver options as `SearchBuilder::options()`, such as `max_distance`; `typoTolerance()` wins over its `max_distance`). Narrowing the columns with `searchIn()` overrides only the column list. A model without the `Searchable` trait is searched with LIKE — or, with the `Fuzzy` trait, with its own `getFuzzyAlgorithm()` and `getFuzzyOptions()` (its `$fuzzyAlgorithm`, default `default_algorithm`, and `$fuzzyOptions`), which `using()`, `options()` and `typoTolerance()` override — on the `searchIn()` columns its table has; without `searchIn()`, on its declared `$searchable['columns']`, else — with the `Fuzzy` trait — on `getFuzzySearchableColumns()` (its `$fuzzySearchable`, `name` by default), else on whichever of `name` and `title` its table has, and a model with none of these contributes nothing. Every model, with the trait or without, contributes at most `max_candidates` rows.

`useInvertedIndex()` searches each model with the `Searchable` trait through its BM25 inverted index, exactly as `Model::search($term)->useInvertedIndex()` does (under `searchIn()`, `searchOn()` with those columns), rows and counts alike: a model whose index was never built matches nothing. On the index, `using()` and `options()` are not read, and `typoTolerance()` sets how many edits a query word is expanded by in the index's dictionary. A model without the `Searchable` trait is still searched with LIKE or its `Fuzzy` algorithm, as above. Each `Searchable` model's `_score` is normalised to its own top hit (1.0), on the index as on LIKE, and a model without the trait carries no `_score` and sorts as 0. `useInvertedIndex(false)` turns it off.

A relation column (`author.name`) passed to the federated `searchIn()` is ignored. A model with none of the named columns on its table contributes nothing and is left out of `getCounts()`; one with some of them is searched on those only. Relation columns a model declares in `$searchable['columns']` are searched, as `Model::search()` searches them.

### Search Analytics

Per-query debug information about the builder you are holding — nothing is stored. For DB-backed reporting across searches (popular terms, zero-result terms, latency by path) see [Persisted Search Analytics](../README.md#persisted-search-analytics).

Get detailed analytics about your search configuration:

```php
$analytics = User::search('john')
    ->searchIn(['name' => 10, 'email' => 5])
    ->using('levenshtein')
    ->typoTolerance(2)
    ->getAnalytics();

// Returns: [
//     'search_term' => 'john',
//     'algorithm' => 'levenshtein',
//     'columns_searched' => ['name', 'email'],
//     'typo_tolerance' => 2,
//     'tokenized' => false,
//     'stop_words_active' => false,
//     ...
// ]
```

---
