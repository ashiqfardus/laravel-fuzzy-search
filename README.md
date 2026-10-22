# Laravel Fuzzy Search

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![License](https://img.shields.io/packagist/l/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![PHP Version](https://img.shields.io/packagist/php-v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Laravel Version](https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-FF2D20?logo=laravel)](https://laravel.com)

A powerful, **zero-config** fuzzy search package for Laravel with fluent API. Works with all major databases without external services.

**Demo:** [laravel-fuzzy-search-demo](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) - See the package in action!

**Documentation:** [Installation](#installation) • [Quick Start](#quick-start) • [Usage guide](docs/usage.md) • [Algorithms](docs/algorithms.md) • [Scoring](docs/scoring.md) • [BM25 Index](docs/bm25.md) • [Performance](docs/performance.md) • [Configuration & CLI](docs/configuration.md) • [Integrations](docs/integrations.md) • [Upgrade v2→v3](docs/UPGRADE_v2_TO_v3.md)

## Features

| Category | Features |
|----------|----------|
| **Core** | Zero-config search • Fluent API • Eloquent & Query Builder support • Relationship search (dot notation) |
| **Algorithms** | Multiple fuzzy algorithms • Typo tolerance • Multi-word token search |
| **Scoring** | Field weighting • Relevance scoring • Prefix boosting • Partial match • Recency boost |
| **Text Processing** | Stop-word filtering • Synonym support • Per-locale stop-word lists |
| **Internationalization** | Unicode support • Accent insensitivity • Multi-language |
| **Results** | Highlighted results • Custom scoring hooks • Debug/explain-score mode |
| **Performance** | BM25 inverted index • Async indexing (queue) • Redis/cache support |
| **Pagination** | Stable ranking • Offset pagination • Simple (look-ahead) pagination |
| **Reliability** | Fallback search strategy • DB-agnostic • SQL-injection safe |
| **Configuration** | Config file support • Per-model customization |
| **Developer Tools** | CLI indexing • Benchmark tools • Performance utilities |
| **Smart Search** | Autocomplete suggestions (dictionary-backed on indexed models) • "Did you mean" spell correction • Multi-model federation • Persisted search analytics |
| **Integrations** | Filament global search & table search • Scout driver • JSON API resources |

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Search Algorithms](#search-algorithms)
- [BM25 Inverted Index](#bm25-inverted-index)
- [Extended Search Syntax](#extended-search-syntax)
- [Integrations](#integrations)
- [Persisted Search Analytics](#persisted-search-analytics)
- [Performance at scale](#performance-at-scale)
- [Documentation](#documentation)
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

If you plan to use the **BM25 inverted index**, also run:

```bash
php artisan migrate
```

Installs with different table prefixes can share a schema (or SQLite file): with `prefix_indexes` on, Laravel's default for MySQL, PostgreSQL and SQL Server (add it to an SQLite connection), the package's index names carry the prefix, as Laravel's own do.

> **Upgrading from v1.x?** There are breaking changes — result rankings and `_score` values may shift.
> Run the scanner to find affected code, then follow the full guide.
>
> ```bash
> composer require ashiqfardus/laravel-fuzzy-search
> php artisan migrate
> php artisan fuzzy-search:upgrade-v1   # scans your app/ for v1-era API usage
> ```
>
> → [Full upgrade guide](docs/UPGRADE_v1_TO_v2.md), then the [v2→v3 guide](docs/UPGRADE_v2_TO_v3.md): every behaviour change there applies to you too.

> **Upgrading from v2.0.x?** 3.0 is a major release: it breaks code and configs written for 2.0, so read the
> [upgrade guide](docs/UPGRADE_v2_TO_v3.md) before you move to it with `composer require ashiqfardus/laravel-fuzzy-search:^3.0`
> (a `^2.0` constraint never installs 3.0). In short: run the new migrations under `php artisan down` (on MySQL and MariaDB a
> large index takes a while: from 2.0.1 at 1M rows the whole upgrade took 4 m 33 s on MySQL, RC3 7c96ec9), rebuild each model
> once (a plain rebuild can run while the app indexes; only `--fresh` needs nothing indexing, so run a `rebuild --fresh` a
> migration's warning asks for before the restart), turn on `READ_COMMITTED_SNAPSHOT` (or `scout.after_commit`) on SQL Server,
> and restart the queue workers right after migrating. The guide opens with the step-by-step checklist.

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

Without `take()` (or `limit()`), `get()` returns only the first 15 matches: on `Model::search()`, on `FederatedSearch` (its `getGrouped()` too) and on `FuzzySearch::on()`; `Model::search()` and `FederatedSearch` page through the rest with `paginate()`.

**How auto-detection works:** The package automatically detects common column names in this priority order:
- `name`, `title` (weight: 10)
- `email`, `username` (weight: 8)
- `first_name`, `last_name` (weight: 7)
- `description`, `content`, `body` (weight: 5)
- `bio`, `summary`, `excerpt` (weight: 3)
- `slug`, `sku`, `code` (weight: 2-6)

If none of these exist, it falls back to the first text columns of `$fillable`. It never picks a hidden or secret-named column, `id` or the timestamps: see [Auto-detection](docs/usage.md#auto-detection) for the exact rules.

### Manual Column Configuration

You can manually specify which columns to search and their weights. On PostgreSQL and SQL Server each must be a text column (see [Notes](docs/algorithms.md#notes) under the database compatibility table):

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

Column names that may come from the request are checked: a secret-named or hidden column is refused unless `$searchable['columns']` lists it (see [Request-supplied columns](docs/usage.md#request-supplied-columns)).

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

**More:** chaining Eloquent, relationships, the query builder, text processing, highlighting and debugging, pagination, safety and events are in the [usage guide](docs/usage.md).

---

## Search Algorithms

### Available Algorithms

| Algorithm | Best For | Typo Tolerance |
|-----------|----------|----------------|
| `fuzzy` | General purpose | High |
| `levenshtein` | Strict typo matching | Configurable |
| `soundex` | Phonetic matching (English names) | Phonetic |
| `metaphone` | Phonetic matching (more accurate; ASCII letters only) | Phonetic |
| `trigram` | Similarity matching | Medium (a shared three-letter run) |
| `similar_text` | Percentage similarity (`similar_text.min_percentage`, default 70) | None (the value must contain the term) |
| `simple` / `like` | Exact substring (LIKE) | None |

```php
// Use specific algorithm
User::search('john')->using('levenshtein')->get();
User::search('stephen')->using('soundex')->get();  // Finds "Steven"
User::search('stephen')->using('metaphone')->get(); // More accurate phonetic — see setup below
User::search('laptop')->using('similar_text')->get(); // Contains "laptop", at least 70% similar (min_percentage)
```

> ⚠️ **`metaphone` requires one-time setup.** Unlike the other algorithms, it searches against a precomputed `{column}_metaphone` shadow column. Calling `using('metaphone')` without it throws `RuntimeException`. Run the three commands shown in [Shadow Columns](docs/algorithms.md#shadow-columns) once per searchable column.

Shadow columns, typo tolerance, multi-word and in-memory search, how the algorithms compare and what each one does on each database: [docs/algorithms.md](docs/algorithms.md). Field weights, relevance scoring and its hooks, suggestions, "did you mean" and multi-model federation: [docs/scoring.md](docs/scoring.md).

---

## BM25 Inverted Index

A real inverted index for large tables, across seven tables: `fuzzy_index_terms`, `fuzzy_index_postings`, `fuzzy_index_documents`, `fuzzy_index_meta`, the `fuzzy_index_model_types` and `fuzzy_index_columns` dictionaries the postings name their model type and column through, and `fuzzy_index_model_terms`, each model's own words with its own document count of each.

```bash
php artisan fuzzy-search:rebuild "App\Models\Post"    # build once; with indexing.enabled on, saves keep it in sync
```

With `indexing.enabled` set to `true` (it ships `false`; see [Async Indexing](docs/performance.md#async-indexing-queue-support)), the index follows Eloquent model events, once the save's transaction commits. Writes that fire no model events leave it stale: `Model::query()->update()`, `insert()`, a query-builder `delete()`, `saveQuietly()`, `Model::withoutEvents()` and raw SQL. Re-index rows written this way with `php artisan fuzzy-search:rebuild "App\Models\Post"`. A plain rebuild also removes the documents of rows such a write deleted or trashed, or that the model's `searchIndexQuery()` leaves out. A row moved out of a global scope keeps its document until `IndexModelJob::dispatch(Post::class, $id)` repairs it (the job removes a row that is gone or hidden) or a `rebuild --fresh`.

```php
Post::search('tolkien')->useInvertedIndex()->get();
```

- **Column weights (BM25F-lite)** — `searchIn()` / `$searchable['columns']` weights scale ranking on the index too, not only the LIKE/Levenshtein paths.
- **Typo tolerance & as-you-type** — the index expands each query term through its own term dictionary, so `typoTolerance()` and `asYouType()` work without an exact token match.
- **When to use it** — On MariaDB at 1M rows (fb82c82) an index search for a common word took 0.12 s where `fuzzy` LIKE took 17 s; on a small table, LIKE is simpler to operate.
- **Single-table inheritance** — each row is indexed under its own class; return the parent from each child's `searchIndexType()` to index the hierarchy once ([docs/bm25.md](docs/bm25.md#single-table-inheritance)).
- **Writes** — saves reach the index once their transaction commits; with `indexing.async` off an index error is reported to your exception handler, naming the model and key, not thrown from `save()`. Indexing inside a transaction of yours can deadlock: set `scout.after_commit` to `true`, and see [Index writes inside transactions](docs/bm25.md#index-writes-inside-transactions).

→ Full guide: [docs/bm25.md](docs/bm25.md)

---

## Extended Search Syntax

`->extended()` opts a search string into Fuse.js-style query operators for precise matching, instead of a plain substring search.

```php
User::search('^John !banned')->extended()->get();
```

Operators: `'include`, `=exact`, `^prefix`, `suffix$` (each of the whole column value), `!exclude`, `|` (OR), `( )` (grouping), `~typo`, `field:term`, and quoted `"phrases"`.

Extended queries always run on the LIKE path — `->useInvertedIndex()` is ignored when combined with `->extended()`, and `getDebugInfo()['index_ignored']` reports it. They apply no synonyms and drop no stop words: every word stays a term of the query. A `field:` scope reaches any searchable column, a hidden one included, so for untrusted input declare only public columns searchable or check the fields against an allowlist.

→ Full guide: [docs/extended-syntax.md](docs/extended-syntax.md)

---

## Integrations

### Scout driver

The Scout engine is bundled and registers automatically when `laravel/scout` 10.1 or later (or 11.x) is installed:

```
SCOUT_DRIVER=fuzzy-search
```

It searches the same BM25 index, with the same scoring, as `useInvertedIndex()`, so there is no second index to keep in sync. A model on it needs the package's `Searchable` trait beside Scout's. An empty or null query matches nothing, with or without `allow_empty_search`. Semantic and hybrid search (Scout 11.6+) are not supported. Ordering, global scopes and tenant constraints, soft deletes and limits: [Scout driver](docs/integrations.md#scout-driver).

### Filament

`HasFuzzyGlobalSearch` swaps a Resource's LIKE-based global search for fuzzy search, and `FuzzySearch::tableSearch(['name'])` does the same for a table column (Filament 3.3, 4.x and 5.x): [Filament integration](docs/integrations.md#filament-integration).

### JSON API resources

`FuzzySearchResource` and `FuzzySearchCollection::fromBuilder()` wrap results into API responses with the package's `_score`/`_highlighted` fields: [JSON API resources](docs/integrations.md#json-api-resources).

### Livewire

A search-as-you-type box needs a client-side debounce (`wire:model.live.debounce.300ms`) plus `asYouType()` and `suggest()`: [Livewire recipe](docs/integrations.md#livewire-recipe).

---

## Persisted Search Analytics

Opt-in, DB-backed analytics: set `analytics.enabled` to `true` and each search that fires `FuzzySearchExecuted` writes one row to the `analytics.table` table (`fuzzy_search_logs` by default; the migration creates the table that key names, so set it before `php artisan migrate`). Scout searches and cache hits fire none; see [what counts as one row](docs/analytics.md#what-counts-as-one-row).

```php
SearchAnalytics::popular(7, 5);
SearchAnalytics::zeroResults(7, 5);
```

The `SearchAnalytics` facade also reports `averageLatency()` by path and `volume()` per day; `php artisan fuzzy-search:analytics` prints a report and `fuzzy-search:analytics:prune` deletes rows past `retention_days`. Terms are stored (or hashed via `hash_terms`) — no user identity is recorded.

→ Full guide: [docs/analytics.md](docs/analytics.md)

---

## Performance at scale

Measured on 3.0.0 and its release candidates, one database at a time, with rows of about 16 indexed words each: MySQL and PostgreSQL to 24M rows, MariaDB to 12M and SQL Server to 6M, with no search or command past 120 s at any of them. The first page of a rare, mid and common word and the slowest search per database, the index size, memory, index builds and upgrade times are in [Sizing the index](docs/performance.md#sizing-the-index); async indexing, caching and tuning in [docs/performance.md](docs/performance.md).

---

## Documentation

| Guide | What it covers |
|---|---|
| [Usage guide](docs/usage.md) | Eloquent chaining, relationships, text processing, highlighting, debugging, pagination, safety, events |
| [Search algorithms](docs/algorithms.md) | Each LIKE algorithm, shadow columns, typo tolerance, how they compare, per-database behaviour |
| [Scoring](docs/scoring.md) | Field weights, relevance, prefix and recency boosts, scoring hooks, suggestions, "did you mean", federation |
| [BM25 index](docs/bm25.md) | The inverted index: setup, production, writes, commands, tuning |
| [Performance and sizing](docs/performance.md) | Async indexing, caching, `max_candidates`, measured sizes and timings |
| [Configuration and CLI](docs/configuration.md) | The config file, presets, per-model customization, every Artisan command |
| [Extended syntax](docs/extended-syntax.md) | Fuse.js-style operators |
| [Relationships](docs/relationships.md) | Searching related models |
| [Tokenization](docs/tokenization.md) | Stop words, synonyms, stemming, locales |
| [Integrations](docs/integrations.md) | Scout, Filament, JSON API resources, Livewire |
| [Analytics](docs/analytics.md) | Persisted search analytics |
| [Upgrade v2→v3](docs/UPGRADE_v2_TO_v3.md) · [v1→v2](docs/UPGRADE_v1_TO_v2.md) | Breaking changes and the upgrade checklist |

---

## Testing

```bash
# Run tests
composer test

# Run with coverage
composer test-coverage

# Run the Performance test suite (timing and memory bounds; CI does not run it)
composer benchmark
```

To time the LIKE algorithms on your own app's tables, use `php artisan fuzzy-search:benchmark "App\Models\User" --term="john"` (see [CLI Tools](docs/configuration.md#cli-tools)); time an index search through `lastExecution()->latencyMs`.

---

## Requirements

- PHP 8.1 – 8.5
- Laravel 10.x, 11.x, 12.x or 13.x. CI tests these PHP × Laravel pairs:

  | PHP | Laravel |
  |---|---|
  | 8.1 | 10 |
  | 8.2 | 10, 11, 12 |
  | 8.3 | 10, 11, 12, 13 |
  | 8.4 | 10, 11, 12, 13 |
  | 8.5 | 12, 13 |

  SQLite runs every pair. MySQL 8 and PostgreSQL 14 run the PHP 8.2–8.5 pairs, and MariaDB 11.4 and SQL Server 2022 the PHP 8.3–8.5 pairs, with Laravel 10, 12 and 13.
- Any supported database — MySQL 8+, MariaDB 11.4 (CI); 10.6+ expected, PostgreSQL 14+, SQLite, or SQL Server 2022
- Optional: Laravel Scout 10.1 or later (or 11.x) for the [Scout driver](#scout-driver) (semantic and hybrid search are not supported); Filament 3.3, 4.x or 5.x for the [Filament integration](docs/integrations.md#filament-integration)

---

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Credits

- [Md Asikul Islam](https://github.com/ashiqfardus)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.
