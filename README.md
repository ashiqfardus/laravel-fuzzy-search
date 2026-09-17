# Laravel Fuzzy Search

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![License](https://img.shields.io/packagist/l/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![PHP Version](https://img.shields.io/packagist/php-v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Laravel Version](https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-FF2D20?logo=laravel)](https://laravel.com)

A powerful, **zero-config** fuzzy search package for Laravel with fluent API. Works with all major databases without external services.

**Demo:** [laravel-fuzzy-search-demo](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) - See the package in action!

**Documentation:** [Installation](#installation) • [Quick Start](#quick-start) • [Algorithms](#search-algorithms) • [BM25 Index](#bm25-inverted-index) • [Extended Syntax](#extended-search-syntax) • [Scout Driver](#scout-driver) • [Performance](#performance--scaling) • [Compatibility](#algorithm--database-compatibility) • [Upgrade v1→v2](docs/UPGRADE_v1_TO_v2.md)

## Features

| Category | Features |
|----------|----------|
| **Core** | Zero-config search • Fluent API • Eloquent & Query Builder support • Relationship search (dot notation) |
| **Algorithms** | Multiple fuzzy algorithms • Typo tolerance • Multi-word token search |
| **Scoring** | Field weighting • Relevance scoring • Prefix boosting • Partial match • Recency boost |
| **Text Processing** | Stop-word filtering • Synonym support • Language/locale awareness |
| **Internationalization** | Unicode support • Accent insensitivity • Multi-language |
| **Results** | Highlighted results • Custom scoring hooks • Debug/explain-score mode |
| **Performance** | BM25 inverted index • Async indexing (queue) • Redis/cache support |
| **Pagination** | Stable ranking • Cursor pagination • Offset pagination |
| **Reliability** | Fallback search strategy • DB-agnostic • Rate-limit friendly • SQL-injection safe |
| **Configuration** | Config file support • Per-model customization |
| **Developer Tools** | CLI indexing • Benchmark tools • Built-in test suite • Performance utilities |
| **Smart Search** | Autocomplete suggestions (dictionary-backed on indexed models) • "Did you mean" spell correction • Multi-model federation • Persisted search analytics |

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

Dotted column names search through Eloquent relations — `belongsTo`, `hasMany`, `belongsToMany`, and nested paths — on the LIKE and extended paths; the BM25 index stores related text through `searchableText()` instead (see below):

```php
Post::search('tolkien')
    ->searchIn(['title' => 10, 'author.name' => 5, 'tags.name' => 3, 'comments.author.name' => 1])
    ->highlight('mark')
    ->paginate(15);

$post->_highlighted['author.name'];            // "<mark>Tolk</mark>ien"
@fuzzyHighlight($post, 'tags.name')            // the related row that matched
```

- **Filtering** compiles to `whereHas()` (a portable `EXISTS` subquery); nested paths use the same `whereHas('comments.author', …)` Eloquent supports.
- **Scoring** uses the column's `searchIn()` weight; a to-many relation counts its best related row.
- **Highlighting**, `_matches` and `suggest()` include relation columns under the dotted key.
- **Extended syntax** (`'include`, `^prefix`, `=exact`, `!not`, `|`, `~word`, `field:word`) works on relation columns; `!tolkien` excludes rows with any matching related row, and `author.name:tolkien` scopes a term to that one relation column.
- A dotted name is treated as a relation only when its first segment is a relation method on the model. `posts.title` on a model whose table is `posts` stays a table-qualified column, exactly as in v2.0. Relation paths need `Model::search()`; a Query Builder source throws.
- Touched relations are eager-loaded on the results.
- `searchIn()` on a `Model::search()` builder *adds* the listed columns to the model's configured `$searchable['columns']` (it has never replaced them); to search only the listed columns, list them all in `searchIn()` or build the query from `new SearchBuilder(Model::query(), app(FuzzySearch::class))`.
- Relation columns are not part of the SQL relevance `ORDER BY`, so when more than `max_candidates` rows match, rows that match only through a relation may fall outside the rescored window.
- Polymorphic (`morphTo`) relation paths are not supported.

**BM25 index:** relations are not joined at query time. Define `searchableText()` to put related text into the index, eager-load it during rebuilds with `searchIndexQuery()`, declare the foreign key in `reindex_on`, and reindex the children when the parent changes:

```php
class Post extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns'    => ['title' => 10, 'author.name' => 5],
        'reindex_on' => ['author_id'],
    ];

    public function searchableText(): array
    {
        return ['title' => $this->title, 'author' => $this->author?->name, 'tags' => $this->tags->pluck('name')->implode(' ')];
    }

    public function searchIndexQuery(Builder $query): Builder
    {
        return $query->with(['author', 'tags']);
    }
}

class Author extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (Author $author) => Post::reindexRelated('author_id', $author->id));
    }
}
```

Changing a parent row (renaming an author) does **not** reindex its children automatically — that is what the `saved` hook above is for.

`$searchable['columns']` must be non-empty for `SearchableIndexingObserver` to index a model at all; an empty (or missing) `columns` config is treated as "not indexed" and every save is skipped.

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

```php
User::search('joh')
    ->partialMatch()    // Matches "john", "johnny", "johanna"
    ->minMatchLength(2) // Minimum 2 characters
    ->get();
```

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

Boost newer records in search results:

```php
// Recent records (within 30 days) get 1.5x score boost
User::search('john')
    ->boostRecent(1.5, 'created_at', 30)
    ->get();

// With defaults: 1.5x boost, created_at column, 30 days
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

// Get counts per model
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

// paginate()'s total() counts only reachable rows: when limitPerModel() caps a model's
// contribution, that model's share of the total is capped the same way, so the page
// count never promises more rows than the search can actually return. Each model's share
// is also bounded by max_candidates (default 1000, see "max_candidates Tuning" below) —
// a model with more matches than that never contributes more than max_candidates rows.
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

### Stop-Word Filtering

```php
// Use default stop words
User::search('the quick brown fox')
    ->ignoreStopWords()
    ->get();

// Custom stop words
User::search('the quick brown fox')
    ->ignoreStopWords(['the', 'a', 'an', 'and', 'or', 'but'])
    ->get();

// Language-specific stop words
User::search('der schnelle braune fuchs')
    ->ignoreStopWords('de')  // German stop words
    ->get();
```

Built-in lists cover eight locales — `en`, `de`, `fr`, `es`, `it`, `pt`, `nl`, `ru` — configured under `stop_words` in `config/fuzzy-search.php`. Any entry may instead be an absolute path to a text file, one word per line (`#` starts a comment, blank lines are ignored); a missing file throws `InvalidArgumentException` naming the path:

```php
// config/fuzzy-search.php
'stop_words' => [
    'en' => storage_path('fuzzy-search/stop-words-en.txt'),
],
```

`ignoreStopWords('xx')` reads `stop_words.{xx}` from config first, and only falls back to the builder's smaller built-in en/de/fr/es lists when that key isn't configured — pass an array (`ignoreStopWords([...])`) when you want a list that ignores config entirely. The bare `->ignoreStopWords()` shown above is unaffected by this change — it always uses the built-in English list regardless of config; call `->ignoreStopWords('en')` explicitly to get the configured list.

### Synonym Support

```php
User::search('laptop')
    ->withSynonyms([
        'laptop' => ['notebook', 'computer', 'macbook'],
        'phone' => ['mobile', 'cell', 'smartphone'],
    ])
    ->get();

// Or use synonym groups
User::search('laptop')
    ->synonymGroup(['laptop', 'notebook', 'computer'])
    ->get();
```

### Language / Locale Awareness

```php
User::search('john')
    ->locale('en')      // English
    ->get();

User::search('münchen')
    ->locale('de')      // German - handles umlauts
    ->get();
```

### Unicode & Accent Insensitivity

```php
// Matches "café", "cafe", "Café"
User::search('cafe')
    ->accentInsensitive()
    ->get();

// Matches "naïve", "naive"
User::search('naive')
    ->unicodeNormalize()
    ->get();
```

Search terms are handled per character, not per byte, so Bengali, Hindi, Thai and accented Latin work with every algorithm, and the BM25 tokenizer keeps combining marks (vowel signs, virama, tone marks) attached to their letters. If you indexed such text with a release before 2.1.0, rebuild once with `fuzzy-search:rebuild "App\Models\Product" --fresh`.

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

For large tables (10k+ rows), the BM25 inverted index provides ranked, fast results without scanning the full table.

### How It Works

The indexing system has two parts:

**Part 1 — One-time initial build.** Run once after install (or after a schema change):

```bash
php artisan fuzzy-search:rebuild "App\Models\User" --fresh
```

**Part 2 — Automatic incremental updates.** After the initial build, every time a model is saved or deleted, the package dispatches a small queue job that re-indexes just that one row. No cron jobs or manual work needed.

The flow when a record is saved:

```
User::create(['name' => 'John'])
  → Eloquent fires 'saved' event
  → SearchableIndexingObserver dispatches IndexModelJob to queue
  → queue worker indexes the row (3 SQL queries)
  → 'john' is now in the index
```

**Constraints are honoured on the index path.** Filters, `where()` constraints applied before the search, and global scopes are checked against the ranking in chunks (`bm25.candidate_chunk`, default 200) until the page is full, and `paginate()` totals reflect them:

```php
Product::search('watch')
    ->useInvertedIndex()
    ->filter('published', true)
    ->paginate(20);   // total = published matches only, pages never come back short

// Equivalent — filter()/filterIn() still work, but constraints can also be chained
// straight onto the builder (see "Chaining Eloquent" above):
Product::search('watch')
    ->useInvertedIndex()
    ->where('published', true)
    ->paginate(20);
```

### Database Tables

| Table | Purpose |
| --- | --- |
| `fuzzy_index_terms` | Term dictionary: unique terms + document frequency (used for `didYouMean()`) |
| `fuzzy_index_postings` | Postings: term → model mapping with term frequency, one row per `(term, column)` |
| `fuzzy_index_meta` | BM25 normalization: total docs + avg document length per model |
| `fuzzy_index_documents` | Per-document length cache for O(1) BM25 scoring |

### Production Setup

**Step 1 — Run migrations:**

```bash
php artisan migrate
```

**Step 2 — Enable indexing in config:**

```php
// config/fuzzy-search.php
'indexing' => [
    'enabled'          => true,       // must be true or saves are never indexed
    'async'            => true,       // true = queued (recommended for production)
    'queue'            => 'indexing', // dedicated queue keeps indexing isolated
    'chunk_size'       => 500,
    'max_tokens_per_doc' => 5000,     // security cap: prevents index poisoning
],
```

**Step 3 — Declare searchable columns on your model:**

```php
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

class User extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'name'  => 10,
            'email' => 5,
            'bio'   => 2,
        ],
    ];
}
```

**Step 4 — Build the initial index:**

```bash
# For small tables (< 50k rows)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh

# For large tables (50k+ rows), dispatch batch queue jobs
php artisan fuzzy-search:rebuild "App\Models\User" --fresh --async --queue=indexing
```

**Step 5 — Start a queue worker:**

```bash
# Development
php artisan queue:work --queue=indexing,default

# Production (Supervisor)
```

Supervisor config (`/etc/supervisor/conf.d/fuzzy-search-worker.conf`):

```ini
[program:fuzzy-search-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work database --queue=indexing,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/fuzzy-search-worker.log
```

For **Laravel Horizon** (Redis):

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue'      => ['indexing', 'default'],
            'balance'    => 'auto',
            'processes'  => 4,
        ],
    ],
],
```

### Usage

```php
// BM25 search — faster + better relevance on large tables
$users = User::search('john')->useInvertedIndex()->get();

