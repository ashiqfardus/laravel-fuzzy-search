# Laravel Fuzzy Search

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![License](https://img.shields.io/packagist/l/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![PHP Version](https://img.shields.io/packagist/php-v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Laravel Version](https://img.shields.io/badge/Laravel-9%2B%20|%2010%20|%2011%20|%2012%20|%2013-FF2D20?logo=laravel)](https://laravel.com)

A powerful, **zero-config** fuzzy search package for Laravel with fluent API. Works with all major databases without external services.

**Demo:** [laravel-fuzzy-search-demo](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) - See the package in action!

**Documentation:** [Installation](#installation) • [Quick Start](#quick-start) • [Algorithms](#search-algorithms) • [BM25 Index](#bm25-inverted-index) • [Extended Syntax](#extended-search-syntax) • [Scout Driver](#scout-driver) • [Performance](#performance--scaling) • [Compatibility](#algorithm--database-compatibility) • [Upgrade v1→v2](docs/UPGRADE_v1_TO_v2.md)

## Features

| Category | Features |
|----------|----------|
| **Core** | Zero-config search • Fluent API • Eloquent & Query Builder support |
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
| **Smart Search** | Autocomplete suggestions • "Did you mean" spell correction • Multi-model federation • Search analytics |

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
```

### Search Analytics

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

### Database Tables

| Table | Purpose |
| --- | --- |
| `fuzzy_index_terms` | Term dictionary: unique terms + document frequency (used for `didYouMean()`) |
| `fuzzy_index_postings` | Postings: term → model mapping with term frequency |
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

> **Limitation:** The BM25 inverted index requires integer primary keys. Models with UUID/ULID primary keys are not currently supported — use the standard LIKE or Levenshtein paths for those.

> **Column weights and BM25:** `searchIn()` weights are respected by the LIKE/Levenshtein scoring paths but are ignored by BM25. BM25 scores by term frequency and inverse document frequency only.

### Artisan Commands

```bash
# Show index statistics (total docs, tokens, avg length per model)
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

### BM25 Tuning

```php
// config/fuzzy-search.php
'bm25' => [
    'k1' => 1.5,   // Term-frequency saturation (1.2–2.0). Higher = more weight to repeated terms.
    'b'  => 0.75,  // Length normalisation (0–1). 0 = ignore doc length. 1 = full normalisation.
],
```

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

Supported languages: English, French, German, Spanish, Italian, Russian, Dutch, Portuguese, Swedish, Danish, Norwegian. You must rebuild the index after changing the stemmer.

### Observer Auto-Attach

Adding the `Searchable` trait automatically registers observers via `bootSearchable()`:

- **`SearchableIndexingObserver`** — listens to `saved` and `deleted` events. Queues an `IndexModelJob` to update the BM25 index. This is a no-op when `indexing.enabled` is `false`.
- **`SearchableObserver`** — listens to `saved` events and writes metaphone shadow columns if they exist. Safe when no shadow columns are configured — the observer silently exits.

No configuration is required for either observer until you enable those features.

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

### Pagination with Extended Syntax

`paginate()` and `cursorPaginate()` are **not compatible** with `extended()` or `searchBoolean()` and will throw a `BadMethodCallException`. `simplePaginate()` works correctly.

```php
// ✓ Works
User::search('=John ^Doe')->extended()->simplePaginate(15);
User::search('=John ^Doe')->extended()->get();

// ✗ Throws BadMethodCallException
User::search('=John ^Doe')->extended()->paginate(15);
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

Add both traits to your model:

```php
use Laravel\Scout\Searchable;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;

class User extends Model
{
    use Searchable, FuzzySearchable {
        // FuzzySearchable::search() takes precedence — returns the fluent SearchBuilder.
        // Scout's underlying engine (FuzzySearchEngine) is still used when SCOUT_DRIVER=fuzzy-search.
        FuzzySearchable::search insteadof Searchable;
        Searchable::search as scoutSearch;
    }

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}

$users = User::search('john')->get();
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

// Simple pagination (no total count - faster)
$users = User::search('john')->simplePaginate(15);

// Cursor pagination (best for infinite scroll)
$users = User::search('john')->cursorPaginate(15);

// Manual pagination
$users = User::search('john')
    ->take(10)
    ->skip(20)
    ->get();
```

> **Note:** `paginate()` and `cursorPaginate()` are not compatible with `extended()` or `searchBoolean()`. Use `simplePaginate()` or `get()` with those.

---

## Reliability & Safety

### Fallback Search Strategy

```php
// Automatically falls back to simpler algorithm if primary fails
User::search('john')
    ->using('trigram')
    ->fallback('fuzzy')      // First fallback
    ->fallback('simple')     // Second fallback
    ->get();
```

### Rate-Limit Friendliness

```php
// Built-in debouncing for real-time search
User::search($query)
    ->debounce(300)  // 300ms debounce
    ->get();

// Query complexity limits
User::search($query)
    ->maxPatterns(50)  // Limit pattern generation
    ->get();
```

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

Fired after every `->get()` or `->paginate()` call. Useful for monitoring search latency and volume in production.

```php
use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;

Event::listen(FuzzySearchExecuted::class, function ($event) {
    Log::info('search', [
        'term'      => $event->searchTerm,
        'columns'   => $event->columns,
        'algorithm' => $event->algorithm,
        'count'     => $event->candidateCount,
        'ms'        => $event->latencyMs,
    ]);
});
```

Properties:

- `searchTerm` (string) — the user's query
- `columns` (array) — columns being searched
- `algorithm` (string) — algorithm used: `simple`, `fuzzy`, `levenshtein`, `soundex`, `metaphone`, `trigram`, `similar_text`, or `bm25`
- `candidateCount` (int) — rows fetched from SQL before scoring
- `latencyMs` (float) — total search time in milliseconds

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
        'prefix_match' => 50,
        'contains' => 25,
        'fuzzy_match' => 10,
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
        'chunk_size' => 1000,
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

# Show index status (row counts, avg doc length, term count per model)
php artisan fuzzy-search:status
```

### Benchmark & Debug Commands

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

**At scale:** The BM25 path uses an indexed term lookup — query time grows with the number of matching postings, not total row count. A well-maintained 1M-row index returns results in the same ~12–20 ms window as the 100k baseline.

### When to Use BM25 vs LIKE

**Use BM25 (`useInvertedIndex()`) when:**
- Table has 10k+ rows
- Result ranking/relevance quality matters
- You have queue workers running
- Models use integer primary keys

**Use LIKE / fuzzy when:**
- Small tables (< 10k rows) — LIKE can be faster due to BM25 scoring overhead
- UUID/ULID primary keys
- You need column weights to affect ranking

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

| Algorithm | MySQL 8 | MariaDB 10.6 | PostgreSQL 14 | SQLite | SQL Server |
|---|---|---|---|---|---|
| **simple** / **like** | `LIKE '%term%'` | `LIKE '%term%'` | `ILIKE '%term%'` | `LIKE '%term%'` | `LOWER() LIKE` |
| **fuzzy** | LIKE pattern set (typo patterns, transpositions) | LIKE pattern set | ILIKE pattern set | LIKE pattern set | LIKE pattern set |
| **levenshtein** | Native `LEVENSHTEIN()` UDF if `use_native_functions=true`, else pattern set | Pattern set | `similarity()` via pg_trgm if `use_native_functions=true`, else pattern set | Pattern set | Pattern set |
| **trigram** | LIKE pattern set | LIKE pattern set | Native `similarity()` via pg_trgm if `use_native_functions=true` | LIKE pattern set | LIKE pattern set |
| **soundex** | Native `SOUNDEX()` — always on, applied to first or last word | Native `SOUNDEX()` — always on | Native `SOUNDEX()` via `fuzzystrmatch` if `use_native_functions=true`, else pattern fallback | Pattern fallback | Pattern fallback |
| **metaphone** | Shadow column `{col}_metaphone` + exact `=` match | Shadow column | Shadow column | Shadow column | Shadow column |
| **similar_text** | `LIKE '%term%'` (SQL); `similar_text()` scores in PHP after fetch | Same | `ILIKE '%term%'`; PHP scores | Same | Same |

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

> **Pagination note:** `paginate()` and `simplePaginate()` use DB-level pagination and score within the current page only. For globally-ranked pagination across all pages, use the BM25 inverted index.

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
- Laravel 9.x, 10.x, 11.x, 12.x, or 13.x
- Any supported database

---

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Credits

- [Md Asikul Islam](https://github.com/ashiqfardus)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.
