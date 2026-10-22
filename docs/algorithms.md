# Search algorithms

The LIKE-path algorithms in depth: shadow columns, typo tolerance, multi-word search, in-memory mode, how they compare, and what each does on each database.

## The LIKE algorithms in depth

### Shadow Columns

Most algorithms compute their score on the fly during the SQL query. **Metaphone is the exception** — PHP's `metaphone()` function isn't available in SQL, so the package precomputes the phonetic code on every save and stores it in a sibling column.

For a `users` table with a `name` column, that means adding a `name_metaphone` column right next to it. Each row's `name_metaphone` holds the phonetic code (e.g. `Stephen` → `STFN`, `Steven` → `STFN`, `Stefan` → `STFN`). At search time, the query is a simple equality check against the precomputed code — fast, no per-row PHP calls. The code covers the whole value, so `smith` finds a row whose name is `Smith` but not `Jane Smith` (whose code is that of both words): to match one word of a longer value, use `soundex` (which matches the first or the last word) or the index (`useInvertedIndex()`, any word).

**Setup (one-time per column):**

```bash
# 1. Generate the migration that adds the shadow column + index
php artisan fuzzy-search:add-shadow-column "App\Models\User" name --type=metaphone

# 2. Apply it
php artisan migrate

# 3. Fill it for the rows you already have (saves fill it from then on)
php artisan fuzzy-search:rebuild "App\Models\User"
```

A rebuild writes only the rows whose shadow value is missing or out of date, one UPDATE per 500 rows, and it rebuilds the model's BM25 index too. `--type` accepts `metaphone` only. A code is cut to 191 characters, so a long value (a bio, a description) fits the column the command generates, and the `string()` column earlier releases generated (255, or 191 under `Schema::defaultStringLength(191)`).

After this, the `SearchableObserver` keeps `name_metaphone` in sync automatically on every `save()` and `update()`. A shadow is written only while its column still holds exactly the text the code encodes (accents and letter case included, also under MySQL's and MariaDB's accent-insensitive collations), so a save racing another save of the same row, or a rebuild, never leaves one text's code beside the other text. A declared column read through an accessor, a cast or a date has no stored text its code comes from, and its shadow is written unguarded; so is a column that is not character-typed, such as a JSON column (MySQL re-formats the JSON it stores). On Laravel 10 releases before 10.30, which cannot read column types, every column stays guarded and a JSON column is compared as its stored text: MySQL's JSON and PostgreSQL's `jsonb` re-format that text, so their shadow is written by `fuzzy-search:rebuild` rather than on save.

**What gets generated:**

```php
// database/migrations/{timestamp}_add_name_metaphone_to_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->string('name_metaphone', 191)->nullable()->after('name');
    $table->index('name_metaphone');
});
```

**Safety guards in the command:**

- The model must exist and be an Eloquent model.
- The model must live in your app namespace — vendor/framework classes are rejected.
- The column name is sanitized to `[a-zA-Z0-9_]` only — blocks SQL injection through the argument.

### Typo Tolerance

```php
// Auto typo tolerance based on word length
User::search('jonh')->get();  // Finds "John"

// Configure tolerance level
User::search('john')
    ->typoTolerance(1)  // Allow 1 typo
    ->get();

// Disable typo tolerance
User::search('john')
    ->typoTolerance(0)
    ->get();
```

### Multi-Word Token Search

```php
// Searches each word independently and combines results
User::search('john doe developer')
    ->tokenize()        // Split into tokens
    ->matchAll()        // All tokens must match (AND)
    ->get();

User::search('john doe developer')
    ->tokenize()
    ->matchAny()        // Any token can match (OR)
    ->get();
```

### In-Memory Mode

```php
use Ashiqfardus\LaravelFuzzySearch\Facades\FuzzySearch;

$matches = FuzzySearch::on($staticArray)->search('term')->searchIn(['name'])->get();
```

> **Supported methods:** `search`, `searchIn`, `take`, `skip`, `withRelevance`, `get`.
> Any other `SearchBuilder` method (e.g. `extended()`, `using()`, `preset()`, `paginate()`) will throw a `\BadMethodCallException` to prevent silent failures.