// didYouMean() reads from the term dictionary — O(1) at any dataset size
$suggestions = User::search('jonh')->searchIn(['name'])->didYouMean(3);
```

> **Note:** `useIndex()` is an alias for `useInvertedIndex()`. The deprecated legacy `search_index` table from v1 is no longer used.

> **Primary keys:** Integer, UUID and ULID primary keys are supported (`model_id` is stored as a 36-character string).

### Column weights on the index (BM25F-lite)

`searchIn()` weights (and `$searchable['columns']` weights) now scale ranking on `useInvertedIndex()` too, not just the LIKE/Levenshtein paths:

```php
Product::search('watch')
    ->useInvertedIndex()
    ->searchIn(['title' => 10, 'description' => 1])
    ->get();
```

- Each column's term frequency is scaled by its weight and summed per document and term before BM25 saturation runs once (BM25F-lite) — heavier columns win ties and near-ties, but a 10:1 weight does not multiply the final score by 10.
- A weight of `0` removes that column from scoring entirely. Weights are integers `>= 1`: a fractional weight is truncated to an integer (`0.5` becomes `0`, i.e. removed).
- The list form `searchIn(['title'])` sets that column to weight **1** and leaves the model's other weights in place — pass explicit weights (`searchIn(['title' => 10, 'description' => 1])`) when you mean to re-rank.
- Hook models (`searchableText()`) are weighted by the hook's returned keys when a key matches a searchable column name; any other key weighs 1.
- Postings are stored per `(term, column)`, but the scorer sums them in SQL first: `bm25.max_postings_per_term` is one shared cap over the query's matched terms applied to `(document, term)` rows ordered by weighted frequency (highest first), so a document is never partially cut across its columns — raise the cap if your corpus reaches it.
- Requires `php artisan migrate` and `php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh` per model — rows indexed before this feature rank at weight 1 until rebuilt, and `php artisan fuzzy-search:status` lists them.

### Typo tolerance, as-you-type, synonyms and stop words on the index

The inverted index expands your query through its own term dictionary, so it no longer needs an exact token to match:

```php
User::search('jonh')->useInvertedIndex()->get();                 // finds "john" (typoTolerance() = 2 by default)
User::search('jonh')->useInvertedIndex()->typoTolerance(0)->get(); // exact terms only
User::search('joh')->useInvertedIndex()->asYouType()->get();      // last token is a prefix: john, johnny …
User::search('laptop')->useInvertedIndex()->withSynonyms(['laptop' => ['notebook']])->get();
User::search('the pro')->useInvertedIndex()->ignoreStopWords(['pro'])->get(); // added to the configured list for this query
```

- Each query term of at least `typo_tolerance.min_word_length` characters is expanded with up to `bm25.fuzzy.max_expansions` dictionary terms within `typoTolerance()` edits, closest first. With `bm25.fuzzy.damping` (default on) an expansion contributes `1 − distance / length` of what the exact term would, so it always counts for less — but it is not outranked automatically: BM25 weighs rarity (idf), so a rare expansion can still outscore a common exact term. `typo_tolerance.enabled = false` turns expansion off globally.
- Expansions are picked from the `bm25.fuzzy.candidate_pool` (default 500) most common dictionary terms of a similar length — a term outside that window is **never** reached, however close it is. Rare surnames, SKUs and part numbers live in that tail, so raise the pool for such catalogs.
- `asYouType()` (or `$searchable['as_you_type' => true]`) expands the **last** token by prefix, capped at `bm25.prefix.max_expansions`.
- Synonyms score at full weight (they are alternatives, not typos). On the inverted index `ignoreStopWords()` **adds** its list to the configured locale list for that query — a term dropped at index time cannot match anyway, so a configured stop word cannot be restored, and `ignoreStopWords([])` cannot bring one back. On the LIKE path it still replaces the configured list.
- `highlight()` marks every term that matched, including expansions. `getDebugInfo()['index_terms']` lists the weighted terms that ran.
- The Scout engine keeps exact-term matching; use the builder for typo-tolerant index searches.
- Upgrading from v2.0: the dictionary gained a `term_length` column — run `php artisan migrate` (existing rows are backfilled).

### Artisan Commands

```bash
# Show index statistics (total docs, tokens, avg length per model) and list postings that predate column weighting
php artisan fuzzy-search:status

