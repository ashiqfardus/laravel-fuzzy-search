# Performance and sizing

Async indexing, caching, `max_candidates`, recommended optimizations, and the measured sizes and timings at scale.

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

// Re-index a single model
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
IndexModelJob::dispatch(User::class, $user->id)->onQueue(config('fuzzy-search.indexing.queue')); // dispatch() alone uses the default queue
```

### Caching

```php
// Cache get() (and first()/simplePaginate(), which go through it) for 60 minutes
User::search('john')->cache(60)->get();

// No argument: the cache.ttl config (seconds)
User::search('john')->cache()->get();

// A key of your own, used as given
User::search('john')->cache(60, 'user-search-john')->get();

// With cache.enabled on, turn caching off for one query
User::search('john')->cache(0)->get();
```

```php
// config/fuzzy-search.php
'cache' => [
    'enabled' => false,          // true: cache every search without calling cache()
    'driver'  => 'default',      // a store from config/cache.php, or 'default' for the app's
    'ttl'     => 3600,           // seconds
    'prefix'  => 'fuzzy_search_',// generated keys start with this
],
```

`cache.ttl` counts **seconds**; `cache($minutes)` counts **minutes** and overrides it for that query; `cache()` or `cache(null)` caches for `cache.ttl` (before 3.0 `cache(null)` meant "not cached"); `cache(0)` turns caching off for that query, also when `cache.enabled` is on. With `cache.enabled` on, "every search" means every `get()`, `first()` and `simplePaginate()`: `paginate()`, `count()` and `getFacets()` are never cached, and `FederatedSearch::paginate()` reads fresh, like `paginate()`.

> **Before 3.0 the `cache` block was never read.** If your published `config/fuzzy-search.php` has `'enabled' => true` (the old README showed it), 3.0 caches every `get()`, `first()` and `simplePaginate()` for `ttl` **seconds**, and a `ttl` you wrote as minutes is now read as seconds. Set `'enabled' => false` to keep 2.0's behaviour.

A key you pass is used as given, so `Cache::forget('user-search-john')` removes it. A generated key starts with `cache.prefix` and covers everything that changes the result: the term, columns and weights, algorithm and options, filters, forwarded `where()`/joins/scopes (the SQL and its bindings), limit and offset, `orderBy()`, highlight tags, `withRelevance()`, `debugScore()`, the index model class, the model class, the names of its eager loads, where it runs — connection name, driver, host, port, database, table prefix and, on PostgreSQL, the `search_path` (one extra `select current_setting('search_path')` per cached search) — and the whole `fuzzy-search` config. Tenants on separate connections, databases or schemas therefore never share an entry, and changing any config value (`min_percentage`, `max_candidates`, a driver option, `cache.ttl` itself) starts a fresh set of entries; the old ones expire with their TTL. A key you name changes only when you change it. A search with a `customScore()` closure is cached only under a key you name: a closure cannot be part of a generated key.

A cached entry holds only arrays and scalars. By default that is each model's key with its `_score` and `_raw_score`. Rows of a search with a join, a union, an aliased FROM (`from('users as u')`) or a `fromSub()`, or without the key selected, are stored as their attributes, as the database returned them. A plain query builder's rows are stored as arrays. It therefore reads back on every store under Laravel 13's `cache.serializable_classes => false`.

A hit re-reads models stored by key, in the cached order, through the current query. That applies its global scopes, its eager loads with their constraints, and its `retrieved` listeners, so `with(['reviews' => fn ($q) => $q->where('user_id', auth()->id())])` is never served to another user. Models stored as attributes are rebuilt from them instead: their `retrieved` listeners and the current eager loads run the same way, but the query does not read them again.

On a hit:
- A model deleted since the entry was written is left out when the rows were stored by key. Rows stored as attributes (a join, a union, an aliased FROM) and a plain query builder's rows are served as they were stored until the entry expires: a deleted row is served until the TTL ends. A row stored as attributes (a join, a union, an aliased FROM, a `fromSub()` or a select without the key) is stored without the model's `$hidden` attributes, so after a cache hit `makeVisible()` finds them empty; don't cache such a query if you show hidden columns from it. With a `$visible` list, the attributes outside it are still stored in the cache store (it filters serialisation, and a joined or aliased column must read the same on a hit as on a miss).
- `_highlighted`, `_matches` and `_debug` are computed for the current request, so each viewer sees their own `makeVisible()`/`makeHidden()`.
- The rows are still the ones the cached search found: a row that no longer matches stays until the entry expires.

A hit costs the keyed read (none for rows stored as attributes) plus the eager-load queries. It fires no `FuzzySearchExecuted` event and writes no analytics row.

---

## Scaling

### `max_candidates` Tuning

For the LIKE/Levenshtein paths, SQL candidates are fetched then re-scored in PHP. The candidate set size is controlled by `max_candidates` (default: 1000). Lower this on large tables to reduce memory usage:

```php
// config/fuzzy-search.php
'max_candidates' => 500,  // top-level key; fetch fewer candidates on large tables
```

### Recommended Optimizations

```php
// For large tables: the BM25 index, plus a cache for repeated searches
User::search('john')
    ->useInvertedIndex()
    ->cache(60)
    ->get();

