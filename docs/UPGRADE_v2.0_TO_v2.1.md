# Upgrading from v2.0 to v2.1

This guide covers behaviour changes that can affect an existing v2.0 install.

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

**If you left these keys untouched** (or never published `config/fuzzy-search.php`), nothing
changes: the new defaults reproduce the same values the PHP scorer already used internally, so
ranking is identical.

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
`FederatedSearch::paginate()` is not clamped; the rows it returns are already bounded by
`max_candidates` and `limitPerModel()`.

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

## Behaviour changes

These land alongside the config wiring above. None require code changes, but a few affect what
you get back from a search:

- **A backslash in a search term is literal on MySQL, MariaDB and PostgreSQL.** 2.0 passed it to LIKE, which read it as an escape, so `back\slash` matched `backslash` and not itself; 2.1 escapes it. SQLite and SQL Server always treated it as literal.
- **Rebuild your index if you search non-Latin or accented text.** The BM25 tokenizer now keeps
  combining marks (`\p{M}`) attached to their base character instead of stripping them. Indexes
  built from Bengali, Hindi, Thai, or decomposed-accent Latin text before this fix are stale —
  run `fuzzy-search:rebuild "App\Models\YourModel" --fresh` once.
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
- **Accessor-backed searchable fields reindex on every save by default.** If a searchable column
  is a computed accessor (e.g., a `brand_name` accessor backed by `brand_id`), `wasChanged()`
  can't see it, so previously the model never reindexed after an update. It now reindexes on
  every save unless you declare `$searchable['reindex_on' => ['brand_id']]` to trigger reindexing
  only when the real backing column(s) change.
- **New config keys:** `indexing.job` (`tries`, `backoff`, `timeout`) bounds retries of
  `IndexModelJob`/`RebuildIndexJob`; `bm25.candidate_chunk` (default 200) sets the chunk size used
  when checking BM25 rankings against a constrained query.
- **`indexing.table` was removed** — see "Removed config keys" above.
- **`FuzzySearch::$config` is now nullable (`?array`)** and is null when the class is container-built; subclasses that read `$this->config` must call `currentConfig()` instead.
- **TrigramDriver's LIKE fallback cap raised from 10 to 100.** It now caps its pattern list at
  `performance.max_patterns` (default 100) instead of a hard-coded 10, so long terms match more
  widely; lower the key or call `maxPatterns()` to restore the old cap.
- **`FederatedSearch` results are now deterministically ordered.** Ordering is score, then
  `orderByModel()` (or the `across()` order), then primary key — previously ties and
  `withRelevance(false)` results came back in database order.
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
  column to search, and its search matches nothing (see below).
  Declare `$searchable['columns']` to keep searching a hidden column. Detection reads the model
  class's default `$hidden` and `$visible`, once per process, so a column hidden at runtime with
  `makeHidden()`, `setHidden()` or a per-request `getHidden()` is still searched and indexed; put
  it in `$hidden` to keep it out.
- **Case-insensitive scoring and highlighting now cover every script.** They folded ASCII
  only, so a lower-case Cyrillic, Greek or accented term scored an upper-case value as a fuzzy
  near-miss and highlighted nothing. Such results now rank as exact/prefix/contains matches and
  are highlighted, so the order of non-ASCII results can change. ASCII-only text scores and
  highlights exactly as before; `_matches` indices are still byte offsets.
- **Scout: `scout:delete-index` now deletes.** It passed the index name where a model class was
  expected and removed nothing; it now clears every indexed model whose `indexableAs()` is that
  name. A deploy script that runs it before `scout:import` now really starts from empty. The
  engine's `raw()['total']` is also the match count now, not the size of the returned page.
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
  nothing to `FederatedSearch`, and `FuzzySearch::tableSearch()` matches nothing for it. A
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

## Relationship search

- Nothing to change for existing calls — table-qualified column names (`table.column`) are unchanged.
- `_highlighted`/`_matches` gain dotted keys only when you search relation columns.
- `AstCompiler::compile()` (internal, `@internal`) gained an optional fourth argument.
- `AstCompiler::__construct()` (internal) gained optional second and third arguments (`int $typoDistance`, `array $fuzzyOptions`).
- `SearchBuilder::calculateRelevanceScores()` (protected) gained an optional second argument (the term to score against); a subclass overriding it must accept it.

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

