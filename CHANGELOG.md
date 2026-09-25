# Changelog

<!-- markdownlint-disable MD024 -->

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] — 2026-09-18

Upgrading from 2.0.x: read the [upgrade guide](https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md) (`docs/UPGRADE_v2.0_TO_v2.1.md` in the package), which opens with every behaviour change and what to do about it.

**Behaviour changes and breaking bits.** These change what existing code gets back from 2.0; each is detailed in the sections below and in the guide.

- An empty (or whitespace-only) search term throws `EmptySearchTermException` from every terminal, `count()`, `paginate()` and `getFacets()` included, unless `allow_empty_search` is on.
- `min_search_length` applies on every path: a one-character term matches nothing on `paginate()`, `count()` and `getFacets()` too. A model with no searchable column matches nothing instead of every row.
- `orderBy()` on the builder replaces the relevance order on every path; it used to be re-sorted away or ignored.
- `similar_text` enforces `similar_text.min_percentage` (70): far fewer rows on long columns. `0` restores 2.0's results.
- Accent folding (`unicode.accent_insensitive`, on by default) searches the accent-free form beside the typed term; it used to replace it. PostgreSQL's `unaccent()` runs only on an explicit opt-in.
- Auto-detection picks text columns only, and never a hidden or secret-named column; zero-config models are now indexed.
- `searchIn()` relation paths need a `Relation` return type or a path declared in `$searchable['columns']`.
- `_highlighted` is escaped for every column; `_highlighted`, `_matches` and `_debug` leave hidden columns out.
- The `cache.*` config is read: a published `'enabled' => true` caches every search, `ttl` counts seconds, and every cache key changed.
- `paginate()` and `simplePaginate()` clamp `perPage` to between 1 and `max_candidates` on every path, federated search included.
- BM25: typo-tolerant by default, constraints applied before the page is cut, one `_score` scale across pages, idf and dictionary per model, column weights after a rebuild (the Scout engine too), and ties by key.
- Indexing runs after the save's transaction commits, indexes the row as stored, and with `indexing.async` off reports an index error instead of throwing it from `save()`.
- `suggest()` on an indexed model completes from the dictionary (lower-case terms); `suggest()` and `didYouMean()` never offer a hidden column's words.
- Extended syntax: `~word` and `field:` are operators, `!` negates only at the start of a token, `""` is skipped, and a `!` term keeps rows with NULL columns.
- `FederatedSearch` searches each model with its own configuration, orders ties deterministically and counts matches, not page rows. Scout `orderBy()` orders and `hybrid()` throws.
- A backslash in a search term is literal on MySQL, MariaDB and PostgreSQL.
- Config keys documented as reserved are read (`scoring.*`, `highlighting.*`, `performance.max_patterns`, `unicode.normalize`); three unread keys are removed; `debounce()`, `locale()` and `minMatchLength()` are deprecated. `ignoreStopWords('en')` prefers the shorter configured list.
- Commands exit 1 on input they cannot use.
- Five new migrations; four of them alter the index tables, which takes a while on a large index. Run them with the indexing workers paused (see below).

### Added

