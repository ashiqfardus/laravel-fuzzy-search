# Search Analytics

[← Back to the README](../README.md)

---

## Persisted Search Analytics

Opt-in, DB-backed search analytics: every `FuzzySearchExecuted` event can be written to a table (`analytics.table`, `fuzzy_search_logs` by default) for later reporting, instead of (or alongside) the live event listener in [Events](../README.md#events).

```php
// config/fuzzy-search.php
'analytics' => [
    'enabled'        => false,              // off by default — enable deliberately
    'queue'          => null,               // null = insert inline; a queue name dispatches RecordSearchLogJob there instead
    'sample_rate'    => 1.0,                // 0.0–1.0 share of searches recorded
    'retention_days' => 30,                 // what `fuzzy-search:analytics:prune` deletes beyond
    'hash_terms'     => false,              // true stores only a keyed SHA-256 (HMAC with APP_KEY), never the term itself
    'table'          => 'fuzzy_search_logs', // the migration creates this table: set it before php artisan migrate
],
```

Run `php artisan migrate` to create the table — it has no effect until `analytics.enabled` is `true`. The migration creates the table `analytics.table` names, so change it before migrating. The flag is read on every search, so `config(['fuzzy-search.analytics.enabled' => true])` at runtime starts recording without a reboot.

Each row holds: `term` (the raw search term, or `''` when `hash_terms` is on), `normalized_term` (lower-cased, whitespace-collapsed and cut to 191 characters, the width its index allows (a character outside the BMP counts as two) — or its keyed SHA-256 when `hash_terms` is on), `model_type` (the Eloquent class searched, `null` for query-builder/in-memory searches), `algorithm`, `path` (`like`, `bm25`, `extended`, or `in_memory`), `result_count`, `latency_ms` (capped at 999999.99, the column's maximum), `day` (the date `created_at` falls on, used by `volume()`) and `created_at`.

### What counts as one row

One row per executed search **attempt**, which is not always one row per user query:

- `fallback()` writes one row per algorithm it tries — a query that misses on `fuzzy` and then matches on `soundex` is two rows (two `popular()` searches, and the miss's latency is mixed into `averageLatency()`).
- `FederatedSearch` writes one row per inner model — "laptop" across three models is three rows.
- Nothing is recorded for a cache hit — a `cache()` call, or any search while `cache.enabled` is on — (the search never runs), for `count()` or `exists`-style calls (only `get()`, `paginate()` and `simplePaginate()` fire the event), for terms shorter than `min_search_length`, or for an in-memory search with an empty term or no `searchIn()` columns (on a single-model search; `FederatedSearch::simplePaginate()` records each inner model's own fetch).
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

`--days` and `--limit` must be whole numbers (`--days` ≥ 0, `--limit` ≥ 1); anything else exits 1 without querying or deleting. The report prints logged terms with control characters shown as `\xNN` and console tags as plain text, since a search term is user input.

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

