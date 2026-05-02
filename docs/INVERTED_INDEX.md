# Inverted Index

> Available from v2.0.0-alpha.2

The inverted index provides BM25-ranked full-text search, scalable `didYouMean()`, and the Scout driver foundation. It is opt-in — the default LIKE-pattern search still works without any index.

## Tables

| Table | Purpose |
|---|---|
| `fuzzy_index_terms` | Term dictionary: unique terms + document frequency |
| `fuzzy_index_postings` | Postings: term → model mapping with term frequency |
| `fuzzy_index_meta` | BM25 normalization: total docs + avg document length per model |

## Setup

```bash
# Run migrations (creates the three index tables)
php artisan migrate

# Build the index for a model (synchronous, ~500 rows/s on commodity hardware)
php artisan fuzzy-search:rebuild "App\Models\User"

# Or flush and rebuild fresh
php artisan fuzzy-search:rebuild "App\Models\User" --fresh
```

## Usage

```php
// BM25 search — much faster on large tables, better relevance
$users = User::search('john')->useInvertedIndex()->get();

// useIndex() is an alias
$users = User::search('john')->useIndex()->get();

// didYouMean() now reads from the term dictionary — works at any scale
$suggestions = User::search('jonh')->searchIn(['name'])->didYouMean(3);
// Returns: [['term' => 'john', 'distance' => 1, 'confidence' => 0.8], ...]
```

## Automatic incremental updates

Enable in `config/fuzzy-search.php`:

```php
'indexing' => [
    'enabled' => true,   // Required — off by default to avoid surprise queue dispatches
    'async'   => true,   // true = queued (recommended); false = synchronous
    'queue'   => 'default',
],
```

When `enabled = true`, `SearchableIndexingObserver` dispatches `IndexModelJob` on every model `saved`/`deleted` event. The index stays in sync without a full rebuild.

## Stemming (optional)

Default: no stemming (`NullStemmer`). To enable Porter stemming:

```bash
composer require wamania/php-stemmer
```

```php
// config/fuzzy-search.php
'indexing' => [
    'stemmer' => \Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer::class,
],
```

With stemming active, `running` matches `run`, `ran`, `runs`.

## BM25 parameters

```php
// config/fuzzy-search.php
'bm25' => [
    'k1' => 1.5,   // Term-frequency saturation. Higher = more weight to repeated terms.
    'b'  => 0.75,  // Length normalisation. 0 = off, 1 = full.
],
```

## Commands

```bash
php artisan fuzzy-search:status                              # Index statistics per model
php artisan fuzzy-search:rebuild "App\Models\User" --fresh  # Full rebuild
php artisan fuzzy-search:flush "App\Models\User"            # Delete model's index
```

## Fallback behaviour

If `useInvertedIndex()` is called on a `DB::table()` query without an explicit model class, it silently falls back to the LIKE-pattern path. No exception is thrown — `useInvertedIndex()` is an optimisation hint, not a hard requirement.

```php
// Falls back to LIKE path silently — no crash
DB::table('users')->fuzzySearch(['name'], 'john')->useInvertedIndex()->get();

// BM25 path works with explicit model class
DB::table('users')->fuzzySearch(['name'], 'john')->useInvertedIndex('App\Models\User')->get();
```
