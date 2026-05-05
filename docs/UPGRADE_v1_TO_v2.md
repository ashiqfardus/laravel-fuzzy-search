# Upgrading from v1.x to v2.0.0

> **v2 automatically registers migrations for new tables and schema changes.** When you run `php artisan migrate` after upgrading to v2, the following migrations are applied automatically:
>
> | Migration | What it does |
> | --- | --- |
> | `create_fuzzy_index_terms_table` | Creates `fuzzy_index_terms` |
> | `create_fuzzy_index_postings_table` | Creates `fuzzy_index_postings` |
> | `create_fuzzy_index_meta_table` | Creates `fuzzy_index_meta` |
> | `create_fuzzy_index_documents_table` | Creates `fuzzy_index_documents` |
> | `2026_05_03_000001_add_unique_index_to_fuzzy_index_postings` | *(alpha.4)* Adds a unique index on `(term_id, model_type, model_id)` to prevent duplicate postings under concurrent indexing |
> | `2026_05_03_000002_widen_term_column_to_255` | *(alpha.4)* Widens `fuzzy_index_terms.term` from `varchar(191)` to `varchar(255)` |
>
> The four index tables are harmless if unused. If you never plan to use BM25 search, simply ignore them.
>
> **Upgrading from alpha.3?** Before running the unique-index migration, clean up any duplicate postings created by concurrent indexing:
>
> ```sql
> -- MySQL / MariaDB
> DELETE p1 FROM fuzzy_index_postings p1
> INNER JOIN fuzzy_index_postings p2
>   ON  p1.term_id    = p2.term_id
>   AND p1.model_type = p2.model_type
>   AND p1.model_id   = p2.model_id
>   AND p1.id > p2.id;
>
> -- PostgreSQL
> DELETE FROM fuzzy_index_postings
> WHERE id NOT IN (
>     SELECT MIN(id)
>     FROM   fuzzy_index_postings
>     GROUP  BY term_id, model_type, model_id
> );
> ```
>
> Then run `php artisan migrate`.

## Phase 0 changes

### BREAKING: `using('fuzzy')`, `using('trigram')`, `using('simple')` now behave differently

In v1.x these three algorithm names silently fell through to the Levenshtein pattern fallback — they produced identical SQL to `using('levenshtein')`.

In v2.x they route to their correct drivers (`FuzzyDriver`, `TrigramDriver`, `SimpleDriver`), each producing distinct SQL.

**Impact:** Top-N result rankings may shift for queries using these algorithms.

**If you need the old behavior temporarily**, add to `config/fuzzy-search.php`:
```php
'legacy_dispatch' => true,
```
This silently falls back to `LevenshteinDriver` for unrecognised algorithm names (v1.x behavior). Remove once you have validated your results.

---

### BREAKING: `get()` rescores before applying `limit/offset`

In v1.x, `->take(10)->get()` applied SQL `LIMIT 10` then rescored in PHP. This meant the top-10 were the first 10 rows that passed the SQL filter, not the 10 most relevant.

In v2.x, `get()` fetches up to `max_candidates` (default 1000) rows, rescores all of them in PHP, then slices to the requested limit/offset. Results are more accurate.

**Impact:** Top-N results may change if PHP scoring disagreed with the SQL CASE-WHEN ordering.

To tune the candidate ceiling:
```php
// config/fuzzy-search.php
'max_candidates' => 500,  // lower for faster queries on large tables
```

> **Note:** `paginate()` and `simplePaginate()` are not affected — they continue to use DB-level pagination.

---

### NEW: `using('metaphone')` requires a shadow column

The `metaphone` algorithm now uses PHP's `metaphone()` function against a precomputed `{column}_metaphone` shadow column. Without the column it throws a `RuntimeException` with instructions.

**Migration steps:**
```bash
php artisan fuzzy-search:add-shadow-column "App\Models\User" name --type=metaphone
php artisan migrate
php artisan fuzzy-search:rebuild "App\Models\User" --fresh
```

---

### New config keys

| Key | Default | Purpose |
|---|---|---|
| `max_candidates` | `1000` | Max SQL rows fetched before PHP rescore |
| `legacy_dispatch` | `false` | Silence `InvalidAlgorithmException` for unknown algorithm names |

---

### No changes for: levenshtein, soundex, simple, like

These algorithm names behave identically to v1.x (same driver, same SQL).

---

## Phase 1 changes (inverted index + BM25)

Phase 1 adds an opt-in inverted index with BM25 ranking. All existing search behavior is unchanged if you do nothing — the new features activate only when you follow these steps.

### Step-by-step upgrade to use Phase 1

```bash
# 1. Run the new migrations (creates fuzzy_index_terms, fuzzy_index_postings, fuzzy_index_meta)
php artisan migrate

# 2. Build the index for your searchable models
php artisan fuzzy-search:rebuild "App\Models\User"
php artisan fuzzy-search:rebuild "App\Models\Post"
# ... repeat for each model

# 3. (Optional) Enable automatic incremental updates on model save/delete
#    Without this step the index goes stale after writes.
```