# Rebuild synchronously (good for < 50k rows)
php artisan fuzzy-search:rebuild "App\Models\User"
php artisan fuzzy-search:rebuild "App\Models\User" --fresh

# Rebuild asynchronously via queue (recommended for large tables)
php artisan fuzzy-search:rebuild "App\Models\User" --async
php artisan fuzzy-search:rebuild "App\Models\User" --fresh --async --queue=indexing

# Delete all index entries for a model
php artisan fuzzy-search:flush "App\Models\User"
```

Rebuilds load rows through the model's optional `searchIndexQuery()` hook (see *Searchable fields backed by accessors*), so relation-backed columns can be eager-loaded instead of queried once per row.

### BM25 Tuning

```php
// config/fuzzy-search.php
'bm25' => [
    'k1' => 1.5,   // Term-frequency saturation (1.2–2.0). Higher = more weight to repeated terms.
    'b'  => 0.75,  // Length normalisation (0–1). 0 = ignore doc length. 1 = full normalisation.
    'fuzzy' => [
        'candidate_pool' => 500,  // Dictionary terms (most common first) considered per query term for typo expansion.
        'max_expansions' => 5,    // Max dictionary terms added per query term within typoTolerance() edits.
        'damping'        => true, // An expansion contributes 1 - distance/length of what the exact term would.
    ],
    'prefix' => [
        'max_expansions' => 10,   // Max dictionary terms added by asYouType() for the last token's prefix.
    ],
],
```

### Tokenizers

The index splits each column's text into tokens before storing it. The default, `WhitespaceTokenizer`, splits on anything that isn't a letter, mark or digit and drops single-character tokens — it works for Latin, Cyrillic, Greek, Bengali, Hindi, Thai and every other script that separates words with spaces.

Chinese, Japanese and Korean don't use spaces between words, so `WhitespaceTokenizer` keeps a whole CJK run as one token — searching for part of it won't match. Two opt-in tokenizers cut character n-grams instead:

- **`NgramTokenizer(int $n = 2)`** — cuts every run of letters/marks/digits into `n`-character windows (a run of `n` characters or fewer is kept whole). It n-grams *everything*, Latin included — pick it only when a column is entirely CJK.
- **`ScriptAwareTokenizer(int $n = 2)`** — n-grams only the Han/Hiragana/Katakana/Hangul runs and applies the whitespace rule to everything else, so mixed text tokenizes correctly in one pass:

  ```php
  (new \Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer())->tokenize('Tokyo 東京 tower');
  // ['tokyo', '東京', 'tower'] — WhitespaceTokenizer would keep a longer CJK run
  // (e.g. "東京都心タワー") as a single token instead of overlapping bigrams.
  ```

  Pick this whenever a column can contain both CJK and non-CJK text.

Both tokenizers cut windows per Unicode code point, not per grapheme: on scripts that write accents as combining marks (decomposed Latin, Vietnamese, Indic, Thai) a window can separate a base letter from its mark. `ScriptAwareTokenizer` sidesteps this outside CJK runs by falling back to the whitespace rule, which keeps marks attached.

Enable a tokenizer globally, or per model (see **Per-Model Pipelines** below):

```php
// config/fuzzy-search.php
'indexing' => [
    'tokenizer' => \Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer::class,
],
```

Either way, rebuild after changing it — existing postings were tokenized the old way:

```bash
php artisan fuzzy-search:rebuild "App\Models\Product" --fresh
```

On the index path, `highlight()` marks whatever the query actually matched (see *Typo tolerance, as-you-type, synonyms and stop words on the index* above); for a CJK term tokenized into n-grams, that is the matching n-gram fragment, which may be shorter than the whole word.

### Per-Model Pipelines

Every model shares the global `indexing.*` config by default. Override the tokenizer, stemmer (and its language) or stop-word locale for one model with `$searchable`:

```php
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer;

