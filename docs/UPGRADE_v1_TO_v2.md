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
