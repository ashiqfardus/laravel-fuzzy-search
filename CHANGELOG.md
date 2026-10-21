# Changelog

<!-- markdownlint-disable MD024 -->

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] — 2026-09-30

2.1 adds relationship search, a typo-tolerant BM25 index with column weights and a dictionary per model, federated pagination, persisted search analytics, Filament and JSON API integrations and new tokenizers. It also fixes many 2.0.x defects, a few of them security issues. Some existing calls now return, throw or cache differently, and the new migrations rewrite the index tables. Read the [upgrade guide][u] (`docs/UPGRADE_v2.0_TO_v2.1.md` in the package) before you upgrade: it opens with a checklist and every behaviour change, and it is the full reference for each line below.

### Upgrade notes (read first)

- **Plan the migrations.** Eleven new migrations; ten change the index tables. On MySQL and MariaDB they take a while (the compact postings migration alone takes about 22 minutes for 6M rows on MySQL), and index searches fail until they finish: migrate under `php artisan down` with the indexing workers stopped, or empty the index and rebuild instead. On PostgreSQL and SQL Server 2.0.x could not write the index, so the migrations find it empty and take seconds; build each model's index once afterwards with `fuzzy-search:rebuild`. ([guide][u-checklist])
- **Upgrade order.** Stop the indexing workers and saves, `migrate`, then `rebuild --fresh` each model a migration's warning names (nothing may be indexing), then restart the workers and Octane, then run a plain `rebuild` of every other indexed model for weighted ranking (it runs with writers on). ([guide][u-checklist])
- **Empty terms throw everywhere.** `''` or whitespace throws `EmptySearchTermException` from `count()`, `paginate()`, `getFacets()` and `FuzzySearch::on()` too, unless `allow_empty_search` is on. ([guide][u-exceptions])
- **`min_search_length` applies on every path**, so a one-character term matches nothing on `paginate()`, `count()` and `getFacets()` as well. A model with no searchable column matches nothing instead of every row. ([guide][u-search])
- **`orderBy()` on the builder is the result order on every path.** The LIKE path re-sorted it away and the extended and index paths ignored it. To shape only the candidate window, order the underlying query: `->query(fn ($q) => $q->orderBy(...))`. ([guide][u-search])
- **`paginate()` ranks across up to `max_candidates` rows before slicing** (2.0 ranked within the page), and works with `extended()`. ([guide][u-search])
- **The SQL candidate order changed with an untouched config.** It moves from 2.0.1's hard-coded 100/50/10 (exact/prefix/contains) to `scoring.*`, 100/80/60. PHP rescoring keeps 2.0's values, but a search matching more than `max_candidates` rows (1,000 by default) can pick a different window and top page. ([guide][u-config])
- **Inert config keys are now read:** `scoring.*`, `highlighting.*`, `performance.max_patterns`, `similar_text.min_percentage`, `cache.*`, `synonyms` and `unicode.normalize`. A config published from 2.0 has `'normalize' => true`, which now NFC-normalises every term. ([guide][u-config])
- **The `cache.*` config is live.** A published `'enabled' => true` caches every search, `ttl` counts seconds, and every cache key changed. An entry holds row keys and scores, and a hit re-reads the rows for the current request. ([guide][u-caching])
- **`paginate()` and `simplePaginate()` clamp `perPage`** to between 1 and `max_candidates` on every path, `FederatedSearch` included; the index path clamped at 100. ([guide][u-pages])
- **`similar_text` enforces `similar_text.min_percentage` (70)**, so long columns return far fewer rows. `0` restores 2.0's results. ([guide][u-search])
- **Accent folding keeps the typed term.** `unicode.accent_insensitive` searches the accent-free form beside the term instead of replacing it. On PostgreSQL `unaccent()` runs only on an explicit `accentInsensitive()` opt-in. ([guide][u-search])
- **Rows MySQL/MariaDB match through an accent-insensitive collation** (or PostgreSQL through `unaccent()`) rank and highlight as the matches they are, so such results can reorder. ([guide][u-search])
- **`levenshtein` on PostgreSQL runs its pattern set** whatever `use_native_functions` says (pg_trgm missed one-edit typos of short words): more rows. ([guide][u-search])
- **The trigram LIKE fallback drops one- and two-character grams**, which matched almost every row. It returns fewer rows, and a short-word typo such as `jhon` needs `fuzzy` or `levenshtein`. ([guide][u-search])
- **Typo settings are honoured.** `typoTolerance(0)` and `(1)` now apply to the `fuzzy` algorithm. `typo_tolerance.enabled = false` also stops typo matches on `fuzzy`, `levenshtein`, the `whereFuzzy`-style macros and the `Fuzzy` scopes. ([guide][u-search])
- **`distinct()` on the LIKE path adds no relevance `ORDER BY`** (it threw on PostgreSQL and SQL Server). Rescoring ranks the first `max_candidates` rows read, so add `orderBy()` or drop `distinct()` to rank every match. ([guide][u-search])
- **A column weighted `0` is out of the search on every path**, not only out of `_score`. ([guide][u-search])
- **`getSearchScore()`, `customScore()` and `boostRecent()`.** A `getSearchScore()` override is now called. `customScore()` and `boostRecent()` now rank `useInvertedIndex()` results too, re-ranking the first `max_candidates` matches. ([guide][u-search])
- **Stop words match in any letter case** on the LIKE path ("Не", "À"), and `ignoreStopWords('en')` prefers the configured list, which is shorter than the built-in one. ([guide][u-tokenizers])
- **Synonym words ignore case** (`'Laptop' => [...]` now expands `laptop`). ([guide][u-search])
- **A backslash in a term is literal** on MySQL, MariaDB and PostgreSQL. On MySQL, MariaDB, SQLite and SQL Server every LIKE carries `ESCAPE '!'`. ([guide][u-search])
- **One search binds at most 2,000 values of its own.** A very long query, or a term on many columns, matches fewer typo variants, and one too large even for one pattern per word and column throws `QuerySyntaxException`. ([guide][u-search])
- **On a connection with a table prefix, write a qualified column's table unprefixed** in `orderByFuzzy()` and the other raw-SQL paths; the prefix is now added for you. ([guide][u-search])
- **Auto-detection picks text columns only**, on the LIKE path too (see Security for hidden and secret columns), and zero-config models are now indexed. ([guide][u-indexing])
- **Dotted `searchIn()` names.** `author.name` searches through a relation when `author()` declares a `Relation` return type, or the path is declared in `$searchable['columns']`. A name whose first part names a table of the query stays a table column. ([guide][u-relations])
- **`_highlighted` is escaped for every column**, and `@fuzzyHighlight` renders an empty string for a relation that is not loaded instead of lazy-loading it. ([guide][u-highlighted])
- **Extended syntax:** `~word` and `field:` are operators, and `!` negates only at the start of a token. `""` is skipped, a `!` term keeps rows with NULL columns, and a query may have exactly `query.max_tokens` tokens. ([guide][u-extended])
- **BM25 changes rankings.** The index is typo-tolerant by default (`typoTolerance(0)` for exact terms). Constraints apply before the page is cut, and `_score` has one scale across pages. Idf and dictionary are per model, ties come back by key, and column weights apply after a rebuild, in the Scout engine too. ([guide][u-bm25])
- **Index-path totals count what the pages serve.** In relevance order, past `bm25.max_postings_per_term`, `total()` and `count()` count the matches the ranking holds. ([guide][u-indexing])
- **Indexing runs after the transaction on the model's own connection commits** and indexes the row as stored. With `indexing.async` off, an index error is reported to your exception handler, naming the model and key, instead of thrown from `save()`. ([guide][u-bm25])
- **Index writes inside your own transaction are not retried.** That means Scout with `scout.after_commit` off and no queue, or `searchable()` inside `DB::transaction()`. Two or more such writes in one transaction can deadlock (`DeadlockException`). Set `scout.after_commit` to `true`, or index after the commit. ([guide][u-bm25], [setup][d-bm25-setup])
- **SQL Server:** turn on `READ_COMMITTED_SNAPSHOT`, or set `scout.after_commit`. Index writes to one index run one at a time. ([guide][u-bm25])
- **PostgreSQL:** `fuzzy-search:rebuild` and index writes run `ANALYZE` on the index tables, and a synchronous rebuild runs `VACUUM (ANALYZE)` on the postings. ([guide][u-indexing])
- **MySQL/MariaDB:** `model_id` compares byte-wise, so string keys that differ only by case, accents or trailing spaces are separate documents. Rebuild such a model with `--fresh`. ([guide][u-bm25])
- **Rebuild with `--fresh` for non-Latin text:** the tokenizer keeps combining marks, so an index built from Bengali, Hindi, Thai or decomposed-accent text is stale. ([guide][u-indexing])
- **Accessor-backed searchable columns reindex on every save** unless the model declares `reindex_on`. ([guide][u-indexing])
- **`suggest()` on an indexed model completes from the dictionary** (lower-case terms); `suggestFrom('table')` restores 2.0. ([guide][u-suggest])
- **`didYouMean()`** ranks the closest term first, its reach scales with the term's length, and it throws real database errors. ([guide][u-bm25])
- **`FederatedSearch` searches each model with its own configuration** (`->using('fuzzy')->typoTolerance(2)` restores 2.0). It orders ties deterministically, `getCounts()` counts matches, and `searchIn()` leaves out a model with none of the named columns. ([guide][u-federated])
- **Scout:** a `where()` field must be a column of the model's table, now that the engine applies it (see Security). `orderBy()` orders and `hybrid()` throws. The `fuzzy-search` driver needs the package's `Searchable` trait and rejects a custom Scout key (`LogicException`). On Scout 10.1+, `paginateRaw()` returns models. ([guide][u-federated])
- **`FuzzySearch::on()`** over models reads attributes, casts, accessors and loaded relations only. It leaves non-matching items untouched and ranks a near miss below every literal match. ([guide][u-federated])
- **Commands exit 1 on input they cannot use**, and `fuzzy-search:flush <model>` runs `clear <model>`. ([guide][u-bm25])

