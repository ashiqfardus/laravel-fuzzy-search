# Laravel Fuzzy Search

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![License](https://img.shields.io/packagist/l/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)

A powerful, **zero-config** fuzzy search package for Laravel with fluent API. Works with all major databases without external services.

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

// Option 2: Specify at query time (overrides $searchable)
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
| `soundex` | Phonetic matching | ✅ Phonetic | Fast |
| `trigram` | Similarity matching | ✅ High | Medium |
| `simple` | Exact substring | ❌ None | Fastest |

```php
// Use specific algorithm
User::search('john')->using('levenshtein')->get();
User::search('stephen')->using('soundex')->get();  // Finds "Steven"
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

### Search Index Table

```bash
# Create search index
php artisan fuzzy-search:index User

# Rebuild index
php artisan fuzzy-search:index User --fresh

# Index specific columns
php artisan fuzzy-search:index User --columns=name,email
```

```php
// Use indexed search (faster for large tables)
User::search('john')
    ->useIndex()
    ->get();
```

### Async Indexing (Queue Support)

```php
// In config/fuzzy-search.php
'indexing' => [
    'async' => true,
    'queue' => 'search-indexing',
    'chunk_size' => 500,
],

// Dispatch indexing job
User::reindex();  // Queued automatically
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
        'table' => 'search_index',
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
# Index a model
php artisan fuzzy-search:index User

# Index with fresh rebuild
php artisan fuzzy-search:index User --fresh

# Index all searchable models
php artisan fuzzy-search:index --all

# Clear index
php artisan fuzzy-search:clear User
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
    ->useIndex()
    ->cache(60)
    ->searchIn(['name', 'email'])  // Not all columns
    ->maxPatterns(50)
    ->get();
```

## Requirements

- PHP 8.0 or higher
- Laravel 9.x, 10.x, 11.x, or 12.x
- Any supported database

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Credits

- [Md Asikul Islam](https://github.com/ashiqfardus)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

