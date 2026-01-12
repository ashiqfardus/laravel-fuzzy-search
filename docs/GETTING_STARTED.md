# Getting Started with Laravel Fuzzy Search

This guide will help you get up and running with Laravel Fuzzy Search in just a few minutes.

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Basic Usage](#basic-usage)
- [Common Use Cases](#common-use-cases)
- [Troubleshooting](#troubleshooting)

## Installation

### Requirements

- PHP 8.0 or higher
- Laravel 9.x, 10.x, 11.x, or 12.x
- MySQL, PostgreSQL, SQLite, or SQL Server

### Install via Composer

```bash
composer require ashiqfardus/laravel-fuzzy-search
```

That's it! The package is auto-discovered and ready to use immediately.

### Optional: Publish Configuration

```bash
php artisan vendor:publish --tag=fuzzy-search-config
```

This creates `config/fuzzy-search.php` where you can customize default settings.

## Quick Start

### 1. Add the Searchable Trait

Add the `Searchable` trait to any Eloquent model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

class Article extends Model
{
    use Searchable;
}
```

### 2. Search Immediately (Zero Config)

The package auto-detects common column names:

```php
// Automatically searches: title, name, email, description, etc.
$articles = Article::search('laravel')->get();
```

### 3. Customize Search Columns

For better results, specify which columns to search:

```php
class Article extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'title' => 10,        // Highest weight
            'body' => 5,          // Medium weight
            'excerpt' => 3,       // Lower weight
        ],
        'algorithm' => 'fuzzy',   // Default algorithm
        'typo_tolerance' => 2,    // Allow 2 character differences
    ];
}
```

## Basic Usage

### Simple Search

```php
// Basic search
$results = Article::search('laravel')->get();

// With pagination
$results = Article::search('laravel')->paginate(20);

// Limit results
$results = Article::search('laravel')->take(10)->get();
```

### Search with Filters

```php
$results = Article::search('laravel')
    ->where('status', 'published')
    ->where('created_at', '>', now()->subDays(30))
    ->orderBy('created_at', 'desc')
    ->get();
```

### Get Relevance Scores

```php
$results = Article::search('laravel')
    ->withRelevance()
    ->get();

foreach ($results as $article) {
    echo "Score: " . $article->_score;
    echo "Title: " . $article->title;
}
```

### Highlight Matches

```php
$results = Article::search('laravel')
    ->highlight('mark')  // Wraps matches in <mark> tags
    ->get();

foreach ($results as $article) {
    echo $article->_highlighted['title'];
    // Output: "Getting started with <mark>Laravel</mark> Fuzzy Search"
}
```

## Common Use Cases

### 1. Blog / Content Search

```php
class Post extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'title' => 10,
            'body' => 5,
            'excerpt' => 3,
        ],
    ];
}

// Usage
$posts = Post::search('getting started with laravel')
    ->where('status', 'published')
    ->paginate(15);
```

**Or use the blog preset:**

```php
$posts = Post::search('getting started with laravel')
    ->preset('blog')
    ->paginate(15);
```

### 2. E-commerce Product Search

```php
class Product extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'name' => 10,
            'sku' => 8,
            'brand' => 6,
            'description' => 5,
        ],
        'typo_tolerance' => 1,  // Lower tolerance for SKUs
    ];
}

// Usage - handles typos automatically
$products = Product::search('iphone 15 pro')  // Finds "iPhone 15 Pro"
    ->where('stock', '>', 0)
    ->orderBy('price', 'asc')
    ->get();
```

**Or use the ecommerce preset:**

```php
$products = Product::search('iphone 15 pro')
    ->preset('ecommerce')
    ->get();
```

### 3. User Search

```php
class User extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'name' => 10,
            'username' => 9,
            'email' => 8,
        ],
    ];
}

// Usage - finds users with typo tolerance
$users = User::search('john')  // Finds "John", "Jon", "Johnny"
    ->get();
```

**Or use the users preset:**

```php
$users = User::search('john')
    ->preset('users')
    ->get();
```

### 4. Phonetic Search (Names)

Perfect for searching names that sound similar:

```php
$users = User::search('steven')
    ->using('soundex')  // Finds "Stephen", "Stefan", "Stephan"
    ->get();
```

**Or use the phonetic preset:**

```php
$users = User::search('steven')
    ->preset('phonetic')
    ->get();
```

### 5. Real-time Autocomplete

```php
public function autocomplete(Request $request)
{
    $query = $request->input('q');
    
    $suggestions = Product::search($query)
        ->take(10)
        ->get(['name', 'sku', 'image']);
    
    return response()->json($suggestions);
}
```

With suggestions:

```php
$suggestions = Product::search($query)
    ->suggest()  // Returns suggested completions
    ->take(10);
```

### 6. Multi-word Search

```php
// Match ANY word (default)
$results = Article::search('laravel php framework')
    ->matchAny()  // Finds articles with "laravel" OR "php" OR "framework"
    ->get();

