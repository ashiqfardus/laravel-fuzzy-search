# Changelog

<!-- markdownlint-disable MD024 -->

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] — Unreleased

### Added

- `fallback()` now runs: when the primary algorithm (LIKE-pattern or BM25) returns no rows, the search is retried with each fallback in order. Applies to `get()`, `first()`, `paginate()`, `simplePaginate()` and `count()`; filters and prior `where()` constraints carry over, and one `FuzzySearchExecuted` event fires per attempt.
- `$searchable['reindex_on' => [...]]` declares the real columns that trigger a reindex for accessor-backed searchable fields (e.g. `brand_id` behind a `brand_name` accessor). Exposed as `Searchable::getReindexTriggers()`.
- `searchIndexQuery(Builder $query): Builder` model hook: `fuzzy-search:rebuild` (sync and `--async`) and `RebuildIndexJob` load rows through it, so relation-backed columns can be eager-loaded instead of queried per row.
- `indexing.job` config (`tries` 3, `backoff` [10, 60, 300], `timeout` 120) bounds retries of `IndexModelJob` and `RebuildIndexJob`.
- `bm25.candidate_chunk` config (default 200): chunk size used when checking the BM25 ranking against a constrained query.
- SearchBuilder forwards Eloquent/Query Builder calls (where*, whereHas, with*, join*, when, local scopes, …) and adds query(Closure) — no more filter()-only workarounds. Cache keys now include forwarded constraints.

### Changed

- `maxPatterns()` and `performance.max_patterns` now actually cap the LIKE-pattern list for all pattern-based algorithms.
- **BM25 honours your constraints.** `filter()`/`filterIn()`, `where()` constraints applied before the search, and global scopes are applied *before* the ranking is cut to the page, so selective filters no longer return short or empty pages, and `paginate()` totals count only matching rows. The Scout engine applies the builder's `where()`/`whereIn()`/`whereNotIn()`/`query()` the same way.
- `_score` on paginated BM25 results is normalised against the corpus-wide maximum (as `get()` already did) instead of the page maximum, so page 2's first row is no longer always 1.0.
- `WhitespaceTokenizer` keeps combining marks (`\p{M}`) inside tokens. Indexes built from Bengali, Hindi, Thai or decomposed-accent text need one `fuzzy-search:rebuild --fresh`.
- The trigram fallback's whole-term pattern is the term itself; previously it was a concatenation of the trigrams and never matched.
- A model whose searchable columns include an accessor is reindexed on every save unless it declares `reindex_on` (previously such models never reindexed after an update).
- Synchronous indexing (`indexing.async = false`) reloads the model from the database before indexing, exactly like the queued job, so relations loaded before the change are not written to the index.

### Removed

- `indexing.table` config key — the v1 `search_index` table name, unread by v2 outside the deprecated `performReindex()` path (which keeps its own default).

### Fixed

- **Multibyte terms:** `FuzzyDriver`, `LevenshteinDriver`, `TrigramDriver` and `SoundexDriver` sliced the search term by byte, producing invalid UTF-8 LIKE patterns for Bengali, Hindi, Thai and accented Latin (PostgreSQL rejected them; other databases never matched). `min_search_length` and `query.max_term_length` also counted bytes. All now work per character.
- Accessor-backed searchable fields never reindexed on update (`wasChanged()` cannot see them), so a product moved to another brand stayed findable under the old brand.
- `fallback()` stored its algorithms and never ran them.