### Database migrations

`php artisan migrate` runs them. Timings, locking and rollback are in the [upgrade guide][u-bm25]. A migration that stops part way (a lock wait timeout, a killed deploy) finishes when you run `migrate` again.

| Migration | What it does | Cost on a large index |
| --- | --- | --- |
| `2026_09_17_000001_add_term_length_to_fuzzy_index_terms_table` | Adds `fuzzy_index_terms.term_length`, backfilled in characters | updates every word |
| `2026_09_17_000002_binary_collation_on_fuzzy_index_terms_term` | MySQL/MariaDB: `term` to `utf8mb4_bin`, so `café` and `cafe` are separate words | rewrites the dictionary |
| `2026_09_18_000001_add_column_name_to_fuzzy_index_postings_table` | Postings carry their column (weighted BM25); postings from before keep an empty column | swaps the postings key |
| `2026_09_19_000001_create_fuzzy_search_logs_table` | Creates the `analytics.table` table (`fuzzy_search_logs`); set `analytics.table` first | instant |
| `2026_09_20_000001_widen_model_id_on_fuzzy_index_tables` | `model_id` 36 → 191 characters; byte-wise on MySQL/MariaDB | MySQL/MariaDB copy both tables |
| `2026_09_30_000001_binary_collation_on_fuzzy_index_terms_term_on_sqlsrv` | SQL Server: `term` to `Latin1_General_100_BIN2`; rebuild with `--fresh` after | rebuilds the dictionary's indexes |
| `2026_10_01_000001_rework_fuzzy_index_terms_and_postings_indexes` | Drops `postings_term_model_idx`, and adds a `(term_length, doc_count, id)` dictionary index that `2026_10_20_000001` drops again | builds a dictionary index |
| `2026_10_02_000001_compact_fuzzy_index_postings_table` | Rewrites the postings in the compact layout, with the `fuzzy_index_model_types` and `fuzzy_index_columns` dictionaries; needs PostgreSQL 11+ | copies the postings (about 22 minutes for 6M rows on MySQL) |
| `2026_10_13_000001_create_fuzzy_index_model_terms_table` | Creates `fuzzy_index_model_terms`, each model's own words and counts | instant |
| `2026_10_13_000002_backfill_fuzzy_index_model_terms_table` | Fills it from an existing index | reads every posting (about 3 minutes for 6M rows on MySQL) |
| `2026_10_20_000001_drop_term_length_index_from_fuzzy_index_terms_table` | Drops the dictionary index `2026_10_01_000001` built, which no search reads | instant |

