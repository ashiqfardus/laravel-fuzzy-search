# Upgrading from v1.x to v2.0.0

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
php artisan fuzzy-search:index "App\Models\User" --fresh
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
