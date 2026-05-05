# Changelog

<!-- markdownlint-disable MD024 -->

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0-alpha.4] — 2026-05-04 — Bug-fixes, security hardening, schema improvements, and concurrency fixes

### Fixed

- **Lexer:** `!` is now treated as an operator boundary — `=John!!!` no longer parses the `!` characters as part of the term (fixes zero-result queries using NOT operators adjacent to other tokens)
- **`searchIn()`:** Duplicate column names are deduplicated — chaining `->searchIn(['name'])` after the `Searchable` trait already included `name` no longer produces triple-binding SQL `(name = ? OR email = ? OR name = ?)`
- **Extended search empty-guard:** `->extended($query)` and `->searchBoolean($query)` now bypass the empty-term guard when a query string is provided, so `User::search('')->extended('john')` works correctly
- **Cache key:** `useSearchIndex`, `extendedQuery`, `columnWeights`, and `stopWords` are now included in the cache key — prevents cross-search result poisoning when two different searches share the same base query string
- **BM25 paginate total:** `paginateIndexed()` computes the real total via `COUNT(DISTINCT model_id)` instead of inferring it from a bounded fetch — page counts are now accurate for large result sets
- **BM25 debug output:** `debugScore()` information is now emitted in both BM25 code paths (direct and paginated)
- **IndexManager — concurrent upsert safety:** `indexModel()` and `indexBatch()` now use `upsert()` on `fuzzy_index_postings` instead of `insert()` — eliminates `QueryException` when two workers index the same model simultaneously against the `UNIQUE (term_id, model_type, model_id)` constraint
- **IndexManager — first-insert race:** `upsertMeta()` and `upsertMetaBulk()` now use `insertOrIgnore()` to guarantee the meta row exists before issuing atomic `UPDATE` increments — prevents concurrent first-ever indexing from throwing on the `UNIQUE` constraint
- **IndexManager — `avg_doc_length` drift:** `removeFromIndex()` now subtracts the deleted document's `doc_length` from `total_tokens` before recomputing `avg_doc_length`; `indexModel()` applies a delta when re-indexing so `total_tokens` stays accurate across updates
- **IndexManager — `doc_count` leak in batch re-index:** `indexBatch()` now fetches and decrements `doc_count` for terms belonging to re-indexed documents before re-inserting, preventing monotonic inflation of IDF scores on every `fuzzy-search:rebuild` run
- **`FuzzySearchEngine::paginate()` real total:** Scout engine `paginate()` now calls `Bm25Scorer::count()` (a single `COUNT(DISTINCT model_id)` query) for the true match total instead of using the bounded fetch count — page counts are now correct for any result set size
- **Migration dedup guard:** `add_unique_index_to_fuzzy_index_postings` now removes duplicate rows before adding the `UNIQUE` constraint, so the migration does not fail on databases with pre-existing duplicates from concurrent indexing
- **Migration MySQL key-length:** `widen_term_column_to_255` now drops the unique index, widens the column, then recreates a `term(191)` prefix index on MySQL — safe on MySQL 5.7 where a full `varchar(255)` utf8mb4 index key would exceed the 767-byte limit
- **False-positive tests:** Fixed two tests that used `|| true` / always-true callbacks (`FederatedSearchTest`, `QueueTest`) — they now assert the actual values
- **Docs — `InMemorySearch::highlight()`:** Removed `highlight` from the `InMemorySearch` supported-methods list in `docs/QUERY_LANGUAGE.md` — `highlight()` is not implemented on `InMemorySearch`
- **Docs — extended syntax operators:** `docs/UPGRADE_v1_TO_v2.md` examples corrected to use actual Lexer operators (`!` for NOT, `'` for include-match) instead of unsupported Fuse.js `+`/`-` prefixes
- **Docs — `GETTING_STARTED.md` method names:** Corrected `scoreWith()` → `customScore()` and `_score_breakdown` → `_debug` to match the actual `SearchBuilder` API

### Security

- **`@fuzzyHighlight` tag whitelist:** The `$tag` argument is validated against `[a-zA-Z][a-zA-Z0-9-]*` — user-controlled tag values can no longer inject arbitrary HTML attributes or close tags
- **Column name validation:** Column names passed to `searchIn()` and `applyFuzzyWhere()` are validated against `/^[a-zA-Z_][a-zA-Z0-9_.]*$/` — blocks backtick-injection on MySQL
- **ORDER BY direction validation:** `$direction` in `applyFuzzyOrder()` is now validated to `asc` or `desc` — prevents SQL injection via raw `ORDER BY` interpolation
- **`paginateIndexed()` page-size cap:** `$perPage` is clamped to a maximum of 100 — prevents DoS via abnormally large page size requests

### Added

- **`InMemorySearch` method guard:** Calling an unsupported method on an `InMemorySearch` instance now throws `BadMethodCallException` with a list of supported methods, rather than failing silently

### Changed

- **Observer skips unchanged models:** `SearchableIndexingObserver` no longer dispatches `IndexModelJob` when a `saved` event fires but no searchable column was actually modified — reduces background job volume on high-write tables
- **BM25 per-term posting cap:** The BM25 scorer now applies a SQL-side `LIMIT` before fetching postings, controlled by `bm25.max_postings_per_term` (default 50 000) — bounds peak memory usage per search request