### Added

- PHP 8.5 support.
- Relationship search: `searchIn(['author.name', 'tags.name', 'comments.author.name'])` filters through the relation, scores, highlights and suggests relation columns, and works with the extended syntax. ([docs][d-relations])
- The builder forwards Eloquent and query-builder calls (`where*`, `whereHas`, `with*`, `join*`, `when`, local scopes), and adds `query(Closure)` and `Searchable::searchOn($query, $term, $columns)`. ([docs][r-chaining])
- `FederatedSearch`: `paginate()`, `simplePaginate()`, `limitPerModel()`, `orderByModel()` and `useInvertedIndex()`. ([docs][r-federation])
- Typo-tolerant BM25 (`bm25.fuzzy.*`), `asYouType()` prefix matching (`bm25.prefix.*`), and synonyms and stop words on the index path. `highlight()` there marks every term the query matched. ([docs][d-bm25-typo])
- Column weights on the index (BM25F-lite): `searchIn()` and `$searchable['columns']` weights scale each column's term frequency, after one rebuild. ([docs][d-bm25-weights])
- Extended syntax `~word` (typo-tolerant term) and `field:term` scopes, combinable with every operator. ([docs][d-extended])
- Model hooks: `searchableText()`, `searchIndexQuery()`, `$searchable['reindex_on']` (`getReindexTriggers()`) and `Searchable::reindexRelated()`. ([docs][d-bm25-accessors])
- `searchIndexType()`: a single-table-inheritance child can share its parent's index. ([docs][d-bm25-sti])
- Config keys `indexing.job` (retry limits for the index jobs), `bm25.candidate_chunk` and `highlighting.max_matches` (100 by default, `0` for no limit). ([guide][u-api])
- Persisted search analytics (opt-in): `analytics.*`, the `fuzzy_search_logs` table, the `SearchAnalytics` facade and the `fuzzy-search:analytics` and `fuzzy-search:analytics:prune` commands. ([docs][d-analytics])
- `FuzzySearchExecuted` carries `resultCount`, `path` and `modelClass`, and in-memory searches fire it too. Five-argument listeners keep working. ([guide][u-analytics])
- Dictionary-backed `suggest()` on indexed models, and `suggestFrom('index'|'table')`. ([guide][u-suggest])
- `NgramTokenizer` and `ScriptAwareTokenizer`, per-model index pipelines (`$searchable['tokenizer']`, `['stemmer']`, `['stemmer_language']`, `['locale']`), `indexing.accent_insensitive`, and stop-word lists for it, pt, nl and ru, from config or from a file. ([docs][d-tokenizers])
- Filament (v3–v5) global search (`HasFuzzyGlobalSearch`) and `FuzzySearch::tableSearch()` for Filament tables. ([docs][d-filament])
- JSON API resources `FuzzySearchResource` and `FuzzySearchCollection::fromBuilder()`, and `SearchBuilder::lastExecution()`. ([docs][d-resources])
- `fuzzy-search:status --legacy` lists postings that predate column weighting; without it, that check is skipped past 1,000,000 indexed tokens. ([docs][d-bm25-commands])

