# Laravel Fuzzy Search

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![License](https://img.shields.io/packagist/l/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![PHP Version](https://img.shields.io/packagist/php-v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Laravel Version](https://img.shields.io/badge/Laravel-9%2B%20|%2010%20|%2011%20|%2012%20|%2013-FF2D20?logo=laravel)](https://laravel.com)

A powerful, **zero-config** fuzzy search package for Laravel with fluent API. Works with all major databases without external services.

**🚀 Demo:** [laravel-fuzzy-search-demo](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) - See the package in action!

**📚 Documentation:** [Getting Started](docs/GETTING_STARTED.md) • [Capability Matrix](docs/CAPABILITY_MATRIX.md) • [Inverted Index](docs/INVERTED_INDEX.md) • [Scout Driver](docs/SCOUT_DRIVER.md) • [Extended Search](docs/EXTENDED_SEARCH.md) • [Query Language](docs/QUERY_LANGUAGE.md) • [Upgrade v1→v2](docs/UPGRADE_v1_TO_v2.md)

## ✨ Features

| Category | Features |
|----------|----------|
| **Core** | Zero-config search • Fluent API • Eloquent & Query Builder support |
| **Algorithms** | Multiple fuzzy algorithms • Typo tolerance • Multi-word token search |
| **Scoring** | Field weighting • Relevance scoring • Prefix boosting • Partial match • Recency boost |
| **Text Processing** | Stop-word filtering • Synonym support • Language/locale awareness |
| **Internationalization** | Unicode support • Accent insensitivity • Multi-language |
| **Results** | Highlighted results • Custom scoring hooks • Debug/explain-score mode |
| **Performance** | Search index table • Async indexing (queue) • Redis/cache support |
| **Pagination** | Stable ranking • Cursor pagination • Offset pagination |
| **Reliability** | Fallback search strategy • DB-agnostic • Rate-limit friendly • SQL-injection safe |
| **Configuration** | Config file support • Per-model customization |
| **Developer Tools** | CLI indexing • Benchmark tools • Built-in test suite • Performance utilities |
| **Smart Search** | Autocomplete suggestions • "Did you mean" spell correction • Multi-model federation • Search analytics |

## Installation

```bash
composer require ashiqfardus/laravel-fuzzy-search
```

**That's it!** Zero configuration required. Start searching immediately.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=fuzzy-search-config
```

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

## Search Algorithms

### Available Algorithms

| Algorithm | Best For | Typo Tolerance | Speed |
|-----------|----------|----------------|-------|
| `fuzzy` | General purpose | ✅ High | Fast |
| `levenshtein` | Strict typo matching | ✅ Configurable | Medium |
| `soundex` | Phonetic matching (English names) | ✅ Phonetic | Fast |
| `metaphone` | Phonetic matching (more accurate) | ✅ Phonetic | Fast |
| `trigram` | Similarity matching | ✅ High | Medium |
| `similar_text` | Percentage similarity | ✅ Medium | Medium |
| `simple` / `like` | Exact substring (LIKE) | ❌ None | Fastest |

```php
// Use specific algorithm
User::search('john')->using('levenshtein')->get();
User::search('stephen')->using('soundex')->get();  // Finds "Steven"
User::search('stephen')->using('metaphone')->get(); // More accurate phonetic
User::search('laptop')->using('similar_text')->get(); // Percentage match
```

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

### BM25 Inverted Index (v2+)

For large tables, the inverted index provides BM25-ranked results:

```bash
php artisan migrate
php artisan fuzzy-search:rebuild "App\Models\User"
```

```php
// BM25 search — faster + more relevant ranking on 10k+ rows
$users = User::search('john')->useInvertedIndex()->get();

// Scout driver — bundled, no separate package
// Set SCOUT_DRIVER=fuzzy-search in .env
$users = User::search('john')->get(); // via Scout
```

See [Inverted Index](docs/INVERTED_INDEX.md) and [Scout Driver](docs/SCOUT_DRIVER.md) docs.

### Extended Search Syntax (v2+)

Fuse.js-style operators for precise queries:

```php
$users = User::search('=John ^Doe !banned')->extended()->get();
$users = User::search('admin (john | jane)')->extended()->get();
```

See [Extended Search docs](docs/EXTENDED_SEARCH.md) for the operator reference.

### In-Memory Mode (v2+)

```php
use Ashiqfardus\LaravelFuzzySearch\Facades\FuzzySearch;

$matches = FuzzySearch::on($staticArray)->search('term')->searchIn(['name'])->get();
```

> **Supported methods:** `search`, `searchIn`, `take`, `skip`, `withRelevance`, `get`.
> Any other `SearchBuilder` method (e.g. `extended()`, `using()`, `preset()`, `paginate()`) will throw a `\BadMethodCallException` to prevent silent failures.

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

## Performance & Indexing

### BM25 Inverted Index

For tables with 10k+ rows, build the BM25 index for ranked, fast results:

```bash
php artisan migrate
php artisan fuzzy-search:rebuild "App\Models\User"
```

```php
// Use BM25 indexed search (faster + ranked for large tables)
User::search('john')
    ->useInvertedIndex()
    ->get();
```

> **Note:** `useIndex()` is an alias for `useInvertedIndex()` — both query the BM25 `fuzzy_index_*` tables. The deprecated legacy `search_index` table from v1 is no longer used.

See [Inverted Index](docs/INVERTED_INDEX.md) for production setup (queue workers, auto-indexing via observer, Horizon config).

### Async Indexing (Queue Support)

```php
// In config/fuzzy-search.php
'indexing' => [
    'async' => true,
    'queue' => 'search-indexing',
    'chunk_size' => 500,
],

// Re-index a single model (dispatches IndexModelJob to queue)
// use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
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

> **Note:** `paginate()`, `simplePaginate()`, and `cursorPaginate()` are **not compatible** with `extended()` or `searchBoolean()`. Combining them throws a `BadMethodCallException`. Use `get()` when extended syntax is active. See [Extended Search](docs/EXTENDED_SEARCH.md#pagination) for details.

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

### Database Compatibility

| Database | Full Support | Native Functions |
|----------|--------------|------------------|
| MySQL 5.7+ | ✅ | SOUNDEX |
| PostgreSQL 9.6+ | ✅ | pg_trgm, fuzzystrmatch |
| SQLite 3.x | ✅ | Pattern-based |
| SQL Server 2016+ | ✅ | Pattern-based |
| MariaDB 10.2+ | ✅ | SOUNDEX |

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

The package provides custom exceptions for better error handling:

```php
use Ashiqfardus\LaravelFuzzySearch\Exceptions\LaravelFuzzySearchException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidAlgorithmException;

// Catch all fuzzy search exceptions
try {
    $results = User::search($term)->get();
} catch (LaravelFuzzySearchException $e) {
    // Handle any fuzzy search error
    Log::error('Search failed', $e->toArray());
}

// Catch specific exceptions
try {
    $results = User::search('')->get();  // Empty search
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

## Events

### `FuzzySearchExecuted`

Fired after every `->get()` or `->paginate()` call. Useful for monitoring search latency and volume in production.

```php
use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;

Event::listen(FuzzySearchExecuted::class, function ($event) {
    Log::info('search', [
        'term'      => $event->searchTerm,
        'columns'   => $event->columns,
        'algorithm' => $event->algorithm,    // 'fuzzy', 'levenshtein', 'bm25', etc.
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

#### Why Use Presets?

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
// Use a preset
User::search('john')->preset('users')->get();
Post::search('laravel')->preset('blog')->get();
Product::search('laptop')->preset('ecommerce')->get();

// Phonetic search for names
Contact::search('steven')->preset('phonetic')->get();  // Finds "Stephen"

// Exact search for SKUs
Product::search('SKU-12345')->preset('exact')->get();
```

#### Override Preset Settings

Presets can be combined with other methods - later calls override preset values:

```php
// Use blog preset but with higher typo tolerance
Post::search('laravel')
    ->preset('blog')
    ->typoTolerance(3)  // Override preset's default of 2
    ->get();

// Use ecommerce preset but search different columns
Product::search('laptop')
    ->preset('ecommerce')
    ->searchIn(['name' => 10, 'category' => 5])  // Override columns
    ->get();
```

#### Create Custom Presets

Add your own presets in `config/fuzzy-search.php`:

```php
'presets' => [
    // ... existing presets ...
    
    'documents' => [
        'columns' => ['title' => 10, 'content' => 8, 'tags' => 5],
        'algorithm' => 'trigram',
        'typo_tolerance' => 2,
        'stop_words_enabled' => true,
        'locale' => 'en',
    ],
    
    'multilingual' => [
        'columns' => ['title' => 10, 'body' => 5],
        'algorithm' => 'fuzzy',
        'accent_insensitive' => true,
        'unicode_normalize' => true,
    ],
],
```

Then use your custom preset:
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
        // Boost featured products
        return $this->is_featured ? $baseScore * 1.5 : $baseScore;
    }
}
```

## CLI Tools

### Indexing Commands

```bash
# Build / rebuild BM25 index for a model
php artisan fuzzy-search:rebuild "App\Models\User"

# Rebuild with fresh index (flush first)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh

# Clear BM25 index for a model
php artisan fuzzy-search:clear "App\Models\User"

# Clear BM25 index for all models
php artisan fuzzy-search:clear --all
```

### Benchmark Tools

```bash
# Benchmark search performance
php artisan fuzzy-search:benchmark User --term="john" --iterations=100

# Output:
# Algorithm: fuzzy
# Average time: 12.5ms
# Min: 8ms, Max: 25ms
# Queries/second: 80
# Memory usage: 2.1MB
```

### Debug Commands

```bash
# Explain a search query
php artisan fuzzy-search:explain User --term="john"

# Output query analysis, patterns generated, scoring breakdown
```

## Testing

```bash
# Run tests
composer test

# Run with coverage
composer test-coverage

# Run benchmarks
composer benchmark
```

## Performance Tips

1. **Use Indexing** for tables with 10k+ rows
2. **Enable Caching** for repeated searches
3. **Limit Columns** - only search relevant fields
4. **Use Simple Algorithm** when typo tolerance isn't needed
5. **Set Max Patterns** to prevent query explosion

```php
User::search('john')
    ->useInvertedIndex()
    ->cache(60)
    ->searchIn(['name', 'email'])  // Not all columns
    ->maxPatterns(50)
    ->get();
```

## Requirements

- PHP 8.1 or higher
- Laravel 9.x, 10.x, 11.x, 12.x, or 13.x
- Any supported database

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Credits

- [Md Asikul Islam](https://github.com/ashiqfardus)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

