# Configuration and CLI

The config file, presets, per-model customization and every Artisan command.

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
        'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'it'],
        'de' => ['der', 'die', 'das', 'und', 'oder', 'aber'],
    ],
    
    'synonyms' => [
        // Default synonyms of every SearchBuilder search except extended()/searchBoolean()
        // (word => its synonyms; case is ignored):
        // 'laptop' => ['notebook', 'computer'],
    ],
    
    'indexing' => [
        'enabled' => false,
        'async' => true,
        'queue' => 'default',
    ],
    
    'cache' => [
        'enabled' => false,
        'driver' => 'default',  // any cache store name, or 'default' for the app's
        'ttl' => 3600,          // seconds
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

A preset's `columns` are added to the search's columns, the way `searchIn()` adds them, but only the ones the table has: `users` names `name`, `email` and `username`, and on a table of `name` and `email` it searches those two. A preset with none of its columns on the table adds none, and its algorithm and other settings still apply. A column you name with `searchIn()` is never dropped, so a wrong one still fails with an unknown-column error. A dotted column, and every column when the query joins another table or the table cannot be listed, is kept as given. To search other columns than the preset names, publish the config and change its `columns`, or drop its `columns` key and pass the columns with `searchIn()`:

```php
// config/fuzzy-search.php — the users preset without its columns
'users' => [
    'algorithm' => 'levenshtein',
    'typo_tolerance' => 2,
    'accent_insensitive' => true,
],
```

```php
User::search('john')->preset('users')->searchIn(['name' => 10, 'email' => 8])->get();
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

    // Custom scoring logic: runs once per row before normalisation, on the LIKE, extended and index paths and in the Scout engine
    public function getSearchScore(float $baseScore): float
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

# Rebuild with fresh index (flushes the model's index first; asks no confirmation)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh

# Rebuild asynchronously (for large tables)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh --async --queue=indexing

# Remove a model's index entries (the same as fuzzy-search:clear "App\Models\User"; asks no confirmation)
php artisan fuzzy-search:flush "App\Models\User"

# Clear BM25 index for a model (asks no confirmation); a renamed or deleted model's rows go by its old class name
php artisan fuzzy-search:clear "App\Models\User"

# Clear BM25 index for all models (asks no confirmation). It truncates where it can; while other
# sessions use the index tables, or where TRUNCATE is not allowed, it deletes row by row (on MySQL/MariaDB in
# bounded batches), which is slow on a large index: run it again when the index is idle, so it truncates,
# before rebuilding. See docs/bm25.md#artisan-commands
php artisan fuzzy-search:clear --all

# Show index statistics (total docs, tokens, avg length per model; the dictionary's unique terms for all models together)
# and list postings that predate column weighting
# (past 1,000,000 indexed tokens that check, which reads every posting, runs only with --legacy)
php artisan fuzzy-search:status
php artisan fuzzy-search:status --legacy
```

`--async` dispatches a Laravel job batch, which needs the `job_batches` table: create it once with `php artisan make:queue-batches-table` (Laravel 10: `php artisan queue:batches-table`) and `php artisan migrate`. Without it, the command stops before touching the index.

Run `flush`, `clear` and `rebuild --fresh` (which flushes first) while nothing is indexing any model; see [Artisan Commands](bm25.md#artisan-commands) for why.

`flush`, `clear`, `clear --all` and `rebuild --fresh` delete index entries as soon as they run. None of them asks for confirmation, in production either, so that non-interactive deploy scripts keep working. Guard them in your own production scripts.

Every command exits with status 1 when it cannot act on its input: a class that is not an Eloquent model (for `rebuild`, one it cannot index; for `benchmark` and `explain`, one without the `Searchable` trait), `--iterations` below 1, a `--days` below 0 or a `--limit` below 1 (or either one not a whole number), or an `add-shadow-column --type` other than `metaphone`.

### Benchmark & Debug Commands

`fuzzy-search:analytics` and `fuzzy-search:analytics:prune` are documented under [Persisted Search Analytics](../README.md#persisted-search-analytics).

```bash
# Benchmark search performance
php artisan fuzzy-search:benchmark "App\Models\User" --term="john" --iterations=100

# Explain a search query
php artisan fuzzy-search:explain User --term="john"
```

`benchmark` and `explain` run real searches, but turn `analytics.enabled` and `cache.enabled` off for the run (and restore them), so they write no analytics row and a cache hit is never timed as a search. An unknown `--algorithm` or an empty `--term` prints the message and exits with 1.

---