// Match ALL words
$results = Article::search('laravel php framework')
    ->matchAll()  // Only finds articles with all three words
    ->get();
```

### 7. Search Across Multiple Models

```php
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;

$results = FederatedSearch::across([
    Article::class,
    Product::class,
    User::class,
])
    ->search('laravel')
    ->get();

foreach ($results as $result) {
    echo "Type: " . $result->_model_type;
    echo "Score: " . $result->_score;
}
```

## Advanced Features

### Custom Scoring

```php
$results = Article::search('laravel')
    ->scoreWith(function($article, $baseScore) {
        // Boost recent articles
        $recencyBoost = $article->created_at->gt(now()->subDays(7)) ? 20 : 0;
        
        // Boost featured articles
        $featuredBoost = $article->is_featured ? 50 : 0;
        
        return $baseScore + $recencyBoost + $featuredBoost;
    })
    ->get();
```

### Stop Words & Synonyms

```php
$results = Article::search('the best laravel tutorial')
    ->ignoreStopWords(['the', 'a', 'an'])  // Ignores "the"
    ->withSynonyms([
        'tutorial' => ['guide', 'lesson', 'walkthrough']
    ])
    ->get();
```

### Debugging

```php
$results = Article::search('laravel')
    ->debugScore()  // Shows score breakdown
    ->get();

foreach ($results as $article) {
    print_r($article->_score_breakdown);
}

// Get debug info about search configuration
$debugInfo = Article::search('laravel')
    ->using('fuzzy')
    ->typoTolerance(2)
    ->getDebugInfo();

print_r($debugInfo);
```

## Query Builder Support

You can also use fuzzy search directly on query builders:

```php
use Illuminate\Support\Facades\DB;

// Simple where clause
$results = DB::table('articles')
    ->whereFuzzy('title', 'laravel')
    ->get();

// Multiple columns
$results = DB::table('articles')
    ->whereFuzzyMultiple(['title', 'body'], 'laravel')
    ->get();
```

## Troubleshooting

### No Results Returned

**Problem:** Search returns empty results even though data exists.

**Solutions:**

1. **Check if columns are searchable:**
```php
// Make sure columns are fillable or specified in $searchable
protected $fillable = ['title', 'body'];
```

2. **Lower typo tolerance temporarily:**
```php
$results = Article::search('test')
    ->typoTolerance(0)  // Exact matches only
    ->get();
```

3. **Use exact algorithm:**
```php
$results = Article::search('test')
    ->preset('exact')
    ->get();
```

4. **Enable debug mode:**
```php
$results = Article::search('test')
    ->debugScore()
    ->get();
```

### Empty Search Term Error

**Problem:** `EmptySearchTermException` is thrown.

**Solution:** Either provide a search term or allow empty searches:

```php
// Option 1: Check before searching
if (!empty($searchTerm)) {
    $results = Article::search($searchTerm)->get();
} else {
    $results = Article::all();
}

// Option 2: Enable in config
// config/fuzzy-search.php
'allow_empty_search' => true,
```

### Slow Queries

**Problem:** Search queries are slow with large datasets.

**Solutions:**

1. **Enable search index:**
```php
// In config
'indexing' => [
    'enabled' => true,
    'async' => true,
],

// Build index
php artisan fuzzy-search:index Article
```

2. **Enable caching:**
```php
$results = Article::search('laravel')
    ->cache(60)  // Cache for 60 minutes
    ->get();
```

3. **Reduce columns searched:**
```php
// Only search most important columns
$results = Article::search('laravel')
    ->searchIn(['title' => 10])  // Only search title
    ->get();
```

4. **Use simple algorithm for large datasets:**
```php
$results = Article::search('laravel')
    ->using('simple')  // Faster but less typo-tolerant
    ->get();
```

### Invalid Algorithm Error

**Problem:** `InvalidAlgorithmException` is thrown.

**Solution:** Use a valid algorithm:

```php
// Valid algorithms: fuzzy, levenshtein, soundex, trigram, simple
$results = Article::search('test')
    ->using('fuzzy')  // ✓ Valid
    ->get();
```

## Next Steps

- Read the [full documentation](../README.md)
- Check out [performance tips](PERFORMANCE.md)
- Learn about [algorithm comparison](COMPARISON.md)
- View [API reference](API.md)
- Browse [example implementations](../examples/)

## Need Help?

- 📖 [Read the README](../README.md)
- 🐛 [Report a bug](https://github.com/ashiqfardus/laravel-fuzzy-search/issues/new?template=bug_report.yml)
- 💡 [Request a feature](https://github.com/ashiqfardus/laravel-fuzzy-search/issues/new?template=feature_request.yml)
- ❓ [Ask a question](https://github.com/ashiqfardus/laravel-fuzzy-search/issues/new?template=question.yml)