class Product extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns'          => ['name' => 10, 'description' => 5],
        'tokenizer'        => ScriptAwareTokenizer::class,
        'stemmer'          => PorterStemmer::class,
        'stemmer_language' => 'French',
        'locale'           => 'fr',
    ];
}
```

Any key you omit falls back to the global config. `stemmer_language` is passed to the stemmer's constructor (`new PorterStemmer('French')`) — see "Stemming (Optional)" below for the full list of Snowball languages. It only means something to a stemmer whose constructor accepts one; naming it on a stemmer that takes none (`NullStemmer`) throws `InvalidArgumentException` instead of silently ignoring it. `locale` picks the stop-word list from `config('fuzzy-search.stop_words')` for this model's index pipeline — it's independent of the query builder's `->locale()`.

The resolved pipeline is cached per model class for the lifetime of the request/worker; nothing in a running process needs to call `IndexManager::resetPipelineCache()` yourself unless you swap `$searchable` at runtime (tests that do this between cases should call it). Query-time processing — typo expansion and `suggest()` — follows the same per-model pipeline automatically, and the Scout engine passes its model too. `didYouMean()` is a separate, unscoped lookup: it queries the whole cross-model dictionary on the raw search term without running it through any model's tokenizer, stemmer or stop-word list. Rebuild after changing any of these keys, same as the global tokenizer/stemmer:

```bash
php artisan fuzzy-search:rebuild "App\Models\Product" --fresh
```

### Accent Folding on the Index

Off by default. Turn it on to fold accents at both index time and query time on the BM25 path, so `café` and `cafe` land in the same dictionary term:

```php
// config/fuzzy-search.php
'indexing' => [
    'accent_insensitive' => true,
],
```

With `ext-intl` installed, folding decomposes the string (`Normalizer::FORM_D`), strips the combining-diacritical-mark blocks, then recomposes (`FORM_C`) — that handles most accented Latin/Greek/Cyrillic — before a built-in map runs for characters a decomposition doesn't cover (`ß` → `ss`, `ø` → `o`). Without `ext-intl`, only the map runs, which is the same coverage v2.0 had. This is a global setting — it applies to every model's index, there's no per-model override.

Rebuild after flipping it, same as the tokenizer and stemmer:

```bash
php artisan fuzzy-search:rebuild "App\Models\Product" --fresh
```

The LIKE path's `->accentInsensitive()` (see *Unicode & Accent Insensitivity* above) folds with the exact same `Accents::fold()`, so turning both on gives one consistent behaviour across paths. `suggest()` also folds the typed prefix, but only when the model's index pipeline itself folds.

### Stemming (Optional)

Default: no stemming (`NullStemmer`). With `NullStemmer`, `running` only matches `running`, not `run` or `ran`.

To enable Porter stemming:

```bash
composer require wamania/php-stemmer
```

```php
// config/fuzzy-search.php
'indexing' => [
    'stemmer' => \Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer::class,
],
```

Supported languages: English, French, German, Spanish, Italian, Russian, Romanian, Dutch, Portuguese, Swedish, Danish, Norwegian. You must rebuild the index after changing the stemmer.

### Observer Auto-Attach

Adding the `Searchable` trait automatically registers observers via `bootSearchable()`:

- **`SearchableIndexingObserver`** — listens to `saved` and `deleted` events. Queues an `IndexModelJob` to update the BM25 index. This is a no-op when `indexing.enabled` is `false`.
- **`SearchableObserver`** — listens to `saved` events and writes metaphone shadow columns if they exist. Safe when no shadow columns are configured — the observer silently exits.

No configuration is required for either observer until you enable those features.

#### Searchable fields backed by accessors

A searchable column does not have to be a real column. An accessor that reads a relation works too, but the observer cannot see it change, so declare the real columns that should trigger a reindex:

```php
use Illuminate\Database\Eloquent\Builder;