Without `searchIn()` there is no column to search, so a search matches nothing. An empty (or whitespace-only) term throws `EmptySearchTermException`, as every other search does, unless `allow_empty_search` is on; then it returns the items unsearched. Over Eloquent models, a column is read as an attribute, cast or accessor. A dotted `searchIn()` name follows only relations already loaded, so call `load('author')` before searching `author.name`. Case is folded in every script, the way `Model::search()` scores (`ÉCOLE` finds `école`, `МОСКВА` finds `москва`), a term is cut at `query.max_term_length` characters (default 128), and `similar_text()` compares at most the first 255 characters of a value and of the term.

A result stays a read model. With relevance on (the default), each matched item gets `_score` and `_raw_score`: attributes on an Eloquent model, keys on an array, properties on an object. So `save()` on a matched model fails with an unknown-column error: re-fetch it by key first, or `unset($model->_score, $model->_raw_score)` before saving. `withRelevance(false)` adds neither. An item that did not match is left exactly as it was. On an object the two are dynamic properties: a class with declared properties raises PHP's dynamic-property deprecation for them, and a `readonly` class cannot take them, so search such objects with `withRelevance(false)`, which writes nothing onto an item.

---

## Choosing an algorithm

### Algorithm Comparison

| Algorithm | Typo Tolerance | Best For |
|-----------|----------------|----------|
| **simple** | None | Exact matches, SKUs |
| **fuzzy** | High | General purpose |
| **soundex** | Phonetic | Name searches |
| **trigram** | Medium (a shared three-letter run) | Similarity matching |
| **levenshtein** | Configurable | Precise typo matching |
| **BM25 index** | Native (dictionary expansion) | Large tables, ranked results |

The LIKE algorithms scan the table, the index reads only the matched words' postings: on MariaDB at 1M rows (fb82c82), `simple` took 0.79 s, `fuzzy` 17 s and `levenshtein` 44 s, and an index search for a common word 0.12 s (see [Sizing the index](performance.md#sizing-the-index)).

**At scale:** an index search reads the postings of the words it matches, not the table, so its cost follows how common the searched words are, and the index has to fit in the database's memory to stay fast. For measured numbers, see [Sizing the index](performance.md#sizing-the-index); `php artisan fuzzy-search:benchmark` times the LIKE algorithms on your own tables, and `lastExecution()->latencyMs` after a `useInvertedIndex()` search's `get()` or `paginate()` times an index search in your app. Three reads grow with the data:

