# Inverted Index

> Available from v2.0.0-alpha.2

The inverted index provides BM25-ranked full-text search, scalable `didYouMean()`, and the Scout driver foundation. It is opt-in — the default LIKE-pattern search still works without any index.

---

## How it works

The indexing system has two parts that work together:

**Part 1 — One-time initial build.** You run this once after install (or after a schema change):

```bash
php artisan fuzzy-search:rebuild "App\Models\User" --fresh
```

**Part 2 — Automatic incremental updates.** After that, every time a model is saved or deleted, the package dispatches a small queue job that re-indexes just that one row. The index stays current without any cron jobs or manual work.

The flow when a record is saved:

```text
User::create(['name' => 'John'])
  → Eloquent fires 'saved' event
  → SearchableIndexingObserver::saved() fires
  → checks config('fuzzy-search.indexing.enabled') → true
  → dispatches IndexModelJob to the configured queue
  → queue worker picks it up
  → IndexManager::indexModel($user) runs
  → 3 SQL queries: upsert terms + fetch IDs + insert postings
  → 'john' is now in the index
```

---

## Database tables

| Table | Purpose |
| --- | --- |
| `fuzzy_index_terms` | Term dictionary: unique terms + document frequency |
| `fuzzy_index_postings` | Postings: term → model mapping with term frequency |
| `fuzzy_index_meta` | BM25 normalization: total docs + avg document length per model |

---

## Production setup (step by step)

### Step 1 — Run migrations

```bash
php artisan migrate
```

Creates the three index tables.

### Step 2 — Enable indexing in config

Open `config/fuzzy-search.php`. The indexing block is **disabled by default** to avoid surprise queue dispatches on apps that haven't set up a worker yet.

```php
'indexing' => [
    'enabled'          => true,       // ← must be true or saves are never indexed
    'async'            => true,       // true = queued (recommended for production)
    'queue'            => 'indexing', // dedicated queue (recommended)
    'chunk_size'       => 500,
    'max_tokens_per_doc' => 5000,     // security cap: prevents index poisoning
],
```

> **Why a dedicated queue?** Mixing indexing jobs with your app's main queue means a burst of saves (e.g. a bulk import) can delay emails, notifications, and other jobs. A dedicated `indexing` queue keeps them isolated.

### Step 3 — Declare searchable columns on your models

The observer needs to know which columns to index. Add the `Searchable` trait and declare your columns:

```php
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

class User extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'name'  => 10,   // weight 10 (higher = more important in BM25)
            'email' => 5,
            'bio'   => 2,
        ],
    ];
}
```

If `$searchable['columns']` is not declared, `getSearchableColumns()` returns an empty array and the observer silently skips the model.

### Step 4 — Build the initial index

For small tables (< 50k rows), run synchronously:

```bash
php artisan fuzzy-search:rebuild "App\Models\User" --fresh
```

For large tables (50k+ rows), use `--async` to dispatch batch queue jobs instead of blocking the artisan process:

```bash
php artisan fuzzy-search:rebuild "App\Models\User" --fresh --async --queue=indexing
```

The `--async` flag splits the work into 500-row `RebuildIndexJob` chunks dispatched via `Bus::batch()`. You can monitor progress in Horizon or via `queue:size`.

### Step 5 — Start a queue worker

**This is the most important step.** `IndexModelJob` and `RebuildIndexJob` are queued jobs — without a running worker, jobs pile up and the index never updates.

```bash
# Development — run in foreground
php artisan queue:work --queue=indexing,default

# Production — run with Supervisor (recommended)
```

**Supervisor config** (save to `/etc/supervisor/conf.d/fuzzy-search-worker.conf`):

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

```bash
# Apply the config
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start fuzzy-search-worker:*
```

If you are using **Laravel Horizon** (Redis), add an `indexing` queue to your Horizon config:

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

---

## Usage

```php
// BM25 search — faster + better relevance on large tables
$users = User::search('john')->useInvertedIndex()->get();

// useIndex() is an alias
$users = User::search('john')->useIndex()->get();

// didYouMean() reads from the term dictionary — O(1) at any dataset size
$suggestions = User::search('jonh')->searchIn(['name'])->didYouMean(3);
// Returns: [['term' => 'john', 'distance' => 1, 'confidence' => 0.8], ...]
```

---

## Verifying the index is healthy

```bash
# Check how many docs are indexed per model
php artisan fuzzy-search:status
```

If `Total docs` equals your table's row count, the index is healthy. If it's lower, either the initial rebuild didn't finish or some saves happened before the observer was enabled.

To fix a stale index:

```bash
php artisan fuzzy-search:rebuild "App\Models\User" --fresh
```

---