class Product extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns'    => ['name' => 10, 'brand_name' => 5],
        'reindex_on' => ['brand_id'],   // real columns that invalidate brand_name
    ];

    public function getBrandNameAttribute(): ?string
    {
        return $this->brand?->name;
    }

    // Optional: the query fuzzy-search:rebuild loads rows through — eager-load here
    public function searchIndexQuery(Builder $query): Builder
    {
        return $query->with('brand');
    }
}
```

Without `reindex_on`, a model with an accessor-backed column is reindexed on **every** save (correct, but wasteful on hot paths such as stock updates). With it, only changes to the searchable columns or the listed triggers reindex. Indexing always reloads the row from the database first, so a relation that was already loaded on the instance before the change is never written to the index.

Changing the *related* row (renaming the brand) does not reindex the products that reference it — handle that with an observer on the related model or a `fuzzy-search:rebuild`.

### Sync vs Async

| | `async = true` (default) | `async = false` |
| --- | --- | --- |
| **How it works** | Dispatches `IndexModelJob` to queue | Indexes in the same request, no queue |
| **Request latency** | Unaffected | +~10ms per save |
| **Requires queue worker** | Yes | No |
| **Best for** | Production apps | Tests, local dev, low-traffic apps |

For **tests**, set `indexing.async = false` so indexing happens synchronously:

```php
// In your test setUp
config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
```

---

## Extended Search Syntax

Use Fuse.js-style operators inside your search string for precise control over matching.

### Operators

| Token | Meaning | Example |
| --- | --- | --- |
| `word` | Substring match (default) | `john` |
| `'word` | Explicit substring include | `'admin` |
| `=word` | Exact equality | `=John` |
| `^word` | Prefix match | `^Doe` |
| `word$` | Suffix match | `Sr$` |
| `!word` | Exclude (NOT) | `!banned` |
| `!^word` | Inverse prefix | `!^test` |
| `!word$` | Inverse suffix | `!@spam.com$` |
| `\|` | OR | `john \| jane` |
| ` ` (whitespace) | AND (implicit) | `=John ^Doe` |
| `( ... )` | Grouping | `admin (john \| jane)` |
| `"phrase"` | Quoted single token | `"hello world"` |
| `~word` | Typo-tolerant match (uses typoTolerance()) | `~jonh` |
| `field:word` | Limit a term to one column (any operator after the colon) | `email:^admin`, `author.name:smith`, `!name:bob` |

### Typo-tolerant and field-scoped terms

`~word` runs the term through the same typo-tolerant matching as the rest of the package — the level set by `->typoTolerance()` (default 2), or a plain substring when the level is `0` or `config('fuzzy-search.typo_tolerance.enabled')` is `false`. `~` can't combine with `'`, `=`, `^`, a quoted phrase, or a trailing `$`; `~word` stands on its own (a field scope in front is fine — `name:~jonh`).