### Changed

- Typo expansion, `asYouType()`, `suggest()`, `didYouMean()` and BM25 document frequency read each model's own words and counts in `fuzzy_index_model_terms`, one word length at a time through its `(model_type_id, term_length, doc_count, term_id)` key, newest word first among equal counts. Another model's words no longer crowd a model's own out, and the cost no longer grows with the shared dictionary. `fuzzy_index_terms.doc_count` is a total across models that no search reads. ([guide][u-bm25])
- The postings table is compact and clustered by word, with each document's length in the posting. A common word is read as one range: on MySQL at 12M rows its first page takes 0.59 s, and three such words 1.6 s (at 6M rows the old layout took 43 s, and timed out). The index takes about 1.5 GB per million rows on MySQL and 2.9 GB on PostgreSQL (measured at 300,000 rows). ([sizing][r-sizing])
- The index stores one posting per (term, column), the column by id through `fuzzy_index_columns`. Postings from before 2.1 keep an empty column, which `fuzzy-search:status` reports, and a plain `rebuild` replaces them. ([guide][u-weighted])
- In relevance order on the index path, a model that a one-to-many join repeats is served once. ([guide][u-bm25])
- `maxPatterns()` and `performance.max_patterns` cap the pattern list of every pattern-based algorithm. The trigram fallback's cap moves from a hard-coded 10 to that key (100). ([guide][u-api])
- Multi-term `extended()` queries score each leaf term on its own, and an accented term and its folded form count once (the better of the two). ([guide][u-search])
- `FederatedSearch` searches a `Fuzzy`-trait model with its own `$fuzzyAlgorithm` and `$fuzzyOptions`. A model without the `Searchable` trait contributes at most `max_candidates` rows. ([guide][u-federated])
- The Scout engine's `update()` writes a collection in one transaction. ([guide][u-federated])
- The FuzzySearch singleton reads `config('fuzzy-search')` live, so runtime overrides reach the drivers. ([guide][u-api])
- PHP API: `FuzzySearch::$config` is nullable (use `currentConfig()`). `SearchBuilder::first()` is declared `Model|\stdClass|array|null`. `IndexManager::processTerms()` and several protected methods take optional arguments. `PorterStemmer`, `NullStemmer` and `WhitespaceTokenizer` are no longer `@internal`. ([guide][u-api])
- `composer.json` requires `illuminate/http` directly. ([guide][u-filament])
- `using('levenshtein')` makes no typo patterns for a term shorter than `typo_tolerance.min_word_length` (4), so `jo` no longer finds most of a table. ([guide][u-search])
- An empty or NULL column scores 0 for a short term; it no longer earns the typo floor. ([guide][u-search])
- `preset()` leaves out the columns the table doesn't have; explicit columns are untouched. ([guide][u-search])
- An extended query throws `QuerySyntaxException` for a `!` with nothing after it (it was ignored). ([guide][u-extended])
- The LIKE and extended paths read only the page's rows under `orderBy()` or `withRelevance(false)`; `_score` is normalised over the rows read. ([guide][u-search])
- A plain `fuzzy-search:rebuild` removes the documents of rows that were deleted, trashed or left out by `searchIndexQuery()` without model events. Rows a global scope hides keep theirs, and a Scout model keeps the trashed rows Scout indexed while `scout.soft_delete` is on. `--async` does it in the batch's last job, the new `PruneIndexJob`. ([guide][u-indexing])
- `fuzzy-search:clear --all` truncates the index tables when they are idle (2.0.1 deleted every row), and `rebuild --fresh` and `fuzzy-search:clear <model>` truncate them when the model is the only one indexed. MySQL and MariaDB try 3 times, 2 s apart, before deleting, and MySQL takes no table lock where it cannot read `performance_schema`. ([guide][u-bm25])
- On MySQL and MariaDB a `rebuild --fresh` or `clear` that cannot truncate deletes in transactions of about 20,000 rows, so the rebuild after it does not crawl behind the purge of one huge transaction; one interrupted part way is finished by running it again. ([guide][u-bm25])
- Index names the migrations create carry the table prefix when the connection's `prefix_indexes` is on, so installs with different prefixes can share one schema (or one SQLite file); existing installs keep their names.

