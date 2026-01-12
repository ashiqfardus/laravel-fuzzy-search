# Performance Guide

Learn how to optimize Laravel Fuzzy Search for maximum performance.

## Table of Contents

- [Algorithm Performance Comparison](#algorithm-performance-comparison)
- [Optimization Strategies](#optimization-strategies)
- [Benchmarking](#benchmarking)
- [Database Optimization](#database-optimization)
- [Caching Strategies](#caching-strategies)
- [Index Usage](#index-usage)

## Algorithm Performance Comparison

### Speed vs Accuracy Trade-offs

| Algorithm | Speed | Typo Tolerance | Best For | Dataset Size |
|-----------|-------|----------------|----------|--------------|
| **simple** | ⚡⚡⚡⚡⚡ Fastest | ❌ None | Exact matches, SKUs | Any size |
| **fuzzy** | ⚡⚡⚡⚡ Very Fast | ✅ High | General purpose | < 100K rows |
| **soundex** | ⚡⚡⚡⚡ Very Fast | ✅ Phonetic | Name searches | < 100K rows |
| **trigram** | ⚡⚡⚡ Fast | ✅ Very High | Similarity matching | < 50K rows |
| **levenshtein** | ⚡⚡ Medium | ✅ Configurable | Precise typo matching | < 50K rows |

### Performance Benchmarks

Based on a dataset of **10,000 records**:

#### Query Time (avg over 100 queries)

```
Algorithm        Query Time    Results Quality
-------------------------------------------------
simple           12ms          ⭐⭐⭐ (exact only)
fuzzy            18ms          ⭐⭐⭐⭐⭐ (excellent)
soundex          22ms          ⭐⭐⭐⭐ (phonetic)
trigram          45ms          ⭐⭐⭐⭐⭐ (excellent)
levenshtein      67ms          ⭐⭐⭐⭐ (precise)
```

#### Dataset Size Impact

Query time by dataset size (fuzzy algorithm):

```
Rows        Query Time    Memory Usage
------------------------------------------
1,000       5ms          2MB
10,000      18ms         8MB
50,000      72ms         32MB
100,000     145ms        64MB
500,000     890ms        245MB
1,000,000   1.8s         512MB
```

**Recommendation:** For datasets > 100K rows, use indexing or consider external search engines.

## Optimization Strategies

### 1. Choose the Right Algorithm

```php
// ❌ Slow for large datasets
$results = Product::search('laptop')
    ->using('levenshtein')  // Expensive calculations
    ->get();

// ✅ Fast for large datasets
$results = Product::search('laptop')
    ->using('fuzzy')  // Optimized for speed
    ->get();

// ✅ Even faster for exact matches
$results = Product::search('SKU-12345')
    ->using('simple')  // Just LIKE query
    ->get();
```

### 2. Limit Searchable Columns

```php
// ❌ Slow - searches all fillable columns
class Product extends Model
{
    use Searchable;
    
    protected $fillable = [
        'name', 'description', 'long_description',
        'meta_title', 'meta_description', 'meta_keywords',
        'sku', 'barcode', 'brand', 'category'
    ];
}

// ✅ Fast - only search most important columns
class Product extends Model
{
    use Searchable;
    
    protected array $searchable = [
        'columns' => [
            'name' => 10,
            'sku' => 8,
        ],
    ];
}
```

### 3. Reduce Typo Tolerance

```php
// ❌ More permissive = slower
$results = Product::search('laptop')
    ->typoTolerance(5)  // Generates many variations
    ->get();

// ✅ Lower tolerance = faster
$results = Product::search('laptop')
    ->typoTolerance(1)  // Fewer variations to check
    ->get();

// ✅ For SKUs/codes, disable completely
$results = Product::search('SKU-12345')
    ->typoTolerance(0)
    ->get();
```

### 4. Use Take/Limit

```php
// ❌ Returns all results (slow)
$results = Product::search('laptop')->get();

// ✅ Limit results (fast)
$results = Product::search('laptop')
    ->take(20)  // Only get 20 results
    ->get();
```

### 5. Disable Features You Don't Need

```php
// ❌ All features enabled (slower)
$results = Product::search('laptop')
    ->withRelevance()
    ->highlight('mark')
    ->withSynonyms([...])
    ->ignoreStopWords([...])
    ->accentInsensitive()
    ->unicodeNormalize()
    ->get();

// ✅ Minimal features (faster)
$results = Product::search('laptop')
    ->get();
```

## Benchmarking

Use the built-in benchmark command:

```bash
# Benchmark all algorithms
php artisan fuzzy-search:benchmark Article

# Benchmark specific algorithm
php artisan fuzzy-search:benchmark Article --algorithm=fuzzy

# Custom dataset size
php artisan fuzzy-search:benchmark Article --rows=50000

# Multiple queries
php artisan fuzzy-search:benchmark Article --queries=1000
```

### Example Output

```
┌─────────────────────────────────────────────────┐
│ Fuzzy Search Benchmark - Article Model          │
├─────────────────────────────────────────────────┤
│ Dataset: 10,000 rows                            │
│ Queries: 100                                    │
└─────────────────────────────────────────────────┘

Algorithm       Avg Time    Min Time    Max Time    Results
────────────────────────────────────────────────────────────
simple          12ms        8ms         18ms        45
fuzzy           18ms        12ms        28ms        52
soundex         22ms        15ms        35ms        48
trigram         45ms        32ms        68ms        54
levenshtein     67ms        48ms        95ms        51

Memory Peak: 64MB
```

## Database Optimization

### 1. Add Indexes

Add indexes to frequently searched columns:

```php
// In migration
Schema::create('articles', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->timestamps();
    
    // Add indexes for search performance
    $table->index('title');
    $table->fullText('body');  // MySQL 5.6+, PostgreSQL 12+
});
```

### 2. Use Appropriate Column Types

```php
// ❌ Slow - TEXT column for short strings
$table->text('name');

// ✅ Fast - VARCHAR for short strings
$table->string('name', 255);
```

### 3. Database-Specific Optimizations

#### MySQL

```sql
-- Enable full-text search
ALTER TABLE articles ADD FULLTEXT INDEX ft_title_body (title, body);

-- Use InnoDB (faster than MyISAM for reads)
ALTER TABLE articles ENGINE=InnoDB;
```

#### PostgreSQL

```sql
-- Install pg_trgm extension for faster similarity searches
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Add trigram index
CREATE INDEX trgm_idx_articles_title ON articles USING gin (title gin_trgm_ops);

-- Add GIN index for full-text search
CREATE INDEX idx_articles_title_gin ON articles USING gin(to_tsvector('english', title));
```

Enable in config:

```php
// config/fuzzy-search.php
'use_native_functions' => true,  // Use pg_trgm for trigram algorithm
```

#### SQLite

```sql
-- Add FTS5 virtual table for full-text search
CREATE VIRTUAL TABLE articles_fts USING fts5(title, body);
```

### 4. Optimize Table Structure

```bash
# MySQL - Analyze and optimize
php artisan db
ANALYZE TABLE articles;
OPTIMIZE TABLE articles;

# PostgreSQL - Update statistics
ANALYZE articles;
```

## Caching Strategies

### 1. Basic Caching

```php
// Cache for 1 hour (60 minutes)
$results = Article::search('laravel')
    ->cache(60)
    ->get();

// Custom cache key
$results = Article::search('laravel')
    ->cache(60, 'articles:search:laravel')
    ->get();
```

### 2. Tag-Based Cache Invalidation

```php
// In your model
class Article extends Model
{
    use Searchable;
    
    protected static function booted()
    {
        static::saved(function ($article) {
            Cache::tags(['articles'])->flush();
        });
        
        static::deleted(function ($article) {
            Cache::tags(['articles'])->flush();
        });
    }
}

// Use tagged cache
$results = Article::search('laravel')
    ->cache(3600, 'articles:search:laravel', ['articles'])
    ->get();
```

### 3. Redis Configuration

```php
// config/fuzzy-search.php
'cache' => [
    'enabled' => true,
    'driver' => 'redis',  // Use Redis instead of file cache
    'ttl' => 3600,
    'prefix' => 'fuzzy_search:',
],
```

### 4. Autocomplete Cache Strategy

For autocomplete, cache suggestions:

```php
public function autocomplete(Request $request)
{
    $query = $request->input('q');
    $cacheKey = 'autocomplete:' . md5($query);
    
    return Cache::remember($cacheKey, 300, function () use ($query) {
        return Product::search($query)
            ->take(10)
            ->get(['id', 'name', 'image']);
    });
}
```

## Index Usage

### 1. Enable Search Index

```php
// config/fuzzy-search.php
'indexing' => [
    'enabled' => true,
    'table' => 'search_index',
    'async' => true,        // Use queue for indexing
    'queue' => 'default',
    'chunk_size' => 500,
],
```

### 2. Build Index

```bash
# Index a model
php artisan fuzzy-search:index Article

# Reindex with progress
php artisan fuzzy-search:index Article --fresh

# Index multiple models
php artisan fuzzy-search:index Article Product User
```

### 3. Use Index in Queries

```php
$results = Article::search('laravel')
    ->useIndex()  // Use pre-built index
    ->get();
```

### 4. Automatic Index Updates

Add to your model:

```php
class Article extends Model
{
    use Searchable;
    
    protected static function booted()
    {
        static::saved(function ($article) {
            dispatch(new ReindexModelJob(Article::class, $article->id));
        });
    }
}
```

### 5. Clear Index

```bash
# Clear index for a model
php artisan fuzzy-search:clear Article

# Clear all indexes
php artisan fuzzy-search:clear --all
```

## Query Optimization Tips

### 1. Eager Loading

```php
// ❌ N+1 query problem
$results = Article::search('laravel')->get();
foreach ($results as $article) {
    echo $article->author->name;  // N queries
}

// ✅ Eager load relationships
$results = Article::search('laravel')
    ->with('author', 'categories')
    ->get();
```

### 2. Select Only Needed Columns

```php
// ❌ Selects all columns
$results = Article::search('laravel')->get();

// ✅ Select only needed columns
$results = Article::search('laravel')
    ->get(['id', 'title', 'excerpt', 'created_at']);
```

### 3. Use Cursors for Large Results

```php
// For processing large result sets
Article::search('laravel')
    ->cursor()
    ->each(function ($article) {
        // Process one at a time
    });
```

### 4. Batch Processing

```php
// Process in chunks
Article::search('laravel')
    ->chunk(100, function ($articles) {
        foreach ($articles as $article) {
            // Process
        }
    });
```

## Real-time Search Optimization

### 1. Debouncing

```php
// In config
'performance' => [
    'debounce_ms' => 300,  // Wait 300ms before searching
],

// Or per query
$results = Article::search($query)
    ->debounce(300)
    ->get();
```

### 2. Minimum Search Length

```php
// config/fuzzy-search.php
'min_search_length' => 3,  // Don't search if term < 3 chars

// Or in code
if (strlen($searchTerm) >= 3) {
    $results = Article::search($searchTerm)->get();
}
```

### 3. Progressive Loading

```javascript
// Frontend: Load more as user scrolls
let page = 1;

function loadMore() {
    fetch(`/api/search?q=${query}&page=${page}`)
        .then(res => res.json())
        .then(data => {
            appendResults(data);
            page++;
        });
}
```

## Monitoring & Profiling

### 1. Laravel Debugbar

```bash
composer require barryvdh/laravel-debugbar --dev
```

Check query time in debugbar:

```php
$results = Article::search('laravel')
    ->debugScore()  // See query details
    ->get();
```

### 2. Laravel Telescope

```bash
php artisan telescope:install
```

Monitor slow queries in Telescope's Query tab.

### 3. Custom Logging

```php
use Illuminate\Support\Facades\Log;

$start = microtime(true);
$results = Article::search('laravel')->get();
$duration = (microtime(true) - $start) * 1000;

Log::channel('fuzzy-search')->info('Search query', [
    'term' => 'laravel',
    'duration_ms' => $duration,
    'results_count' => $results->count(),
]);
```

## Performance Checklist

- [ ] Choose appropriate algorithm for your use case
- [ ] Limit searchable columns to essentials
- [ ] Add database indexes on searched columns
- [ ] Use `take()` to limit result sets
- [ ] Enable caching for frequently searched terms
- [ ] Consider search index for large datasets (> 100K rows)
- [ ] Eager load relationships
- [ ] Select only needed columns
- [ ] Monitor query performance with debugbar/telescope
- [ ] Use queue for async indexing
- [ ] Implement debouncing for real-time search
- [ ] Set minimum search length (3+ characters)

## When to Consider External Solutions

If you need:

- **> 1M records**: Consider Elasticsearch, Meilisearch, or Typesense
- **Complex faceting**: Use Algolia or Meilisearch
- **Real-time typo correction**: Algolia or Typesense
- **Multi-language NLP**: Elasticsearch with language analyzers
- **Distributed search**: Elasticsearch cluster

This package is perfect for:

- **< 500K records** with good optimization
- **Simple to moderate search** requirements
- **No external dependencies** requirement
- **Budget-conscious** projects (no search service costs)
- **Privacy-sensitive** data (stays in your database)

---

Need help? Check out the [Getting Started Guide](GETTING_STARTED.md) or [open an issue](https://github.com/ashiqfardus/laravel-fuzzy-search/issues).