`field:word` limits a term to one searchable column: a direct column, a table-qualified column matched by its bare name (`users.name` answers to `name:`), or a relation column declared in `searchIn()` / `$searchable['columns']` (`author.name:smith`). Any operator can follow the colon — `email:^admin`, `name:~jonh`, `!name:bob`, `name:"john doe"`. An unknown field throws `QuerySyntaxException` listing the searchable fields (by their bare names); `field:` with nothing after the colon throws too, and so does a bare name that matches two searchable columns (`users.name` and `profiles.name`) — qualify it, `users.name:john`.

Both operators are only recognised at the start of a token (after an optional `!`), so `12:30` and `jo~hn` stay literal — and so does a quoted phrase. Quote a token of the form `word:…`, or one starting with `~`, to keep it literal (`"name:john"`, `"~x"`). The everyday casualties are URLs and mail addresses at the start of a token (`http://example.com`, `mailto:bob@example.com` — `http:` and `mailto:` are read as field scopes) and `Re:` / `Fwd:` subject prefixes (nothing follows the colon, so they throw); quote them — `"http://example.com"`, `"mailto:bob@example.com"`, `"Re:" meeting`.

The extended syntax always runs on the LIKE path; `->useInvertedIndex()` is ignored for it — `getDebugInfo()` reports `index_ignored => true` when both are set.