### Deprecated

- `debounce()`, `locale()` and `minMatchLength()` raise `E_USER_DEPRECATED`; they were never implemented and will be removed in v3.0.0. `partialMatch()` is documented as a no-op, not deprecated. ([guide][u-deprecations])

### Removed

- Config keys `performance.chunk_size`, `performance.debounce_ms` and `levenshtein.cost_insert`, `cost_replace` and `cost_delete`: nothing read them. `indexing.table` is gone from the shipped config; only the deprecated `fuzzy-search:index` command and `performReindex()` still read it (default `search_index`). ([guide][u-removed])
- The protected `SearchBuilder::$debounceMs` and `$stopWordLocale` properties, which nothing read. ([guide][u-api])

### Fixed

These are defects in 2.0.x. Changes already listed under Upgrade notes are not repeated.

**Search**

- A literal `%` or `_` matched nothing on SQLite and SQL Server and under MySQL's `NO_BACKSLASH_ESCAPES`, and `[` opened a character class on SQL Server. ([guide][u-search])
- `extended()` results were highlighted and scored by the literal query string instead of its terms. ([guide][u-extended])
- A term made only of stop words returned every row on the LIKE path, and `search('0')` threw from `get()` and listed every row from `paginate()`. ([guide][u-search])
- A `SearchBuilder` was not reusable: a terminal call added the search to the base query, so `first()` followed by `get()` returned one row, and `suggest()` after `get()` returned nothing. ([guide][u-search])
- `fallback()` stored its algorithms and never ran them. ([guide][u-search])
- `getFacets()` threw on MySQL 8 and PostgreSQL for a relevance-ordered search, and under a global scope that calls `select()`; facets now come highest count first. `count()` threw on PostgreSQL. ([guide][u-dbfixes])
- `count()` disagreed with `paginate()->total()` on the extended and index paths. `extended()` with `useInvertedIndex()` counted and paginated the plain term on the index.
- `query.max_term_length` was not applied to extended tokens, `count()` and `paginate()`, the query macros, the `Fuzzy` scopes or `suggest()`. ([guide][u-search])
- Multibyte terms: the drivers sliced terms by byte, `min_search_length` counted bytes, and scoring and highlighting folded ASCII only. ([guide][u-search])
- `stableRanking()` ordered by a hard-coded `id`, broke on UUID and custom keys and aliased FROMs, and named the key twice on SQL Server.
- A join with a column of the same name as a searched column failed as ambiguous, and so did `suggest()`'s table scan; `using('metaphone')` threw on an aliased FROM.
- A union in the base query was searched in its first part only, so on MySQL and MariaDB it served every row of the other parts whatever the term.
- A one-to-many join served some rows twice and others never across `paginate()` pages.
- The `searchFuzzy()` scope weighed every column 1 and threw for list-form columns. ([guide][u-search])
- `fuzzyLevenshtein($term, $columns, 0)` ran with the configured distance. ([guide][u-search])
- `?page` values such as `abc`, `0` or `-3`, and `page(0)` or `skip(-n)`, threw or served the wrong rows. ([guide][u-search])
- `boostRecent()` threw on a column that reads as an array or object, and boosted a future date as if it were past. ([docs][r-recency])
- `metaphone` with a term that encodes to an empty code returned every row whose code is empty. ([docs][r-shadow])
- `soundex`: a term without letters matched unrelated rows, and on PostgreSQL a leading non-ASCII letter was skipped, and capitalised names were missed without fuzzystrmatch. A multi-word term did not find the full name on MySQL and MariaDB, and on SQLite and SQL Server it matched by spelling patterns, so `jon` missed "John" and `smith` missed "Smyth"; SQL Server now uses its native `SOUNDEX()` and SQLite a function the package registers.
- A searchable column named with an SQL keyword broke `get()` on SQLite. A column name with a trailing newline passed the column check. ([guide][u-exceptions])
- `suggest()`'s table scan missed capitalised values on PostgreSQL, did not complete capitalised non-ASCII words, and offered whichever completions it read first instead of the shortest.
- `first()` on a query-builder source threw a `TypeError`. ([guide][u-api])
- `debugScore()` on the index path named `fuzzy` as the algorithm, and a zero-config model under `default_algorithm => 'like'` reported `simple`.
- `using('levenshtein')` matched most rows for a short term and found `Monica Geller` for `john`. ([guide][u-search])
- The exact match did not always rank first on SQLite and PostgreSQL (its case counted), so past `max_candidates` it could fall out of the window.
- `didYouMean()` corrected a multi-word term as one word; it now corrects it word by word.
- `highlight()` on the LIKE path did not mark tokens and synonyms.
- On SQLite, a non-ASCII LIKE term matched only in its own case.
- `facet()` and the other column checks accepted a trailing or doubled dot.