In `config/fuzzy-search.php`:

```php
'indexing' => [
    'enabled' => true,    // ← set this to true
    'async'   => true,    // dispatches IndexModelJob to queue on save/delete
    'queue'   => 'default',
],
```

### New artisan commands

```bash
php artisan fuzzy-search:status              # Show index statistics
php artisan fuzzy-search:rebuild {Model}     # Rebuild (synchronous)
php artisan fuzzy-search:rebuild {Model} --async   # Rebuild via queued batch jobs (for large tables)
php artisan fuzzy-search:flush {Model}       # Remove all index entries for a model
```

### Using BM25 search

```php
// Drop-in addition to any existing search call
$users = User::search('john')->useInvertedIndex()->get();

// DB::table() callers — pass model class explicitly
DB::table('users')->fuzzySearch(['name'], 'john')->useInvertedIndex('App\Models\User')->get();

// didYouMean() now reads from the term dictionary (no table scan)
$suggestions = User::search('jonh')->searchIn(['name'])->didYouMean(3);
```

### Scout driver

```bash
composer require laravel/scout
```

In `.env`:

```env
SCOUT_DRIVER=fuzzy-search
```

The Scout engine adapter is bundled — no separate package. See [Scout Driver docs](SCOUT_DRIVER.md).

### Performance notes

BM25 via `useInvertedIndex()` is faster than LIKE-pattern search on tables with **~500k+ rows**. On smaller tables the BM25 scoring overhead may be comparable to or slightly slower than LIKE. Always benchmark against your own dataset.

For large table rebuilds (>100k rows) use `--async`:

```bash
php artisan fuzzy-search:rebuild "App\Models\User" --async --queue=indexing
```

### New Phase 1 config keys

| Key | Default | Purpose |
| --- | --- | --- |
| `indexing.enabled` | `false` | Set `true` to enable observer-based auto-indexing on save/delete |
| `indexing.tokenizer` | `WhitespaceTokenizer` | Tokenizer class |
| `indexing.stemmer` | `NullStemmer` | Stemmer class (use `PorterStemmer` for English stemming) |
| `indexing.max_tokens_per_doc` | `5000` | Max unique tokens indexed per document (security cap) |
| `bm25.k1` | `1.5` | BM25 k1 — term-frequency saturation |
| `bm25.b` | `0.75` | BM25 b — length normalisation |

---

## Phase 2 changes

### BREAKING: `_score` is now normalized to `[0, 1]`

In Phase 1, `_score` was the raw BM25 float and could be any positive value. In Phase 2, `_score` is clamped and normalized to the range `[0, 1]` across the current result set.

**Impact:** Any code that compared `_score` against a fixed threshold (e.g. `if ($result->_score > 5)`) or sorted results assuming an unbounded float will silently behave differently. Relative ordering within a single result set is preserved, but absolute values have changed.

**Migration:** Replace threshold checks with relative comparisons, or read the preserved raw value:

```php
// Old — breaks silently
if ($result->_score > 5) { ... }

// New — use the normalized score on [0,1]
if ($result->_score > 0.5) { ... }

// Or access the original BM25 value
if ($result->_raw_score > 5) { ... }
```

The raw BM25 value is always available as `_raw_score` on every result object.

---

### NEW: Extended search syntax (`->extended()` / `->searchBoolean()`)

Phase 2 introduces a Fuse.js-style query language with prefix operators (`!`, `=`, `^`, `'`). Two new entry points activate it:

```php
// Extended syntax: prefix operators
// '  = include-match (soft contains)   =  = exact   ^  = prefix   $  = suffix   !  = NOT
$results = User::search("john !doe 'exact")->extended()->get();

// Boolean syntax: implicit AND (space), | for OR, ! for NOT, parentheses for grouping
$results = User::search("john doe | (jane !smith)")->searchBoolean()->get();
```

Both entry points share the same query parser. Two new config keys cap parser resource usage:

| Key | Default | Purpose |
| --- | --- | --- |
| `query.max_tokens` | `32` | Maximum number of tokens the parser will process in a single query string |
| `query.max_depth` | `16` | Maximum nesting depth for parenthesised sub-expressions |

Queries that exceed either limit throw a `QuerySyntaxException` at parse time — they will not silently truncate.

---

### NEW: `_matches` array

When `->highlight()` is chained, each result carries a `_matches` array — a list of match objects, one per matched column:

```php
$results = User::search("'john")->extended()->highlight()->get();

foreach ($results as $result) {
    // e.g. [
    //   ['column' => 'name',  'value' => 'John Doe',        'indices' => [[0, 4]]],
    //   ['column' => 'email', 'value' => 'john@example.com', 'indices' => [[0, 4]]],
    // ]
    dump($result->_matches);
}
```

Each entry has three keys: `column` (the column name), `value` (the raw column value), and `indices` (an array of `[start, end]` byte-offset pairs for each match).

