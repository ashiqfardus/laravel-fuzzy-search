# Upgrading from v2.0 to v2.1

This guide covers behaviour changes that can affect an existing v2.0 install. It grows as
Phase 1 tasks land — each section below was added by the task that introduced the change.

## Config keys that now take effect

Earlier releases documented several config keys as "reserved for future use" — the values
were parsed into the config array but never read by the package. As of 2.1.0 they are read:

| Key | What it controls | v2.0 behaviour | v2.1.0 default |
|---|---|---|---|
| `scoring.exact_match` / `prefix_match` / `contains` / `fuzzy_match` | Base points per match tier, used by both the SQL `ORDER BY` and the PHP rescorer | Ignored — scoring constants were hard-coded | `100` / `80` / `60` / `50` |
| `highlighting.enabled` / `tag_open` / `tag_close` | Default highlight tags, and whether every search is highlighted without calling `->highlight()` | Ignored — tags only came from `->highlight()` arguments | `false` / `<em>` / `</em>` |
| `performance.max_patterns` | Default cap on generated LIKE patterns | Ignored — cap only applied via `->maxPatterns()` | `100` |
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

## Database fixes you get for free

No code changes required — these are bug fixes in the package itself:

- **PostgreSQL:** BM25 indexing failed on every write with an "ambiguous doc_count" error; the
  inverted index now works correctly.
- **SQL Server:** BM25 indexing threw "This database engine does not support inserting while
  ignoring errors"; meta rows are now created with a portable upsert. `didYouMean()` also used
  `LENGTH()`, which SQL Server doesn't have.
- **MariaDB:** connections reporting driver name `"mariadb"` (Laravel 11+) now use native
  `SOUNDEX()`, the Levenshtein UDF path, quoted identifiers and the MySQL flush branch —
  previously every MySQL-only branch silently fell back to generic SQL on MariaDB.

## Behaviour changes from the 2026-09-16 fix batch

A batch of fixes landed alongside the config wiring above. None require code changes, but a
few affect what you get back from a search:

- **Rebuild your index if you search non-Latin or accented text.** The BM25 tokenizer now keeps
  combining marks (`\p{M}`) attached to their base character instead of stripping them. Indexes
  built from Bengali, Hindi, Thai, or decomposed-accent Latin text before this fix are stale —
  run `fuzzy-search:rebuild "App\Models\YourModel" --fresh` once.
- **Paginated BM25 `_score` is now corpus-wide.** `_score` on a paginated BM25 page is normalised
  against the corpus-wide maximum (as `get()` already did), not the page's own maximum — so page
  2's top row is no longer always `1.0`. The LIKE and extended paths changed too: `paginate()`
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

## Relationship search

- Nothing to change for existing calls — table-qualified column names (`table.column`) are unchanged.
- `_highlighted`/`_matches` gain dotted keys only when you search relation columns.
- `AstCompiler::compile()` (internal, `@internal`) gained an optional fourth argument.

## Inverted index (BM25)

- **New migration to run.** `fuzzy_index_terms` gained a `term_length` column — run `php artisan migrate`. Existing rows are backfilled automatically; no index rebuild is required.
- **BM25 searches are typo-tolerant by default.** `useInvertedIndex()` now expands each query term against the dictionary within `typoTolerance()` edits (2 by default). Call `->typoTolerance(0)` to restore exact-term-only matching. `_score` normalisation itself is unchanged, but rankings can now include near-miss rows that a pre-2.1.0 search would not have returned.
- **`didYouMean()` now throws on real database errors** instead of swallowing them — it still returns `[]` only when the `fuzzy_index_terms` table itself is missing (e.g. migrations not yet run).
- **MySQL/MariaDB: a second new migration rewrites `fuzzy_index_terms`.** The `term` column moves to `utf8mb4_bin`, so `café`/`cafe` (and `résumé`/`resume`) are distinct dictionary terms as they always were on the other drivers — indexing a document containing both previously failed with "Undefined array key" (B25). The dictionary becomes accent- and case-sensitive on MySQL/MariaDB; the tokenizer lowercases every term, so searches are unaffected. Run `php artisan migrate`.