**Index**

- `tokenize()->matchAll()` was ignored, and a search restricted to some columns matched every indexed column. ([guide][u-indexing])
- A BM25 search that matched nothing fired no `FuzzySearchExecuted`. ([guide][u-api])
- BM25 idf compared a word's count across every model with the searched model's own total, so a word another model used often ranked the best match last, with a negative `_score`. Every `rebuild --fresh` inflated the counts of words other models still use. ([guide][u-bm25])
- A ranking capped at `bm25.max_postings_per_term` changed between requests with no data change. ([guide][u-indexing])
- `bm25.max_postings_per_term` capped all the words of a search together, so a common word's rows could push a rarer word's matches out of a relevance search, the best match holding both words included. It now caps each word on its own. ([guide][u-indexing])
- A primary-key change left the old key's index entries behind.
- A job of a cancelled `rebuild --async` batch still indexed rows.
- Under an app-wide array fetch mode (a `StatementPrepared` listener setting `PDO::FETCH_ASSOC`), index searches, `didYouMean()` and `fuzzy-search:status` threw "Attempt to read property on array". The package now reads its own rows as objects.
- Concurrent index writes: two writes for one row counted it twice, a write that read before a newer save indexed the older text, and writes whose words crossed could deadlock. A re-index also rewrote the dictionary row of every word it kept. ([setup][d-bm25-setup])
- MySQL/MariaDB, an index write inside your transaction: it could leave word counts wrong, put a deleted row back or index older text, and lose a deadlock to a write of a neighbouring row. ([setup][d-bm25-setup])
- `fuzzy-search:clear --all` could leave a model's totals short when a write committed during it, and a flush could fail another model's write on the postings' foreign key. ([docs][d-bm25-commands])
- Two index tokens sharing their first 191 characters collided on MySQL/MariaDB; terms are now capped at 191 characters. ([guide][u-bm25])
- `restore()` did not re-index a soft-deleted model, and with `indexing.async` off a soft delete's removal could land after a restore. ([guide][u-indexing])
- A searchable column holding `"0"` was skipped, and one qualified with the model's own table (`posts.title`) was never indexed. ([guide][u-indexing])
- On a read/write split, an index write read the row from the replica. ([docs][d-bm25-how])
- On a connection with a table prefix, `migrate` failed on MySQL (the terms table's unique key was added to the unprefixed table), and indexing and the flush's orphan sweep ran raw SQL against unprefixed tables. A second install with its own prefix in a shared schema stopped at `relation postings_unique_idx already exists`.
- `count()` and `paginate()` on `useInvertedIndex()` did not cap the term at `query.max_term_length`. `take(PHP_INT_MAX)` threw, and `skip()->take()->get()` hydrated every model up to twice the page end.
- An empty index result came back in a base `Collection`, not the model's collection.
- A rebuild of a single-table-inheritance parent indexed each chunk under its first row's class. ([guide][u-indexing])
- Metaphone shadow columns: a save of a partially selected model wiped them, two saves at once could leave the other save's code, a long value could not be saved, and the column lookup was cached by table name alone across connections. ([docs][r-shadow])
- A cached search threw a `TypeError` on every hit under Laravel 13's `cache.serializable_classes => false`. ([guide][u-caching])

**Scout**

- `search()` reported the page size as `total`, and `scout:delete-index` deleted nothing. ([guide][u-indexing])
- On Scout 10.0, `paginate()->total()` under a `query()` callback counted rows outside the matches (an ungrouped `orWhere()`, a union). ([guide][u-federated])
- Rows a global scope hides counted in `total()` and left pages short. A `query()` callback whose select left out the key lost the order and scores, and a join that repeats a model filled a page with copies. ([guide][u-federated])
- A null query (an empty `?q=`) threw a `TypeError`. A queued delete of a model keyed as `users.id` threw and left the row indexed. ([guide][u-federated])
- The engine bound every word of a long query; it now searches the first `query.max_term_length` characters.
- A `?page` near `PHP_INT_MAX`, or a negative `take()`, threw or sliced from the end.
- The dual-trait model (Scout's `Searchable` with the package's, as `docs/integrations.md` shows it) threw on `search()` and every save. ([docs][d-scout])
- `delete()` of a collection removed the models one at a time; it now uses one transaction per 500 models.

**Federated**

- `getCounts()` counted the page, not the matches. ([guide][u-federated])
- `searchIn()` was ignored for `Searchable` models, and a column a table lacks raised an SQL error. ([guide][u-federated])
- `options()` was never applied, a negative `limit()` sliced from the end, and the declared columns of a model without the `Searchable` trait were never seen (on a Scout model, reading them ran an index write). ([guide][u-federated])

**In-memory (`FuzzySearch::on()`)**

- Case folded in ASCII only, and the whole term was scored however long. ([guide][u-federated])
- It wrote a temporary property onto every matched object (an `Error` on a `readonly` class), warned on an array column, read a `LazyCollection` to the end, and sliced from the end on a negative `skip()` or `take()`.

**Commands**

- `rebuild --fresh --async` on the database queue flushed the index, then failed to queue past the database's bound-parameter limit. Without the `job_batches` table it failed after flushing. ([docs][d-bm25-commands])
- `rebuild --async` reads the model's keys 10,000 at a time; 2.0.1 loaded every key in one query before it queued a job, which on a large table ran the command out of memory. ([docs][d-bm25-commands])
- `rebuild` did not fill metaphone shadow columns, and printed "Done." for a model that produced no terms; it also chunks by key now (`chunkById()`). ([guide][u-bm25])
- A class named with a leading backslash (`"\App\Models\Post"`) flushed and cleared nothing, and `clear` or `flush` refused a class that no longer exists. ([guide][u-indexing])
- The deprecated `fuzzy-search:index` died on SQLite and SQL Server, ignored declared columns and threw on a Scout dual-trait model.
- `fuzzy-search:benchmark` and `:explain` timed the cache instead of the search, and did not exit 1 with a message for a bad algorithm or an empty term.
- `fuzzy-search:explain` printed a hand-built "Pattern Analysis" that did not match the search; it prints the search's SQL and bindings.

**Per database**

- PostgreSQL and SQL Server: BM25 indexing failed on every write (the "ambiguous doc_count" upsert, and `insertOrIgnore()` on SQL Server), so the index stayed empty. It now works. ([guide][u-dbfixes])
- SQL Server: `didYouMean()` used `LENGTH()`, the dictionary folded words such as `straße`/`strasse`, and long `extended()`, `tokenize()` or accented searches passed 2,100 bindings. ([guide][u-dbfixes])
- MySQL/MariaDB: the dictionary folded `café` and `cafe` into one word, and indexing a document with both failed. ([guide][u-bm25])
- MariaDB connections (driver `mariadb`, Laravel 11+) fell back to generic SQL on every MySQL-only branch. ([guide][u-dbfixes])
- `PorterStemmer`'s install hint named a version it cannot load; it names `wamania/php-stemmer:^1.3`. ([docs][d-stemming])

### Security

- **Soundex filter bypass** (2.0.0–2.0.1; MySQL, MariaDB connected through the `mysql` driver (every MariaDB connection on Laravel 10), PostgreSQL with native functions). `SOUNDEX(...) OR SOUNDEX(...)` was not parenthesised, so a `where()`, tenant scope or soft-delete scope chained after `using('soundex')` guarded one arm only. Check soundex searches behind a tenant or permission constraint. ([guide][u-search])
- **The Scout engine ignored `where()`, `whereIn()`, `whereNotIn()` and soft deletes** (2.0.x): a Scout `where('tenant_id', …)` returned every tenant's matches, and trashed rows came back. Check Scout searches that rely on these constraints. ([guide][u-federated])
- **A plain query builder's `where()` was dropped on the index path** (2.0.x): `new SearchBuilder(DB::table('notes')->where(...), …)->useInvertedIndex(Note::class)` served rows the `where()` excluded. ([guide][u-bm25])
- **`didYouMean()` offered other models', other tenants' and hidden columns' words** (2.0.x): it read the whole dictionary and ignored `where()`, joins and scopes. It now offers the searched model's words that the query can see, and so does the new dictionary `suggest()`. ([guide][u-bm25])
- **`_score` revealed rows the query hides** (2.0.x): BM25 `_score` was scaled against the best match in the whole index, other tenants' included. `_raw_score` still counts the model's whole index. ([docs][d-bm25-how])
- **Index-path totals counted rows the query hides** (2.0.x): `useInvertedIndex()->paginate()->total()` counted every match in the model's index, whatever the `where()`, join or tenant scope, so a tenant could learn how many other tenants' rows hold a word. ([guide][u-search])
- **The result cache served one query's rows to another** (2.0.x with `cache()`): another tenant's rows, another search's highlight markup, another request's eager loads and visibility. ([guide][u-caching])
- **`orderByFuzzy()` did not check its column** (since 1.0): a sort field taken from the request could inject SQL. ([guide][u-exceptions])
- **A column name could run a model method** (2.0.x): `searchIn()` names on the index path with highlighting and in `FuzzySearch::on()`, `boostRecent()`'s column, and `@fuzzyHighlight`'s fallback were read through `getAttribute()`, so `?recent_by=markEmailAsVerified` called that method. Check apps that take a column name from the request. ([guide][u-highlighted])
- **Hidden columns leaked** (2.0.x): `_highlighted`, `_matches`, `_debug` and `suggest()`'s table scan included `$hidden` columns, and auto-detection could search hidden columns and `password`- or `token`-named ones. ([guide][u-indexing])
- **Invalid UTF-8 and NUL bytes** (2.0.x and earlier): `?q=john%C3` was a 500 on PostgreSQL and SQL Server, and a NUL inside a term matched every row on SQLite, PostgreSQL and SQL Server. Both are dropped from every term. ([guide][u-search])
- **PHP rescoring was a CPU DoS** (2.0.x): `similar_text()` and `levenshtein()` compared whole values, so long values or terms took seconds, in `FuzzySearch::on()` too. They compare at most 255 characters. ([guide][u-search])

[u]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md
[u-checklist]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#upgrade-checklist
[u-search]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#search-results
[u-exceptions]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#exceptions
[u-caching]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#caching
[u-indexing]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#inverted-index-indexing-and-commands
[u-federated]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#federated-scout-and-in-memory-search
[u-api]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#config-and-php-api
[u-highlighted]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#_highlighted-is-now-escaped-for-every-column
[u-config]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#config-keys-that-now-take-effect
[u-removed]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#removed-config-keys
[u-deprecations]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#deprecations
[u-pages]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#paginate-page-sizes
[u-dbfixes]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#database-fixes-you-get-for-free
[u-relations]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#relationship-search
[u-extended]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#extended-syntax--and-field-are-operators-now
[u-bm25]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#inverted-index-bm25
[u-weighted]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#weighted-bm25-column-weights-on-the-index
[u-analytics]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#search-analytics-new
[u-suggest]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#suggest-on-an-indexed-model-now-completes-from-the-dictionary
[u-tokenizers]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#tokenizers-per-model-pipelines-accent-folding-and-stop-word-files-new
[u-filament]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/UPGRADE_v2.0_TO_v2.1.md#filament-integration-json-api-resources-and-lastexecution-new
[d-bm25-how]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/bm25.md#how-it-works
[d-bm25-setup]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/bm25.md#production-setup
[d-bm25-typo]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/bm25.md#typo-tolerance-as-you-type-synonyms-and-stop-words-on-the-index
[d-bm25-weights]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/bm25.md#column-weights-on-the-index-bm25f-lite
[d-bm25-commands]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/bm25.md#artisan-commands
[d-bm25-sti]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/bm25.md#single-table-inheritance
[d-bm25-accessors]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/bm25.md#searchable-fields-backed-by-accessors
[d-relations]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/relationships.md#searching-through-relationships
[d-extended]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/extended-syntax.md#typo-tolerant-and-field-scoped-terms
[d-analytics]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/analytics.md#persisted-search-analytics
[d-tokenizers]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/tokenization.md#tokenizers
[d-stemming]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/tokenization.md#stemming-optional
[d-filament]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/integrations.md#filament-integration
[d-resources]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/integrations.md#json-api-resources
[d-scout]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/docs/integrations.md#scout-driver
[r-chaining]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/README.md#chaining-eloquent
[r-federation]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/README.md#multi-model-federation-search
[r-recency]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/README.md#recency-boost
[r-sizing]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/README.md#sizing-the-index
[r-shadow]: https://github.com/ashiqfardus/laravel-fuzzy-search/blob/v2.1.0/README.md#shadow-columns

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