> **Note:** `_matches` is populated only when `->highlight()` is also called. Calling `->extended()` alone does not populate `_matches`.

---

### NEW: `@fuzzyHighlight` Blade directive

Phase 2 adds a dedicated Blade directive that wraps matched tokens in `<mark>` tags. It is XSS-safe — all output is passed through `e()` before wrapping.

```blade
{{-- Pass the result object and the column name --}}
@fuzzyHighlight($result, 'name')

{{-- Optional third argument overrides the wrapping tag (default: "mark") --}}
@fuzzyHighlight($result, 'name', 'strong')
```

This replaces the previous pattern of echoing `$result->_highlighted['name']` directly, which was not XSS-safe unless the caller remembered to escape it.

---

### NEW: In-memory search (`FuzzySearch::on()`)

`FuzzySearch::on($collection)` accepts any `Collection` or array and returns an `InMemorySearch` instance that scores records entirely in PHP — no database queries. It uses exact, prefix, contains, and `similar_text()` scoring (not the BM25 inverted-index pipeline).

```php
use Ashiqfardus\LaravelFuzzySearch\Facades\FuzzySearch;

$results = FuzzySearch::on($items)
    ->searchIn(['name', 'bio'])
    ->search('john')
    ->get();
```

Two new config keys control in-memory behaviour:

| Key | Default | Purpose |
| --- | --- | --- |
| `in_memory.max_items` | `10000` | Hard limit on collection size; larger collections throw `\InvalidArgumentException` |
| `in_memory.min_similarity` | `60` | Minimum similarity score (0–100) for a result to be included |

---

### New Phase 2 config keys

If you published the config file under v1 or Phase 1, add the following keys to `config/fuzzy-search.php` to opt in to the new defaults and avoid falling back to hard-coded values:

```php
'query' => [
    'max_tokens' => 32,
    'max_depth'  => 16,
],

'in_memory' => [
    'max_items'      => 10000,
    'min_similarity' => 60,
],
```

If you did not publish the config, these defaults are already active — no action required.

## v2.0.0 hardening changes

These changes were introduced during the alpha.4 hardening phase. If you were on any pre-release alpha, apply the steps below before upgrading to v2.0.0.

### Database migrations (run automatically on `php artisan migrate`)

Two new migrations are automatically applied:

- **`add_unique_index_to_fuzzy_index_postings`** — adds `UNIQUE (term_id, model_type, model_id)` on the postings table to prevent duplicate rows under concurrent indexing. If you have an alpha.3 index with duplicates, run this SQL first:

```sql
-- MySQL / MariaDB
DELETE p1 FROM fuzzy_index_postings p1
INNER JOIN fuzzy_index_postings p2
  ON  p1.term_id    = p2.term_id
  AND p1.model_type = p2.model_type
  AND p1.model_id   = p2.model_id
  AND p1.id > p2.id;

-- PostgreSQL
DELETE FROM fuzzy_index_postings
WHERE id NOT IN (
    SELECT MIN(id)
    FROM   fuzzy_index_postings
    GROUP  BY term_id, model_type, model_id
);
```

Then run `php artisan migrate`.

- **`widen_term_column_to_255`** — widens `fuzzy_index_terms.term` from `varchar(191)` to `varchar(255)`. On MySQL the unique index is recreated as a `term(191)` prefix key to stay under the 767-byte key limit.

### BREAKING: `cursorPaginate()` now throws unconditionally

In alpha.3 `cursorPaginate()` silently dropped relevance scoring when called on a `SearchBuilder`. In alpha.4 it always throws `BadMethodCallException`.

**Migration:** use `simplePaginate()` or `get()` instead.

```php
// Before (alpha.3 — silently broken):
User::search('john')->cursorPaginate(20);

// After:
User::search('john')->simplePaginate(20);
// or:
User::search('john')->get();
```

### Cache key now covers all builder state

In alpha.3 the cache key omitted most builder-state flags (synonyms, locale, recency boost, typo tolerance, etc.), causing result poisoning when two different queries shared the same `(term, columns)` pair. Alpha.4 fixes this automatically — no code changes required.

If your application cached search results from alpha.3 in Redis or Memcached, flush the cache after upgrading:

```bash
php artisan cache:clear
```

### `useIndex()` deprecated — use `useInvertedIndex()`

`SearchBuilder::useIndex()` is still callable but emits a `@deprecated` notice. Replace with `useInvertedIndex()`:

```php
// Before:
User::search('john')->useIndex()->get();

// After:
User::search('john')->useInvertedIndex()->get();
```

### Codebase scanner

The new `fuzzy-search:upgrade-v1` command scans your `app/` directory for known v1-era API usage and prints a migration TODO table with file, line number, and recommended action:

```bash
php artisan fuzzy-search:upgrade-v1
# scan a specific directory:
php artisan fuzzy-search:upgrade-v1 app/Services
```

Exits with code 1 when v1 patterns are found, 0 when clean — safe to use as a CI gate during a migration.