- `typoTolerance(0)` and `(1)` were ignored by the default fuzzy algorithm — every typo pattern was always generated. Pattern families are now gated by the tolerance level and `typo_tolerance.min_word_length`.
- MariaDB connections (driver name "mariadb" on Laravel 11+) now use native SOUNDEX(), the Levenshtein UDF path, quoted identifiers and the MySQL flush branch — previously every MySQL-only branch silently fell back to generic SQL.
- **PostgreSQL and SQL Server:** BM25 indexing failed on every write with an "ambiguous doc_count" error inside the upsert. The inverted index now works on both.
- **SQL Server:** BM25 indexing threw "This database engine does not support inserting while ignoring errors"; meta rows are now created with a portable upsert.
- **SQL Server:** didYouMean() used LENGTH(), which SQL Server does not have; MySQL/MariaDB now use CHAR_LENGTH() so multibyte terms are measured in characters.
- **PostgreSQL:** using('soundex') without fuzzystrmatch returned no rows for capitalised names (case-sensitive LIKE).
- **SQL Server:** indexing a document containing a purely numeric token (e.g. "10") failed with "Conversion failed when converting the nvarchar value"; term bindings are now always strings.
- stableRanking() ordered by a hard-coded "id" column and broke on UUID / custom-key models.
- suggest() missed capitalised values on PostgreSQL (case-sensitive LIKE).
- A searchable column holding the string "0" was skipped by the indexer.
- FederatedSearch::searchIn() was ignored for models using the Searchable trait; columns a table does not have are now skipped instead of raising SQL errors.

### Deprecated

- `debounce()` — a server-side debounce cannot exist; it now raises `E_USER_DEPRECATED` and will be removed in v3.0.0. Debounce on the client (`wire:model.live.debounce.300ms`, a JS timer).

## [2.0.1] — 2026-09-16

### Fixed

- Allow `symfony/finder` ^8 so the package installs alongside Laravel 13.

## [2.0.0] — 2026-05-05

v2.0.0 is a major release adding a BM25 inverted index, extended search syntax, in-memory search, Scout integration, and significant scoring improvements. All v1.x behavior is preserved unless noted in the breaking changes below.

### Added

- **BM25 inverted index** (`useInvertedIndex()`) — globally-ranked full-text search; faster than LIKE-pattern search on 500k+ rows
- **Extended search syntax** — Fuse.js-style operators: `'include`, `=exact`, `^prefix`, `word$`, `!exclude`, `|` (OR), `( )` grouping, `"quoted phrase"`
- `SearchBuilder::extended($query)` and `SearchBuilder::searchBoolean($query)` entry points for structured queries
- **In-memory search** — `FuzzySearch::on($collection)` for searching PHP collections with no DB queries
- **`@fuzzyHighlight` Blade directive** — XSS-safe `<mark>` rendering; passes all output through `e()`
- `_matches` array on results — column, value, and `[start, end]` character-offset pairs per match (requires `->highlight()`)
- `_raw_score` — original BM25 float preserved alongside normalized `_score`
- **`FuzzySearchEngine`** — Scout engine adapter bundled in core; activate with `SCOUT_DRIVER=fuzzy-search`; no extra package required
- `php artisan fuzzy-search:status` — show index statistics per model
- `php artisan fuzzy-search:rebuild {Model} [--async]` — full or queued index rebuild
- `php artisan fuzzy-search:flush {Model}` — remove all index entries for a model
- `php artisan fuzzy-search:upgrade-v1 [path]` — scan codebase for v1-era API usage; exits 1 when patterns found (CI-safe gate)
- `SearchBuilder::preset()` — apply a named config preset (`ecommerce`, `blog`, `users`, `phonetic`, `exact`)
- `SearchBuilder::getFacets()` — group result counts by field values
- `SearchBuilder::didYouMean()` — O(1) spell-correction via the term dictionary (no table scan)
- `InMemorySearch` method guard — throws `BadMethodCallException` with a list of supported methods
- Observer skips unchanged saves — `SearchableIndexingObserver` no longer dispatches when no searchable column changed

### Changed

- **`_score` normalized to `[0, 1]`** across all search paths. Code comparing `_score` against an absolute threshold > 1 must be updated. Use `_raw_score` for the original BM25 float.
- **`get()` rescores before slicing** — fetches up to `max_candidates` rows, rescores all in PHP, then slices. Top-N results are the most relevant N, not the first N SQL rows. May change result order vs v1.x.
- **`using('fuzzy')`, `using('trigram')`, `using('simple')`** now route to their correct drivers instead of falling through to Levenshtein. Rankings may shift for queries using these algorithms.
- `cursorPaginate()` on `SearchBuilder` always throws `BadMethodCallException` — use `simplePaginate()` or `get()`.
- `_highlighted` is now derived from `_matches` (still backwards-compatible)
- `FederatedSearch` cross-model ranking uses normalized scores
- BM25 per-term posting cap (`bm25.max_postings_per_term`, default 50 000) bounds peak memory per request