## What can go wrong (and how to fix it)

| Problem | Symptom | Fix |
| --- | --- | --- |
| `indexing.enabled = false` | Index never updates after writes; `fuzzy-search:status` shows stale count | Set `indexing.enabled = true` and restart workers |
| No queue worker running | Jobs pile up, `queue:size indexing` grows, index goes stale | Start the worker; `php artisan queue:retry all` for stuck jobs |
| Worker crashed | Index stale for rows saved during downtime | Supervisor auto-restarts; `queue:retry all` for failed jobs |
| Model missing `$searchable['columns']` | Observer silently skips the model; nothing gets indexed | Add the `$searchable` property to the model |
| `async = true` but no queue configured | `IndexModelJob` dispatched to `default` queue, may never run | Set `queue` to a queue with an active worker |
| Deploy resets Redis | Jobs lost if using Redis queue driver | Run `fuzzy-search:rebuild --fresh` after deploy; use database driver for durability |
| `async = false` on a high-write model | Each save blocks for ~10ms; p95 latency increases | Switch to `async = true` with a worker |

---

## sync vs async — when to use each

| | `async = true` (default) | `async = false` |
| --- | --- | --- |
| **How it works** | Dispatches `IndexModelJob` to queue; worker indexes in background | Indexes in the same request, no queue |
| **Request latency** | Unaffected (job dispatched in microseconds) | +~10ms per save |
| **Requires queue worker** | Yes | No |
| **Risk** | Worker downtime = stale index until worker recovers | None |
| **Best for** | Production apps with a worker / Horizon | Tests, local dev, low-traffic apps |

For **tests**, set `indexing.async = false` so indexing happens synchronously and you can assert index state immediately after a save:

```php
// In your test setUp
config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
```

---

## Commands reference

```bash
# Show index statistics (total docs, tokens, avg length per model)
php artisan fuzzy-search:status

# Rebuild synchronously (good for < 50k rows)
php artisan fuzzy-search:rebuild "App\Models\User"
php artisan fuzzy-search:rebuild "App\Models\User" --fresh

# Rebuild asynchronously via queue (recommended for large tables)
php artisan fuzzy-search:rebuild "App\Models\User" --async
php artisan fuzzy-search:rebuild "App\Models\User" --fresh --async --queue=indexing

# Delete all index entries for a model (does not delete the model data)
php artisan fuzzy-search:flush "App\Models\User"
```

---

## Stemming (optional)

Default: no stemming (`NullStemmer`). With `NullStemmer`, `running` only matches `running`, not `run` or `ran`.

To enable Porter stemming for English:

```bash
composer require wamania/php-stemmer
```

```php
// config/fuzzy-search.php
'indexing' => [
    'stemmer' => \Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer::class,
],
```

With stemming active, `running` matches documents containing `run`, `runs`, `ran`. You must rebuild the index after changing the stemmer.

Supported languages (all from the `wamania/php-stemmer` Snowball library): English, French, German, Spanish, Italian, Russian, Dutch, Portuguese, Swedish, Danish, Norwegian.

---

## BM25 tuning

```php
// config/fuzzy-search.php
'bm25' => [
    'k1' => 1.5,   // Term-frequency saturation (1.2–2.0). Higher = more weight to repeated terms.
    'b'  => 0.75,  // Length normalisation (0–1). 0 = ignore doc length. 1 = full normalisation.
],
```

**When BM25 is faster than LIKE:** At ~500k+ rows, a full-table LIKE scan starts to dominate query time. BM25 queries the index directly and avoids scanning the full table. The break-even point depends on your hardware and DB engine.

**When LIKE may be faster:** For small tables (< 100k rows) or narrow queries (very specific terms), LIKE can be faster due to BM25's scoring overhead. Always benchmark against your own dataset.

---

## Security: token cap

A malicious actor who can write to a model could craft column values with thousands of unique tokens, bloating the index. The `max_tokens_per_doc` config key caps the number of unique tokens indexed per document:

```php
'indexing' => [
    'max_tokens_per_doc' => 5000,  // default; adjust based on your typical document size
],
```

When the cap is hit, a `Log::warning()` is emitted and remaining tokens are discarded. The document is still indexed — just truncated.

---

## Fallback behaviour

If `useInvertedIndex()` is called without a resolvable model class (e.g. on a `DB::table()` query), it silently falls back to the LIKE-pattern path. No exception is thrown.

```php
// Falls back to LIKE path silently — no crash
DB::table('users')->fuzzySearch(['name'], 'john')->useInvertedIndex()->get();

// BM25 path works when you pass the model class explicitly
DB::table('users')->fuzzySearch(['name'], 'john')->useInvertedIndex('App\Models\User')->get();
```