- **Common words.** Every posting of a matched word is read and ranked before `bm25.max_postings_per_term` cuts the ranking: the cap bounds PHP's memory, not that read. A word in a few percent of the rows is the expensive case. A word's postings are one range of the postings table, read in order, each with its document's length, so no other table is read, and the word's document count within the model comes from the per-model dictionary (`fuzzy_index_model_terms`), one keyed read, not a count over those postings: on MySQL with a 4 GB buffer pool, one such word (in 7% of 12M rows of about 16 indexed words each, 5dc853c) takes 0.59 s for its first page and three together 1.6 s; on PostgreSQL with 4 GB of `shared_buffers` at 24M rows (0a05120), 0.93 s and 2.7 s. A `where()` does not shorten that read: the word's postings are read for the whole model before the `where()` and the cap apply. `typoTolerance(0)` and Scout's exact terms skip only the typo expansion. For a selective filter over a common word, the LIKE path with an index on the filtered column is the fast path (20 ms on MariaDB at 1M rows, fb82c82, for a filter holding 0.1% of them).
- **Typo expansion** takes each query term's most common neighbours from the searched model's own words, by its own document count (`fuzzy_index_model_terms`): one index read per word length in the window around the term, each stopping at `bm25.fuzzy.candidate_pool` (500) of the model's words or at the last of them. Once the model has more rows than `bm25.max_postings_per_term`, a word in more than half of them is never an expansion, so a misspelling never reads a stop word's postings (`smith` into `with`, a word in 999,967 of 1M rows). Another model's words never enter it, however many share a length. So where another model's distinct codes fill a word length (product SKUs of six digits, say), a typo search of a users model no longer reads them. `asYouType()` and `suggest()` read the model's own words under the prefix the same way, through `(model_type_id, term)`: a 4-letter `asYouType()` search at 24M rows on PostgreSQL took 2.1 s (0a05120), where it took 40 s through the shared dictionary (10fd1cc); the ranking read is now most of it. `typoTolerance(0)` skips the expansion.
- **`orderBy()` past the cap.** A ranking capped at `bm25.max_postings_per_term` (when one word's read reaches that many rows, one per document holding it: a word in more than 50,000 of the model's rows) restricts the ordered read through the postings: a subquery that reads the rows the query accepts (a common word's first ordered page took 4.3 s on MySQL at 12M rows, 2.9 s on MariaDB at 12M, 0.61 s on SQL Server at 6M and 3.0 s on PostgreSQL at 24M; on PostgreSQL at 24M rows (0a05120) a whole ordered search took 21 ms for a rare word to 3.2 s for page 500 of a mid word). On MySQL and MariaDB a query with no constraint whose words are in at most a tenth of the rows joins the matched ids on the model's key instead, one key lookup per match. On SQL Server the same subquery serves any ranking past `bm25.candidate_chunk` matches (200 by default), and compares `model_id` with the key in the key's own type too, so the matches are looked up by key: on SQL Server 2022 RTM at 3M rows, the page statement for a word in 395 of them took 2.9 ms (1fd04b9), where the subquery read the whole table in 4,625 ms (e0b475d); the whole search 29 ms and 4.8 s. See [docs/bm25.md](bm25.md#usage).

### When to Use BM25 vs LIKE

**Use BM25 (`useInvertedIndex()`) when:**
- The table is large enough that a LIKE scan is slow (see [Sizing the index](performance.md#sizing-the-index))
- Result ranking/relevance quality matters
- You have queue workers running

**Use LIKE / fuzzy when:**
- Small tables, where a LIKE scan is already quick and the index only adds writes to every save

## Algorithm × Database Compatibility

This table shows what each algorithm does at the SQL level on each supported database. "Native" = the database's own function. "Pattern fallback" = PHP generates LIKE patterns. On SQLite, whose LIKE folds ASCII letters only, a LIKE term with a non-ASCII character (`лев`, `Ünal`) is compared through the package's own `fuzzy_lower()` function, registered on the connection, so it matches whatever the case; it costs one PHP call per row and column, so it is slower than an ASCII term there. SQLite's own `lower()` and `LIKE` are not replaced.

| Algorithm | MySQL 8 | MariaDB 11.4 (CI); 10.6+ expected | PostgreSQL 14 | SQLite | SQL Server |
|---|---|---|---|---|---|
| **simple** / **like** | `LIKE '%term%' ESCAPE '!'` | `LIKE '%term%' ESCAPE '!'` | `ILIKE '%term%'` | `LIKE '%term%' ESCAPE '!'` | `LIKE '%term%' ESCAPE '!'`; case-insensitivity follows the column's collation |
| **fuzzy** | LIKE pattern set (typo patterns, transpositions) | LIKE pattern set | ILIKE pattern set | LIKE pattern set | LIKE pattern set |
| **levenshtein** | Native `LEVENSHTEIN()` UDF if `use_native_functions=true`, else pattern set | Same as MySQL | ILIKE pattern set, whatever `use_native_functions` says (pg_trgm's `similarity()` is not an edit distance) | Pattern set | Pattern set |
| **trigram** | LIKE pattern set | LIKE pattern set | Native `similarity()` via pg_trgm if `use_native_functions=true`, else ILIKE pattern set | LIKE pattern set | LIKE pattern set |
| **soundex** | Native `SOUNDEX()` — always on, applied to first or last word | Native `SOUNDEX()` — always on | Native `SOUNDEX()` via `fuzzystrmatch` if `use_native_functions=true` (a term whose first letter is not ASCII, or follows a character that is not, takes the pattern fallback, and such a stored word is not compared; ASCII punctuation or digits before the first letter, as in `"Mila"`, `(Mila)` or `2Mila`, are skipped), else pattern fallback | Package-registered `fuzzy_soundex()` (PHP's `soundex()`) on the first or last word | Native `SOUNDEX()` on the first or last word |
| **metaphone** | Shadow column `{col}_metaphone` + exact `=` match. PHP's `metaphone()` encodes ASCII letters only, and drops H, W and Y before no vowel, so a term whose metaphone code is empty (`99`, `Иван`, `東京`, `H2O`, `Hy`) is matched as `LIKE '%term%'` on the column itself | Same as MySQL | Same (`ILIKE` for a term whose metaphone code is empty) | Same as MySQL | Same as MySQL |
| **similar_text** | `LIKE '%term%'` and `CHAR_LENGTH(col) <= ?` (the `min_percentage` bound); `similar_text()` scores in PHP after fetch | Same | `ILIKE '%term%'` and `CHAR_LENGTH(col) <= ?`; PHP scores | `LIKE` and `LENGTH(col) <= ?` | `LIKE` and `LEN(CAST(col AS NVARCHAR(MAX)) + N'x') - 1 <= ?` |

MariaDB behaves as MySQL 8 for every algorithm (native SOUNDEX/LEVENSHTEIN paths included).

### Notes

- **Literal `%` and `_`:** the package escapes them in a search term so they match themselves. PostgreSQL escapes with a backslash, its LIKE default, and escapes a backslash in the term too. SQLite and SQL Server have no default escape character, and MySQL and MariaDB lose theirs under the `NO_BACKSLASH_ESCAPES` SQL mode, so on all four the package escapes with `!` (a `!` in the term included, and on SQL Server a `[`, which is a wildcard there) and every LIKE a search writes carries `ESCAPE '!'`, which works in every SQL mode, pattern sets included (only the deprecated, unused `getRelevanceExpression()` / `getRelevanceBindings()` driver methods predate this).
- **`use_native_functions`** in `config/fuzzy-search.php` gates optional DB extensions. `SOUNDEX()` is built in on MySQL, MariaDB and SQL Server, and SQLite uses the package's own `fuzzy_soundex()` function, all always active — no flag needed; `soundex` matches by sound on those four and on PostgreSQL with `fuzzystrmatch`, and uses the pattern fallback on PostgreSQL without it. The flag is only relevant for: Levenshtein UDF (MySQL), pg_trgm/fuzzystrmatch (PostgreSQL), unaccent (PostgreSQL).
- **Levenshtein UDF (MySQL):** Not installed by default. See [this gist](https://gist.github.com/yohgaki/9315991) or your DB package manager. With it, `using('levenshtein')` on MySQL and MariaDB compares the whole column value with the term: a row matches only when the value as a whole is within `levenshtein.max_distance` edits of the term, so a word inside a longer value is not found (`john` no longer finds `John Doe`), where the pattern set finds the term, or a near miss of it, anywhere in the value.
- **pg_trgm (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS pg_trgm;`
- **fuzzystrmatch (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;`
- **unaccent (PostgreSQL, for an explicit `accentInsensitive()`):** `CREATE EXTENSION IF NOT EXISTS unaccent;` + `use_native_functions=true`. `unaccent(col) ILIKE unaccent(?)` is then ORed beside the chosen algorithm. It runs only when the search opts in explicitly (`->accentInsensitive()`, `$searchable['accent_insensitive']` or a preset); the global `unicode.accent_insensitive` default never uses it. Without the extension an explicit opt-in fails with `function unaccent(…) does not exist`.
- **MySQL accent insensitive:** Use `utf8mb4_unicode_ci` or `utf8mb4_0900_ai_ci` collation on the column.
- **Non-text columns (PostgreSQL, SQL Server):** a column you declare in `$searchable['columns']` or pass to `searchIn()` must be a text column there. An integer, decimal or date column is searched by its digits on MySQL, MariaDB and SQLite, but on PostgreSQL every search that names it throws (`operator does not exist: bigint ~~* unknown`), and on SQL Server every search for a term that is not a number does (`Conversion failed when converting the nvarchar value …`). To search such a column on those two, expose it as text (a generated or computed text column, or a view) and declare that column. Auto-detection never picks a non-text column.
- **`similar_text` under an accent-insensitive collation:** on MySQL/MariaDB with `utf8mb4_unicode_ci` or `utf8mb4_0900_ai_ci`, `similar_text`'s LIKE also matches accent variants (`Jöhn` for `john`). The `min_percentage` length bound still applies to them: an accent variant has the same length, so the bound approximates PHP's percentage there.
- **`similar_text.min_percentage`:** a match contains the term, so its `similar_text()` percentage is `200·t / (t + v)` for a `t`-character term and a `v`-character value, counted in characters. PHP's `similar_text()` counts bytes, so for single-byte text the bound is exactly its percentage, and for multibyte text a close approximation. The bound keeps values of at most `t·(200 − p) / p` characters: at the default 70, about 1.86 times the term's length. Under `tokenize()` the whole search term's length sets the bound for every token (whole-value similarity), so `john doe` still finds `John Doe`. On SQL Server a character outside the BMP counts as 2. `0` turns the bound off and restores 2.0's results.
- **`distinct()` on the LIKE and extended paths:** a `SELECT DISTINCT` can be ordered only by what it selects, so under `distinct()` the search adds no relevance `ORDER BY`. With no `orderBy()`, the search reads them by the key when the select includes it (`*`, the model's `table.*` or the key), else by every column it selects (an aliased one by its alias, or by its column when another selected column has the alias's name, and a query builder's `*` over a table with no `id` by every column of the table); on PostgreSQL, `distinct('col')` (`DISTINCT ON`) is read by its own columns first, as PostgreSQL requires. Under a join that repeats a model, the key is followed by the other selected columns, and a select of the joined table's `table.*` is read by that table's columns. The rows PHP rescoring ranks are the first `max_candidates` distinct matches in that order, not the best ones, and `paginate()`'s pages past `max_candidates` are read in the same order, so the pages partition the matches. A select of raw expressions only has no column to order by: on SQL Server, give that search an `orderBy()` on a selected column, or its pages past `max_candidates` fail. `stableRanking()` orders by the key only when the select includes it.
- **Metaphone shadow column:** Run `php artisan fuzzy-search:add-shadow-column {Model} {column} --type=metaphone`, then `php artisan migrate`, then `php artisan fuzzy-search:rebuild {Model}` to fill it for existing rows (see [Shadow Columns](#shadow-columns)).

### PHP-Side Scoring

Regardless of algorithm, after SQL candidates are fetched:

1. `similar_text()` and `levenshtein()` run in PHP on each candidate, on at most the first 255 characters of the value and of the term (both are O(n·m)); a value within that length scores exactly as it always has. An `extended()` query scores each leaf term on its own and adds the scores up; the similarity/Levenshtein floor is spent only while the leaf terms' total length stays within 255 characters (the first leaf always gets it), and the remaining leaves score by their exact, prefix or contains tier alone, so a many-term query costs about one capped comparison per value. While accent folding is on, an accented term and its accent-free form count once: a row scores the better of the two, never their sum, and in an `extended()` query a leaf and its folded form are one leaf for scoring. With folding off, `müller | muller` are two separate terms and add up.
2. Results are re-sorted by the combined PHP score (higher = better), unless `orderBy()` set an explicit order.
3. `limit/offset` is applied on the PHP-sorted collection (not in SQL).

Top-N results are always the most relevant N from the candidate set (not just the first N SQL rows). Candidate set size is controlled by `max_candidates` (default: 1000).

> **Pagination note:** `paginate()` ranks globally across up to `max_candidates` rows before slicing. Rows past that window are served in database order and scored within their page. That includes a page that runs across the boundary, so every match is on exactly one page. Add `stableRanking()` so the database order has no ties that two pages' queries could break differently. Under a join that repeats a model (one row per variant), the key ties that model's rows, so on the LIKE and extended paths the pages follow the key and then the selected columns (every column of a selected `table.*`; no raw expression, and no PostgreSQL `json`, `xml` or geometric (`point`, `line`, `lseg`, `box`, `path`, `polygon`, `circle`) column or array of one, or SQL Server `text`, `ntext`, `image`, `xml` or spatial column, which no `ORDER BY` takes; on Laravel 10 before 10.30, which cannot read column types, PostgreSQL and SQL Server keep the key alone): add an `orderBy()` on the joined table's key (`variants.id`) for a specific order, or when the select has no such column. `simplePaginate()` runs through `get()`, so on the LIKE and extended paths it serves only that window: its last page ends at `max_candidates` rows.

---