// On the LIKE path, fewer columns and fewer patterns: searchOn() replaces the model's
// column list for this search (searchIn() after Model::search() only adds columns)
User::searchOn(User::query(), 'john', ['name' => 10])
    ->maxPatterns(50)
    ->get();
```

Key tips:
1. **Use the BM25 index** when a LIKE scan is too slow on your table (see Sizing the index)
2. **Enable caching** for repeated searches
3. **Limit columns** — declare only the fields worth searching in `$searchable['columns']`, or pass a shorter list to `Model::searchOn(Model::query(), $term, [...])` for one search. `searchIn()` cannot narrow a `Searchable` model's search: it adds columns, and its list form sets their weight to 1
4. **Use `simple` algorithm** when typo tolerance isn't needed
5. **Set `max_candidates`** to prevent excessive memory usage on large tables
6. **Use `take()`** to cap result sets
7. **Eager load relationships** to avoid N+1 queries

### Sizing the index

Measured on one laptop database at a time, with rows of about 16 indexed words each (a short name, a description, a brand and a unique SKU, so the dictionary grows with the table). Your words, columns and hardware will move these numbers: time your own index searches, a common word and a rare one, through `lastExecution()->latencyMs` after a `useInvertedIndex()` search's `get()` or `paginate()` (`fuzzy-search:benchmark` times only the LIKE algorithms).

- **Size:** several times the table it indexes, the postings table most of it: 33.2 GB at 12M rows on MySQL (2.8 GB per million rows; built on 5dc853c in several runs; information_schema's estimate); 19.80 GB at 6M rows on SQL Server (3.3 GB per million; used pages; built in several runs); about 64 GB at 24M rows on PostgreSQL (2.7 GB per million; 0a05120; written by the compact migration's copy). `fuzzy_index_model_terms` holds one row per model and word it indexes, so where models share few words it has about as many rows as the dictionary (5,817 MB at 24M rows on PostgreSQL, 0a05120, beside the dictionary's 3,477 MB; 1.43 GB at 12M rows on MySQL, beside 0.86 GB).
- **Memory:** keep the index in the buffer pool (`innodb_buffer_pool_size`, `shared_buffers`). Once it no longer fits, searches for common words read from disk and concurrent searches queue: on MariaDB with a 2 GB buffer pool, sixteen searchers managed 198 searches a second at 1M rows and 48 at 3M (fb82c82), once the index outgrew the pool.
- **Upgrading to 3.0:** the compact postings migration copies the postings table, about 22 minutes for 6M rows (96M postings) on MySQL and 1 hour 45 minutes for 24M rows on PostgreSQL (both on 67be9e1, before the migration's later fixes), then `2026_10_13_000002` counts each model's words from it (about 3 minutes for 6M rows on MySQL and 70 s for 1M on SQL Server, on 81bedf8; 33 minutes for 24M rows on PostgreSQL with a cold cache, on c2b2c1d, and 11 with a warm one, on 81bedf8; all before 8274a49 changed how it sets a range's words left at length 0); from 2.0.1 at 1M rows on MySQL (15.9M postings), on RC3 7c96ec9, whose migrations are the release's: `term_length` 18 s, `column_name` 13 s, the `model_id` widening 2 m 47 s, the compact copy 58 s, the words backfill 15 s, 4 m 33 s in all, and the rollback to 2.0.1 4 m 36 s; the new code's index searches fail until it finishes, and the old code's from its swap at the end: see the [upgrade guide](UPGRADE_v2_TO_v3.md) (`php artisan down`, or flush and rebuild a large index instead). From 2.0.x only MySQL and MariaDB have an index to copy: on PostgreSQL and SQL Server 2.0.x could not write one, so the PostgreSQL and SQL Server times are for an index a pre-release 3.0 build wrote, and from 2.0.x the migrations take seconds there.
- **Index builds:** one process rebuilt 1M rows on MariaDB in 199 s (fb82c82), and four built 12M rows on MySQL in 1 hour 55 minutes (1,742 rows a second; 5dc853c) and on MariaDB, from an empty index, in 2 hours 25 minutes (1,377 a second; 599508a). SQL Server writes one index chunk at a time per database, so four processes indexed 209 to 225 rows a second there at 1M rows (999ee0d, before the change to how a write reads a batch's postings there). `rebuild --async` only queues the work: at 3M rows on SQL Server it queued 6,001 jobs in 21 s (47918e9), and 12M rows in 40 s on MySQL and 40 s on MariaDB (RC3 7c96ec9), 6M in 41 s on SQL Server (616b9d7) and 24M in 68 s on PostgreSQL (0a05120).

| Database (memory for the engine) | Largest table measured | First page of a rare, mid or common word | Slowest search | Searches past 120 s |
|---|---|---|---|---|
| MySQL 9.6 (4 GB buffer pool) | 12M rows | 8 ms, 156 ms, 0.59 s | a common word under `orderBy()` and `paginate()`, 4.3 s | none |
| MariaDB 11.4 (4 GB buffer pool) | 12M rows | 12 ms, 161 ms, 0.57 s | a common word under `orderBy()` and `paginate()`, 2.9 s | none |
| PostgreSQL 16 (4 GB `shared_buffers`) | 24M rows | 9 ms, 426 ms, 0.93 s | page 500 of a mid word under `orderBy()` and `paginate()`, 3.2 s | none |
| SQL Server 2022 (4 GB max server memory; on a separate Windows PC with an NVMe disk, over the local network) | 6M rows | 27 ms, 327 ms, 0.37 s (under `orderBy()`: 34 ms to 0.61 s) | a 4-letter `asYouType()` prefix, 1.3 s | none |

Each row is the largest table measured on that database, on a 3.0.0 release candidate (MySQL on 5dc853c, PostgreSQL on 0a05120, MariaDB on 7c96ec9, SQL Server on 616b9d7; MariaDB's 12M index was built on 599508a, SQL Server's 6M on fb82c82 to 3.41M rows and on 599508a after). Larger tables were not tried, so these sizes are not limits.

The LIKE algorithms scan the table: on MariaDB at 1M rows (fb82c82), `simple` took 0.79 s, `fuzzy` 17 s and `levenshtein` 44 s. Keep them for tables under the sizes in the [Algorithm Comparison](algorithms.md#algorithm-comparison), or narrow them with a selective `where()` (a tenant's 0.1% of 1M rows: 20 ms).

---