### Usage

```php
// Exact first name + prefix last name + exclude banned
$users = User::search('=John ^Doe !banned')->extended()->get();

// OR semantics with grouping
$users = User::search('admin (john | jane)')->extended()->get();

// More examples
'Sr$ | Jr$'              // Names ending in Sr OR Jr
"'manager !@temp.com$"   // Substring 'manager' but not @temp.com emails
```

### Limits

| Limit | Default | Config key |
| --- | --- | --- |
| Maximum tokens per query | 32 | `query.max_tokens` |
| Maximum nesting depth | 16 | `query.max_depth` |
| Maximum characters per term | 128 | `query.max_term_length` |

`query.max_term_length` applies to the LIKE path and to every extended-syntax token: a longer
term is silently truncated before the driver generates its LIKE patterns.

### Pagination with Extended Syntax

`paginate()`, `simplePaginate()` and `get()` all work with `extended()` / `searchBoolean()`. `cursorPaginate()` is still unsupported.

```php
// ✓ Works
User::search('=John ^Doe')->extended()->paginate(15);
User::search('=John ^Doe')->extended()->simplePaginate(15);
User::search('=John ^Doe')->extended()->get();

// ✗ Throws BadMethodCallException
User::search('=John ^Doe')->extended()->cursorPaginate(15);
```

### Match Offsets & Blade Directive

Results with `->highlight()` enabled include a `_matches` array:

```php
$first = $results->first();
$first->_matches;
// [['column' => 'name', 'value' => 'John Doe', 'indices' => [[0, 3]]]]
```

For safe HTML rendering, use the `@fuzzyHighlight` Blade directive:

```blade
@fuzzyHighlight($user, 'name')
```

The directive automatically escapes user-supplied content and wraps matches in `<mark>` tags.

---

## Scout Driver

The Scout engine adapter is bundled in this package and registers automatically when `laravel/scout` is installed. No separate driver package is required.

### Setup

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

The Scout engine wraps the same `IndexManager` + `Bm25Scorer` used by `Model::search()->useInvertedIndex()`. There is no separate index — it reads from the same `fuzzy_index_*` tables.

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

// BM25 has no typo tolerance yet — give it a safety net:
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

Opt-in, DB-backed search analytics: every `FuzzySearchExecuted` event can be written to a `fuzzy_search_logs` table for later reporting, instead of (or alongside) the live event listener above.

```php
// config/fuzzy-search.php
'analytics' => [
    'enabled'        => false,              // off by default — enable deliberately
    'queue'          => null,               // null = insert inline; a queue name dispatches RecordSearchLogJob there instead
    'sample_rate'    => 1.0,                // 0.0–1.0 share of searches recorded
    'retention_days' => 30,                 // what `fuzzy-search:analytics:prune` deletes beyond
    'hash_terms'     => false,              // true stores only a keyed SHA-256 (HMAC with APP_KEY), never the term itself
    'table'          => 'fuzzy_search_logs',
],
```

Run `php artisan migrate` to create the table — it has no effect until `analytics.enabled` is `true`.

Each row holds: `term` (the raw search term, or `''` when `hash_terms` is on), `normalized_term` (lower-cased, whitespace-collapsed — or its keyed SHA-256 when `hash_terms` is on), `model_type` (the Eloquent class searched, `null` for query-builder/in-memory searches), `algorithm`, `path` (`like`, `bm25`, `extended`, or `in_memory`), `result_count`, `latency_ms`, `day` (the date `created_at` falls on, used by `volume()`) and `created_at`.

### What counts as one row

One row per executed search **attempt**, which is not always one row per user query:

