# Laravel Fuzzy Search

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![License](https://img.shields.io/packagist/l/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![PHP Version](https://img.shields.io/packagist/php-v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Laravel Version](https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-FF2D20?logo=laravel)](https://laravel.com)

A powerful, **zero-config** fuzzy search package for Laravel with fluent API. Works with all major databases without external services.

**Demo:** [laravel-fuzzy-search-demo](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) - See the package in action!

**Documentation:** [Installation](#installation) • [Quick Start](#quick-start) • [Algorithms](#search-algorithms) • [BM25 Index](#bm25-inverted-index) • [Extended Syntax](#extended-search-syntax) • [Scout Driver](#scout-driver) • [Performance](#performance--scaling) • [Compatibility](#algorithm--database-compatibility) • [Upgrade v1→v2](docs/UPGRADE_v1_TO_v2.md) • [Upgrade v2.0→v2.1](docs/UPGRADE_v2.0_TO_v2.1.md)

## Features

| Category | Features |
|----------|----------|
| **Core** | Zero-config search • Fluent API • Eloquent & Query Builder support • Relationship search (dot notation) |
| **Algorithms** | Multiple fuzzy algorithms • Typo tolerance • Multi-word token search |
| **Scoring** | Field weighting • Relevance scoring • Prefix boosting • Partial match • Recency boost |
| **Text Processing** | Stop-word filtering • Synonym support • Per-locale stop-word lists |
| **Internationalization** | Unicode support • Accent insensitivity • Multi-language |
| **Results** | Highlighted results • Custom scoring hooks • Debug/explain-score mode |
| **Performance** | BM25 inverted index • Async indexing (queue) • Redis/cache support |
| **Pagination** | Stable ranking • Cursor pagination • Offset pagination |
| **Reliability** | Fallback search strategy • DB-agnostic • Rate-limit friendly • SQL-injection safe |
| **Configuration** | Config file support • Per-model customization |
| **Developer Tools** | CLI indexing • Benchmark tools • Built-in test suite • Performance utilities |
| **Smart Search** | Autocomplete suggestions (dictionary-backed on indexed models) • "Did you mean" spell correction • Multi-model federation • Persisted search analytics |
| **Integrations** | Filament global search & table search • Scout driver • JSON API resources |

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Search Algorithms](#search-algorithms)
- [Field Weighting & Scoring](#field-weighting--scoring)
- [Text Processing](#text-processing)
- [Result Presentation](#result-presentation)
- [Performance & Indexing](#performance--indexing)
- [BM25 Inverted Index](#bm25-inverted-index)
- [Extended Search Syntax](#extended-search-syntax)
- [Scout Driver](#scout-driver)
- [Filament Integration](#filament-integration)
- [JSON API Resources](#json-api-resources)
- [Livewire Recipe](#livewire-recipe)
- [Pagination](#pagination)
- [Reliability & Safety](#reliability--safety)
- [Events](#events)
- [Persisted Search Analytics](#persisted-search-analytics)
- [Configuration](#configuration)
- [CLI Tools](#cli-tools)
- [Performance & Scaling](#performance--scaling)
- [Algorithm × Database Compatibility](#algorithm--database-compatibility)
- [Testing](#testing)
- [Requirements](#requirements)

**Deep dives:** [BM25 index](docs/bm25.md) • [Extended syntax](docs/extended-syntax.md) • [Relationships](docs/relationships.md) • [Text processing & tokenization](docs/tokenization.md) • [Integrations](docs/integrations.md) • [Analytics](docs/analytics.md)

---

## Installation

```bash
composer require ashiqfardus/laravel-fuzzy-search
```

**That's it!** Zero configuration required. Start searching immediately.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=fuzzy-search-config
```

If you plan to use the **BM25 inverted index** (recommended for 10k+ rows), also run:

```bash
php artisan migrate
```

> **Upgrading from v1.x?** There are breaking changes — result rankings and `_score` values may shift.
> Run the scanner to find affected code, then follow the full guide.
>
> ```bash
> composer require ashiqfardus/laravel-fuzzy-search
> php artisan migrate
> php artisan fuzzy-search:upgrade-v1   # scans your app/ for v1-era API usage
> ```
>
> → [Full upgrade guide](docs/UPGRADE_v1_TO_v2.md)

> **Upgrading from v2.0.x?** Run the new migrations (`php artisan migrate`), then rebuild once
> per model (`php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh`) to pick up
> weighted BM25 ranking.
>
> → [Upgrade v2.0→v2.1 guide](docs/UPGRADE_v2.0_TO_v2.1.md)

---

## Quick Start

### Zero-Config Search

```php
// Just add the trait and search!
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

class User extends Model
{
    use Searchable;
}

// Search immediately - auto-detects searchable columns
$users = User::search('john')->get();
```

**How auto-detection works:** The package automatically detects common column names in this priority order:
- `name`, `title` (weight: 10)
- `email`, `username` (weight: 8)
- `first_name`, `last_name` (weight: 7)
- `description`, `content`, `body` (weight: 5)
- `bio`, `summary`, `excerpt` (weight: 3)
- `slug`, `sku`, `code` (weight: 2-6)

If none of these exist, it falls back to the model's `$fillable` columns.

### Manual Column Configuration

You can manually specify which columns to search and their weights:

```php
class User extends Model
{
    use Searchable;

    // Option 1: Define in $searchable property
    protected array $searchable = [
        'columns' => [
            'name' => 10,        // Highest priority
            'email' => 5,        // Medium priority  
            'bio' => 1,          // Lowest priority
        ],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 2,
    ];
}

// Option 2: Specify at query time (merges with / adds to $searchable columns)
$users = User::search('john')
    ->searchIn(['name' => 10, 'email' => 5])
    ->get();

// Option 3: Simple array without weights (all get weight of 1)
$users = User::search('john')
    ->searchIn(['name', 'email', 'username'])
    ->get();
```

### Fluent API

```php
$users = User::search('john doe')
    ->searchIn(['name' => 10, 'email' => 5, 'bio' => 1])  // Field weighting
    ->typoTolerance(2)                                      // Allow 2 typos
    ->withSynonyms(['john' => ['jon', 'johnny']])          // Synonym support
    ->ignoreStopWords(['the', 'and', 'or'])                // Stop-word filtering
    ->accentInsensitive()                                   // Unicode/accent handling
    ->highlight('mark')                                     // Highlighted results
    ->withRelevance()                                       // Relevance scoring
    ->prefixBoost(2.0)                                      // Prefix boosting
    ->debugScore()                                          // Explain scoring
    ->paginate(15);
```

### Chaining Eloquent

`search()` returns a builder that forwards ordinary Eloquent calls, local scopes and eager loads, so it reads like any other query:

```php
$users = User::search('john')
    ->where('is_active', true)
    ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
    ->with('profile')
    ->verified()                 // local scope
    ->query(fn ($q) => $q->where('tenant_id', auth()->user()->tenant_id))
    ->paginate(15);
```

Executing methods that would bypass the search conditions (`delete()`, `exists()`, `pluck()`, `update()`, …) are not forwarded and throw a `BadMethodCallException` — call `get()`, `first()`, `count()` or `paginate()` instead.

`latest()`, `oldest()`, `inRandomOrder()` and `reorder()` are forwarded the same way as `orderBy()`: they only shape which rows make it into the candidate window, since the relevance `ORDER BY` is appended after them and PHP-side rescoring re-sorts by `_score` whenever `withRelevance` is on (the default) — call `withRelevance(false)` if you want the forwarded order to stick. The closure passed to `when()`, `unless()` or `tap()` receives the underlying Eloquent builder, not the `SearchBuilder`.

### Searching Relationships

Dotted column names — `posts.title`, `author.name`, nested paths — search through Eloquent relations (`belongsTo`, `hasMany`, `belongsToMany`) on the LIKE and extended paths, compiling to `whereHas()` (a portable `EXISTS` subquery):

```php
User::search('smith')->searchIn(['posts.title', 'profile.bio'])->get();
```

The BM25 inverted index does not join relations at query time — define `searchableText()` on the model to put related text into the index instead.

`$searchable['columns']` must be non-empty for `SearchableIndexingObserver` to index a model at all.

→ Full guide: [docs/relationships.md](docs/relationships.md)

### Eloquent & Query Builder Support

```php
// Eloquent
User::whereFuzzy('name', 'john')->get();
User::whereFuzzyMultiple(['name', 'email'], 'john')->get();

// Query Builder
DB::table('users')->whereFuzzy('name', 'john')->get();
DB::table('products')->fuzzySearch(['title', 'description'], 'laptop')->get();
```

---

## Search Algorithms

### Available Algorithms

| Algorithm | Best For | Typo Tolerance | Speed |
|-----------|----------|----------------|-------|
| `fuzzy` | General purpose | High | Fast |
| `levenshtein` | Strict typo matching | Configurable | Medium |
| `soundex` | Phonetic matching (English names) | Phonetic | Fast |
| `metaphone` | Phonetic matching (more accurate) | Phonetic | Fast |
| `trigram` | Similarity matching | High | Medium |
| `similar_text` | Percentage similarity | Medium | Medium |
| `simple` / `like` | Exact substring (LIKE) | None | Fastest |

```php
// Use specific algorithm
User::search('john')->using('levenshtein')->get();
User::search('stephen')->using('soundex')->get();  // Finds "Steven"
User::search('stephen')->using('metaphone')->get(); // More accurate phonetic — see setup below
User::search('laptop')->using('similar_text')->get(); // Percentage match
```

> ⚠️ **`metaphone` requires one-time setup.** Unlike the other algorithms, it searches against a precomputed `{column}_metaphone` shadow column. Calling `using('metaphone')` without it throws `RuntimeException`. Run the three commands shown in [Shadow Columns](#shadow-columns) once per searchable column.

### Shadow Columns

Most algorithms compute their score on the fly during the SQL query. **Metaphone is the exception** — PHP's `metaphone()` function isn't available in SQL, so the package precomputes the phonetic code on every save and stores it in a sibling column.

For a `users` table with a `name` column, that means adding a `name_metaphone` column right next to it. Each row's `name_metaphone` holds the phonetic code (e.g. `Stephen` → `STFN`, `Steven` → `STFN`, `Stefan` → `STFN`). At search time, the query is a simple equality check against the precomputed code — fast, no per-row PHP calls.

**Setup (one-time per column):**

```bash
# 1. Generate the migration that adds the shadow column + index
php artisan fuzzy-search:add-shadow-column "App\Models\User" name --type=metaphone

# 2. Apply it
php artisan migrate

# 3. Backfill existing rows (the observer only writes on future saves)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh
```

After this, the `SearchableObserver` keeps `name_metaphone` in sync automatically on every `save()` and `update()`.

**What gets generated:**

```php
// database/migrations/{timestamp}_add_name_metaphone_to_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->string('name_metaphone')->nullable()->after('name');
    $table->index('name_metaphone');
});
```

**Safety guards in the command:**

- The model must exist and be an Eloquent model.
- The model must live in your app namespace — vendor/framework classes are rejected.
- The column name is sanitized to `[a-zA-Z0-9_]` only — blocks SQL injection through the argument.

### Typo Tolerance

```php
// Auto typo tolerance based on word length
User::search('jonh')->get();  // Finds "John"

// Configure tolerance level
User::search('john')
    ->typoTolerance(1)  // Allow 1 typo
    ->get();

// Disable typo tolerance
User::search('john')
    ->typoTolerance(0)
    ->get();
```

### Multi-Word Token Search

```php
// Searches each word independently and combines results
User::search('john doe developer')
    ->tokenize()        // Split into tokens
    ->matchAll()        // All tokens must match (AND)
    ->get();

User::search('john doe developer')
    ->tokenize()
    ->matchAny()        // Any token can match (OR)
    ->get();
```

### In-Memory Mode

```php
use Ashiqfardus\LaravelFuzzySearch\Facades\FuzzySearch;

$matches = FuzzySearch::on($staticArray)->search('term')->searchIn(['name'])->get();
```

> **Supported methods:** `search`, `searchIn`, `take`, `skip`, `withRelevance`, `get`.
> Any other `SearchBuilder` method (e.g. `extended()`, `using()`, `preset()`, `paginate()`) will throw a `\BadMethodCallException` to prevent silent failures.

---

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

### Relevance Scoring

```php
$users = User::search('john')
    ->withRelevance()
    ->get();

foreach ($users as $user) {
    echo "{$user->name}: {$user->_score}";
}
```

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
`minMatchLength()` is deprecated since v2.1.0 and does nothing; set the minimum term length with
the `min_search_length` config key (whole term) or `typo_tolerance.min_word_length` (per word).

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

### Recency Boost

Boost newer records in search results. The boost decays linearly across the window: a row
created this instant gets the full multiplier, one at the end of the window gets none, and the
multiplier in between is

```
1 + (multiplier - 1) x (1 - age_in_days / days)
```

so with `boostRecent(1.5, 'created_at', 30)` a row created today scores x1.5, a 15-day-old row
x1.25 and a 29-day-old row x1.0167. Rows older than the window are untouched (x1.0).

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

### Search Suggestions / Autocomplete

Get autocomplete suggestions based on search term:

```php
$suggestions = User::search('joh')
    ->searchIn(['name', 'email'])
    ->suggest(5);

// Returns: ['John', 'Johnny', 'Johanna', ...]
```

**Indexed models complete from the dictionary.** When the model has been indexed (a `fuzzy_index_meta` row exists for it — see [BM25 Inverted Index](#bm25-inverted-index)), `suggest()` completes the *last whitespace-separated word* of the term against that model's own dictionary, scoped to its postings and ordered by document count across all indexed models (the dictionary's `doc_count` is global, so a term that is common in another indexed model can head the list); any earlier words are kept as typed and prefixed back. Completions are the dictionary's lower-case terms, not the value as stored in the column:

```php
// Product is indexed; only "mo" is completed, "wireless " is kept as typed
Product::search('wireless mo')->suggest(5);

// Returns: ['wireless monitor', 'wireless mouse', 'wireless modem', ...]
```

`searchIn()` does **not** narrow dictionary completions: they are scoped to the model, not to its columns, so a name box on an indexed model can be offered a fragment that only occurs in an email column. Use `suggestFrom('table')` when the column matters — the table scan respects `searchIn()`.

**Un-indexed models keep the table scan** — the v2.0 behaviour, proposing column values as stored:

```php
// User has no fuzzy_index_meta row
User::search('joh')->searchIn(['name'])->suggest(5);

// Returns: ['John', 'Johnny', ...] — the value as stored, not lower-cased
```

`suggestFrom('auto'|'index'|'table')` overrides which source `suggest()` uses; `'auto'` (the default) picks the dictionary when the model is indexed and falls back to the table scan otherwise:

```php
User::search('joh')->suggestFrom('index')->suggest(5); // dictionary only — [] if the model isn't indexed
User::search('joh')->suggestFrom('table')->suggest(5); // table scan only, even on an indexed model
```

### "Did You Mean" Spell Correction

Get alternative spellings when search has typos:

```php
$alternatives = User::search('jonh')  // Typo
    ->searchIn(['name'])
    ->didYouMean(3);

// Returns: [
//     ['term' => 'john', 'distance' => 1, 'confidence' => 0.8],
//     ['term' => 'jon', 'distance' => 2, 'confidence' => 0.6],
// ]
```

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

// Get counts per model — match counts, not page sizes: limit() does not shrink them, and
// they add up to exactly what paginate()->total() reports (see the note below)
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

// Cursor-less "load more" pagination — cheaper than paginate() when you only need hasMorePages()
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->simplePaginate(15);
```

Narrowing the columns per search with `searchIn()` still keeps each model's own configured stop words, synonyms and accent-insensitivity settings — only the column list is overridden.

Relation columns (`author.name`) are not supported in federated searches yet and are ignored.

### Search Analytics

Per-query debug information about the builder you are holding — nothing is stored. For DB-backed reporting across searches (popular terms, zero-result terms, latency by path) see [Persisted Search Analytics](#persisted-search-analytics).

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

## Text Processing

- **Stop-word filtering** — `ignoreStopWords()` drops common words from a query; built-in lists cover eight locales, or pass a custom list or file.
- **Synonyms** — `withSynonyms()` and `synonymGroup()` expand a query to related terms.
- **Per-locale stop words** — `ignoreStopWords('de')` picks a locale's list at query time and `$searchable['locale']` picks one for a model's index pipeline. (`locale()` on the builder never selected either; it is deprecated since v2.1.0 and does nothing.)
- **Unicode & accent insensitivity** — `accentInsensitive()` and `unicodeNormalize()` match `café`/`cafe` and `naïve`/`naive`; text is handled per character, not per byte, so combining marks stay attached to their base letters.
- Index-time options — the tokenizer, per-model pipelines, accent folding on the index, and optional stemming — sit apart from the query-time behavior above; changing any of them needs `php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh`.

→ Full guide: [docs/tokenization.md](docs/tokenization.md)

---

## Result Presentation

### Highlighted Results

```php
$users = User::search('john')
    ->highlight('em')  // Wrap matches in <em> tags
    ->get();

foreach ($users as $user) {
    echo $user->_highlighted['name'];  // "Hello <em>John</em> Doe"
}

// Custom highlight
$users = User::search('john')
    ->highlight('<mark class="highlight">', '</mark>')
    ->get();
```

Set `highlighting.enabled = true` in the config to highlight every search without calling `highlight()`.

Every value in `_highlighted` is safe to render as HTML: a matched column is wrapped in the highlight tag (and escaped first), and — since v2.1.0 — a column that did not match is HTML-escaped too, so the whole array can be echoed with `{!! !!}` without an extra `e()` call.

### Debug / Explain-Score Mode

```php
$users = User::search('john')
    ->debugScore()
    ->get();

foreach ($users as $user) {
    print_r($user->_debug);
    // [
    //     'term' => 'john',
    //     'column_scores' => ['name' => 100, 'email' => 25],
    //     'multipliers' => ['prefix_boost' => 2.0, 'weight' => 10],
    //     'final_score' => 250,
    //     'matched_algorithm' => 'fuzzy',
    // ]
}
```

---

## Performance & Indexing

### Async Indexing (Queue Support)

```php
// In config/fuzzy-search.php
'indexing' => [
    'enabled' => true,          // without this the indexing observer returns early
    'async' => true,
    'queue' => 'search-indexing',
    'chunk_size' => 500,
    'job' => [
        'tries'   => 3,             // attempts before the index job is marked failed
        'backoff' => [10, 60, 300], // seconds before the 2nd, 3rd, ... attempt
        'timeout' => 120,           // seconds a single job may run
    ],
],

// Re-index a single model (dispatches IndexModelJob to queue)
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
IndexModelJob::dispatch(User::class, $user->id);
```

### Redis / Cache Support

```php
// Cache search results
User::search('john')
    ->cache(minutes: 60)
    ->get();

// Cache with custom key
User::search('john')
    ->cache(60, 'user-search-john')
    ->get();

// Use Redis for pattern storage
// In config/fuzzy-search.php
'cache' => [
    'enabled' => true,
    'driver' => 'redis',
    'ttl' => 3600,
],
```

---

## BM25 Inverted Index

A real inverted index for large tables, across four tables: `fuzzy_index_terms`, `fuzzy_index_postings`, `fuzzy_index_documents`, `fuzzy_index_meta`.

```bash
php artisan fuzzy-search:rebuild "App\Models\Post"    # build once, then stays in sync automatically
```

```php
Post::search('tolkien')->useInvertedIndex()->get();
```

- **Column weights (BM25F-lite)** — `searchIn()` / `$searchable['columns']` weights scale ranking on the index too, not only the LIKE/Levenshtein paths.
- **Typo tolerance & as-you-type** — the index expands each query term through its own term dictionary, so `typoTolerance()` and `asYouType()` work without an exact token match.
- BM25 tends to beat LIKE once a table passes roughly 10k+ rows; below that, LIKE is simpler to operate.

→ Full guide: [docs/bm25.md](docs/bm25.md)

---

## Extended Search Syntax

`->extended()` opts a search string into Fuse.js-style query operators for precise matching, instead of a plain substring search.

```php
User::search('=John ^Doe !banned')->extended()->get();
```

Operators: `'include`, `=exact`, `^prefix`, `suffix$`, `!exclude`, `|` (OR), `( )` (grouping), `~typo`, `field:term`, and quoted `"phrases"`.

Extended queries always run on the LIKE path — `->useInvertedIndex()` is ignored when combined with `->extended()`, and `getDebugInfo()['index_ignored']` reports it.

→ Full guide: [docs/extended-syntax.md](docs/extended-syntax.md)

---

## Scout Driver

The Scout engine adapter is bundled in this package and registers automatically when `laravel/scout` is installed — no separate driver package needed.

```
SCOUT_DRIVER=fuzzy-search
```

It wraps the same `IndexManager` + `Bm25Scorer` used by `Model::search()->useInvertedIndex()`, so Scout searches share the same index and the same relevance scoring — there is no separate index to keep in sync.

→ Full guide: [docs/integrations.md](docs/integrations.md#scout-driver)

---

## Filament Integration

`HasFuzzyGlobalSearch` swaps a Filament Resource's LIKE-based global search for fuzzy search — typo tolerance, relevance ordering, highlighted details — while everything else about the Resource stays as defined.

```php
use HasFuzzyGlobalSearch; // on the Resource, for global search

FuzzySearch::tableSearch(['name']); // for individual table columns
```

Supports Filament v3, v4 and v5. Filament is a soft dependency — nothing in this package requires it unless you use the trait or helper.

→ Full guide: [docs/integrations.md](docs/integrations.md#filament-integration)

---

## JSON API Resources

`FuzzySearchResource` wraps one result row, and `FuzzySearchCollection::fromBuilder()` wraps a paginated search, into normal Laravel API responses with the package's underscore-prefixed fields alongside the plain attributes.

```php
return FuzzySearchCollection::fromBuilder(User::search($q)->highlight('mark'), perPage: 20);
```

The response's `meta` carries `query`, `algorithm`, `latency_ms` and `suggestions` (populated only when the page is empty). `_highlighted` values are already HTML-escaped — render them directly, don't escape again.

→ Full guide: [docs/integrations.md](docs/integrations.md#json-api-resources)

---

## Livewire Recipe

A search-as-you-type box needs only a client-side debounce — `wire:model.live.debounce.300ms` — plus `->asYouType()` and `->suggest()` on the builder. This replaces `SearchBuilder::debounce()`, deprecated since v2.1.0: by the time the builder runs server-side, the request has already arrived, so a server-side debounce can't do anything.

This is documentation only — no such component ships with the package or the demo app.

→ Full guide: [docs/integrations.md](docs/integrations.md#livewire-recipe)

---

## Pagination

### Stable Ranking

```php
// Results maintain consistent order across pages
$page1 = User::search('john')->stableRanking()->paginate(10, page: 1);
$page2 = User::search('john')->stableRanking()->paginate(10, page: 2);
```

### Pagination Methods

```php
// Offset pagination
$users = User::search('john')->paginate(15);

// Simple pagination (no total count - faster; best for infinite scroll)
$users = User::search('john')->simplePaginate(15);

// paginate() clamps perPage to max_candidates (default 1000) on every path — LIKE, extended
// and BM25 alike — because that is the widest window the ranking is built from.
$users = User::search('john')->paginate(2000);  // perPage() === 1000

// cursorPaginate() always throws BadMethodCallException — it bypasses PHP-side
// relevance scoring. Use simplePaginate() above instead.

// Manual pagination
$users = User::search('john')
    ->take(10)
    ->skip(20)
    ->get();
```

---

## Reliability & Safety

### Fallback Search Strategy

When the primary algorithm returns no rows, the same search is re-run with each fallback in order until one returns results. Works with `get()`, `first()`, `paginate()`, `simplePaginate()` and `count()`; filters and `where()` constraints carry over to each attempt.

```php
User::search('jonh')
    ->using('simple')        // plain LIKE — no match for the typo
    ->fallback('fuzzy')      // transposition pattern finds "John"
    ->fallback('soundex')    // tried only if fuzzy also finds nothing
    ->get();

// A term the dictionary cannot reach (bm25.fuzzy.candidate_pool) still gets a safety net:
Product::search('smartwach')
    ->useInvertedIndex()
    ->fallback('fuzzy')
    ->get();
```

One `FuzzySearchExecuted` event fires per attempt, so analytics can tell which algorithm answered.

### Query Complexity Limits

```php
User::search($query)
    ->maxPatterns(50)  // Cap the LIKE patterns generated per column (default: performance.max_patterns = 100)
    ->get();
```

> `debounce()` is deprecated and does nothing: a request that has already reached the server cannot be debounced. Debounce on the client instead (`wire:model.live.debounce.300ms` in Livewire, or a timer in JavaScript). It will be removed in v3.0.0.

### SQL Injection Safety

All queries use parameterized bindings. Search terms are automatically sanitized.

```php
// Safe - input is sanitized
User::search("'; DROP TABLE users; --")->get();
```

### Exception Handling

```php
use Ashiqfardus\LaravelFuzzySearch\Exceptions\LaravelFuzzySearchException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidAlgorithmException;

// Catch all fuzzy search exceptions
try {
    $results = User::search($term)->get();
} catch (LaravelFuzzySearchException $e) {
    Log::error('Search failed', $e->toArray());
}

// Catch specific exceptions
try {
    $results = User::search('')->get();
} catch (EmptySearchTermException $e) {
    return response()->json(['error' => 'Please enter a search term']);
}

try {
    $results = User::search('test')->using('invalid')->get();
} catch (InvalidAlgorithmException $e) {
    return response()->json(['error' => $e->getMessage()]);
}
```

**Available Exceptions:**
- `LaravelFuzzySearchException` - Base exception (catch all)
- `EmptySearchTermException` - Search term is empty
- `InvalidAlgorithmException` - Invalid algorithm specified
- `InvalidConfigException` - Configuration error
- `SearchableColumnsNotFoundException` - No searchable columns found

---

## Events

### `FuzzySearchExecuted`

Fired after every `->get()` or `->paginate()` call, and — since v2.1 — after every in-memory search too (`FuzzySearch::on($items)->search(...)->get()`). Useful for monitoring search latency and volume in production.

An in-memory search called with an empty term or no `searchIn()` columns returns early and fires no event: nothing was searched, so there's nothing to log — the same rule the query-builder path already applies via its minimum search-length guard.

```php
use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;

Event::listen(FuzzySearchExecuted::class, function ($event) {
    Log::info('search', [
        'term'      => $event->searchTerm,
        'columns'   => $event->columns,
        'algorithm' => $event->algorithm,
        'count'     => $event->candidateCount,
        'ms'        => $event->latencyMs,
        'results'   => $event->resultCount,
        'path'      => $event->path,
        'model'     => $event->modelClass,
    ]);
});
```

Properties:

- `searchTerm` (string) — the user's query
- `columns` (array) — columns being searched
- `algorithm` (string) — algorithm used: `simple`, `fuzzy`, `levenshtein`, `soundex`, `metaphone`, `trigram`, `similar_text`, or `bm25`
- `candidateCount` (int) — rows fetched from SQL before scoring
- `latencyMs` (float) — total search time in milliseconds
- `resultCount` (int) — rows returned to the caller; `-1` when unknown
- `path` (string) — which code path answered: `like`, `bm25`, `extended`, or `in_memory`
- `modelClass` (`?string`) — the Eloquent model class searched; `null` for query-builder and in-memory searches

---

## Persisted Search Analytics

Opt-in, DB-backed analytics: set `analytics.enabled` to `true` and every executed search writes one row to `fuzzy_search_logs`.

```php
SearchAnalytics::popular(7, 5);
SearchAnalytics::zeroResults(7, 5);
```

The `SearchAnalytics` facade also reports `averageLatency()` by path and `volume()` per day; `php artisan fuzzy-search:analytics` prints a report and `fuzzy-search:analytics:prune` deletes rows past `retention_days`. Terms are stored (or hashed via `hash_terms`) — no user identity is recorded.

→ Full guide: [docs/analytics.md](docs/analytics.md)

---

## Configuration

### Config File

```php
// config/fuzzy-search.php

return [
    'default_algorithm' => 'fuzzy',
    
    'typo_tolerance' => [
        'enabled' => true,
        'max_distance' => 2,
        'min_word_length' => 4,  // No typo tolerance for short words
    ],
    
    'scoring' => [
        'exact_match' => 100,
        'prefix_match' => 80,
        'contains' => 60,
        'fuzzy_match' => 50,
    ],
    
    'stop_words' => [
        'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at'],
        'de' => ['der', 'die', 'das', 'und', 'oder', 'aber'],
    ],
    
    'synonyms' => [
        // Global synonyms
    ],
    
    'indexing' => [
        'enabled' => false,
        'async' => true,
        'queue' => 'default',
    ],
    
    'cache' => [
        'enabled' => false,
        'driver' => 'default',  // any cache store name, or 'default' for the app's
        'ttl' => 3600,
    ],
    
    'max_candidates' => 1000,   // top level, not under 'performance'
    
    'performance' => [
        'max_patterns' => 100,
    ],
];
```

### Config Presets

Presets are **predefined search configurations** for common use cases. Instead of manually configuring multiple options every time, use a single preset name.

**Without preset (verbose):**
```php
Post::search('laravel')
    ->searchIn(['title' => 10, 'body' => 5, 'excerpt' => 3])
    ->using('fuzzy')
    ->typoTolerance(2)
    ->ignoreStopWords('en')
    ->accentInsensitive()
    ->get();
```

**With preset (clean):**
```php
Post::search('laravel')->preset('blog')->get();
```

#### Available Presets

| Preset | Best For | Algorithm | Typo Tolerance | Features |
|--------|----------|-----------|----------------|----------|
| `blog` | Blog posts, articles | fuzzy | 2 | Stop words, accent-insensitive |
| `ecommerce` | Product search | fuzzy | 1 | Weighted product columns, no stop words |
| `users` | User/contact search | levenshtein | 2 | Accent-insensitive |
| `phonetic` | Name pronunciation | soundex | 0 | Phonetic matching |
| `exact` | SKUs, codes, IDs | simple | 0 | Plain LIKE, no typo patterns |

#### Preset Configuration Reference

```php
// config/fuzzy-search.php
'presets' => [
    'blog' => [
        'columns' => ['title' => 10, 'body' => 5, 'excerpt' => 3],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 2,
        'stop_words_enabled' => true,
        'accent_insensitive' => true,
    ],
    
    'ecommerce' => [
        'columns' => ['name' => 10, 'description' => 5, 'sku' => 8, 'brand' => 6],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 1,
        'partial_match' => true,
        'stop_words_enabled' => false,
    ],
    
    'users' => [
        'columns' => ['name' => 10, 'email' => 8, 'username' => 9],
        'algorithm' => 'levenshtein',
        'typo_tolerance' => 2,
        'accent_insensitive' => true,
    ],
    
    'phonetic' => [
        'columns' => ['name' => 10],
        'algorithm' => 'soundex',
        'typo_tolerance' => 0,
    ],
    
    'exact' => [
        'algorithm' => 'simple',
        'typo_tolerance' => 0,
        'partial_match' => true,
    ],
],
```

#### Using Presets

```php
User::search('john')->preset('users')->get();
Post::search('laravel')->preset('blog')->get();
Product::search('laptop')->preset('ecommerce')->get();
Contact::search('steven')->preset('phonetic')->get();  // Finds "Stephen"
Product::search('SKU-12345')->preset('exact')->get();
```

#### Override Preset Settings

```php
// Use blog preset but with higher typo tolerance
Post::search('laravel')
    ->preset('blog')
    ->typoTolerance(3)  // Override preset's default of 2
    ->get();
```

#### Create Custom Presets

```php
// config/fuzzy-search.php
'presets' => [
    'documents' => [
        'columns' => ['title' => 10, 'content' => 8, 'tags' => 5],
        'algorithm' => 'trigram',
        'typo_tolerance' => 2,
        'stop_words_enabled' => true,
        'locale' => 'en',
    ],
],
```

```php
Document::search('report')->preset('documents')->get();
```

### Per-Model Customization

```php
class Product extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'title' => 10,
            'description' => 5,
            'sku' => 8,
        ],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 2,
        'stop_words' => ['the', 'a', 'an'],
        'synonyms' => [
            'laptop' => ['notebook', 'computer'],
        ],
        'accent_insensitive' => true,
    ];

    // Custom scoring logic
    public function getSearchScore($baseScore): float
    {
        return $this->is_featured ? $baseScore * 1.5 : $baseScore;
    }
}
```

---

## CLI Tools

### Indexing Commands

```bash
# Build / rebuild BM25 index for a model
php artisan fuzzy-search:rebuild "App\Models\User"

# Rebuild with fresh index (flush first)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh

# Rebuild asynchronously (for large tables)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh --async --queue=indexing

# Flush index entries for a model
php artisan fuzzy-search:flush "App\Models\User"

# Clear BM25 index for a model
php artisan fuzzy-search:clear "App\Models\User"

# Clear BM25 index for all models
php artisan fuzzy-search:clear --all

# Show index status (row counts, avg doc length, term count per model) and list postings that predate column weighting
php artisan fuzzy-search:status
```

### Benchmark & Debug Commands

`fuzzy-search:analytics` and `fuzzy-search:analytics:prune` are documented under [Persisted Search Analytics](#persisted-search-analytics).

```bash
# Benchmark search performance
php artisan fuzzy-search:benchmark "App\Models\User" --term="john" --iterations=100

# Explain a search query
php artisan fuzzy-search:explain User --term="john"
```

---

## Performance & Scaling

### Algorithm Comparison

| Algorithm | Speed | Typo Tolerance | Best For | Dataset Size |
|-----------|-------|----------------|----------|--------------|
| **simple** | Fastest | None | Exact matches, SKUs | Any size |
| **fuzzy** | Very Fast | High | General purpose | < 100K rows |
| **soundex** | Very Fast | Phonetic | Name searches | < 100K rows |
| **trigram** | Fast | Very High | Similarity matching | < 50K rows |
| **levenshtein** | Medium | Configurable | Precise typo matching | < 50K rows |
| **BM25 index** | Fast at scale | Native (dictionary expansion) | Large tables, ranked results | 10K+ rows |

### Measured Latency (100k-row MySQL 8.0 dataset)

Numbers measured on the [live demo](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) (commodity VPS, warm cache). Run `php artisan demo:seed` in the demo project to seed the same dataset.

| Search path | Median latency | Notes |
|---|---|---|
| LIKE (`using('simple')`) | ~8 ms | Full table scan |
| Levenshtein (`using('levenshtein')`) | ~45 ms | PHP re-score over 1,000 SQL candidates |
| BM25 inverted index (`useInvertedIndex()`) | ~12 ms | Three parameterised SQL queries + PHP BM25 scoring |
| Extended syntax (`->extended()`) | ~15 ms | Includes AST compilation and multi-operator SQL generation |

**At scale:** The BM25 path uses an indexed term lookup — query time grows with the number of matching postings, not total row count. A well-maintained 1M-row index returns results in the same ~12–20 ms window as the 100k baseline. Typo expansion adds one dictionary query per query term whose cost grows with dictionary size (roughly 30 ms per term, measured on SQLite at ~450k distinct terms); call `typoTolerance(0)` on latency-critical searches.

### When to Use BM25 vs LIKE

**Use BM25 (`useInvertedIndex()`) when:**
- Table has 10k+ rows
- Result ranking/relevance quality matters
- You have queue workers running

**Use LIKE / fuzzy when:**
- Small tables (< 10k rows) — LIKE can be faster due to BM25 scoring overhead

### `max_candidates` Tuning

For the LIKE/Levenshtein paths, SQL candidates are fetched then re-scored in PHP. The candidate set size is controlled by `max_candidates` (default: 1000). Lower this on large tables to reduce memory usage:

```php
// config/fuzzy-search.php
'max_candidates' => 500,  // top-level key; fetch fewer candidates on large tables
```

### Recommended Optimizations

```php
// For 100k+ rows: enable BM25 index + cache + limit columns
User::search('john')
    ->useInvertedIndex()
    ->cache(60)
    ->searchIn(['name', 'email'])
    ->maxPatterns(50)
    ->get();
```

Key tips:
1. **Use the BM25 index** for tables with 10k+ rows
2. **Enable caching** for repeated searches
3. **Limit columns** — only search relevant fields
4. **Use `simple` algorithm** when typo tolerance isn't needed
5. **Set `max_candidates`** to prevent excessive memory usage on large tables
6. **Use `take()`** to cap result sets
7. **Eager load relationships** to avoid N+1 queries

### Scaling Recommendations

| Records | Recommended Strategy | Expected Query Time |
|---------|---------------------|---------------------|
| < 50K | Default (no optimization) | < 50ms |
| 50K - 100K | Add DB indexes + cache | < 100ms |
| 100K - 500K | BM25 index + cache | < 150ms |
| 500K - 1M | BM25 index + partitioning + cache | < 200ms |
| 1M - 10M | BM25 + read replicas + tiered cache | < 300ms |
| > 10M | Consider Meilisearch / Typesense | — |

---

## Algorithm × Database Compatibility

This table shows what each algorithm does at the SQL level on each supported database. "Native" = the database's own function. "Pattern fallback" = PHP generates LIKE patterns.

| Algorithm | MySQL 8 | MariaDB 11.4 (CI); 10.6+ expected | PostgreSQL 14 | SQLite | SQL Server |
|---|---|---|---|---|---|
| **simple** / **like** | `LIKE '%term%'` | `LIKE '%term%'` | `ILIKE '%term%'` | `LIKE '%term%'` | `LOWER() LIKE` |
| **fuzzy** | LIKE pattern set (typo patterns, transpositions) | LIKE pattern set | ILIKE pattern set | LIKE pattern set | LIKE pattern set |
| **levenshtein** | Native `LEVENSHTEIN()` UDF if `use_native_functions=true`, else pattern set | Pattern set | `similarity()` via pg_trgm if `use_native_functions=true`, else pattern set | Pattern set | Pattern set |
| **trigram** | LIKE pattern set | LIKE pattern set | Native `similarity()` via pg_trgm if `use_native_functions=true` | LIKE pattern set | LIKE pattern set |
| **soundex** | Native `SOUNDEX()` — always on, applied to first or last word | Native `SOUNDEX()` — always on | Native `SOUNDEX()` via `fuzzystrmatch` if `use_native_functions=true`, else pattern fallback | Pattern fallback | Pattern fallback |
| **metaphone** | Shadow column `{col}_metaphone` + exact `=` match | Shadow column | Shadow column | Shadow column | Shadow column |
| **similar_text** | `LIKE '%term%'` (SQL); `similar_text()` scores in PHP after fetch | Same | `ILIKE '%term%'`; PHP scores | Same | Same |

MariaDB behaves as MySQL 8 for every algorithm (native SOUNDEX/LEVENSHTEIN paths included).

### Notes

- **`use_native_functions`** in `config/fuzzy-search.php` gates optional DB extensions. MySQL `SOUNDEX()` is built-in and always active — no flag needed. The flag is only relevant for: Levenshtein UDF (MySQL), pg_trgm/fuzzystrmatch (PostgreSQL), unaccent (PostgreSQL).
- **Levenshtein UDF (MySQL):** Not installed by default. See [this gist](https://gist.github.com/yohgaki/9315991) or your DB package manager.
- **pg_trgm (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS pg_trgm;`
- **fuzzystrmatch (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;`
- **unaccent (PostgreSQL, for `accentInsensitive()`):** `CREATE EXTENSION IF NOT EXISTS unaccent;` + `use_native_functions=true`
- **MySQL accent insensitive:** Use `utf8mb4_unicode_ci` or `utf8mb4_0900_ai_ci` collation on the column.
- **Metaphone shadow column:** Run `php artisan fuzzy-search:add-shadow-column {Model} {column} --type=metaphone` then `php artisan migrate`.

### PHP-Side Scoring

Regardless of algorithm, after SQL candidates are fetched:

1. `similar_text()` and `levenshtein()` run in PHP on each candidate.
2. Results are re-sorted by the combined PHP score (higher = better).
3. `limit/offset` is applied on the PHP-sorted collection (not in SQL).

Top-N results are always the most relevant N from the candidate set (not just the first N SQL rows). Candidate set size is controlled by `max_candidates` (default: 1000).

> **Pagination note:** `paginate()` ranks globally across up to `max_candidates` rows before slicing; pages beyond that window use database ordering.

---

## Testing

```bash
# Run tests
composer test

# Run with coverage
composer test-coverage

# Run benchmarks
composer benchmark
```

---

## Requirements

- PHP 8.1 or higher
- Laravel 10.x, 11.x, 12.x, or 13.x
- Any supported database — MySQL 8+, MariaDB 11.4 (CI); 10.6+ expected, PostgreSQL 14+, SQLite, or SQL Server 2022

---

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Credits

- [Md Asikul Islam](https://github.com/ashiqfardus)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.