### Database migrations

Two new migrations are included and run automatically on `php artisan migrate`:

- `2026_05_03_000001_add_unique_index_to_fuzzy_index_postings` — adds a `UNIQUE` constraint on `(term_id, model_type, model_id)` to prevent duplicate postings under concurrent indexing
- `2026_05_03_000002_widen_term_column_to_255` — widens `fuzzy_index_terms.term` from `varchar(191)` to `varchar(255)` to accommodate longer stemmed token forms

See the [upgrade guide](docs/UPGRADE_v1_TO_v2.md) for a deduplication SQL snippet if you are upgrading from alpha.3 with an existing index.

### Config

- `bm25.max_postings_per_term` (default `50000`) — new key controlling the SQL-side per-term posting cutoff

## [2.0.0-alpha.3] — 2026-04-20 — Phase 2: Differentiators

### Added

- Extended-search syntax parser: `'word`, `=word`, `^word`, `word$`, `!word`, `|`, `( )`, `"quoted"`
- `SearchBuilder::extended($query)` — Fuse-style query entry point
- `SearchBuilder::searchBoolean($query)` — alias for `extended()`
- `_matches` array on result objects: column, value, character indices
- `@fuzzyHighlight` Blade directive — XSS-safe match rendering
- `FuzzySearch::on($collection)` + `InMemorySearch` — fuzzy search over PHP collections
- `docs/EXTENDED_SEARCH.md` and `docs/QUERY_LANGUAGE.md`
- Adversarial fuzz suite (`tests/Security/QueryFuzzTest.php`) — 1000 random inputs

### Changed

- `_score` normalized to `[0, 1]` range across all search paths
- `_highlighted` is now derived from `_matches` (still backwards-compatible)
- `FederatedSearch` cross-model ranking uses normalized scores

### Config

- `query.max_tokens` (32), `query.max_depth` (16) — DoS guards on parser
- `in_memory.max_items` (10000) — memory ceiling for `FuzzySearch::on()`

### Backwards-compat

- `_raw_score` preserved on results for code that depended on unbounded scores
- `_highlighted` still populated when `highlight()` is called

## [2.0.0-alpha.2] — Phase 1: Real Search Engine

### Added
- Inverted index: `fuzzy_index_terms`, `fuzzy_index_postings`, `fuzzy_index_meta` tables
- `IndexManager` — tokenize/stem/write postings; `removeFromIndex`; `flush`; `processTerms`
- `TokenizerInterface` + `WhitespaceTokenizer` (Unicode-aware, ≥2 char filter)
- `StemmerInterface` + `NullStemmer` + `PorterStemmer` (via `wamania/php-stemmer`)
- `Bm25Scorer` — PHP-side BM25 computation over joined SQL query; works on MySQL/PG/SQLite
- `IndexModelJob` — per-row incremental index update
- `RebuildIndexJob` — bulk rebuild in batched chunks (uses `Bus::Batchable`)
- `SearchableIndexingObserver` — auto-dispatches `IndexModelJob` on model `saved`/`deleted` (opt-in via `indexing.enabled`)
- `SearchBuilder::useInvertedIndex(string|bool|null)` — BM25 fast path; alias `useIndex()`
- `SearchBuilder::useInvertedIndex('App\Models\User')` — explicit model class for `DB::table()` callers
- BM25 graceful fallback: `DB::table()` without model class falls back to LIKE path silently
- `php artisan fuzzy-search:status` — index statistics per model
- `php artisan fuzzy-search:rebuild {model} [--fresh]` — full rebuild
- `php artisan fuzzy-search:flush {model}` — delete model's index
- `SearchBuilder::didYouMean()` rewritten — queries `fuzzy_index_terms` directly; O(1) at any dataset size
- `FuzzySearchEngine` (Scout adapter) — bundled in core; registers automatically when `laravel/scout` is present (`SCOUT_DRIVER=fuzzy-search`)
- `docs/INVERTED_INDEX.md`
- `docs/SCOUT_DRIVER.md`

### Deprecated

- `ReindexModelJob` — use `IndexModelJob` (per-row) or `RebuildIndexJob` (bulk). Removed in v3.0.0.

### Changed

- `config/fuzzy-search.php` updated with new indexing options: `indexing.enabled`, `indexing.tokenizer`, `indexing.stemmer`
- BM25 parameters added: `bm25.k1` (default: 1.5), `bm25.b` (default: 0.75)

## [1.0.1] - 2026-03-16

### Added
- Laravel 13 support via updated `illuminate/database` and `illuminate/support` constraints.

### Changed
- Expanded dev compatibility constraints for modern test environments.

## [1.0.0] - 2026-01-12

### 🎉 Initial Release

A powerful, zero-config fuzzy search package for Laravel with fluent API. Works with all major databases without external services. Scales to **10 million records** with proper optimization.

### ✨ Features