- `fallback()` writes one row per algorithm it tries — a query that misses on `fuzzy` and then matches on `soundex` is two rows (two `popular()` searches, and the miss's latency is mixed into `averageLatency()`).
- `FederatedSearch` writes one row per inner model — "laptop" across three models is three rows.
- Nothing is recorded for a `cache()` hit (the search never runs), for `count()` or `exists`-style calls (only `get()`, `paginate()` and `simplePaginate()` fire the event), for terms shorter than `min_search_length`, or for an in-memory search with an empty term or no `searchIn()` columns (on a single-model search; `FederatedSearch::simplePaginate()` records each inner model's own fetch).
- `simplePaginate($n)` records the page size, not the `$n + 1` rows it fetches to look ahead for a next page.

### Querying the log

```php
use Ashiqfardus\LaravelFuzzySearch\Facades\SearchAnalytics;

SearchAnalytics::popular(7, 5);
// [['term' => 'laptop', 'searches' => 42, 'avg_results' => 6.3], ...] — last 7 days, top 5

SearchAnalytics::zeroResults(7, 5);
// [['term' => 'asdfgh', 'searches' => 3], ...] — terms whose every search in the window returned nothing

SearchAnalytics::averageLatency(7);
// ['bm25' => 2.7, 'like' => 4.1] — average latency in ms, grouped by path

SearchAnalytics::volume(7);
// ['2026-09-11' => 120, '2026-09-12' => 98, ...] — searches per day, ascending

SearchAnalytics::prune(); // deletes rows older than analytics.retention_days, returns the number deleted
SearchAnalytics::prune(14); // or override the window explicitly
```

### Artisan commands

```bash
# Popular / zero-result / latency / volume report for the last 30 days
php artisan fuzzy-search:analytics

# Same report over a different window and row cap
php artisan fuzzy-search:analytics --days=7 --limit=5

# Only the zero-result table
php artisan fuzzy-search:analytics --zero-results

# Delete rows older than analytics.retention_days (or --days)
php artisan fuzzy-search:analytics:prune
php artisan fuzzy-search:analytics:prune --days=14
```

Schedule the prune so the log doesn't grow unbounded:

```php
// routes/console.php (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

Schedule::command('fuzzy-search:analytics:prune')->daily();
```

```php
// app/Console/Kernel.php::schedule() (Laravel 10, or an app that still has a console kernel)
$schedule->command('fuzzy-search:analytics:prune')->daily();
```

### Privacy

Search terms are user input — treat this table accordingly. Recording is **off by default**; you opt in per environment. The default `retention_days` is 30, enforced by running `fuzzy-search:analytics:prune` on a schedule (it isn't automatic). On high-traffic endpoints, `sample_rate` (`0.0`–`1.0`) records only a fraction of searches instead of every one.

Set `hash_terms` to `true` to store a **keyed SHA-256** (an HMAC with your `APP_KEY`) of the normalized term instead of the term itself, with `term` left empty. `popular()` and `zeroResults()` still group and count correctly, since two equal terms hash equally — it is a pseudonym, not an encryption. Because the digest is keyed, someone holding only the table cannot brute-force it by hashing guessed terms; conversely, rotating `APP_KEY` changes every future digest, so history splits at the rotation and terms recorded before and after it no longer group together. With `APP_KEY` unset the digest is unkeyed and offers no protection against guessing.

With `hash_terms` off and `analytics.queue` set, a job that exhausts its retries leaves the raw term in the serialized payload in `failed_jobs`, outside `prune()`'s reach — prune that table too (`php artisan queue:flush`) if retention matters.

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
        'driver' => 'redis',
        'ttl' => 3600,
    ],
    
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
| `ecommerce` | Product search | fuzzy | 1 | Partial match, no stop words |
| `users` | User/contact search | levenshtein | 2 | Accent-insensitive |
| `phonetic` | Name pronunciation | soundex | 0 | Phonetic matching |
| `exact` | SKUs, codes, IDs | simple | 0 | Partial match only |

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
| **BM25 index** | Fast at scale | Via LIKE fallback | Large tables, ranked results | 10K+ rows |

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
'performance' => [
    'max_candidates' => 500,  // fetch fewer candidates on large tables
],
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

| Algorithm | MySQL 8 | MariaDB 10.6 / 11.4 | PostgreSQL 14 | SQLite | SQL Server |
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
- Any supported database — MySQL 8+, MariaDB 10.6+ / 11.x, PostgreSQL 14+, SQLite, or SQL Server 2022

---

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Credits

- [Md Asikul Islam](https://github.com/ashiqfardus)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.
