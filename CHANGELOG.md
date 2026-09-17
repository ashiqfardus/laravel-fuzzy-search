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
- FederatedSearch: paginate(), simplePaginate(), limitPerModel(), orderByModel().
- Relationship search: searchIn(['title', 'author.name', 'tags.name', 'comments.author.name']) filters through the relation (whereHas / EXISTS) on the LIKE path; nested paths and to-many relations supported. A dotted name is a relation only when its head is a relation method on the model, so table-qualified columns keep working.
- Relation columns are scored with their searchIn() weight (a to-many relation counts its best related row), highlighted under the dotted key (`_highlighted['author.name']`), reported in `_matches`, and rendered by `@fuzzyHighlight($post, 'author.name')`.
- suggest() proposes values from relation columns too.
- Extended syntax (`'include`, `^prefix`, `word$`, `=exact`, `!not`, `|`, grouping) works on relation columns; NOT of a relation term excludes rows with any matching related row.
- searchableText() model hook: return name => text (related data allowed) and the BM25 index stores exactly that; searchIndexQuery() is honoured by single-row reindexes too.
- Searchable::reindexRelated($foreignKey, $id) reindexes every row pointing at a related record (queued or in-process).
- BM25 results eager-load the relation paths named in `searchIn()`, so `@fuzzyHighlight` on a relation column works on the index path too.
- fuzzy_index_terms.term_length (new migration, backfilled) lets the dictionary be filtered by length; run php artisan migrate after upgrading.
- Typo-tolerant BM25: useInvertedIndex() searches expand each query term with up to bm25.fuzzy.max_expansions dictionary terms within typoTolerance() edits, closest first (damped, so an expansion contributes less than the exact term would — though a rare expansion can still outscore a common exact term, because BM25 weighs rarity); typoTolerance(0) or typo_tolerance.enabled=false keeps exact matching. getDebugInfo()['index_terms'] shows the weighted terms.
- asYouType() (and $searchable['as_you_type']): on the inverted index the last token also matches dictionary terms that start with it, capped at bm25.prefix.max_expansions.
- withSynonyms()/synonymGroup() and ignoreStopWords() now apply on the inverted-index path (synonyms at full weight; the builder's stop-word list is added to the configured locale list for that query, since a term dropped at index time cannot match — on the LIKE path it still replaces it).
- highlight() on the inverted index marks every term the query actually matched — exact tokens, typo expansions and as-you-type prefixes — with overlapping matches merged into one tag.
- Column weights apply on the inverted index: searchIn(['title' => 10, 'body' => 1]) and $searchable['columns'] weights scale each column's term frequency before BM25 saturation (BM25F-lite). Unweighted calls and legacy postings score exactly as before.
- fuzzy-search:status warns when a model still has postings without a column (rebuild with --fresh to enable weighted ranking).
- Extended syntax: ~word (typo-tolerant term, gated by typoTolerance()) and field:term scopes — a direct column, a table-qualified column by its bare name, or a relation column from searchIn()/$searchable['columns'] (author.name:john) — combine with every operator (!name:john, name:^jo, name:~jonh); unknown or ambiguous fields and malformed operators throw QuerySyntaxException.
- FuzzySearchExecuted carries resultCount, path (like|bm25|extended|in_memory) and modelClass; in-memory searches now fire it too. Existing five-argument listeners keep working.
- Persisted search analytics (opt-in): config analytics.* and the fuzzy_search_logs table record term, normalized term, model, algorithm, path, result count and latency per search — inline or on a queue, sampled, with an optional SHA-256 term hash.
- SearchAnalytics facade: popular(), zeroResults(), averageLatency() by path, volume() per day, prune().
- Commands fuzzy-search:analytics [--days=30] [--limit=20] [--zero-results] and fuzzy-search:analytics:prune [--days=].
- suggest() completes the last word from the BM25 dictionary (scoped to the model's postings, ordered by document count) when the model is indexed, falling back to the table scan otherwise; suggestFrom('index'|'table') overrides.

### Changed

- `maxPatterns()` and `performance.max_patterns` now actually cap the LIKE-pattern list for all pattern-based algorithms, including the extended syntax's `~word`.
- TrigramDriver's LIKE fallback now caps its pattern list at `performance.max_patterns` (default 100) instead of a hard-coded 10, so long terms match more widely; lower the key or call `maxPatterns()` to restore the old cap.
- **BM25 honours your constraints.** `filter()`/`filterIn()`, `where()` constraints applied before the search, and global scopes are applied *before* the ranking is cut to the page, so selective filters no longer return short or empty pages, and `paginate()` totals count only matching rows. The Scout engine applies the builder's `where()`/`whereIn()`/`whereNotIn()`/`query()` the same way.
- `_score` on paginated BM25 results is normalised against the corpus-wide maximum (as `get()` already did) instead of the page maximum, so page 2's first row is no longer always 1.0.
- suggest() on an indexed model returns dictionary completions (lower-case terms) instead of column values; use suggestFrom('table') for the v2.0 behaviour.
- `WhitespaceTokenizer` keeps combining marks (`\p{M}`) inside tokens. Indexes built from Bengali, Hindi, Thai or decomposed-accent text need one `fuzzy-search:rebuild --fresh`.
- The trigram fallback's whole-term pattern is the term itself; previously it was a concatenation of the trigrams and never matched.
- A model whose searchable columns include an accessor is reindexed on every save unless it declares `reindex_on` (previously such models never reindexed after an update).
- Synchronous indexing (`indexing.async = false`) reloads the model from the database before indexing, exactly like the queued job, so relations loaded before the change are not written to the index.
- `paginate()` now ranks across up to max_candidates rows before slicing (previously scored within the current page only) and works with extended()/searchBoolean().
- `scoring.*`, `highlighting.*`, `performance.max_patterns` and `unicode.normalize` config keys are now read (they were documented as reserved). Defaults preserve v2.0 ranking.
- FederatedSearch results are now deterministically ordered: score, then orderByModel() (or the across() order), then primary key — previously ties and withRelevance(false) results came back in database order.
- Federated `searchIn()` narrowing keeps each model's configured stop words, synonyms and accent settings.
- A search whose `searchIn()` contains a dotted name whose first segment is neither a relation on the model nor a plain table prefix (three or more segments) throws `InvalidArgumentException` naming the column when the query is built.
- didYouMean() reads the dictionary through the new term_length index (no LENGTH() SQL), uses character-based distances, and only returns [] when the fuzzy_index_terms table is missing — other database errors now surface.
- The FuzzySearch singleton reads config('fuzzy-search') live, so runtime config overrides (tests, multi-tenant setups) reach the drivers.
- The inverted index stores one posting per (term, column) — fuzzy_index_postings gained column_name (migration; existing rows keep '' and keep working). Run fuzzy-search:rebuild {Model} --fresh to get weighted ranking.
- getDebugInfo() reports algorithm "extended" for extended-syntax searches and index_ignored when useInvertedIndex() was combined with extended() (the extended syntax runs on the LIKE path).

### Removed

- `indexing.table` config key — the v1 `search_index` table name, unread by v2 outside the deprecated `performReindex()` path (which keeps its own default).
- `performance.chunk_size` and `performance.debounce_ms` config keys — never read by the package.

### Fixed

- Extended-syntax results are highlighted and scored by the query's terms (`~john`, `name:john`) instead of the literal query string; a misspelled `~jonh` still ranks by its term but only literal occurrences are marked instead of the literal query string; terms under a `!` are excluded from both.
- `query.max_term_length` now caps every extended-syntax token (a `~word` of thousands of characters could exhaust memory in the fuzzy driver) and applies to `count()`/`paginate()` on the LIKE path, not only `get()`.
- `paginate()` totals on the LIKE and extended paths now apply Eloquent global scopes (SoftDeletes, tenant scopes) — they overcounted since the Phase 1 pagination rewrite.
- `count()` now agrees with `paginate()->total()` on the extended and BM25 paths.
- extended() + useInvertedIndex(): count() and paginate() took the BM25 index path on the plain search term while get() ran the extended query. count(), paginate(), getFacets(), toSql(), getBindings() and getAnalytics() now run the extended query on the LIKE path (see getDebugInfo()["index_ignored"]); getFacets() in particular used to LIKE-match the raw query string and return empty facets.
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
- FederatedSearch: a model without the Searchable trait whose table has none of the requested searchIn() columns is now skipped instead of raising a SQL error.
- count() no longer carries the relevance ORDER BY into the aggregate (PostgreSQL rejected it).
- `config/fuzzy-search.php` shipped `unicode.normalize => true` — the inert v2.0 default for a key that is now live in v2.1.0. A published config would have silently started NFC-normalising every search term. The shipped default is now `false`, matching v2.0 behaviour; the published `scoring`, `performance.max_patterns` and `highlighting.enabled` defaults are also pinned to match what the code actually uses.
- FederatedSearch `paginate()`/`simplePaginate()` totals now count only reachable rows: when `limitPerModel()` caps a model's contribution, that model's share of the total is capped too — previously the total (and page count) could promise more rows than the search would ever return.
- FederatedSearch `paginate()` could duplicate or skip a row across a page boundary when scores tied; each model's results are now ordered by `stableRanking()` (Searchable models) or the primary key (query-builder fallback) before the per-page limit is applied.
- UUID/ULID primary keys are verified end to end on the inverted index, Scout engine, filter(), stableRanking() and fuzzy-search:rebuild (which now chunks by key with chunkById()); the README no longer lists them as unsupported (B5).
- README Scout recipe: the dual-trait example now resolves bootSearchable() (it was a PHP fatal) and boots Scout's observers from booted() (B26).
- MySQL/MariaDB: the dictionary column fuzzy_index_terms.term now uses utf8mb4_bin, so café/cafe (and résumé/resume) are distinct terms as on the other drivers; indexing a document containing both no longer fails with "Undefined array key" (B25). Run php artisan migrate — the migration rewrites the table. Rebuild existing indexes too (php artisan fuzzy-search:rebuild {Model}): variants that the old collation collapsed into a single dictionary row stay collapsed until the index is rebuilt.

### Database compatibility

First release where the full test suite runs against SQLite, MySQL 8, MariaDB 11.4, PostgreSQL 14 and SQL Server 2022 in CI.

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