#### Core Search
- **Zero-config search** - Auto-detects searchable columns from `$fillable` or `$searchable`
- **Fluent API** - Chain methods naturally: `->search()->using()->typoTolerance()->get()`
- **Full Eloquent & Query Builder support** - Works with both seamlessly
- **8 search algorithms**:
  - `fuzzy` - General purpose with typo tolerance (recommended)
  - `levenshtein` - Edit distance based, configurable tolerance
  - `soundex` - Phonetic matching for similar sounding words
  - `metaphone` - More accurate phonetic matching
  - `trigram` - N-gram similarity (best with PostgreSQL pg_trgm)
  - `similar_text` - Percentage-based similarity
  - `simple` / `like` - Basic LIKE matching (fastest, no typo tolerance)

#### Scoring & Relevance
- **Field weighting** - Prioritize columns: `['title' => 10, 'body' => 5]`
- **Relevance scoring** - Results include `_score` attribute
- **Prefix boosting** - Boost results starting with search term
- **Recency boost** - Boost newer records with `boostRecent()`
- **Custom scoring hooks** - Add your own scoring logic via callbacks
- **Partial match support** - Match substrings within words
- **Improved scoring algorithm** - Uses `similar_text()` for percentage-based similarity scoring
- **Better Levenshtein distance scoring** - Proper weight calculation for fuzzy matches
- **Consistent score rounding** - Scores rounded to 2 decimal places

#### Text Processing
- **Multi-word token search** - `tokenize()` with `matchAll()` or `matchAny()`
- **Stop-word filtering** - Multi-language support (en, de, fr, es)
- **Synonym support** - Define synonyms and synonym groups
- **Unicode normalization** - Proper handling of Unicode characters
- **Accent-insensitive search** - `café` matches `cafe`
- **Locale awareness** - Language-specific processing

#### Smart Search Features
- **Autocomplete** - `suggest()` method for search suggestions
- **Spell correction** - `didYouMean()` for typo suggestions
- **Multi-model search** - `FederatedSearch` to search across multiple models
- **Search analytics** - `getAnalytics()` for search insights

#### Performance & Scaling
- **Search index table** - Optional pre-computed index for large datasets
- **Async indexing** - Queue support for background indexing
- **Redis/cache integration** - Cache search results with `cache(60)`
- **Query pattern limiting** - Prevent regex explosion attacks
- **Debounce support** - Rate limit real-time search requests
- **Scales to 10M records** - With partitioning, materialized views, and caching

#### Pagination
- **Stable ranking** - Consistent ordering across pages
- **Multiple pagination types** - Offset, simple, and cursor pagination
- **Manual control** - `take()` and `skip()` for custom pagination

#### Results & Output
- **Highlighted results** - `highlight('mark')` wraps matches in tags
- **Debug mode** - `debugScore()` explains scoring breakdown
- **Faceted search** - Group results by field values

#### Reliability & Security
- **Fallback strategy** - Graceful degradation when primary algorithm fails
- **SQL injection protection** - All queries use parameterized bindings
- **Database-agnostic** - MySQL, PostgreSQL, SQLite, SQL Server, MariaDB

#### Configuration
- **Config presets** - `blog`, `ecommerce`, `users`, `phonetic`, `exact`
- **Publishable config file** - Full customization via `config/fuzzy-search.php`
- **Per-model customization** - Override settings via `$searchable` property

#### Exception Handling
- `LaravelFuzzySearchException` - Base exception with context support
- `EmptySearchTermException` - When search term is empty
- `InvalidAlgorithmException` - When invalid algorithm specified
- `InvalidConfigException` - When configuration is invalid
- `SearchableColumnsNotFoundException` - When no searchable columns found

#### Developer Tools
- **CLI commands**:
  - `fuzzy-search:index` - Build search index
  - `fuzzy-search:clear` - Clear search index
  - `fuzzy-search:benchmark` - Performance benchmarking
  - `fuzzy-search:explain` - Explain search scoring
- **Comprehensive test suite** - 171+ tests with 230+ assertions

### 📚 Documentation
- `docs/GETTING_STARTED.md` - Quick start guide with examples
- `docs/PERFORMANCE.md` - Optimization guide for scaling to millions of records
- `docs/COMPARISON.md` - Comparison with Laravel Scout, Meilisearch, Algolia, Elasticsearch

### 📋 Requirements
- PHP 8.0, 8.1, 8.2, 8.3, or 8.4
- Laravel 9.x, 10.x, 11.x, or 12.x
- MySQL, PostgreSQL, SQLite, SQL Server, or MariaDB

---

[Full Documentation](https://github.com/ashiqfardus/laravel-fuzzy-search)

[2.0.0-alpha.4]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v2.0.0-alpha.3...v2.0.0-alpha.4
[2.0.0-alpha.3]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v2.0.0-alpha.2...v2.0.0-alpha.3
[2.0.0-alpha.2]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v2.0.0-alpha.1...v2.0.0-alpha.2
[2.0.0-alpha.1]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v1.0.1...v2.0.0-alpha.1
[1.0.1]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/ashiqfardus/laravel-fuzzy-search/releases/tag/v1.0.0