### Security

- Column names validated against `/^[a-zA-Z_][a-zA-Z0-9_.]*$/` — blocks backtick-injection via `searchIn()`
- `ORDER BY` direction whitelisted to `asc`/`desc`
- `@fuzzyHighlight` tag argument validated against `[a-zA-Z][a-zA-Z0-9-]*` — prevents attribute injection
- `paginateIndexed()` page-size capped at 100

### Fixed

- **Lexer:** `!` is treated as an operator boundary — `=John!!!` no longer parses trailing `!` characters as part of the term
- **`searchIn()`:** Duplicate column names are deduplicated — no more triple-binding SQL
- Cache key covers all builder state — prevents result poisoning when two searches share the same `(term, columns)` pair
- `paginateIndexed()` computes the real total via `COUNT(DISTINCT model_id)` — page counts are accurate for large result sets
- `IndexManager` upsert path is race-safe under concurrent indexing
- `avg_doc_length` stays accurate across deletes and re-indexing

### Deprecated

- `useIndex()` → use `useInvertedIndex()`
- `ReindexModelJob` → use `IndexModelJob` (per-row) or `RebuildIndexJob` (bulk). Will be removed in v3.0.0.
- `Searchable::reindex()` / `Searchable::performReindex()` → use `php artisan fuzzy-search:rebuild`

### Database migrations (run automatically on `php artisan migrate`)

| Migration | What it does |
| --- | --- |
| `create_fuzzy_index_terms_table` | Creates `fuzzy_index_terms` with `term varchar(255)` and a unique index |
| `create_fuzzy_index_postings_table` | Creates `fuzzy_index_postings` with `UNIQUE (term_id, model_type, model_id)` |
| `create_fuzzy_index_meta_table` | Creates `fuzzy_index_meta` |
| `create_fuzzy_index_documents_table` | Creates `fuzzy_index_documents` |

These tables are harmless if unused. If you never use BM25 search, simply ignore them.

See the [upgrade guide](docs/UPGRADE_v1_TO_v2.md) for migration steps from v1.x.

### New config keys

| Key | Default | Purpose |
| --- | --- | --- |
| `max_candidates` | `1000` | Max SQL rows fetched before PHP rescore |
| `legacy_dispatch` | `false` | Silence `InvalidAlgorithmException` for unknown algorithm names |
| `indexing.enabled` | `false` | Observer-based auto-indexing on save/delete |
| `indexing.tokenizer` | `WhitespaceTokenizer` | Tokenizer class |
| `indexing.stemmer` | `NullStemmer` | Stemmer class (use `PorterStemmer` for English) |
| `indexing.max_tokens_per_doc` | `5000` | Max unique tokens indexed per document |
| `bm25.k1` | `1.5` | Term-frequency saturation |
| `bm25.b` | `0.75` | Length normalisation |
| `bm25.max_postings_per_term` | `50000` | SQL-side per-term posting cutoff |
| `query.max_tokens` | `32` | Parser token cap |
| `query.max_depth` | `16` | Parser nesting depth cap |
| `query.max_term_length` | `128` | Max character length of a single search term |
| `in_memory.max_items` | `10000` | Memory ceiling for `FuzzySearch::on()` |
| `in_memory.min_similarity` | `60` | Minimum similarity score for in-memory results |

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
- PHP 8.1, 8.2, 8.3, or 8.4
- Laravel 10.x, 11.x, 12.x, or 13.x
- MySQL, PostgreSQL, SQLite, SQL Server, or MariaDB

---

[Full Documentation](https://github.com/ashiqfardus/laravel-fuzzy-search)

[2.0.1]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/ashiqfardus/laravel-fuzzy-search/releases/tag/v2.0.0
[1.0.1]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/ashiqfardus/laravel-fuzzy-search/releases/tag/v1.0.0