`useInvertedIndex()` combined with `extended()` still runs the query on the LIKE path — that was
already true in v2.0, it's just visible now: `getDebugInfo()` reports `'algorithm' => 'extended'`
and `'index_ignored' => true` whenever both are set. `count()` and `paginate()` used to disagree
with `get()` here — they took the BM25 index path on the plain search term while `get()` correctly
ran the extended query. All three (plus `simplePaginate()`, which was always correct — it runs
through `get()`) now agree.

## Inverted index (BM25)

- **New migration to run.** `fuzzy_index_terms` gained a `term_length` column — run `php artisan migrate`. Existing rows are backfilled automatically; no index rebuild is required.
- **BM25 searches are typo-tolerant by default.** `useInvertedIndex()` now expands each query term against the dictionary within `typoTolerance()` edits (2 by default). Call `->typoTolerance(0)` to restore exact-term-only matching. `_score` normalisation itself is unchanged, but rankings can now include near-miss rows that a pre-2.1.0 search would not have returned.
- **The dictionary is read per model.** `didYouMean()` offers only terms posted under the
  searched model (it used to offer every indexed model's terms) and returns `[]` for a builder
  with no Eloquent model unless you pass `useInvertedIndex(Model::class)`. Typo expansion and
  `asYouType()` prefix expansion draw their candidates from the model's own terms too, so rankings
  on the typo-tolerant and as-you-type index paths can change.
- **A plain query builder keeps its `where()`s on the index path.** `new SearchBuilder(DB::table('notes')->where(...))`
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
- **MySQL/MariaDB: a second new migration rewrites `fuzzy_index_terms`.** The `term` column moves to `utf8mb4_bin`, so `café`/`cafe` (and `résumé`/`resume`) are distinct dictionary terms as they always were on the other drivers — indexing a document containing both previously failed with "Undefined array key" (B25). The dictionary becomes accent- and case-sensitive on MySQL/MariaDB; the tokenizer lowercases every term, so searches are unaffected. Run `php artisan migrate`, then rebuild any existing index — `php artisan fuzzy-search:rebuild "App\Models\YourModel"` — because variants the old collation collapsed into one dictionary row stay collapsed until the index is rebuilt.

## Weighted BM25 (column weights on the index)

- **New migration to run.** `fuzzy_index_postings` gained a `column_name` column — run `php artisan migrate`. Existing rows are backfilled with `''` and score at weight 1. Until you rebuild, rows re-indexed after the migration (every save through the observer) carry weights while untouched rows stay at weight 1, so mixed results skew towards recently saved rows — rebuild promptly. `php artisan fuzzy-search:status` shows how many rows are still unweighted.
- **Plan it on a large index.** The migration swaps the postings unique key for a four-column one, and that is a whole-table index build — PostgreSQL holds a lock that blocks writers on `fuzzy_index_postings` for the duration, and the `--fresh` rebuild then re-inserts every posting against the new key. Run both in a maintenance window and pause the queue workers that index.
- **Rebuild each model to get weighted ranking.** `php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh` writes one posting per `(term, column)`, letting `searchIn()`/`$searchable['columns']` weights scale each column's term frequency before BM25 saturation (BM25F-lite). `php artisan fuzzy-search:status` lists any model still carrying un-rebuilt (`''`-column) postings.
- **Rankings on the index path change for every model with unequal column weights, including zero-config ones.** Auto-detected columns already carry weights (e.g. `name` 10, `email` 8), so a plain `Model::search(...)->useInvertedIndex()` call can return results in a different order once you rebuild, even though you configured nothing yourself.
- **Opt out and keep v2.0 ordering** by passing equal weights, e.g. `->searchIn(['name' => 1, 'email' => 1])` — `searchIn()` overrides the weights it names and leaves the model's other `$searchable['columns']` weights in place, so list every weighted column.
- **The Scout engine scores as before** — it reads the same postings but calls the scorer without column weights, so every column weighs 1 and rankings match v2.0.
- **`migrate:rollback` deletes per-column postings.** Rolling back the `column_name` migration removes every posting row that isn't `''`-column (see the migration's `down()`); run `fuzzy-search:rebuild "App\Models\YourModel" --fresh` again afterwards to restore a working index.

## Search analytics (new)

- **New migration to run.** `php artisan migrate` creates `fuzzy_search_logs`. The table sits there with no effect until you set `analytics.enabled` to `true` in `config/fuzzy-search.php`; `migrate:rollback` drops it again.
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