- PHP 8.5 support: the package allows `^8.5`, and CI runs it with Laravel 12 and 13 on every database.
- `fallback()` now runs: when the primary algorithm (LIKE-pattern or BM25) returns no rows, the search is retried with each fallback in order. Applies to `get()`, `first()`, `paginate()`, `simplePaginate()` and `count()`; filters and prior `where()` constraints carry over, and one `FuzzySearchExecuted` event fires per attempt.
- `$searchable['reindex_on' => [...]]` declares the real columns that trigger a reindex for accessor-backed searchable fields (e.g. `brand_id` behind a `brand_name` accessor). Exposed as `Searchable::getReindexTriggers()`.
- `searchIndexQuery(Builder $query): Builder` model hook: `fuzzy-search:rebuild` (sync and `--async`) and `RebuildIndexJob` load rows through it, so relation-backed columns can be eager-loaded instead of queried per row.
- `indexing.job` config (`tries` 3, `backoff` [10, 60, 300], `timeout` 120) bounds retries of `IndexModelJob` and `RebuildIndexJob`.
- `bm25.candidate_chunk` config (default 200): chunk size used when checking the BM25 ranking against a constrained query.
- SearchBuilder forwards Eloquent/Query Builder calls (where*, whereHas, with*, join*, when, local scopes, …) and adds query(Closure) — no more filter()-only workarounds. Cache keys now include forwarded constraints.
- FederatedSearch: paginate(), simplePaginate(), limitPerModel(), orderByModel().
- Relationship search: searchIn(['title', 'author.name', 'tags.name', 'comments.author.name']) filters through the relation (whereHas / EXISTS) on the LIKE path; nested paths and to-many relations supported. A dotted segment is a relation only when it is a public, non-static relation method with a Relation return type (or on a path listed in $searchable['columns']), defined neither by Laravel nor by this package; a name that is not a method, or whose first part names a table of the query (the FROM table, a join, or an alias), stays a table-qualified column.
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
- Persisted search analytics (opt-in): config analytics.* and the fuzzy_search_logs table record term, normalized term, model, algorithm, path, result count and latency per search — inline or on a queue, sampled, with an optional keyed SHA-256 term hash (HMAC with APP_KEY).
- SearchAnalytics facade: popular(), zeroResults(), averageLatency() by path, volume() per day, prune().
- Commands fuzzy-search:analytics [--days=30] [--limit=20] [--zero-results] and fuzzy-search:analytics:prune [--days=].
- suggest() completes the last word from the BM25 dictionary (scoped to the model's postings, ordered by document count) when the model is indexed and the base query carries no `where()`, `join()` or scope (SoftDeletes aside), falling back to the table scan otherwise, which honours them; suggestFrom('index'|'table') overrides, and 'index' stays model-wide.
- NgramTokenizer (character n-grams, default 2) and ScriptAwareTokenizer (whitespace for Latin/Cyrillic/Indic runs, n-grams for Chinese/Japanese/Korean runs) for the BM25 index; opt-in via indexing.tokenizer or $searchable['tokenizer'].
- Per-model index pipelines: $searchable['tokenizer'], ['stemmer'], ['stemmer_language'], ['locale'] override the global indexing config for that model (query-time processing follows); rebuild --fresh after changing them.
- indexing.accent_insensitive folds accents at index and query time on the BM25 path (ext-intl decomposition when available, the built-in map otherwise); the LIKE path's accentInsensitive() uses the same folding.
- Stop-word lists for it, pt, nl and ru; any stop_words.{locale} entry may be a path to a one-word-per-line file; ignoreStopWords('xx') reads the configured list first.
- Filament (v3/v4/v5) global search: the HasFuzzyGlobalSearch trait on a Resource runs the package's search over getGloballySearchableAttributes() (nested attribute groups are flattened), applying the model's $searchable configuration — algorithm, typo tolerance, as-you-type, stop words, synonyms, accents, options — with the resource's $fuzzySearchAlgorithm/$fuzzyTypoTolerance overriding it; honours getGlobalSearchEloquentQuery()/modifyGlobalSearchQuery(), and adds highlighted details. Filament stays optional.
- Searchable::searchOn(Builder $query, string $term, ?array $columns = null) builds a SearchBuilder on an existing Eloquent query with the model's $searchable configuration applied (Model::search() is now that call on a fresh query); $columns replaces the configured column list instead of adding to it.
- FuzzySearch::tableSearch() returns the (query, search) closure Filament tables expect for Column::searchable(query: …) (v3, v4, v5) and Table::searchUsing() (Filament v4+ only — the method does not exist in v3), applying the fuzzy predicate to the named columns (or the model's $searchable columns), qualified with the table name, with the typed term trimmed and capped at query.max_term_length.
- JSON API resources: FuzzySearchResource (model attributes + _score, _raw_score, _highlighted, _matches, _model_type) and FuzzySearchCollection::fromBuilder() with meta.query, meta.algorithm, meta.latency_ms and meta.suggestions (didYouMean() when nothing matched); SearchBuilder::lastExecution() exposes the last FuzzySearchExecuted event.

### Changed

- **`min_search_length` applies on every search path.** `paginate()` (an empty page, total 0), `count()` (0), `getFacets()`, `FuzzySearch::on()` and the Scout engine's `search()`/`paginate()` now honour `min_search_length` the way `get()` and `simplePaginate()` always have: a plain term shorter than the minimum (in characters) matches nothing and fires no `FuzzySearchExecuted`. `FederatedSearch` honoured it only for models that use the `Searchable` trait; it now covers models without the trait too, `getCounts()` included. This is a behaviour change for one-character searches with the shipped default of 2, which used to page, count and facet normally. `extended()`/`searchBoolean()` queries are still not measured, and the `whereFuzzy`-style macros, the `Fuzzy` scopes and `tableSearch()` are not governed by it.
- **A search with no searchable column matches nothing instead of every row.** A model that declares no `$searchable['columns']` and has no auto-detectable column (every text column `$hidden`, say), searched without `searchIn()`, added no condition to `search()`, `searchOn()`, the `searchFuzzy()` scope and `useInvertedIndex()`, and returned every row. It now matches nothing, like a term below `min_search_length`: no rows, a total and count of 0, empty facets, no `FuzzySearchExecuted` and no cache write. It contributes nothing to `FederatedSearch`, and `FuzzySearch::tableSearch()` with no column matches nothing. `FuzzySearch::on()` without `searchIn()` matches nothing too (it returned every item; an empty term still lists them), and a `FederatedSearch` model without the `Searchable` trait and without `searchIn()` is searched only on its declared columns, else on whichever of the guessed `name` and `title` columns its table has — with none, it contributes nothing instead of throwing on MySQL, MariaDB, PostgreSQL and SQL Server (on SQLite a term such as `name` matched every row). A `SearchBuilder` on a plain query builder without `searchIn()` matches nothing too, unless `useInvertedIndex(Model::class)` searches that model's index; a `searchableText()` hook still searches on the index. A model whose table cannot be read is not taken for one with no column: the query runs and its database error surfaces (on `useInvertedIndex()` the table is only read when the index has matches, so there an empty index returns nothing without an error). `extended()`/`searchBoolean()` still throw `SearchableColumnsNotFoundException`.
- `paginate()` applies one page-size rule on every path: `perPage` is clamped to `max_candidates` (default 1000). The BM25 path used to clamp at a hard-coded 100 while the LIKE and extended paths clamped at nothing, so `paginate(200)` returned 200 rows without `useInvertedIndex()` and 100 with it. `paginate(200)` now returns 200 everywhere; a `perPage` above `max_candidates` (previously unbounded on the LIKE path) now returns `max_candidates`, and one below 1 returns a one-row page (`paginate(0)` used to throw `DivisionByZeroError`). `simplePaginate()` is clamped the same way: it accepted any page size, so on the index path `simplePaginate($request->per_page)` hydrated that many models. `FederatedSearch::paginate()` and `simplePaginate()` clamp `perPage` the same way (`FederatedSearch::paginate(0)` threw `DivisionByZeroError` too). `take()`/`limit()` are not clamped (on the LIKE and extended paths the `max_candidates` candidate window still bounds `take()`).
- `_highlighted` values for columns that did not match are now HTML-escaped like matched ones, so the array is uniformly safe to render as HTML.
- `maxPatterns()` and `performance.max_patterns` now actually cap the LIKE-pattern list for all pattern-based algorithms, including the extended syntax's `~word`.
- TrigramDriver's LIKE fallback now caps its pattern list at `performance.max_patterns` (default 100) instead of a hard-coded 10, so long terms match more widely; lower the key or call `maxPatterns()` to restore the old cap.
- **BM25 honours your constraints.** `filter()`/`filterIn()`, `where()` constraints applied before the search, and global scopes are applied *before* the ranking is cut to the page, so selective filters no longer return short or empty pages, and `paginate()` totals count only matching rows. A `join()` that narrows the rows counts as such a constraint (a one-to-many join counts each model once, not once per joined row), and an ungrouped `where(A)->orWhere(B)` is parenthesised before the ranked ids are applied (unparenthesised, `count()` and `paginate()->total()` counted every row matching `A`). The Scout engine applies the builder's `where()`/`whereIn()`/`whereNotIn()`/`query()` the same way.
- `_score` on BM25 results is normalised against the best-ranked row the query can see, the same row on every page, instead of the page maximum, so page 2's first row is no longer always 1.0 on `paginate()`. Under a `where()`, a join, a scope or `filter()`, the top result the query lets through scores 1.0 (see Security).
- suggest() on an indexed model returns dictionary completions (lower-case terms) instead of column values; use suggestFrom('table') for the v2.0 behaviour.
- `WhitespaceTokenizer` keeps combining marks (`\p{M}`) inside tokens. Indexes built from Bengali, Hindi, Thai or decomposed-accent text need one `fuzzy-search:rebuild --fresh`.
- The trigram fallback's whole-term pattern is the term itself; previously it was a concatenation of the trigrams and never matched.
- A model whose searchable columns include an accessor is reindexed on every save unless it declares `reindex_on` (previously such models never reindexed after an update).
- Synchronous indexing (`indexing.async = false`) reloads the model from the database before indexing, exactly like the queued job, so relations loaded before the change are not written to the index.
- `paginate()` now ranks across up to max_candidates rows before slicing (previously scored within the current page only) and works with extended()/searchBoolean().
- `scoring.*`, `highlighting.*`, `performance.max_patterns` and `unicode.normalize` config keys are now read (they were documented as reserved). Defaults preserve v2.0 ranking.
- FederatedSearch results are now deterministically ordered: score, then orderByModel() (or the across() order), then primary key — previously ties and withRelevance(false) results came back in database order.
- **`FederatedSearch` searches each model with its own algorithm and typo tolerance.** Without `using()` or `typoTolerance()`, every `Searchable` model was searched with `fuzzy` and a typo tolerance of 2, overriding its `$searchable['algorithm']` and `['typo_tolerance']`. Each model now searches the way `Model::search()` does, with its own configuration — stop words, synonyms and accent settings included, which `searchIn()` narrowing keeps; `using()` and `typoTolerance()` still override every model, and `typoTolerance()` now reaches models without the trait too. `->using('fuzzy')->typoTolerance(2)` restores the old results.
- `FederatedSearch` searches a model with only the `Fuzzy` trait with its own `$fuzzyAlgorithm` and `$fuzzyOptions` — the ones its `fuzzy()` scope uses — instead of LIKE with no options; `using()`, `options()` and `typoTolerance()` override them. A `Fuzzy` model that declares no `$fuzzyAlgorithm` is searched with `default_algorithm` (shipped: `fuzzy`), as its scope is.
- `FederatedSearch`: a model without the `Searchable` trait contributes at most `max_candidates` rows to `get()`, `paginate()`, `simplePaginate()` and `getCounts()`, as a `Searchable` model always has. Its matches were counted and fetched without limit, and a deep page fetched offset + perPage rows from it.
- A search whose `searchIn()` contains a dotted name whose first segment is neither a relation on the model nor a plain table prefix (three or more segments) throws `InvalidArgumentException` naming the column when the query is built.
- didYouMean() reads the dictionary through the new term_length index (no LENGTH() SQL), uses character-based distances, and treats only a missing `fuzzy_index_terms` table as "nothing to suggest"; other database errors now surface.
- didYouMean() ranks the closest term first: distance, then document count, then confidence, then the term itself, so the order is deterministic. It used to rank by document count first, so `jonh` offered `com` (three edits away, on every email address) before `jon` (one edit). Its maximum distance now scales with the term's length (1 edit for 2–3 characters, 2 for 4–5, 3 from 6) instead of a fixed 3, which let a 4-letter term match almost any short word. BM25 typo expansion keeps `typoTolerance()`.
- BM25 typo expansion and `asYouType()` prefix expansion draw their candidates from the searched model's own dictionary terms only. The candidate pool and the prefix slots used to be filled from every indexed model's terms, so another model's popular terms could crowd this model's close neighbours out, and rankings on the typo-tolerant and as-you-type index paths can change.
- The FuzzySearch singleton reads config('fuzzy-search') live, so runtime config overrides (tests, multi-tenant setups) reach the drivers.
- The inverted index stores one posting per (term, column) — fuzzy_index_postings gained column_name (migration; existing rows keep '' and keep working). Run fuzzy-search:rebuild {Model} --fresh to get weighted ranking.
- IndexManager::processTerms() accepts an optional model class (third argument) so query-time processing can use that model's pipeline; the two-argument form is unchanged.
- `ignoreStopWords('xx')` now reads `stop_words.{xx}` from config first and falls back to the builder's built-in en/de/fr/es lists only when that key is absent — previously it always used the built-in list regardless of config. An untouched config is unaffected only for locales whose built-in and configured lists happen to match; `en` differs (config ships the 14-word list, the builder's built-in `en` list has 36 words), so `ignoreStopWords('en')` now filters fewer words than in v2.0. Pass an array (`ignoreStopWords([...])`) for a custom list, unaffected by this change. Configured lists are now lower-cased before use (a capitalised config stop word used to match nothing).
- **`orderBy()` on the builder is now the result order on every path.** It used to be re-sorted away by relevance on the LIKE path and ignored on the extended and index paths. Several calls apply in order, `_score` is still attached, and `stableRanking()` is the final tiebreak (now on the extended path too). Code that called `orderBy()` only to shape the candidate window should order the underlying query instead: `->query(fn ($q) => $q->orderBy(...))`.
- **The `cache.*` config is now read.** `cache.enabled` caches every `get()`, `first()` and `simplePaginate()` for `cache.ttl` **seconds** in the `cache.driver` store, under generated keys that start with `cache.prefix`; `cache(0)` opts one query out. An app that published `'enabled' => true` (the 2.0 and 2.1 README showed it) now caches every such search, with no `FuzzySearchExecuted` event or analytics row on a hit. A `ttl` written as minutes is now read as seconds. `cache()`'s default argument is now `null`, meaning `cache.ttl` seconds (the shipped 3600 equals the old 60-minute default); `cache(null)` used to mean "not cached". `getDebugInfo()['cache_ttl']` reports seconds. A search with a `customScore()` closure is cached only under a key you name.
- A cached search stores no relations. Every read loads the current request's eager loads, with their constraints, which costs the eager-load queries on a hit.
- Changing any `fuzzy-search` config value now starts a fresh set of cached searches.
- Relevance scores of multi-term `extended()` queries: each leaf term is scored on its own and the scores add up (single-term queries score exactly as before). The similarity floor is spent only within a 255-character budget of leaf terms; later leaves score by tier.
- When an accented term is searched in both forms, relevance takes the better of the two per row (for `extended()`, per leaf) instead of adding them up; unaccented terms score exactly as in 2.0.
- A dotted `searchIn()` name whose first part names a table of the query (the FROM table, a join, or an alias) is that table's column; no method is looked at.
- **`similar_text` enforces `similar_text.min_percentage`.** The key (default `70`) and the `fuzzySimilar()` scope's `$minPercentage` were never read, so `similar_text` matched every value that contains the term. Because the term is contained in every match, PHP's `similar_text()` percentage is `200·t / (t + v)`. The driver now adds the same condition in SQL as a character-length bound, `CHAR_LENGTH(col) <= floor(t·(200 − p) / p)` (`LENGTH(col)` on SQLite, `LEN(CAST(col AS NVARCHAR(MAX)) + N'x') - 1` on SQL Server, where a character outside the BMP counts as 2), on every path: `search()`, `count()`, `paginate()`, the macros and the scope. On MySQL and MariaDB under an accent-insensitive collation (`utf8mb4_unicode_ci`, `utf8mb4_0900_ai_ci`), the LIKE also matches accent variants (`Jöhn` for `john`). There the term is contained only up to accents, so the bound approximates PHP's percentage, and it still applies because an accent variant has the same length. Under `tokenize()`, every token is bounded by the whole search term's length (whole-value similarity), so `"john doe"` still finds `"John Doe"`. With the default `70`, a match may be at most about 1.86 times the term's length, so on long columns `similar_text` now returns far fewer rows. Set `similar_text.min_percentage` to `0` to restore 2.0's results. A per-call `min_percentage` option overrides the config, and `0` or `null` turns the bound off for that call. `fuzzySimilar($term, $columns, 0)` turns it off too.
- **`model_id` is 191 characters wide** on `fuzzy_index_postings` and `fuzzy_index_documents` (it was 36), so string keys longer than a UUID can be indexed. The new migration below widens it.
- **With `indexing.async` off, an index error no longer fails the save.** The index is written after the save's transaction commits, and an error there (a deadlock, a lock-wait timeout, a database hiccup) is passed to your exception handler (`report()`) instead of being thrown, so the committed write and your own after-commit callbacks go on. Run `php artisan fuzzy-search:rebuild "App\Models\YourModel"` to repair the index. Before, the same error was thrown from `save()` and rolled back the caller's transaction. With `indexing.async` on, a failure to queue the job is reported the same way.
- The Scout engine's `update()` writes a collection of models in one transaction, instead of one transaction per model, so an error on one model (a `searchableText()` value that cannot be indexed, or a write that loses all three deadlock attempts) leaves none of that collection indexed, where before the models ahead of it were.
- The indexer indexes a saved row as it is stored when it writes, not the instance it was handed: the observer, `IndexModelJob` and `fuzzy-search:rebuild` read it through the model's query (a row that query hides, or a trashed row, leaves the index), and the Scout engine's `update()` reads it as Scout's jobs do (without global scopes, keeping a trashed row under `scout.soft_delete`). Unsaved changes on an instance are not indexed.

### Deprecated

- `debounce()` — a server-side debounce cannot exist; it now raises `E_USER_DEPRECATED` and will be removed in v3.0.0. Debounce on the client (`wire:model.live.debounce.300ms`, a JS timer).
- `locale()` and `minMatchLength()` — never implemented: both values were only ever read into the cache key, so neither changed a single query. They now raise `E_USER_DEPRECATED`, stay no-ops and will be removed in v3.0.0. Select a stop-word list with `ignoreStopWords('de')` (query time) or `$searchable['locale']` (index pipeline); set a minimum term length with `min_search_length` / `typo_tolerance.min_word_length`. `partialMatch()` is documented as a no-op instead (LIKE patterns already match substrings) and is not deprecated. The README "Partial Match Support" and "Locale awareness" sections, `docs/tokenization.md`'s locale section and the `ecommerce`/`exact` preset descriptions no longer promise behaviour that never existed.

### Removed

- `indexing.table` config key — the v1 `search_index` table name, unread by v2 outside the deprecated `performReindex()` path (which keeps its own default).
- `performance.chunk_size` and `performance.debounce_ms` config keys — never read by the package.

### Fixed

- **Zero-config models were never indexed.** A model that uses the `Searchable` trait without declaring `$searchable['columns']` — the README Quick Start — got `[]` from `getSearchableColumns()`, so the BM25 indexer wrote no postings, `SearchableObserver` maintained no `*_metaphone` shadow columns, `FuzzySearch::tableSearch()` added no predicate and `extended()` found no columns, while `search()` itself auto-detected them and worked. The accessor now falls back to the auto-detected columns. It also accepts the list form `['columns' => ['name', 'email']]`, which used to report the column names as `[0, 1]`. Auto-detection now selects string-like attributes only, and never hidden attributes (see Security), on the LIKE path as well as the index path: a column cast to an enum, `array`, `json`, `object`, `collection` or a custom cast class is never auto-selected (a later string column can take the freed slot). Neither are `encrypted` and `hashed` casts, in any letter case: their decrypted text or hash would otherwise be written to the index and served by `suggest()` and `didYouMean()`. An auto-detected column is indexed as the model's raw attribute value, not through a get accessor (see Security). A value that still turns out not to be text is skipped rather than thrown, so enabling indexing cannot make a save throw. Declare a column in `$searchable['columns']` to search or index anything else; a declared column that cannot be indexed as text still raises an error naming it. The detected list is resolved once per model class, connection and table, on the model's own connection, instead of on every save and every indexed row. `fuzzy-search:rebuild` now warns when a model produced no index terms instead of printing "Done." alone, and reports how many records it indexed.
- **Saving a partially selected model wiped its `*_metaphone` shadow columns (present in 2.0).** After `select(['id', 'title'])`, a save set the shadow column of each searchable column it had not loaded to NULL, or threw `MissingAttributeException` under `Model::preventAccessingMissingAttributes()`. A column the save never loaded did not change, so its shadow column is now left as it is.
- **A SearchBuilder is reusable again.** `get()`, `toSql()`, `getBindings()`, `count()`, `paginate()`, `getFacets()` and `getAnalytics()` appended the search's WHERE group and relevance ORDER BY to the caller's query, so the README's own `toSql()`-next-to-`get()` debugging pattern executed the search twice over, a second `get()` four times, and the query grew without bound (results stayed correct — an AND of identical predicates). Every terminal call now compiles onto a clone, so the base query is never mutated and constraints added after a terminal call still apply. `first()` also restores the limit it sets — `first()` followed by `get()` no longer returns a single row. `fallback()` retries start from the same pristine base, and a nested terminal call (a listener inspecting the builder mid-search) starts from it too.
- **`suggest()` no longer runs inside the previous search.** It clones the builder's query, which `get()` had already filled with the search conditions, so the Livewire recipe in `docs/integrations.md` — suggest on the same builder when the page came back empty — could only ever return `[]`. Constraints the caller applied still scope table-scan and `'auto'` suggestions (see Security); the search's own conditions no longer do. `didYouMean()` never ran inside the search and was not affected.
- **The Scout engine ranked unweighted.** `search()` and `paginate()` called `rank()` without the model's column weights, so a `$searchable['columns']` map of `['title' => 10, 'description' => 5]` ranked as if both columns weighed 1 — while the README promised Scout shares "the same relevance scoring" as `useInvertedIndex()`. Both now resolve the weights the way `Model::search()` does (`Searchable::getSearchableColumnWeights()`), and `paginate()`'s total skips zero-weight columns like the builder's `count()` does. A zero-config model is affected too: its auto-detected columns carry the detection weights (name/title 10, email 8, …), so Scout now orders those models the way `Model::search()` does instead of flat.
- **Scout `hybrid()` ran a plain keyword search.** Scout 11.6 added `Builder::semantic()` and `Builder::hybrid()`. Scout rejects `semantic()` for an engine without semantic support, but `hybrid()` reached this engine, which ignored it and returned an ordinary BM25 page. The engine is keyword-only, so `hybrid()` now throws `Laravel\Scout\Exceptions\NotSupportedException` from `search()` and `paginate()`. The Scout constraint (require-dev and `suggest`) no longer lists `^12.0`: no Scout 12 exists to test against; `^10.0|^11.0` is supported, and CI runs the latest 11.x across the matrix and the latest 10.x in a job of its own.
- **Scout engine totals and `scout:delete-index`.** `search()` reported the page size as `'total'` — what `raw()['total']` returns — instead of the match count; it now counts the ranked matches that satisfy the builder's constraints, as `paginate()` already did. `deleteIndex()` handed Scout's index *name* to a flush keyed by model class, so `scout:delete-index` deleted nothing; it now flushes every indexed model whose `indexableAs()` is that name.
- **`FederatedSearch::getCounts()` counted the page, not the matches.** It grouped `get()`'s result, so a `limit(1)` search reported 1 for a model that matched 40 — while the README sells it as "counts per model". It now returns the same per-model counts `paginate()->total()` is the sum of (one COUNT per model instead of a full search), so the two can never disagree; a model that matches nothing reports 0 instead of being absent.
- **`FederatedSearch::paginate()->total()` promised unfillable pages.** A model's share of the total was its full match count, but a ranked search never hands over more than `max_candidates` rows per model, so a model with more matches than that produced pages that came back empty. Each model's share is now capped at `max_candidates` as well as at `limitPerModel()`, which is what the README always claimed.
- **`getFacets()` threw on MySQL 8 and PostgreSQL** for any relevance-ordered search — that is, every search that did not opt out with `withRelevance(false)`. The prepared query's relevance `ORDER BY (CASE WHEN …)` survived into the grouped aggregate, which MySQL rejects under `only_full_group_by` (1055) and PostgreSQL under 42803. The facet aggregate now drops the search's ordering and applies its own: highest count first, then by value.
- **A literal `%` or `_` in a search term matched nothing on SQLite and SQL Server, and on MySQL and MariaDB under the `NO_BACKSLASH_ESCAPES` SQL mode (present since 1.x), and a literal `\` was lost on MySQL, MariaDB and PostgreSQL.** The package escaped `%` and `_` with a backslash, which only MySQL, MariaDB and PostgreSQL read as the LIKE escape character. SQLite and SQL Server have none by default, so the backslash became one more character the value had to contain: `search('50%')` and `search('snake_case')->using('simple')` returned nothing, and so did `extended()`, the query-builder macros, `suggest()`'s table scan, the relevance ordering and, on SQL Server, the dictionary's prefix lookup. MySQL and MariaDB stop reading it under the `NO_BACKSLASH_ESCAPES` SQL mode, where such a term matched nothing in the same way. On MySQL, MariaDB, SQLite and SQL Server the package now escapes with `!` and every LIKE carries `ESCAPE '!'`, which means the same in every SQL mode, so a literal `%`, `_` or `!` matches itself there, and so does `[` on SQL Server, where it opened a character class (`[draft]` matched any value with a d, r, a, f or t); a backslash is an ordinary character on all four. PostgreSQL keeps its default backslash escape, with no `ESCAPE` clause, and now escapes a backslash in a term too. Before, MySQL, MariaDB and PostgreSQL read a backslash in a term as an escape, so `back\slash` matched `backslash` and not itself (see the upgrade guide). For a term without a backslash, the SQL and bindings on PostgreSQL are unchanged; on MySQL and MariaDB every LIKE gains `ESCAPE '!'`, and a `!` in a term is escaped.
- **`extended()` / `searchBoolean()`: a `!` negates only as the first character of a token (present since 2.0.0).** The lexer ended a word at any `!` and read what followed as a NOT term, so `yahoo!mail` meant `yahoo !mail` and excluded the very row it named, `'wow!` searched for `wow`, and a `!` right after another operator dropped that operator: `^!a`, `'!a`, `=!a` and `!!a` all meant NOT `a`, and `~!a` threw. `!` is now an operator only as a token's first character, as `~` and `field:` are operators only at the start of a token; anywhere else it is part of the term. This changes the meaning of `a!b`; of 2.0.0's `=John!!!`, whose trailing `!` characters are now part of the term; and of `^!a`, `'!a`, `=!a`, `~!a` and `!!a`, which now search for `!a` (as a prefix, a substring, an exact value, a typo-tolerant term, and NOT `!a`). Write `a !b` for the old meaning of `a!b`, and `!a` for that of `^!a` (see the upgrade guide). A `!` right after a field scope's colon (`name:!john`) still throws, now with a message that says to write `!name:john`.
- The `searchFuzzy()` scope (`Model::searchFuzzy($term)`) weighed every column 1 — a null check that was always true left the weighted branch dead — and threw `InvalidArgumentException` for list-form columns (`['columns' => ['name', 'email']]`). It now searches the configured columns with their `$searchable['columns']` weights; columns passed as its second argument replace them, a plain list still weighing 1 each.
- A BM25 search that matched nothing fired no FuzzySearchExecuted, so zero-result analytics never saw index-path misses.
- A failed analytics insert (missing table, oversized value, DB blip) no longer throws out of the search; it is reported and the search returns normally.
- The analytics listener was registered only when `analytics.enabled` was already `true` at boot, so enabling it at runtime (`config([...])`, a per-tenant toggle) never recorded anything although the listener re-checks the flag per search. It is now always registered and the flag alone decides. A `latency_ms` above 999999.99 — the `decimal(8,2)` column's maximum — failed the insert on MySQL (strict mode) and PostgreSQL and lost the row; it is now clamped to that maximum. Logged strings are cut to their column width in UTF-16 units, so a term full of emoji or other characters outside the BMP no longer overflows SQL Server's `nvarchar(255)` and loses the row.
- The deprecated `fuzzy-search:index` command died on SQLite and SQL Server with Laravel's "This database driver does not support fulltext index creation." while creating its legacy `search_index` table (it carries a FULLTEXT index). It now stops with a message pointing to `fuzzy-search:rebuild`; a `search_index` table that already exists is still used on any database.
- simplePaginate() reported its look-ahead row in FuzzySearchExecuted::resultCount.
- Extended-syntax results are highlighted and scored by the query's terms (`~john`, `name:john`) instead of the literal query string; a misspelled `~jonh` still ranks by its term but only literal occurrences are marked instead of the literal query string; terms under a `!` are excluded from both.
- `query.max_term_length` now caps every extended-syntax token (a `~word` of thousands of characters could exhaust memory in the fuzzy driver) and applies to `count()`/`paginate()` on the LIKE path, not only `get()`.
- `paginate()` totals on the LIKE and extended paths now apply Eloquent global scopes (SoftDeletes, tenant scopes) — they overcounted since the Phase 1 pagination rewrite.
- `count()` now agrees with `paginate()->total()` on the extended and BM25 paths.
- extended() + useInvertedIndex(): count() and paginate() took the BM25 index path on the plain search term while get() ran the extended query. count(), paginate(), getFacets(), toSql(), getBindings() and getAnalytics() now run the extended query on the LIKE path; getDebugInfo() reports algorithm "extended" for extended-syntax searches and index_ignored when useInvertedIndex() was combined with extended(). getFacets() in particular used to LIKE-match the raw query string and return empty facets.
- **Multibyte terms:** `FuzzyDriver`, `LevenshteinDriver`, `TrigramDriver` and `SoundexDriver` sliced the search term by byte, producing invalid UTF-8 LIKE patterns for Bengali, Hindi, Thai and accented Latin (PostgreSQL rejected them; other databases never matched). `min_search_length` and `query.max_term_length` also counted bytes. All now work per character.
- **Multibyte case-insensitivity:** relevance scoring and highlighting lower-cased with byte `strtolower()`, which folds ASCII only, so `привет` scored `ПРИВЕТ мир` as a fuzzy near-miss instead of a prefix match and highlighted nothing (likewise `über`/`ÜBER`, `güneş`/`GÜNEŞ`). Scoring now lower-cases with `mb_strtolower()` and highlighting matches case-insensitively per character, so `highlight('mark')` renders `<mark>ПРИВЕТ</mark> мир`. `_matches` indices remain inclusive `[start, end]` **byte** offsets into `value` — the unit `@fuzzyHighlight` slices by (the 2.0.0 entry below called them character offsets; they have always been bytes); ASCII offsets are unchanged. The indexer measured the 255-character term limit in bytes, silently dropping Cyrillic tokens over 127 characters and Bengali/Hindi ones over 85; it now counts characters, a character outside the BMP counting twice because SQL Server's `nvarchar(255)` holds 255 UTF-16 units. `suggest()` let a one-character multibyte term past its two-character minimum; it now counts characters too.
- Accessor-backed searchable fields never reindexed on update (`wasChanged()` cannot see them), so a product moved to another brand stayed findable under the old brand.
- `restore()` on a soft-deleted model did not re-index it. A soft delete removes the row from the index, and `restore()` saves the model without changing a searchable column, so the observer skipped it and the restored row stayed out of `useInvertedIndex()` results until the next `fuzzy-search:rebuild`. A change to the model's deleted-at column (read through `getDeletedAtColumn()`, so a renamed `DELETED_AT` works) now triggers a reindex.
- `fallback()` stored its algorithms and never ran them.
- `typoTolerance(0)` and `(1)` were ignored by the default fuzzy algorithm — every typo pattern was always generated. Pattern families are now gated by the tolerance level and `typo_tolerance.min_word_length`.
- MariaDB connections (driver name "mariadb" on Laravel 11+) now use native SOUNDEX(), the Levenshtein UDF path, quoted identifiers and the MySQL flush branch — previously every MySQL-only branch silently fell back to generic SQL.
- **PostgreSQL and SQL Server:** BM25 indexing failed on every write with an "ambiguous doc_count" error inside the upsert. The inverted index now works on both.
- **SQL Server:** BM25 indexing threw "This database engine does not support inserting while ignoring errors"; meta rows are now created with a portable upsert.
- **SQL Server:** didYouMean() used LENGTH(), which SQL Server does not have; MySQL/MariaDB now use CHAR_LENGTH() so multibyte terms are measured in characters.
- **PostgreSQL:** using('soundex') without fuzzystrmatch returned no rows for capitalised names (case-sensitive LIKE).
- **SQL Server:** indexing a document containing a purely numeric token (e.g. "10") failed with "Conversion failed when converting the nvarchar value"; term bindings are now always strings.
- stableRanking() ordered by a hard-coded "id" column and broke on UUID / custom-key models. It now orders an Eloquent search by the model's key, qualified with the FROM's alias when the query has one (`from('users as u')` orders by `u.id`) and bare under `fromSub()`. A plain query builder's `id` is qualified with the FROM's table or alias under a join, where `select *` made a bare `id` ambiguous on MySQL, MariaDB, PostgreSQL and SQL Server.
- suggest() missed capitalised values on PostgreSQL (case-sensitive LIKE).
- suggest()'s table scan named its columns bare, so a joined table with a column of the same name made the query ambiguous (a SQL error). Under a join, a column of the query's own table is now qualified with that table (or its alias), on an Eloquent and a plain query builder; a column only a joined table has (a translations join) stays bare. On PostgreSQL the `ILIKE` column is written with the connection's table prefix, so a table-qualified `searchIn()` column names the table the FROM does.
- Searching a model whose query joins a table with a column of the same name as a searched column no longer fails with an ambiguous-column error (since 2.0).
- `using('metaphone')` on a query with an aliased FROM (`DB::table('users as u')`) threw "requires a shadow column" (present in 2.0): the check looked for the shadow column on a table named `users as u`. It now reads the FROM's table, and a qualified column names the FROM's alias before any table of that name, as SQL resolves it, so `from('users as profiles')` under a join works when a `profiles` table exists.
- On a connection with a table prefix, a table-qualified column in raw SQL (`orderByFuzzy()`, the relevance `ORDER BY`, `=exact` terms, PostgreSQL `ILIKE`/`SOUNDEX`/`similarity()`, MySQL/MariaDB `LEVENSHTEIN()`) named the unprefixed table and failed; it now takes the connection's prefix, as in `where()`. Write the table unprefixed: `orderByFuzzy('pre_users.name', …)`, which happened to work before, now names `pre_pre_users`. On SQLite that raw SQL also quotes a qualified column, so a table named like a keyword (`order`, `values`) no longer breaks it.
- `getFacets()` failed with "Undefined property: stdClass::$count" when a global scope called `select()` (a `join()->select('users.*')` scope): the scope's select replaced the facet's `COUNT(*)`.
- A column name with a trailing newline (`"name\n"`) passed the column check in `searchIn()`, `facet()`, `FederatedSearch::searchIn()` and the `whereFuzzy`-style macros; it is now rejected like any other invalid name.
- `extended()`/`searchBoolean()`: an empty quoted phrase `""` compiled to `LIKE '%%'` and matched every row, and `name:""` and `"%FF"` (which cleans to `""`) did the same. An empty phrase is now skipped like an empty word, `name:""` raises the missing-term `QuerySyntaxException`, and a query with nothing else raises "no searchable terms".
- A term made only of stop words (`search('the')->ignoreStopWords('en')`) matches nothing on every path. The LIKE path applied no condition and returned every row from `get()`, `count()` and `paginate()`, while the index path returned none.
- A searchable column holding the string "0" was skipped by the indexer.
- A search for `"0"` (a SKU, a house number) is a real search. `empty()` read it as the empty term, so `get()`, `first()`, `simplePaginate()` and `FederatedSearch` threw `EmptySearchTermException` and `paginate()`/`count()` returned every row; `tokenize()` also dropped a `0` word. This predates 2.1. `"0"` is a one-character term, so it finds rows only when `min_search_length` is 1; at the shipped default of 2 it now matches nothing instead of throwing or listing every row.
- FederatedSearch::searchIn() was ignored for models using the Searchable trait; columns a table does not have are now skipped instead of raising SQL errors.
- FederatedSearch: a model without the Searchable trait whose table has none of the requested searchIn() columns is now skipped instead of raising a SQL error.
- count() no longer carries the relevance ORDER BY into the aggregate (PostgreSQL rejected it).
- `config/fuzzy-search.php` shipped `unicode.normalize => true` — the inert v2.0 default for a key that is now live in v2.1.0. A published config would have silently started NFC-normalising every search term. The shipped default is now `false`, matching v2.0 behaviour; the published `scoring`, `performance.max_patterns` and `highlighting.enabled` defaults are also pinned to match what the code actually uses.
- FederatedSearch `paginate()`/`simplePaginate()` totals now count only reachable rows: when `limitPerModel()` caps a model's contribution, that model's share of the total is capped too — previously the total (and page count) could promise more rows than the search would ever return.
- FederatedSearch `paginate()` could duplicate or skip a row across a page boundary when scores tied; each model's results are now ordered by `stableRanking()` (Searchable models) or the primary key (query-builder fallback) before the per-page limit is applied.
- UUID/ULID primary keys are verified end to end on the inverted index, Scout engine, filter(), stableRanking() and fuzzy-search:rebuild (which now chunks by key with chunkById()); the README no longer lists them as unsupported (B5).
- README Scout recipe: the dual-trait example now resolves bootSearchable() (it was a PHP fatal) and boots Scout's observers from booted() (B26). On a model without a `$searchable` property it still threw on `search()` and `create()` until the fix below.
- MySQL/MariaDB: the dictionary column fuzzy_index_terms.term now uses utf8mb4_bin, so café/cafe (and résumé/resume) are distinct terms as on the other drivers; indexing a document containing both no longer fails with "Undefined array key" (B25). Run php artisan migrate — the migration rewrites the table. Rebuild existing indexes too (php artisan fuzzy-search:rebuild {Model}): variants that the old collation collapsed into a single dictionary row stay collapsed until the index is rebuilt.
- An empty (or whitespace-only) term now throws `EmptySearchTermException` from `count()`, `paginate()` and `getFacets()` too, as `get()` always did, on the LIKE and index paths, unless `allow_empty_search` is on — then every terminal lists every row. They used to return every row while `get()` threw.
- `?page` is sanitised on every paginator: `?page=abc`, `?page[]=1` or a page past the integer range on `useInvertedIndex()->paginate()`/`simplePaginate()` no longer throws a `TypeError`, and `?page=0` or `?page=-3` is page 1 instead of the wrong rows. `page(0)`, a negative `page()` and `skip(-n)` served the last rows; they now serve the first.
- A `!` negation in `extended()` no longer drops rows whose searchable columns are NULL (`pro !banned` missed a product with a NULL description).
- `getSearchScore()` on a `Searchable` model — documented as a scoring hook — is now called: once per row on the LIKE, extended and index paths, before normalisation, and the results are ranked by it.
- `count()` and `paginate()` on `useInvertedIndex()` cap the term at `query.max_term_length` as `get()` does (a 3,000-word term bound 3,000 parameters, past SQL Server's 2,100).
- On the index path, `orderBy()` on a `withCount()` or `selectRaw()` alias threw on MySQL, PostgreSQL and SQL Server and was ignored on SQLite.
- `stableRanking()` with `orderBy('id')` named the key twice in ORDER BY, which SQL Server rejects.
- A nested `searchIn()` path with a missing segment named the wrong segment in its error.
- `suggest()`'s table scan matched hidden columns too, so rows that could yield no suggestion used up its rows; with a hidden `email` it could return nothing although a visible name matched.
- The cache key covers the whole `fuzzy-search` config (compared independent of key order), so a search cached before a config change — `unicode.accent_insensitive`, `similar_text.min_percentage`, `max_candidates`, a driver option — is never served after it.
- **Query macros, the `Fuzzy` scopes and the FederatedSearch fallback ignored `query.max_term_length`.** `whereFuzzy()`, `orWhereFuzzy()`, `whereFuzzyMultiple()`, `orderByFuzzy()`, `fuzzySearch()`, every `Fuzzy` scope, and `FederatedSearch` over a model without the `Searchable` trait bound the whole term: an 800-character `levenshtein` term took 1.5 s and 367 MB, and a 2,000-character one exhausted memory. The term is now cut to `query.max_term_length` characters there, as on `search()`. The pattern-based drivers (`fuzzy`, `levenshtein`, `trigram` and `soundex`'s pattern fallback) also stop building LIKE patterns once `max_patterns` are kept, instead of building the whole set and slicing it. The patterns kept, and so the SQL, are unchanged.
- **`unicode.accent_insensitive` replaced the typed term with its accent-free form.** With the shipped default (`true`), "Müller" searched only "Muller" and missed "Zoë Müller" wherever the column is not folded (SQLite, PostgreSQL, SQL Server; MySQL and MariaDB matched both under an accent-insensitive collation). The folded form is now searched **beside** the typed one, like a synonym. That applies to `search()` with every algorithm, `tokenize()` and `withSynonyms()`, to `extended()` (a term becomes `typed | folded`, and `!term` excludes both), to the relevance `ORDER BY`, PHP scoring and highlighting. A term that folding leaves unchanged (any ASCII term) adds nothing, and its SQL is byte-identical to 2.0. Stop words are now matched against the typed word, so an accented stop word (`für`, `à`, `être`) is dropped as listed. The `whereFuzzy`-style macros search the term they are given, and the inverted index keeps its own `indexing.accent_insensitive`. Folding the term never folds the column: an unaccented `cafe` finds `Café` as a substring match only under an accent-insensitive collation on MySQL/MariaDB, or through PostgreSQL's `unaccent()` below.
- **PostgreSQL with `use_native_functions=true` replaced every algorithm with `unaccent()` under the shipped `unicode.accent_insensitive` default.** Every search ran `unaccent(col) ILIKE unaccent(?)`, with no typo tolerance, and failed with `function unaccent(character varying) does not exist` wherever the extension was missing. The global key alone never emits `unaccent()` now. An explicit opt-in (`->accentInsensitive()`, `$searchable['accent_insensitive']`, a preset with `accent_insensitive`, or a macro's `['accent_insensitive' => true]` option) ORs `unaccent(col) ILIKE unaccent(?)` beside the algorithm's own predicate, in one parenthesised group. Typo tolerance stays, and a `where()` or `filter()` still constrains every match. The opt-in still needs the `unaccent` extension (`CREATE EXTENSION unaccent`). A `similar_text` search keeps its `min_percentage` length bound on that alternative, and a term and its folded form share one alternative.
- **Auto-detection picked numeric columns.** On a zero-config model whose table has none of the priority column names, detection fell back to `$fillable` (or to the first column) without looking at the column's type. It then searched `user_id bigint` and `total decimal`, and PostgreSQL rejected the search with `operator does not exist: bigint ~~* unknown`. Every detection branch now keeps only columns whose database type is a text type, matched by its exact name: char, varchar, text in every size, nchar/nvarchar/ntext, character varying, citext, clob, and MySQL/MariaDB enum/set. An untyped SQLite column is kept, as in 2.0. Integer, decimal, boolean and date columns are skipped on every database, and so are PostgreSQL enums and domains: a type named `charge_status` is not text. JSON is skipped where the database has a JSON type (MySQL, PostgreSQL) and kept where it stores JSON as text (SQLite, MariaDB, SQL Server). A UUID column is skipped where the database has a UUID type (PostgreSQL `uuid`, SQL Server `uniqueidentifier`, MariaDB's native `uuid` on Laravel 11+) and kept where it is `char(36)` (MySQL, SQLite, MariaDB on Laravel 10). The types are read once per connection and table with `Schema::getColumns()`, in the same read as the table's column listing. On a Laravel 10 release without that method, detection behaves as in 2.0. Declared columns are unchanged.
- **The Scout dual-trait recipe still threw.** A model using both Scout's `Searchable` and this package's trait, written as `docs/integrations.md` shows it (no `$searchable` property), threw `LogicException: …::searchable must return a relationship instance` on `search()` and on every `create()`/`save()`. With the `fuzzy-search` Scout driver it recursed until memory ran out. Reading the undeclared `$searchable` fell through Eloquent's `__get()` to Scout's `searchable()` method, which indexed the model and failed. Every read of the property now checks that the model declares it.
- **`fuzzyLevenshtein($term, $columns, 0)` ran with the configured distance.** A max distance of `0` was treated as "not given"; it now means exact containment, as `null` still means the configured `levenshtein.max_distance`.
- **Scout `orderBy()` was ignored.** `orderBy()`, `orderByDesc()`, `latest()` and `oldest()` on a Scout search did nothing — results came back in relevance order, and the engine's `map()` re-sorted any other order by score. An explicit order now replaces the relevance order, as on Scout's database engine: the matches that satisfy the builder's constraints come back in that order, ties by key descending, on `get()`, `first()`, `paginate()` and `simplePaginate()`; `_score` still carries each match's BM25 score. No list of ranked ids large enough to pass SQL Server's 2,100-parameter limit is ever bound, and past `bm25.candidate_chunk` matches the ordered keys are read 1,000 at a time rather than as one result set. An order column that is not a column name throws `InvalidArgumentException`.
- **The Scout engine did not cap the query.** It looked up every word of the query with one bound parameter each, so a query of a few thousand words passed SQL Server's 2,100-parameter limit, and one long word was bound whole. It now searches the first `query.max_term_length` characters (default 128), as `useInvertedIndex()` does.
- A `?page` near `PHP_INT_MAX` threw a `TypeError` from `FederatedSearch::paginate()`/`simplePaginate()` and from the Scout engine's `paginate()`. The page is now capped so that its offset cannot overflow, and such a page is empty. A negative `perPage` on `FederatedSearch::paginate()` gave a negative offset; it is clamped.
- `FederatedSearch` read the declared columns of a model without the `Searchable` trait with `isset()` from outside the model. On a model with Scout's `Searchable` that ran Scout's `searchable()` on a blank model (an index write) and threw `LogicException`; a protected `$fuzzySearchable` (the `Fuzzy` trait's documented declaration) or `$searchable` was never seen, so the search ran on guessed columns. Both are now read the way the model itself reads them.
- `FederatedSearch::options()` was stored and never applied. It now reaches every model, as `SearchBuilder::options()` for a `Searchable` model and as the `whereFuzzyMultiple()` options for a model without the trait; `typoTolerance()` wins over its `max_distance`.
- **`FuzzySearch::on()` folded case in ASCII only.** `ÉCOLE` scored `école` as a near-miss instead of an exact match, and `МОСКВА` or `ΑΘΗΝΑ` did not match their lower-case forms at all. The term and every value are now case-folded in every script, as `Model::search()`'s scoring folds them.
- `FuzzySearch::on()` scored the whole term, however long, with `similar_text()` against every value; it now cuts the term at `query.max_term_length` characters, as `Model::search()` does, and `FuzzySearchExecuted` reports the term that was searched.
- **BM25 ranked the best match last once another model shared its words (present in 2.0).** The idf compared the dictionary's `doc_count`, which counts every indexed model's documents, with the searched model's own document total, so it went negative when another model used a word more often than this model has rows: 7 users and 30 products saying "john" put "John Doe" last, with a negative `_score`. The idf now counts the term's documents within the searched model, and no score is negative. `fuzzy-search:rebuild --fresh` (and `fuzzy-search:clear`/`flush`) also gives back the model's share of each term's `doc_count`; every fresh rebuild used to inflate the terms other models still use, which `didYouMean()` and the typo and prefix expansions rank by.
- **A connection with a table prefix broke `php artisan migrate` and the index (the migrate failure is new in 2.1).** The `term_length` backfill, the MySQL `term(191)` key and the `utf8mb4_bin` migrations ran raw SQL against `fuzzy_index_terms` as written (`no such table: fuzzy_index_terms`), and so did indexing (`doc_count + 1`), `flush()`'s orphan sweep and weighted BM25 (`p.frequency`). They now reach the prefixed tables; without a prefix the SQL is unchanged byte for byte.
- **Equal BM25 scores came back in a different order on each database (present in 2.0).** Ties now rank by model key, ascending: integer keys first, as numbers, then string keys.
- **Saves and deletes were indexed inside the caller's transaction (present in 2.0).** The observer now indexes (or queues `IndexModelJob`) after the model's transaction commits. A rolled-back row used to stay searchable when the model lives on another connection than the index, and a queued job could run before the row it reloads was committed and so miss it.
- **Two index writes for one row counted it twice (present in 2.0).** An `IndexModelJob`, the Scout engine's `update()` and a rebuild batch each read whether the row was already indexed, then added it to `doc_count` and `total_docs` from that read, so two of them overlapping for the same row both counted it. Each write now claims the row's document entry in the database first, in the same transaction as the write: a second write for the same row waits for the first to commit, then re-reads the row and indexes it as it is committed now. The wait is a short row lock, released if the process dies, and lasts as long as the other write's transaction. No cache lock is involved.
- **A write that read a row before a newer save could leave the older text indexed (present in 2.0).** An `IndexModelJob` (or a sync save's index write) that loaded the row, then committed after a newer save's own index write, overwrote it with the older text until the next save. The indexer now reads the row after it has claimed the row's index entry, and indexes the row as committed then, so whichever write commits last leaves the latest save indexed. A row that is gone, or soft-deleted, leaves the index instead.
- **Index writes for different rows that share words could deadlock (PostgreSQL since 2.0).** Each write gave back its old words' counts, then raised its new words' counts, so two writes whose words crossed each held a row the other needed; parallel batches that added the same new words inserted them in different orders. A write now changes every existing word's count in one statement, after locking the rows in one fixed order, and inserts new words in one sorted statement (on MySQL/MariaDB, sorted within each count). A write that still hits a deadlock (or, on MySQL/MariaDB, a lock-wait timeout) is tried up to three times.
- **A flush of one model could fail another model's index write (present in 2.0 on MySQL/MariaDB).** A flush deletes every word no posting uses any more, such as a word a re-index dropped. A write that reused such a word, and had read it just before the flush deleted it, failed on the postings' foreign key. The write now inserts the word again.
- **SQL Server: two index writes adding the same new word at the same moment failed the second (present in 2.0).** SQL Server's `MERGE` checks for the word without locking it, so both writes inserted it and the second failed with a duplicate-key error (2627), which no retry covered. While `XACT_ABORT` is off (SQL Server's default), the write now runs the statement again and updates the row the other write inserted. The same holds for a model's first document row and meta row. With `XACT_ABORT` on for the connection (`SET XACT_ABORT ON`, or the server's `user options`), the error has already rolled back the whole write, so the statement is not run again: the write fails as in 2.0, and is retried by the queue (`indexing.async`) or reported to your exception handler (sync).
- **Indexing a document with a few hundred distinct tokens failed on SQL Server (present in 2.0).** The indexer sent a document's postings, or a rebuild batch's dictionary lookup, as one statement with more than SQL Server's 2,100 bindings. It now splits them, at most 2,000 bindings a statement.
- **Two index tokens longer than 191 characters that shared their first 191 characters collided on MySQL/MariaDB (present in 2.0).** The dictionary is keyed on `term(191)`, so the second token's postings were dropped and a search for it found nothing. Every term is now capped at 191 characters, at index time and on every lookup (BM25 terms, typo and prefix expansion, `didYouMean()`, `suggest()`). A token over 191 characters used to be indexed whole below 256 characters and skipped above; it is now indexed as its first 191.
- **`fuzzy-search:rebuild` did not fill metaphone shadow columns (present in 2.0).** The setup guide said it backfills `*_metaphone` for existing rows; only a save did. Rebuild (sync and each `--async` batch job) now fills them for every row, with a query update that fires no model events: one UPDATE per 600 rows, and none for a row whose shadow value is already right.
- **`rebuild --async` without the `job_batches` table failed after `--fresh` had emptied the index.** It now stops before touching the index and names the command that creates the table.
- **The search log migration ignored `analytics.table`** and created `fuzzy_search_logs` while the recorder wrote to the configured name. Its indexed `normalized_term` column was 255 characters, a 1,020-byte key under utf8mb4 that MySQL's COMPACT row format rejects (767 bytes); it is now 191.
- **Commands exited 0 on input they could not use.** `rebuild` of a class it cannot index, `flush`/`clear` of a class that is not an Eloquent model (`flush stdClass` reported success), and `benchmark --iterations=0` (`DivisionByZeroError`) now print an error and exit 1. So do a non-integer `--days` or `--limit` on the analytics commands, where `analytics:prune --days=abc` read 0 days and deleted every row, and an `add-shadow-column --type` other than `metaphone`, which wrote a migration for a column nothing would ever fill. `fuzzy-search:flush <model>` now runs `fuzzy-search:clear <model>`.
- **`fuzzy-search:index` (deprecated) ignored a model's declared columns and threw on a Scout dual-trait model.** It read `$model->searchable` from outside the model, where the property is protected, so it always fell back to the common column names, and on a model that also uses Scout's `Searchable` the read resolved Scout's `searchable()` method as a relation and threw `LogicException`.
- `extended()`/`searchBoolean()` threw at `query.max_tokens` tokens instead of past it ("Query has 32 tokens, exceeding the configured maximum of 32"), so the default 32 allowed 31. A query may now have exactly `query.max_tokens` tokens, and one more throws. `query.max_depth` already allowed exactly its value.
- `PorterStemmer`'s missing-package message named `composer require wamania/php-stemmer`, which installs v4, whose classes it cannot load; it now names `composer require "wamania/php-stemmer:^1.2"`.

### Security

- **Soundex filter bypass (present in 2.0.0 and 2.0.1).** On MySQL, MariaDB and PostgreSQL with native functions, `using('soundex')` / `preset('phonetic')` / `whereFuzzy($column, $term, 'soundex')` emitted `SOUNDEX(first word) = SOUNDEX(?) OR SOUNDEX(last word) = SOUNDEX(?)` without parentheses. `AND` binds tighter than `OR`, so a constraint chained after the search — `->where('tenant_id', $id)`, a soft-delete or tenant scope, the relation key inside a `whereHas` — only guarded the second arm and rows leaked past it. The predicate is now one parenthesised group, and a security test asserts that every driver's predicate stays self-contained on all five dialects. SQLite and SQL Server were never affected (they use the grouped pattern fallback).
- **Invalid UTF-8 in a search term (present since 1.x).** A crafted request such as `?q=john%C3` reached the bind parameters as-is: PostgreSQL (SQLSTATE 22021) and SQL Server (IMSSP, "translating string ... to UCS-2") rejected it, so the search was a 500 there, while SQLite and MySQL searched the raw bytes. Invalid UTF-8 byte sequences are now dropped from the term wherever it enters the package — `search()`, `extended()`/`searchBoolean()`, the `whereFuzzy`/`orWhereFuzzy`/`whereFuzzyMultiple`/`orderByFuzzy`/`fuzzySearch` macros and the `Fuzzy` trait scopes, `FuzzySearch::tableSearch()`, Filament global search, `FederatedSearch`, `FuzzySearch::on()`, the Scout engine and the analytics recorder — so `jo%C3hn` searches `john` and behaviour is identical on all five databases. Valid characters are untouched, and the bytes are dropped rather than replaced (a `?` would be a literal in a LIKE pattern).
  - A term made only of invalid bytes (`?q=%FF`) gets past an app's `filled('q')` check but cleans to nothing. It now matches nothing, like a term below `min_search_length`. `get()`, `first()`, `simplePaginate()`, `paginate()` (total 0), `count()`, `getFacets()`, `FederatedSearch` and `FuzzySearch::on()` return no rows on every database; on these paths it never throws `EmptySearchTermException` and never returns every row. The `whereFuzzy`-style macros and the `Fuzzy` scopes treat it as `''`: like `''`, they match every row whose column is not NULL, and `tableSearch()` adds no constraint at all. A genuinely empty `''` is unchanged.
  - `extended()`/`searchBoolean()`, `tokenize()`, stop-word removal and `suggest()`'s table scan split words on ASCII whitespace only. `ctype_space()` and a regex `\s` without `/u` follow the locale: under a UTF-8 `LC_CTYPE` on macOS/BSD they counted byte 0xA0 as a space, which cut characters such as `à`, `ঠ` and `丠` in half. A valid extended query then sent invalid UTF-8 to the database (PHP 8.1/8.2) or searched for a literal `?` (8.3+); `tokenize()` searched `voilà` as `voil` and `ঠাকুর` as `LIKE '%%'`, which matched every row; `suggest()` returned the broken half. Linux was not affected.
  - On PHP 8.1, `strtolower()` follows the locale as well: under a UTF-8 `LC_CTYPE` on macOS/BSD it turned the lead byte of `à`, `É` or `д` into invalid UTF-8, which `suggest()`'s table scan, the `similar_text` driver and the `fuzzy`/`levenshtein` relevance ordering bound as a parameter. Those values are now lowercased ASCII-only, as `strtolower()` does on PHP 8.2+, so nothing changes there.
- **Auto-detection could index secrets and hidden attributes.** A model without `$searchable['columns']` has its columns auto-detected, and since this release indexed. Detection ignored `$hidden` and `$visible`, and its last fallback, the first string-like column, could pick `password`, `remember_token` or `two_factor_secret` (the LIKE path has searched such a pick since 2.0). The indexer read each column through `getAttribute()`, so a get accessor that decrypts a column (`getBioAttribute()` returning `decrypt($value)`, or `Attribute::make(get: …)`) wrote the plaintext to `fuzzy_index_terms`, where `suggest()` and `didYouMean()` served it. Detection now skips hidden columns and every secret-named column — any name containing `password`; `token` or a name ending in `_token`; `secret`, `api_key` or `private_key` as a whole underscore-separated part of the name (`secret_note`, `stripe_api_key`, `webhook_secret` — not `secretary_name`); or a name ending in `recovery_codes` (in any letter case) — in every branch, and an auto-detected column is indexed, and its `*_metaphone` shadow column filled, from the model's raw attribute value. Declared columns are unchanged, and declaring a column is what opts into its accessor. The raw value is all the package sees, so a masking accessor (`Str::mask()`) is bypassed, encryption that decrypts into the attributes in memory (spatie/laravel-ciphersweet) is invisible, and a column hidden only at runtime (`makeHidden()`) is still detected: put such a column in `$hidden`, or declare `$searchable['columns']`.
- **`didYouMean()` offered every indexed model's terms.** It read the whole cross-model dictionary, and `FuzzySearchCollection` publishes it as `meta.suggestions` on every empty page, so a public product search disclosed other models' tokens (users' names, email domains). It now offers only terms posted under the searched model, and returns `[]` for a builder with no Eloquent model, unless `useInvertedIndex(Model::class)` names one.
- **`didYouMean()` ignored `where()`, joins and global scopes.** On a multi-tenant model one tenant was offered another tenant's terms, published as `meta.suggestions` on every empty page, while `docs/integrations.md` said such constraints applied. Under a `where()`, a `join()` or a global scope it now keeps only terms posted for a row the constrained query can see, checked by primary key against the postings (no table scan). The `SoftDeletes` scope is not counted: the index drops a trashed row's terms, which with `indexing.async` (the default) happens when the queued `IndexModelJob` runs. The new dictionary-backed `suggest()` follows the same rule (see Added).
- **`_score` revealed rows the query hides.** A BM25 `_score` was normalised against the best match in the model's whole index, including rows a `where()`, a join or a tenant scope hides, and `FuzzySearchResource` publishes it: a lower `_score` for the same row told one tenant that another tenant's rows contain a searched word. It is now normalised against the best row the query can see. `_raw_score` is unchanged: its IDF counts every row in the model's index, other tenants' included (see `docs/bm25.md`).
- **A plain query builder's `where()` was dropped on the index path (present in 2.0).** `new SearchBuilder(DB::table('notes')->where('tenant_id', $id), app(FuzzySearch::class))->useInvertedIndex(Note::class)` hydrated the ranked rows through the model's own query, so the search returned rows the `where()` excluded. The builder now runs inside the model's query: its wheres and joins apply alongside the model's global scopes on the index search, `count()`, `paginate()` and `didYouMean()`.
- **`orderByFuzzy()` did not check its column (present since 1.0).** The column went into the raw `ORDER BY` unchecked (on SQLite exactly as written), so an app that passed a request's sort field as the column could have SQL injected into it (`name) , (select …`). It now takes `whereFuzzy()`'s column check: a column that is not letters, digits, underscores and dots throws `InvalidArgumentException`.
- **`searchIn(['x.y'])` called an arbitrary model method to find out whether `x` is a relation.** `searchIn(['unguard.body'])` left the process unguarded, and `save.body` inserted a row. Only a relation method with a `Relation` return type, or a path the model declares in `$searchable['columns']`, is called; anything else throws before any SQL.
- **PHP rescoring was a CPU DoS.** A 127-character term against 300 rows of 20KB took seconds, and an `extended()` OR query far longer. `similar_text()`/`levenshtein()` now compare at most 255 characters of a value and of a term, and `extended()` scores each leaf term on its own within a 255-character budget. `FuzzySearch::levenshteinDistance()` and `similarityPercentage()` (and so the `Fuzzy` trait's `filterFuzzy()` and `sortByFuzzy()`) compare at most the first 255 characters of each string too. A string of 255 characters or fewer is used exactly as given, invalid UTF-8 included, so it scores as before.
- `FuzzySearch::on()` compared the whole value with `similar_text()` (O(n·m)): 300 items of 20KB took over a second per search, and `in_memory.max_items` allows 10,000. It now compares at most the first 255 characters of the value and of the term, as `Model::search()`'s rescoring does; a value within 255 characters scores exactly as before.
- **`_highlighted`, `_matches`, `_debug` and `suggest()` revealed hidden columns.** `_highlighted` and `_matches` (and `FuzzySearchResource`) now hold only what `toArray()` shows, on related models too; `debugScore()`'s `_debug` and `suggest()`'s table scan leave hidden columns out.
- **Index suggestions offered words from hidden columns.** `suggest()` from the dictionary and `didYouMean()` drew on every posting of the model, so a word that exists only in a `$hidden` column (or one left out of `$visible`) could be offered. They now skip those columns' postings. Matching is unchanged: a search, including its typo and as-you-type expansions, still finds rows by a hidden searchable column, as the LIKE path does, and returns only their visible attributes. Postings written before 2.1 carry no column name, so for a model that hides one of its searchable columns they are skipped too until you rebuild.
- **The result cache could serve one query's rows to another.** The key now includes the highlight tags, `withRelevance()`, `debugScore()`, the index model class, the model class, eager-load names, and the connection, database, table prefix and PostgreSQL `search_path`: one tenant could be served another's cached rows, and a plain search another search's highlight markup. A cached search also served another request's eager-loaded rows (a `with()` constraint the key could not see); each read now loads the current request's own.
- **`fuzzy-search:analytics` printed stored search terms raw.** A logged term such as `\e[2J` reached the operator's terminal as an escape sequence, and `<href=…>` became a console hyperlink. Control characters (C0, DEL, C1) now print as `\xNN` and console formatter tags print as text, in the term and path columns.

### Database compatibility

First release where the full test suite runs against SQLite, MySQL 8, MariaDB 11.4, PostgreSQL 14 and SQL Server 2022 in CI.

### Database migrations (run automatically on `php artisan migrate`)

| Migration | What it does |
| --------- | ------------ |
| `2026_09_17_000001_add_term_length_to_fuzzy_index_terms_table` | Adds `term_length` (unsigned smallint) + index to `fuzzy_index_terms` so `didYouMean()` filters by length without `LENGTH()` SQL. |
| `2026_09_17_000002_binary_collation_on_fuzzy_index_terms_term` | MySQL/MariaDB only: rewrites `fuzzy_index_terms.term` to `utf8mb4_bin` so `café` and `cafe` are distinct dictionary terms. |
| `2026_09_18_000001_add_column_name_to_fuzzy_index_postings_table` | Adds `column_name` (varchar 64, default `''`) to `fuzzy_index_postings` and moves the unique key to `(term_id, model_type, model_id, column_name)` — weighted BM25. Existing rows keep `''` and keep working; rebuild with `--fresh` for weighted ranking. |
| `2026_09_19_000001_create_fuzzy_search_logs_table` | Creates the table `analytics.table` names (`fuzzy_search_logs` by default: `id`, `term`, `normalized_term`, `model_type`, `algorithm`, `path`, `result_count`, `latency_ms`, `day`, `created_at`; indexed on `normalized_term`, `created_at`, `result_count`, `day`) for the opt-in persisted analytics. `normalized_term` is varchar 191, so its index fits MySQL's key limit. Set `analytics.table` before you migrate. |
| `2026_09_20_000001_widen_model_id_on_fuzzy_index_tables` | Widens `model_id` from 36 to 191 characters on `fuzzy_index_postings` and `fuzzy_index_documents`. SQL Server drops and recreates the documents primary key that covers the column; SQLite needs no change. Rolling back deletes index rows whose key is longer than 36 characters, giving back their counts. Run it with the indexing workers paused. |

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

[2.1.0]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/ashiqfardus/laravel-fuzzy-search/releases/tag/v2.0.0
[1.0.1]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/ashiqfardus/laravel-fuzzy-search/releases/tag/v1.0.0
