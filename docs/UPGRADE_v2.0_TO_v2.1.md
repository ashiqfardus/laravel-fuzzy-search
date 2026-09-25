# Upgrading from v2.0 to v2.1

This guide covers behaviour changes that can affect an existing v2.0 install.

## Behaviour changes

Every change below can alter what an existing call returns, throws, caches or writes. Most need no
code change; where one does, the bullet says what to do. The sections after this one hold the
details for config, pagination, relationship search, the extended syntax, the inverted index,
analytics, `suggest()` and the tokenizers.

### Search results

- **Accented terms also search their accent-free form, and the typed form is kept.** Under the shipped `unicode.accent_insensitive => true`, 2.0 replaced "Müller" with "Muller". 2.1 searches both, so rows that spell the accent ("Zoë Müller") are found as well as those that don't. A row found only through the folded form ranks and highlights as a match of it. ASCII terms are unaffected: their SQL is byte-identical. An accented stop word (`für`, `à`, `être`) is now dropped as listed, because stop words are matched against the typed word. Folding the term never folds the column: an unaccented `cafe` finds `Café` as a substring match only under an accent-insensitive collation (MySQL/MariaDB) or through PostgreSQL's `unaccent()` below.
- **An accented term and its accent-free form count once for relevance.** A row scores the better of the two, never their sum, and in an `extended()` query a leaf and its folded form are one leaf; with folding off, `müller | muller` are two terms and add up. Unaccented terms score as in 2.0.
- **PostgreSQL: `unaccent()` needs an explicit opt-in.** With `use_native_functions=true`, the global `unicode.accent_insensitive` key no longer turns every search into `unaccent(col) ILIKE unaccent(?)`. Your chosen algorithm (pg_trgm `similarity()`, `SOUNDEX()`, …) runs again. To fold the column too, opt in explicitly: `->accentInsensitive()`, `$searchable['accent_insensitive' => true]`, or a preset. The `unaccent()` alternative is then ORed beside the algorithm. That opt-in requires the extension (`CREATE EXTENSION IF NOT EXISTS unaccent;`); without it such a search fails with `function unaccent(…) does not exist`.
- **`similar_text` returns far fewer rows on long columns.** `similar_text.min_percentage` (default `70`) is now enforced. A match contains the term, so its `similar_text()` percentage is `200·t / (t + v)`, and at 70% a value may be at most about 1.86 times the term's length. "john" still finds "John" and "Johnny" but no longer "John Doe" or "Bob Johnson". Lengths are counted in characters on every database. With `tokenize()`, the whole search term's length sets the bound for every token, so "john doe" still finds "John Doe". Set `similar_text.min_percentage` to `0` (or pass the per-call `min_percentage` option as `0`, or `fuzzySimilar($term, $columns, 0)`) to get 2.0's results back.
- **Auto-detected columns are text columns only.** A zero-config model no longer searches numeric, boolean or date columns picked from `$fillable` or as the first column, or a PostgreSQL enum. On PostgreSQL those searches failed, and elsewhere they matched digits. JSON and UUID columns are skipped where the database has a JSON or UUID type (JSON: MySQL, PostgreSQL; UUID: PostgreSQL, SQL Server, MariaDB's native `uuid`) and still detected where they are stored as text. If you relied on an auto-detected non-text column (for example an integer `code`), declare it in `$searchable['columns']`.
- **`orderBy()` on the builder now sticks.** It replaces the relevance order on every path; before, PHP rescoring re-sorted by `_score` on the LIKE path, and the extended and index paths ignored it. If you called `orderBy()` only to shape the candidate window, order the underlying query instead: `->query(fn ($q) => $q->orderBy('id'))`. `stableRanking()` now also applies on the `extended()` path.
- **Multi-term `extended()` queries score each leaf term on its own**, adding the leaf scores, instead of scoring against all the terms joined into one string: `_raw_score` for those queries changes, and a row that matches more of the query ranks higher. Single-term queries are unchanged. Many-term queries give the similarity floor only to leaf terms within a 255-character total; later terms rank by exact, prefix or contains match alone.
- **A `!` negation keeps rows with NULL searchable columns** — such queries can return more rows than before.
- **PHP rescoring reads at most 255 characters** of a column value (and of a term) for its similarity and Levenshtein floor; exact, prefix and contains tiers still read the whole value.
- **`_highlighted` and `_matches` drop hidden columns** (`$hidden`, or outside `$visible`), on related models too, and `FuzzySearchResource` applies the row's current rule. `debugScore()`'s `_debug` leaves them out too, and `suggest()` never offers a word from one (see [`suggest()`](#suggest-on-an-indexed-model-now-completes-from-the-dictionary)).
- **The query macros and `Fuzzy` scopes cap the term at `query.max_term_length`** (default 128 characters), as `search()` always did. A longer term is truncated, not rejected. `fuzzyLevenshtein($term, $columns, 0)` now means exact containment; it used to run with the configured distance.
- **`?page` is read as a whole number of at least 1 on every paginator.** `?page=0` or `?page=-3` is page 1 instead of the wrong rows, and `?page=abc`, `?page[]=1` or a page past the integer range no longer throws a `TypeError` on the index path. `page(0)`, a negative `page()` and `skip(-n)` serve the first rows instead of the last.
- **A backslash in a search term is literal on MySQL, MariaDB and PostgreSQL.** 2.0 passed it to LIKE, which read it as an escape, so `back\slash` matched `backslash` and not itself. 2.1 escapes it on PostgreSQL; on MySQL and MariaDB every LIKE now carries `ESCAPE '!'`, under which a backslash is an ordinary character (and which, unlike their backslash default, still works under the `NO_BACKSLASH_ESCAPES` SQL mode). SQLite and SQL Server always treated it as literal.
- **Paginated BM25 `_score` now shares one scale across pages.** `_score` on a BM25 result is
  normalised against the best-ranked row the query can see — the same row on every page — not the
  page's own maximum, so page 2's top row is no longer always `1.0`. Under a `where()`, a join, a
  scope or `filter()`, that is the best match the query lets through, so the top result scores
  `1.0` (v2.0's `get()` scaled against the best match in the whole index, other tenants' included). The LIKE and extended paths changed too: `paginate()`
  now normalises `_score` across the whole `max_candidates` candidate window instead of within
  the current page (v2.0 behaviour); pages whose offset falls beyond that window still normalise
  within the page, same as before.
- **BM25 now honours your constraints before cutting the page.** `filter()`/`filterIn()`,
  `where()` constraints, and global scopes are applied *before* the ranking is cut to the
  requested page, instead of after. Selective filters no longer return short or empty pages, and
  `paginate()` totals now count only matching rows — if you were relying on the old (wrong)
  totals, expect them to change.
- **`fallback()` actually runs now.** Previously the fallback algorithms you registered were
  stored but never executed; if the primary search returned no rows, that was the end of it. Now
  each fallback runs in order until one returns results.
- **Case-insensitive scoring and highlighting now cover every script.** They folded ASCII
  only, so a lower-case Cyrillic, Greek or accented term scored an upper-case value as a fuzzy
  near-miss and highlighted nothing. Such results now rank as exact/prefix/contains matches and
  are highlighted, so the order of non-ASCII results can change. ASCII-only text scores and
  highlights exactly as before; `_matches` indices are still byte offsets.
- **`min_search_length` now applies to every search, not only `get()`.** A plain term shorter
  than `min_search_length` characters (default 2) now matches nothing on `paginate()` (an empty
  page, total 0), `count()` (0), `getFacets()`, `FuzzySearch::on()`, the Scout engine and
  `FederatedSearch` models without the `Searchable` trait, as it always did on `get()`,
  `simplePaginate()` and `Searchable` models in `FederatedSearch`, and fires no
  `FuzzySearchExecuted`. In v2.0 `User::search('j')->paginate()` returned every row (the fuzzy
  patterns for a one-letter term match everything) while `->get()` returned nothing. Set
  `min_search_length` to `1` to search one-character terms again; the inverted index and the
  Scout engine still never match them, because they do not index one-character tokens.
  `extended()`/`searchBoolean()` queries, the `whereFuzzy`-style macros, the `Fuzzy` scopes and
  `tableSearch()` are unaffected.
- **A term made only of stop words matches nothing.** With `ignoreStopWords()`, `search('the')`
  applied no condition on the LIKE path and returned every row (the index path returned none); it
  now returns no rows and a total of 0 everywhere.
- **A search on a model with no searchable column now returns nothing instead of every row.**
  When a model declares no `$searchable['columns']`, auto-detection finds none (every text column
  hidden, say) and `searchIn()` is not called, `search()`, `searchOn()`, the `searchFuzzy()` scope
  and `useInvertedIndex()` added no condition and returned every row. They now match nothing, like
  a term below `min_search_length`: an empty collection or `null`, an empty page with a total of 0,
  a count of 0, empty facets, no `FuzzySearchExecuted` and no cache entry. Such a model contributes
  nothing to `FederatedSearch`, and `FuzzySearch::tableSearch()` matches nothing for it. `FuzzySearch::on($items)->search('term')` without `searchIn()` matches nothing too — it returned every item — while an empty term still lists them. A `FederatedSearch` model without the `Searchable` trait, searched without `searchIn()`, is searched only on its declared columns (see the federated bullets below) or else on whichever of the guessed `name` and `title` columns its table has; with none of these it contributes nothing, where it used to throw (and on SQLite a term such as `name` matched every row). A
  `SearchBuilder` on a plain query builder without `searchIn()` matches nothing too, unless
  `useInvertedIndex(Model::class)` searches that model's index; a model with a `searchableText()`
  hook still searches on `useInvertedIndex()`. `getFacets()` always runs on the LIKE path, so
  without `searchIn()` those two index searches return empty facets while `get()` returns the
  index's matches. A model whose table cannot be read (a `$table` typo, a table not migrated yet)
  is not treated as having no column: the query runs and its database error surfaces. On
  `useInvertedIndex()` the table is read only when the index has matches, so an empty index
  still returns nothing without an error.
  `extended()`/`searchBoolean()` still throw `SearchableColumnsNotFoundException`, and the
  `whereFuzzy`-style macros take their columns explicitly. Declare `$searchable['columns']` to
  search such a model.
- **Invalid UTF-8 bytes are dropped from search terms.** `?q=jo%C3hn` now searches `john` on
  every database instead of erroring on PostgreSQL and SQL Server (and searching the raw bytes on
  SQLite and MySQL); the event and the analytics log record the cleaned term. A term made only of
  invalid bytes (`?q=%FF`) matches nothing on `search()`, `FederatedSearch` and `FuzzySearch::on()`:
  no rows, a total of 0, and no `EmptySearchTermException`. The query-builder helpers (the
  `whereFuzzy`-style macros, the `Fuzzy` scopes and `tableSearch()`) treat it as `''`.
- **A `SearchBuilder` is reusable.** Terminal calls compile onto a clone and no longer add the search's conditions to your base query, so `first()` followed by `get()` returns every match (it returned one row), and `suggest()` on a builder that already ran `get()` is no longer narrowed by that search.
- **`paginate()` ranks across up to `max_candidates` rows before slicing** (2.0 ranked within the page), and works with `extended()`/`searchBoolean()`.
- **`typoTolerance(0)` and `typoTolerance(1)` now apply to the default `fuzzy` algorithm**, which generated every typo pattern whatever the level, so those searches match fewer rows.
- **`search('0')` is a real search** for a one-character term. It threw `EmptySearchTermException` from `get()` and listed every row from `paginate()`/`count()`; at the default `min_search_length` of 2 it now matches nothing.
- **`min_search_length` and `query.max_term_length` count characters, not bytes**, so a one-character multibyte term is below the default minimum of 2, and a long non-Latin term keeps more of its characters.
- **The `searchFuzzy()` scope weighs the configured columns** with their `$searchable['columns']` weights (it weighed every column 1), so its ranking changes, and list-form columns no longer throw.
- **On a connection with a table prefix, write a qualified column's table unprefixed** in `orderByFuzzy()` and the other raw-SQL paths: the prefix is now added for you, so `orderByFuzzy('pre_users.name', …)`, which happened to work before, now names `pre_pre_users`.
- **`_highlighted` escapes every column** — see [below](#_highlighted-is-now-escaped-for-every-column).
- **`paginate()` and `simplePaginate()` clamp `perPage`** to between 1 and `max_candidates` — see [page sizes](#paginate-page-sizes).
- **`~`, `field:` and `!` are read differently by `extended()`** — see [Extended syntax](#extended-syntax--and-field-are-operators-now).
- **`suggest()` on an indexed model completes from the dictionary** — see [below](#suggest-on-an-indexed-model-now-completes-from-the-dictionary).
- **`ignoreStopWords('en')` prefers the configured list**, which is shorter than the built-in one — see [Tokenizers](#tokenizers-per-model-pipelines-accent-folding-and-stop-word-files-new).

### Exceptions

- **An empty search term throws on every terminal.** `count()`, `paginate()` and `getFacets()` now throw `EmptySearchTermException` for `''` or whitespace, as `get()` always did. If you paginate an empty search box, set `allow_empty_search => true` (every terminal then lists every row) or check the term first.
- **`searchIn()` relation paths need a `Relation` return type or a declared `$searchable` column**, or they throw — see [Relationship search](#relationship-search).
- **Column names are checked in more places.** `orderByFuzzy()` throws `InvalidArgumentException` for a column that is not letters, digits, underscores and dots, and a column name with a trailing newline is rejected by `searchIn()`, `facet()`, `FederatedSearch::searchIn()` and the macros. A `searchIn()` name of three or more dotted segments whose first segment is not a relation throws when the query is built.
- **`extended()`/`searchBoolean()` accept exactly `query.max_tokens` tokens.** They threw at the limit, so the default 32 allowed 31; one more than the limit still throws.
- **`didYouMean()` surfaces real database errors**, and `extended()` throws for an unknown field or an empty `field:` — see [Inverted index](#inverted-index-bm25) and [Extended syntax](#extended-syntax--and-field-are-operators-now).

### Caching

- **A published `cache` block is live.** The `cache.*` config is now read (`enabled`, `driver`, `ttl` in seconds, `prefix`). If your `config/fuzzy-search.php` has `'enabled' => true` (as the old README showed), every `get()`, `first()` and `simplePaginate()` is now cached for `ttl` **seconds** in that store. A ttl you wrote as minutes is now read as seconds. A cache hit fires no `FuzzySearchExecuted` event and writes no analytics row. Set `'enabled' => false` to keep 2.0's behaviour. `cache()` without an argument, or `cache(null)`, now caches for `cache.ttl` (3600 s by default, the same 60 minutes as before; `cache(null)` used to cache nothing), and `cache(0)` turns caching off for that query. `getDebugInfo()['cache_ttl']` is in seconds.
- **Cache keys changed.** A generated key includes more of the query (highlight tags, `withRelevance()`, `debugScore()`, eager-load names, the connection, database, table prefix and PostgreSQL `search_path`) and the whole `fuzzy-search` config, so entries cached by 2.0 are not reused after upgrading, and any later config change starts a fresh set of entries. A key you pass to `cache($minutes, $key)` is used as given. A search with a `customScore()` closure is cached only under a key you name.
- **A cache hit reloads relations.** Cached rows carry no relations, and each hit runs the current request's eager-load queries, with their constraints.

### Inverted index, indexing and commands

- **BM25 rankings change where models share words**, and equal scores come back by model key — see [Inverted index](#inverted-index-bm25).
- **Indexing waits for the transaction to commit**, and writes for one row wait for each other — see [Inverted index](#inverted-index-bm25).
- **With `indexing.async` off, an index error no longer fails the save.** It is reported to your exception handler instead — see [Inverted index](#inverted-index-bm25).
- **The indexer indexes the row as stored, not the instance you pass**, and index suggestions skip hidden columns — see [Inverted index](#inverted-index-bm25).
- **Index terms are capped at 191 characters** and `model_id` widens to 191 by a new migration — see [Inverted index](#inverted-index-bm25).
- **Commands exit 1 on bad input**, `rebuild` fills metaphone shadow columns, `rebuild --async` needs `job_batches`, and `flush <model>` is `clear <model>` — see [Inverted index](#inverted-index-bm25).
- **BM25 searches are typo-tolerant by default** and the dictionary is read per model — see [Inverted index](#inverted-index-bm25).
- **The Scout engine ranks with column weights** — see [Weighted BM25](#weighted-bm25-column-weights-on-the-index).
- **The search log migration creates the `analytics.table` table** — see [Search analytics](#search-analytics-new).
- **`restore()` re-indexes a soft-deleted model.** A change to the deleted-at column now triggers a reindex; the restored row used to stay out of `useInvertedIndex()` results until the next rebuild.
- **Rebuild your index if you search non-Latin or accented text.** The BM25 tokenizer now keeps
  combining marks (`\p{M}`) attached to their base character instead of stripping them. Indexes
  built from Bengali, Hindi, Thai, or decomposed-accent Latin text before this fix are stale —
  run `fuzzy-search:rebuild "App\Models\YourModel" --fresh` once.
- **Accessor-backed searchable fields reindex on every save by default.** If a searchable column
  is a computed accessor (e.g., a `brand_name` accessor backed by `brand_id`), `wasChanged()`
  can't see it, so previously the model never reindexed after an update. It now reindexes on
  every save unless you declare `$searchable['reindex_on' => ['brand_id']]` to trigger reindexing
  only when the real backing column(s) change.
- **Models with no `$searchable['columns']` are now indexed.** A model that uses the trait and
  declares no columns (`class User extends Model { use Searchable; }`) is now indexed — and gets
  its `*_metaphone` shadow columns maintained — from the columns auto-detection already used for
  `search()`; previously `getSearchableColumns()` returned `[]` and every consumer except
  `search()` silently skipped the model. This only starts writing rows when `indexing.enabled` is
  `true` (it defaults to `false`): if you enable indexing and have such models, their first save
  or `fuzzy-search:rebuild` will now populate the index. Auto-detection selects string-like
  attributes only, and that applies to what a zero-config model **searches** as well as what it
  indexes: a column cast to an enum, `array`, `json`, `object`, `collection` or a custom cast class
  is no longer auto-selected, and neither is an `encrypted` or `hashed` column (its decrypted text
  or hash would otherwise be written to the index and served by `suggest()`), so such a model can search a different set of columns than in v2.0
  (a later string column may take the freed slot). A value that still turns out not to be text is
  skipped rather than thrown, so this cannot make a save throw. Declare `$searchable['columns']`
  to search or index anything else: a declared column is your choice, and one that cannot be
  indexed as text raises an error naming it. An auto-detected column is indexed as the model's raw
  attribute value, not through a get accessor, so an accessor that decrypts or reformats it never
  reaches the index. A masking accessor (`Str::mask()`) is bypassed the same way, so the unmasked
  value is indexed and served by `suggest()` and `didYouMean()`; and encryption that decrypts into
  the attributes in memory (spatie/laravel-ciphersweet) is invisible to the package, so its
  plaintext is indexed. Put such a column in `$hidden`, or declare `$searchable['columns']`.
  Declaring the columns, or overriding `getSearchableColumns()`, is what opts into accessors.
  Only the index reads the stored value: `suggest()`'s table scan, relevance scoring and
  highlighting read an auto-detected column through its accessor, as in 2.0, so for a column an
  accessor decrypts, declare `$searchable['columns']` without it, or hide it (`$hidden`);
  otherwise `suggest()`'s table scan can return words from the decrypted value of rows the query
  can see.
- **Auto-detection skips hidden and secret columns.** A model with no `$searchable['columns']`
  no longer auto-selects a column in `$hidden`, a column outside a non-empty `$visible`, or
  a secret-named column — any name containing `password`; `token` or a name ending in
  `_token`; `secret`, `api_key` or `private_key` as a whole underscore-separated part of the
  name (`secret_note`, `stripe_api_key`, `webhook_secret` — not `secretary_name`); or a name
  ending in `recovery_codes` (in any letter case; `sort_key`-style names are still picked) —
  on the LIKE path as well as the index path. A zero-config model whose only priority column was
  hidden (a hidden `name`, say) now picks another column, or none. With none, the model has no
  column to search, and its search matches nothing (see [Search results](#search-results)).
  Declare `$searchable['columns']` to keep searching a hidden column. Detection reads the model
  class's default `$hidden` and `$visible`, once per process, so a column hidden at runtime with
  `makeHidden()`, `setHidden()` or a per-request `getHidden()` is still searched and indexed; put
  it in `$hidden` to keep it out.
- **Scout: `scout:delete-index` now deletes.** It passed the index name where a model class was
  expected and removed nothing; it now clears every indexed model whose `indexableAs()` is that
  name. A deploy script that runs it before `scout:import` now really starts from empty. The
  engine's `raw()['total']` is also the match count now, not the size of the returned page.

### Federated, Scout and in-memory search

- **`FederatedSearch` searches each model with its own algorithm and typo tolerance.** Without `using()` or `typoTolerance()`, every model that uses the `Searchable` trait was searched with `fuzzy` and a typo tolerance of 2, whatever its `$searchable['algorithm']` and `['typo_tolerance']` said. Each model now searches the way `Model::search()` does, with its own configuration (the configured `default_algorithm` and typo tolerance when it declares none), so a `like` model no longer matches typos in a federated search. `using()` and `typoTolerance()` still apply to every model and `options()`, which used to be ignored, now applies to every model too, and `typoTolerance()` now reaches models without the trait as well. Call `->using('fuzzy')->typoTolerance(2)` to keep the old results.
- **`FederatedSearch` reads the declared columns of models without the `Searchable` trait.** A `Fuzzy`-trait model is searched on its `$fuzzySearchable` (or `getFuzzySearchableColumns()`), and a model with a protected `$searchable['columns']` on those columns; both used to be searched on the guessed `name`/`title`, so results change for such models. A `Fuzzy`-trait model is also searched with its own `$fuzzyAlgorithm` and `$fuzzyOptions` (`default_algorithm` when it declares none) instead of LIKE; call `->using('like')` on the federated search to keep the old matching.
- **`FederatedSearch` holds models without the `Searchable` trait to `max_candidates`.** Such a model contributes at most `max_candidates` rows (default 1000) to `get()`, the paginators and `getCounts()`, as a `Searchable` model always has; its matches used to be counted and fetched without limit, and a deep page fetched offset + perPage rows from it.
- **`FederatedSearch` results are now deterministically ordered.** Ordering is score, then
  `orderByModel()` (or the `across()` order), then primary key — previously ties and
  `withRelevance(false)` results came back in database order.
- **`FederatedSearch::searchIn()` applies to models that use the `Searchable` trait** (it was ignored for them), and columns a table does not have are skipped instead of raising SQL errors.
- **`FederatedSearch::getCounts()` and `paginate()->total()` count reachable matches.** `getCounts()` grouped the page and so reported page sizes; it now returns per-model match counts (0 for a model with none), each capped at `max_candidates` and `limitPerModel()`, and `total()` is their sum.
- **Scout `hybrid()` throws `NotSupportedException`.** It reached the engine and ran a plain keyword search.
- **Scout `orderBy()` now orders.** `orderBy()`, `orderByDesc()`, `latest()` and `oldest()` on a Scout search were ignored and results came back in relevance order; they now replace the relevance order (ties by key, descending), as on Scout's database engine. An order column that is not a column name throws `InvalidArgumentException`. The engine also searches only the first `query.max_term_length` characters of a query.
- **The Scout engine's `update()` writes a collection in one transaction**, so an error on one model leaves none of that collection indexed, where before the models ahead of it were.
- **`FuzzySearch::on()` folds case in every script.** It folded ASCII only, so `ÉCOLE` scored `école` as a near-miss and `МОСКВА` did not match `москва`; such matches now score as exact/prefix/contains matches, so in-memory results for non-ASCII text can rank differently. A term is also cut at `query.max_term_length` characters.

### Config and PHP API

- **`FuzzySearchExecuted` fires in more places.** A BM25 search that matches nothing fires it, in-memory searches fire it (see [Search analytics](#search-analytics-new)), and `simplePaginate()` reports the page size in `resultCount`, not the look-ahead row.
- **Config keys that were documented but inert now take effect** (`scoring.*`, `highlighting.*`, `performance.max_patterns`, `unicode.normalize`, `similar_text.min_percentage`, `cache.*`) — see [below](#config-keys-that-now-take-effect).
- **New config keys:** `indexing.job` (`tries`, `backoff`, `timeout`) bounds retries of
  `IndexModelJob`/`RebuildIndexJob`; `bm25.candidate_chunk` (default 200) sets the chunk size used
  when checking BM25 rankings against a constrained query.
- **`indexing.table` was removed** — see [Removed config keys](#removed-config-keys).
- **`FuzzySearch::$config` is now nullable (`?array`)** and is null when the class is container-built; subclasses that read `$this->config` must call `currentConfig()` instead.
- **TrigramDriver's LIKE fallback cap raised from 10 to 100.** It now caps its pattern list at
  `performance.max_patterns` (default 100) instead of a hard-coded 10, so long terms match more
  widely; lower the key or call `maxPatterns()` to restore the old cap.
- **`debounce()`, `locale()` and `minMatchLength()` raise `E_USER_DEPRECATED`** — see [Deprecations](#deprecations).
- **Protected `SearchBuilder` methods changed:** `calculateRelevanceScores()` takes an optional `?array $terms` second argument (an extended query's leaf terms, scored one by one), and `generateCacheKey()` returns `?string` (null when the search cannot be cached). A subclass overriding either must follow.

## `_highlighted` is now escaped for every column

- **Every value in `_highlighted` is HTML-escaped as of 2.1.0.** In v2.0 only the
  columns that actually matched were escaped and wrapped in the highlight tag; a searched column
  that did not match carried the raw model value. The array is now uniformly safe to render as HTML.
- **A non-matching value echoed with `{{ }}` is now escaped twice.** `{{ $user->_highlighted['name'] }}`
  on a value containing `&`, `<` or `>` renders the literal text `&amp;`, `&lt;`, `&gt;` instead of the
  characters themselves. Render the array with `{!! !!}`, or use `@fuzzyHighlight($model, 'column')`
  for every column — matched or not — which handles both branches for you.

## Config keys that now take effect

Earlier releases documented several config keys as "reserved for future use" — the values
were parsed into the config array but never read by the package. As of 2.1.0 they are read:

| Key | What it controls | v2.0 behaviour | v2.1.0 default |
|---|---|---|---|
| `scoring.exact_match` / `prefix_match` / `contains` / `fuzzy_match` | Base points per match tier, used by both the SQL `ORDER BY` and the PHP rescorer | Ignored — scoring constants were hard-coded | `100` / `80` / `60` / `50` |
| `highlighting.enabled` / `tag_open` / `tag_close` | Default highlight tags, and whether every search is highlighted without calling `->highlight()` | Ignored — tags only came from `->highlight()` arguments | `false` / `<em>` / `</em>` |
| `performance.max_patterns` | Default cap on generated LIKE patterns | Ignored — `maxPatterns()` only stored the value; no driver read it (Trigram sliced at 10 on its own) | `100` |
| `unicode.normalize` | NFC-normalise search terms by default | Ignored — normalization was opt-in per query only | `false` |
| `similar_text.min_percentage` | The least `similar_text()` percentage a `similar_text` match may have, enforced in SQL as a length bound (a match is at most `t·(200 − p) / p` characters for a `t`-character term) | Ignored — `similar_text` matched every value containing the term | `70` |
| `cache.enabled` / `driver` / `ttl` / `prefix` | Cache every `get()`, `first()` and `simplePaginate()` without calling `cache()`; the store; the lifetime in **seconds**; the generated keys' prefix | Ignored — only `cache()` cached, for its own minutes | `false` / `'default'` / `3600` / `'fuzzy_search_'` |

**If you left these keys untouched** (or never published `config/fuzzy-search.php`), nothing
changes for the first four rows: the new defaults reproduce the same values the PHP scorer already
used internally, so ranking is identical. `similar_text.min_percentage` is the exception: its
default of 70 now applies, so `similar_text` searches return fewer rows (see Behaviour changes).
An untouched `cache.enabled` is `false`, so nothing is cached unless you call `cache()`.

That "identical" claim is about the PHP-side rescoring. The SQL `ORDER BY` used while fetching
the initial candidate set is a separate story: it previously used hard-coded weights
(`100`/`50`/`10` for exact/prefix/contains) and now uses the same `100`/`80`/`60` values as
`scoring.*`. On a search that matches more rows than `max_candidates`, that `ORDER BY` decides
which rows make it into the candidate window in the first place — so on tables where matches
exceed `max_candidates`, the candidate window itself (not just its final order) can differ from
v2.0, even with an untouched config.

**If you published the config and edited `scoring.*`** — because the block looked like it did
something — those values now apply for the first time and **will change result ordering**.
Before v2.1.0, the `scoring` block defaulted to `exact_match: 100, prefix_match: 50, contains: 25,
fuzzy_match: 10`, which never matched what the code actually used (`100/80/60/50`). If your
published config still has the old defaults, or your own edited values, review them.

Recommended action: either delete the `scoring` block from your published config (falls back to
the new package defaults), or update it to match the values you actually want, now that it's
live.

**If you published the config, it contains `'normalize' => true`.** That was the v2.0 shipped
value from when the key was inert — harmless at the time, because nothing read it. As of v2.1.0
`unicode.normalize` is live, so a published config will start NFC-normalising every search term
(requires `ext-intl`; it's a silent no-op without the extension). Set it to `false` to keep v2.0
behaviour (also the new package default for a fresh install), or leave it as `true` if you want
normalization.

## Removed config keys

- `performance.chunk_size` — never read; chunk sizing for rebuilds uses `indexing.chunk_size`.
- `performance.debounce_ms` — never read; debouncing belongs on the client (see Deprecations
  below).
- `indexing.table` — the v1 `search_index` table name, unread by v2 outside the deprecated
  `performReindex()` path (which keeps its own default).

Removing these keys from your published config is safe — they had no effect.

## Deprecations

- **`SearchBuilder::debounce()`** — a server-side "debounce" cannot debounce anything: the
  builder only exists for the duration of one request. Calling it now raises
  `E_USER_DEPRECATED` and remains a no-op, as it always was. Debounce on the client instead
  (`wire:model.live.debounce.300ms` in Livewire, or a JS timer around your search input). Will
  be removed in v3.0.0.
- **`SearchBuilder::locale()`** — never selected a stop-word list, a stemmer or a collation: the
  value was only ever folded into the cache key. Calling it now raises `E_USER_DEPRECATED` and
  remains the no-op it always was. Pass the locale where it is actually read —
  `->ignoreStopWords('de')` for a query-time stop-word list, `$searchable['locale']` for a
  model's index pipeline. Will be removed in v3.0.0.
- **`SearchBuilder::minMatchLength()`** — likewise never read into a pattern or a predicate. Use
  the `min_search_length` config key (whole term) or `typo_tolerance.min_word_length` (per word).
  Raises `E_USER_DEPRECATED`, still a no-op, removed in v3.0.0.
- **`SearchBuilder::partialMatch()`** is *not* deprecated but is documented as a no-op: every
  pattern-based algorithm already searches `%term%`, so substring matching needs no call. The
  `ecommerce` and `exact` presets keep their `partial_match` key; it simply never added anything.

## `paginate()` page sizes

`SearchBuilder::paginate()` now clamps `perPage` to between 1 and `max_candidates` (default
1000) on every search path. Three call sites change:

- `paginate($n)` with `useInvertedIndex()` and `$n` between 101 and `max_candidates` returns `$n`
  rows per page instead of the 100 the BM25 path silently clamped to.
- `paginate($n)` with `$n` greater than `max_candidates` returns `max_candidates` rows per page
  on the LIKE and extended paths, which used to accept any page size while ranking at most
  `max_candidates` rows. Raise `max_candidates` if you really page in bigger slices.
- `paginate(0)` or a negative `$n` returns a one-row page. It used to reach the paginator
  unmodified — `paginate(0)` threw `DivisionByZeroError`.

`SearchBuilder::simplePaginate()` is clamped the same way. It used to accept any page size, and
on the index path `simplePaginate($request->per_page)` hydrated that many models. `take()`/`limit()`
stay your explicit limit and are not clamped: on the index path `take(n)` hydrates `n` models, while
on the LIKE and extended paths the `max_candidates` candidate window still bounds them.
`FederatedSearch::paginate()` and `simplePaginate()` are clamped the same way;
`FederatedSearch::paginate(0)` threw `DivisionByZeroError` too.

## Database fixes you get for free

No code changes required — these are bug fixes in the package itself:

- **PostgreSQL:** BM25 indexing failed on every write with an "ambiguous doc_count" error; the
  inverted index now works correctly.
- **SQL Server:** BM25 indexing threw "This database engine does not support inserting while
  ignoring errors"; meta rows are now created with a portable upsert. `didYouMean()` also used
  `LENGTH()`, which SQL Server doesn't have.
- **MySQL 8 / PostgreSQL:** `getFacets()` threw (1055 `only_full_group_by` / 42803) on every
  relevance-ordered search, because the relevance `ORDER BY` reached the grouped aggregate. It
  now works on all supported databases, and facets come back highest count first, then by value
  — previously whatever order the database returned.
- **MariaDB:** connections reporting driver name `"mariadb"` (Laravel 11+) now use native
  `SOUNDEX()`, the Levenshtein UDF path, quoted identifiers and the MySQL flush branch —
  previously every MySQL-only branch silently fell back to generic SQL on MariaDB.

## Relationship search

- Nothing to change for existing calls — table-qualified column names (`table.column`) are unchanged.
- `_highlighted`/`_matches` gain dotted keys only when you search relation columns.
- `AstCompiler::compile()` (internal, `@internal`) gained an optional fourth argument.
- `AstCompiler::__construct()` (internal) gained optional second and third arguments (`int $typoDistance`, `array $fuzzyOptions`).
- **`searchIn()` relation paths need a `Relation` return type or a declared `$searchable` column.** A dotted segment is followed only when it is a public, non-static method with no required parameters, not defined by Laravel or this package, that declares a `Relation` return type — or when the full path is listed in the model's `$searchable['columns']`. Anything else throws `InvalidArgumentException` naming the fix, before any SQL, without calling the method. Add return types to relation methods you search through (`public function author(): BelongsTo`), or list the path in `$searchable['columns']`. Relations defined by Laravel's own traits (for example `Notifiable::notifications()`) are not followed; wrap one in a method of your own with a return type.
- A dotted name that starts with a table of the query (the FROM table, a joined table, or an alias) is that table's column. If a relation you search through has the same name as a table you join, alias the join.

## Extended syntax: ~ and field: are operators now

`extended()` / `searchBoolean()` gained two operators:

- **`~word`** — a typo-tolerant term, run through the same driver as the rest of the package. It
  follows `typoTolerance()` (default 2; `0`, or `config('fuzzy-search.typo_tolerance.enabled') ===
  false`, makes it a plain substring match). `~` can't combine with `'`, `=`, `^`, a quoted phrase or a trailing
  `$` — `~word` stands on its own (a field scope in front is fine: `name:~jonh`) — and `~` alone
  throws.
- **`field:term`** — scopes one term to one searchable column: a direct column, a table-qualified
  column matched by its bare name (`users.name` answers to `name:`), or a relation column declared
  in `searchIn()` / `$searchable['columns']` (`author.name:smith`). Any operator can follow the
  colon (`email:^admin`, `name:~jonh`, `!name:bob`, `name:"john doe"`). `field:` with nothing after
  it (`name:""` included), or a field that isn't searchable, now throws `QuerySyntaxException` (the
  second lists the searchable fields).
- **An empty quoted phrase `""` is skipped** like an empty word. In v2.0 it compiled to
  `LIKE '%%'` and matched every row; a query with nothing else now throws `QuerySyntaxException`.

**If an existing v2.0 query started a token with `~` or `identifier:`, it now parses differently.**
Both operators are recognised only at the start of a token (after an optional `!`), so `12:30` and
`jo~hn` are unaffected — but a v2.0 query such as `extended('~5 rating')` or `extended('ratio:1')`
now tries to parse `~5` as a typo term or `ratio:` as a field scope instead of matching the token
literally. Quote the token to keep the old, literal behaviour: `"~5" rating` / `"ratio:1"`.

The casualties you are most likely to have in production are URLs and mail addresses at the start
of a token and `Re:` / `Fwd:` subject prefixes: `extended('http://example.com')` and
`extended('mailto:bob@example.com')` now throw `Unknown search field "http"` / `"mailto"`, and
`extended('Re: meeting')` throws because nothing follows the colon. Quote them to restore the v2.0
substring match: `"http://example.com"`, `"mailto:bob@example.com"`, `"Re:" meeting`.

**A `!` is now NOT only as the first character of a token.** In v2.0 any `!` started a NOT term,
so `extended('yahoo!mail')` meant `yahoo !mail` (excluding the row it named), `=John!!!` searched
for `John`, and a `!` right after another operator dropped that operator: `^!a`, `'!a`, `=!a` and
`!!a` all meant NOT `a`, and `~!a` threw. Anywhere but a token's first character `!` is now part of
the term: `^!a` is a prefix search for `!a`, `~!a` a typo-tolerant term, `!!a` excludes `!a`. For
the old meaning, start the token with the `!`: `yahoo !mail`, `!a`. `name:!john` still throws; its
message now says to write `!name:john`.

`useInvertedIndex()` combined with `extended()` still runs the query on the LIKE path — that was
already true in v2.0, it's just visible now: `getDebugInfo()` reports `'algorithm' => 'extended'`
and `'index_ignored' => true` whenever both are set. `count()` and `paginate()` used to disagree
with `get()` here — they took the BM25 index path on the plain search term while `get()` correctly
ran the extended query. All three (plus `simplePaginate()`, which was always correct — it runs
through `get()`) now agree.

## Inverted index (BM25)

- **New migration to run.** `fuzzy_index_terms` gained a `term_length` column — run `php artisan migrate`, then restart your queue workers right away (`php artisan queue:restart`, or `php artisan horizon:terminate` under Horizon). Existing rows are backfilled automatically; no index rebuild is required. A worker still running 2.0 code after the migration adds new words with a `term_length` of 0, and typo expansion and `didYouMean()` never reach those words (it also writes postings with no column name, which score at weight 1). If workers indexed during the deploy window, rebuild the models they indexed (`fuzzy-search:rebuild`), or fill the column again: `UPDATE fuzzy_index_terms SET term_length = CHAR_LENGTH(term) WHERE term_length = 0` on MySQL/MariaDB (`LEN(term)` on SQL Server, `LENGTH(term)` on PostgreSQL and SQLite).
- **BM25 searches are typo-tolerant by default.** `useInvertedIndex()` now expands each query term against the dictionary within `typoTolerance()` edits (2 by default). Call `->typoTolerance(0)` to restore exact-term-only matching. `_score` normalisation itself is unchanged, but rankings can now include near-miss rows that a pre-2.1.0 search would not have returned.
- **The dictionary is read per model.** `didYouMean()` offers only terms posted under the
  searched model (it used to offer every indexed model's terms) and returns `[]` for a builder
  with no Eloquent model unless you pass `useInvertedIndex(Model::class)`. Typo expansion and
  `asYouType()` prefix expansion draw their candidates from the model's own terms too, so rankings
  on the typo-tolerant and as-you-type index paths can change.
- **A plain query builder keeps its `where()`s on the index path.** `new SearchBuilder(DB::table('notes')->where(...), app(FuzzySearch::class))`
  with `useInvertedIndex(Note::class)` used to search every row the model can see. It now runs inside
  the model's query, so the builder's wheres and joins apply alongside the model's global scopes.
  The builder must select from the model's table, unaliased (`DB::table('notes as n')` is a SQL
  error), and a narrowed `select()` must include the primary key: the ranked rows are matched back
  by it, so without it the search returns no rows while `count()` and `paginate()->total()` still
  count them.
- **A `join()` that narrows the rows counts as a constraint, like a `where()`.** On the index path,
  `count()` and `paginate()->total()` now count only the models the join lets through (each once,
  however many rows it joins), and `suggest()` / `didYouMean()` treat the query as constrained
  (see below).
- **`didYouMean()` ranks the closest term first,** then the most common, and its reach scales with
  the term's length (1 edit for 2–3 characters, 2 for 4–5, 3 from 6) instead of a fixed 3: a short
  term gets fewer, closer alternatives.
- **`didYouMean()` now throws on real database errors** instead of swallowing them — it still returns `[]` only when the `fuzzy_index_terms` table itself is missing (e.g. migrations not yet run).
- **Another new migration to run.** `2026_09_20_000001_widen_model_id_on_fuzzy_index_tables` widens `model_id` to 191 characters on `fuzzy_index_postings` and `fuzzy_index_documents`, so string keys longer than a UUID can be indexed. On a large index it rewrites two indexes (SQL Server drops and recreates the documents primary key), so run it in a maintenance window with the indexing workers paused. Rolling it back deletes index rows whose key is longer than 36 characters.
- **Rankings change for models that share words with another indexed model.** BM25's idf now counts documents within the searched model; before, a word common in another model pulled this model's scores down, below zero in bad cases. No rebuild is needed, and `_score` is never negative now. Equal scores now come back by model key, ascending.
- **Index terms are capped at 191 characters.** If your text has tokens longer than that (long URLs, hashes, base64), run `fuzzy-search:rebuild "App\Models\YourModel" --fresh`: the dictionary holds them whole (or not at all above 255), while queries now look up the first 191 characters. A token longer than `query.max_term_length` (128 by default) is reachable only through a prefix search (`asYouType()`), or after raising `query.max_term_length` above its length: a plain search cuts the query to that length first, and looks up a term the index does not hold.
- **Indexing waits for the transaction to commit.** A save or delete inside `DB::transaction()` is indexed (or its `IndexModelJob` queued) when the transaction commits, and not at all if it rolls back. A test that saves inside a transaction and inspects the index before committing now sees nothing. Laravel runs these callbacks immediately under `RefreshDatabase`'s wrapping transaction on Laravel 11+ (and on Laravel 10 releases that ship test-aware after-commit callbacks); on older 10.x releases, index inside the test with `IndexModelJob::dispatchSync()` instead. Writes that fire no model events (`Model::query()->update()`, `insert()`, a query-builder `delete()`, `saveQuietly()`, `Model::withoutEvents()`, raw SQL) are still not indexed. Re-index rows written this way with `fuzzy-search:rebuild`. A plain rebuild only reads the rows the model's query returns, so it does not remove a row such a write deleted, trashed or moved out of a global scope. For those, run `fuzzy-search:rebuild --fresh`, or `IndexModelJob::dispatch(Model::class, $id)` per row: the job removes a row that is gone.
- **Index writes for the same row wait for each other.** With `indexing.async` off, a save can briefly wait on the database while another process is indexing the same row: a row lock, released at once if that process dies. In practice the wait lasts as long as the other write's short transaction. MySQL/MariaDB give up after `innodb_lock_wait_timeout` (50 s by default); PostgreSQL (`lock_timeout`) and SQL Server (`LOCK_TIMEOUT`) wait indefinitely unless you set a timeout. Nothing depends on the cache store. Removing a model from the index (a delete through the observer, `IndexModelJob` for a missing row, Scout `delete()`) now also claims its document row, so it waits for a write to that row still in flight. For a model that was never indexed, this costs two extra statements: a placeholder insert and its delete, in the same transaction. Writes for different rows do not wait on this claim, except on SQL Server, where parallel batches filling a nearly empty index can queue on each other's first writes to it. On MySQL/MariaDB a batch (a rebuild chunk, or a Scout collection) takes its rows' locks with one point read per id, in id order, sent as a single statement, so parallel batches such as several `rebuild --async` workers lock only their own rows. Writes whose rows share words already in the dictionary take those words' locks in one order, so they queue briefly on each other instead of deadlocking. Under heavy contention a write that still hits a deadlock (or, on MySQL/MariaDB, a lock-wait timeout) is tried up to three times; on MySQL/MariaDB, parallel batches that add the same new words can meet one now and then. A write that loses all three is retried by the queue (`indexing.async`, with `indexing.job.tries` and `indexing.job.backoff`) or reported to your exception handler (sync).
- **An index error on the sync path is reported, not thrown.** With `indexing.async` off, the index is written after the save's transaction commits, and an error there (a deadlock, a lock-wait timeout, a database hiccup) goes to your exception handler (`report()`) instead of being thrown, so the committed write and your own after-commit callbacks go on. If you relied on `save()` failing when indexing failed, watch your error reporter instead, and repair with `fuzzy-search:rebuild`. With `indexing.async` on, a failure to queue the job is reported the same way.
- **The indexer reads the committed row, not the instance you pass.** The package's observer, `IndexModelJob` and `fuzzy-search:rebuild` read it through the model's query, with its global scopes, `searchIndexQuery()` and SoftDeletes: a row that query hides, or a trashed row, leaves the index. The Scout engine's `update()` (so `$model->searchable()`) reads it as Scout's own jobs do: without global scopes, and keeping a trashed row while `scout.soft_delete` is on, so `onlyTrashed()` and `withTrashed()` find it. It still applies the model's `searchIndexQuery()`, filters included. A global scope that depends on the request (a tenant or an auth scope) sees no request in a queue worker, so it can hide rows there: remove it for indexing with `$query->withoutGlobalScope(...)` in `searchIndexQuery()`. Unsaved changes on an instance are not indexed. A rebuild reads each chunk twice: its own load plus one re-read under the claim.
- **Suggestions skip hidden columns.** A model with a `$hidden` (or `$visible`) searchable column no longer offers that column's words from `suggest()` or `didYouMean()`. Matching is unchanged: a search, including its typo and as-you-type expansions, still finds rows by that column. For such a model, rebuild once (`fuzzy-search:rebuild --fresh`) so postings written before 2.1 come back into suggestions.
- **SQL Server:** turn on `READ_COMMITTED_SNAPSHOT` for the database (`ALTER DATABASE … SET READ_COMMITTED_SNAPSHOT ON`), or set `scout.after_commit` to `true`, which defers Scout's own save and delete hooks until the commit. The indexer re-reads a row after it claims the row's index entry. Under SQL Server's default locking read committed, that read waits on a row another transaction has updated but not committed. That transaction may be one that indexes before it commits: Scout with `after_commit` off and no queue, or `searchable()` inside `DB::transaction()`. Its index write in turn waits on the claim, so the two deadlock (error 1205). Snapshot reads do not wait. A `searchable()` you call yourself inside `DB::transaction()` still indexes at once, whatever `after_commit` says: call it after the commit, or turn on `READ_COMMITTED_SNAPSHOT`. If you run with `XACT_ABORT ON`, an index write that meets a new word (or a model's first document or meta row) that another write inserted at the same moment fails and is retried by the queue (or reported, when sync) instead of updating that row in place.
- **Flush only while nothing is indexing.** Run `fuzzy-search:flush`, `fuzzy-search:clear` and `rebuild --fresh` (which flushes first) while nothing is indexing any model: stop the queue workers that run `IndexModelJob`/`RebuildIndexJob` (a queued job indexes whatever `indexing.enabled` says) and keep requests from saving searchable models. Turning `indexing.enabled` off stops only the package's observer, not queued jobs or the Scout engine. A flush gives back the flushed model's share of each word's `doc_count`, then deletes every word no model's index uses any more, whichever model it came from. A write for the flushed model that commits during the flush can leave a word that other models share with a `doc_count` that is too high. That count only orders `didYouMean()`, `suggest()` and the typo and prefix expansions, not BM25 scores. `fuzzy-search:clear --all` followed by a rebuild of each model resets it. A write for another model that reuses a word the flush is deleting is not safe either. On MySQL/MariaDB the write keeps the word (it inserts it again if the flush deleted it first), but the two can deadlock (the write retries, and the flush can be run again). On PostgreSQL and SQL Server the flush can delete the word after the write commits, together with the write's new posting, so that row is not found by that word until it is indexed again. If something did index during a flush, rebuild the models it indexed. `fuzzy-search:flush <model>` is now exactly `fuzzy-search:clear <model>`.
- **`fuzzy-search:rebuild` fills metaphone shadow columns** for existing rows, and `rebuild --async` stops before touching the index if the `job_batches` table is missing (`php artisan make:queue-batches-table`, Laravel 10: `queue:batches-table`).
- **Commands exit 1 on bad input** (a class that is not a usable model, `--iterations` below 1, a non-integer `--days`/`--limit`, an `add-shadow-column --type` other than `metaphone`). Scripts that relied on exit 0 there will now stop. `analytics:prune --days=abc` used to read 0 days and delete every row.
- **MySQL/MariaDB: a second new migration rewrites `fuzzy_index_terms`.** The `term` column moves to `utf8mb4_bin`, so `café`/`cafe` (and `résumé`/`resume`) are distinct dictionary terms as they always were on the other drivers — indexing a document containing both previously failed with "Undefined array key" (B25). The dictionary becomes accent- and case-sensitive on MySQL/MariaDB; the tokenizer lowercases every term, so searches are unaffected. Run `php artisan migrate`, then rebuild any existing index — `php artisan fuzzy-search:rebuild "App\Models\YourModel"` — because variants the old collation collapsed into one dictionary row stay collapsed until the index is rebuilt.

## Weighted BM25 (column weights on the index)

- **New migration to run.** `fuzzy_index_postings` gained a `column_name` column — run `php artisan migrate`. Existing rows are backfilled with `''` and score at weight 1. Until you rebuild, rows re-indexed after the migration (every save through the observer) carry weights while untouched rows stay at weight 1, so mixed results skew towards recently saved rows — rebuild promptly. `php artisan fuzzy-search:status` shows how many rows are still unweighted.
- **Plan it on a large index.** The migration swaps the postings unique key for a four-column one, and that is a whole-table index build — PostgreSQL holds a lock that blocks writers on `fuzzy_index_postings` for the duration, and the `--fresh` rebuild then re-inserts every posting against the new key. Run both in a maintenance window and pause the queue workers that index.
- **Rebuild each model to get weighted ranking.** `php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh` writes one posting per `(term, column)`, letting `searchIn()`/`$searchable['columns']` weights scale each column's term frequency before BM25 saturation (BM25F-lite). `php artisan fuzzy-search:status` lists any model still carrying un-rebuilt (`''`-column) postings.
- **Rankings on the index path change for every model with unequal column weights, including zero-config ones.** Auto-detected columns already carry weights (e.g. `name` 10, `email` 8), so a plain `Model::search(...)->useInvertedIndex()` call can return results in a different order once you rebuild, even though you configured nothing yourself.
- **Opt out and keep v2.0 ordering** by passing equal weights, e.g. `->searchIn(['name' => 1, 'email' => 1])` — `searchIn()` overrides the weights it names and leaves the model's other `$searchable['columns']` weights in place, so list every weighted column.
- **The Scout engine ranks with the same column weights as `useInvertedIndex()`.** It reads the same postings and weighs each column by the model's `$searchable['columns']` weights (`Searchable::getSearchableColumnWeights()`), so after you rebuild, Scout rankings change for every model with unequal column weights — zero-config models included, since auto-detected columns carry weights (`name` 10, `email` 8, …). A model that does not use the package's `Searchable` trait weighs every column 1, and postings written before the rebuild (`''` column) weigh 1.
- **`migrate:rollback` deletes per-column postings.** Rolling back the `column_name` migration removes every posting row that isn't `''`-column (see the migration's `down()`); run `fuzzy-search:rebuild "App\Models\YourModel" --fresh` again afterwards to restore a working index.

## Search analytics (new)

- **New migration to run.** `php artisan migrate` creates the table `analytics.table` names (`fuzzy_search_logs` by default). Set `analytics.table` before you migrate, because the migration and the recorder both read it. The table sits there with no effect until you set `analytics.enabled` to `true` in `config/fuzzy-search.php`; `migrate:rollback` drops it again.
- **`FuzzySearchExecuted` gained three parameters:** `resultCount` (int, `-1` = unknown), `path` (`like`|`bm25`|`extended`|`in_memory`) and `modelClass` (`?string`, `null` for query-builder and in-memory searches). All three are appended with defaults, so an existing five-argument listener or third-party dispatcher keeps working unchanged.
- **In-memory searches now fire `FuzzySearchExecuted` too.** `FuzzySearch::on($items)->search(...)->get()` previously fired nothing; it now dispatches the same event (`path: 'in_memory'`, `modelClass: null`). If you have a listener that counts or logs this event — including the persisted analytics listener below — it now also sees in-memory searches, so counts recorded after upgrading will be higher than before if your app uses `FuzzySearch::on()`. An in-memory search with an empty term or no `searchIn()` columns still fires nothing, same as v2.0.
- See [Persisted Search Analytics](analytics.md#persisted-search-analytics) for the opt-in `fuzzy_search_logs` recording, the `SearchAnalytics` query API and the two `fuzzy-search:analytics*` commands — none of this runs unless you enable `analytics.enabled`.

## `suggest()` on an indexed model now completes from the dictionary

- **Completions changed for indexed models.** In v2.0, `suggest()` always scanned the table and returned column values as stored. As of v2.1.0, when the model has a `fuzzy_index_meta` row (it has been BM25-indexed), `suggest()` instead completes the last word of the term from that model's dictionary — completions are **lower-case dictionary terms**, not the column value as written, and any earlier words in a multi-word term are kept as typed (`"Bob jo"` → `"Bob john"`).
- **Constrained queries keep the table scan.** When the base query carries a `where()`, a `join()`, a forwarded scope or a global scope other than `SoftDeletes`, the default `'auto'` mode uses the table scan even on an indexed model, because the dictionary is scoped to the model, not to the query. `->suggestFrom('index')` forces the dictionary and ignores those constraints. With `indexing.async` (the default) a trashed row's terms leave the dictionary only when the queued index job runs.
- **Restore v2.0 behaviour** by calling `->suggestFrom('table')`, which forces the table scan regardless of whether the model is indexed.
- Un-indexed models are unaffected — they always used, and still use, the table scan.

## Tokenizers, per-model pipelines, accent folding and stop-word files (new)

- **Nothing changes by default.** The global tokenizer stays `WhitespaceTokenizer`, the global stemmer stays `NullStemmer`, `indexing.accent_insensitive` stays `false`, and `stop_words` gained four locales (`it`, `pt`, `nl`, `ru`) that only apply if you opt into them. An untouched v2.0 config indexes and searches exactly as before — with two edges: (1) when `unicode.accent_insensitive` is on or you call `accentInsensitive()`, the LIKE path now folds characters the v2.0 map did not know (`Ž`→`Z`, `ő`→`o`, `ệ`→`e`, Cyrillic `й`→`и` and `ё`→`е`, Turkish `İ`→`I`) whenever `ext-intl` is loaded — on SQLite and binary collations a term typed with such a character no longer matches a row that stores it, exactly as `café` already behaved in v2.0; (2) a stop word written with capitals in `config('fuzzy-search.stop_words')` is now lower-cased before use, so it is removed where in v2.0 it silently never matched.
- **Each of the three opt-ins requires a rebuild once you turn it on:** `indexing.tokenizer` / `$searchable['tokenizer']` (`NgramTokenizer`, `ScriptAwareTokenizer`), the per-model pipeline overrides (`$searchable['stemmer']`, `['stemmer_language']`, `['locale']`), and `indexing.accent_insensitive`. Each changes what ends up in the dictionary for the model(s) it applies to, so old postings stay stale until you run `php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh`. See [Tokenizers](tokenization.md#tokenizers), [Per-Model Pipelines](tokenization.md#per-model-pipelines) and [Accent Folding on the Index](tokenization.md#accent-folding-on-the-index).
- **`IndexManager::processTerms()` gained a third parameter,** `?string $modelClass = null`, so query-time tokenizing/stemming/stop-word processing can run through that model's pipeline instead of the global default. The existing two-argument call keeps working unchanged; `SearchBuilder` and the Scout engine already pass the model automatically, so this only matters if you call `processTerms()` directly.
- **`ignoreStopWords('en')` (or any locale code) now prefers the configured list.** It reads `config('fuzzy-search.stop_words.{locale}')` first and only falls back to the builder's built-in list when that config key is absent. Out of the box this changes `en`: the shipped config's `en` list has 14 words, the builder's built-in `en` list has 36 — so `ignoreStopWords('en')` now drops fewer stop words than it did in v2.0. If your app relies on the old, larger built-in list, pass it explicitly as an array (`ignoreStopWords(['the', 'a', 'an', ...])`); an array argument is never affected by this change and always replaces the configured list, as before.
- **`Pipeline` (`Ashiqfardus\LaravelFuzzySearch\Indexing\Pipeline`) is `@internal`.** It's the shared tokenize → fold-accents → drop-stop-words → stem sequence behind `IndexManager::pipelineFor()` and `processTerms()`. Construct it only through `IndexManager`; its shape may change without a major version bump.

## Filament integration, JSON API resources and `lastExecution()` (new)

- **New required dependency: `illuminate/http`.** It's present in every Laravel application already, so there is nothing to install; the package's `composer.json` now lists it as a direct requirement instead of an implicit one, backing the new `FuzzySearchResource` / `FuzzySearchCollection` classes.
- **The JSON resources are new, not a behaviour change.** `_score`, `_raw_score`, `_highlighted`, `_matches` and `_model_type` were always plain attributes on the model or array a search returns — a bare `Model::search()->get()` call is unaffected. `FuzzySearchResource` and `FuzzySearchCollection::fromBuilder()` are simply the tidy way to shape that into an API response; adopting them is optional.
- **Two new namespaces:** `Ashiqfardus\LaravelFuzzySearch\Integrations\Filament` (the `HasFuzzyGlobalSearch` trait) and `Ashiqfardus\LaravelFuzzySearch\Http\Resources` (`FuzzySearchResource`, `FuzzySearchCollection`).
- **`SearchBuilder::lastExecution(): ?FuzzySearchExecuted`** returns the event built by the most recent `get()`/`paginate()` call on that builder instance — `null` before either runs, and `count()` never sets it.
- See [Filament Integration](integrations.md#filament-integration), [JSON API Resources](integrations.md#json-api-resources) and [Livewire Recipe](integrations.md#livewire-recipe).
