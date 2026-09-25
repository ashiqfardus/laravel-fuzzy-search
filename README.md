# Laravel Fuzzy Search

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Total Downloads](https://img.shields.io/packagist/dt/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![License](https://img.shields.io/packagist/l/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![PHP Version](https://img.shields.io/packagist/php-v/ashiqfardus/laravel-fuzzy-search.svg?style=flat-square)](https://packagist.org/packages/ashiqfardus/laravel-fuzzy-search)
[![Laravel Version](https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-FF2D20?logo=laravel)](https://laravel.com)

A powerful, **zero-config** fuzzy search package for Laravel with fluent API. Works with all major databases without external services.

**Demo:** [laravel-fuzzy-search-demo](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) - See the package in action!

**Documentation:** [Installation](#installation) • [Quick Start](#quick-start) • [Algorithms](#search-algorithms) • [BM25 Index](#bm25-inverted-index) • [Extended Syntax](#extended-search-syntax) • [Scout Driver](#scout-driver) • [Performance](#performance--scaling) • [Compatibility](#algorithm--database-compatibility) • [Upgrade v1→v2](docs/UPGRADE_v1_TO_v2.md) • [Upgrade v2.0→v2.1](docs/UPGRADE_v2.0_TO_v2.1.md)

## Features

| Category | Features |
|----------|----------|
| **Core** | Zero-config search • Fluent API • Eloquent & Query Builder support • Relationship search (dot notation) |
| **Algorithms** | Multiple fuzzy algorithms • Typo tolerance • Multi-word token search |
| **Scoring** | Field weighting • Relevance scoring • Prefix boosting • Partial match • Recency boost |
| **Text Processing** | Stop-word filtering • Synonym support • Per-locale stop-word lists |
| **Internationalization** | Unicode support • Accent insensitivity • Multi-language |
| **Results** | Highlighted results • Custom scoring hooks • Debug/explain-score mode |
| **Performance** | BM25 inverted index • Async indexing (queue) • Redis/cache support |
| **Pagination** | Stable ranking • Offset pagination • Simple (look-ahead) pagination |
| **Reliability** | Fallback search strategy • DB-agnostic • Rate-limit friendly • SQL-injection safe |
| **Configuration** | Config file support • Per-model customization |
| **Developer Tools** | CLI indexing • Benchmark tools • Built-in test suite • Performance utilities |
| **Smart Search** | Autocomplete suggestions (dictionary-backed on indexed models) • "Did you mean" spell correction • Multi-model federation • Persisted search analytics |
| **Integrations** | Filament global search & table search • Scout driver • JSON API resources |

## Table of Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Search Algorithms](#search-algorithms)
- [Field Weighting & Scoring](#field-weighting--scoring)
- [Text Processing](#text-processing)
- [Result Presentation](#result-presentation)
- [Performance & Indexing](#performance--indexing)
- [BM25 Inverted Index](#bm25-inverted-index)
- [Extended Search Syntax](#extended-search-syntax)
- [Scout Driver](#scout-driver)
- [Filament Integration](#filament-integration)
- [JSON API Resources](#json-api-resources)
- [Livewire Recipe](#livewire-recipe)
- [Pagination](#pagination)
- [Reliability & Safety](#reliability--safety)
- [Events](#events)
- [Persisted Search Analytics](#persisted-search-analytics)
- [Configuration](#configuration)
- [CLI Tools](#cli-tools)
- [Performance & Scaling](#performance--scaling)
- [Algorithm × Database Compatibility](#algorithm--database-compatibility)
- [Testing](#testing)
- [Requirements](#requirements)

**Deep dives:** [BM25 index](docs/bm25.md) • [Extended syntax](docs/extended-syntax.md) • [Relationships](docs/relationships.md) • [Text processing & tokenization](docs/tokenization.md) • [Integrations](docs/integrations.md) • [Analytics](docs/analytics.md)

---

## Installation

```bash
composer require ashiqfardus/laravel-fuzzy-search
```

**That's it!** Zero configuration required. Start searching immediately.

Optionally publish the config file:

```bash
php artisan vendor:publish --tag=fuzzy-search-config
```

If you plan to use the **BM25 inverted index** (recommended for 10k+ rows), also run:

```bash
php artisan migrate
```

> **Upgrading from v1.x?** There are breaking changes — result rankings and `_score` values may shift.
> Run the scanner to find affected code, then follow the full guide.
>
> ```bash
> composer require ashiqfardus/laravel-fuzzy-search
> php artisan migrate
> php artisan fuzzy-search:upgrade-v1   # scans your app/ for v1-era API usage
> ```
>
> → [Full upgrade guide](docs/UPGRADE_v1_TO_v2.md)

> **Upgrading from v2.0.x?** Run the new migrations (`php artisan migrate`), then rebuild once
> per model (`php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh`) to pick up
> weighted BM25 ranking. Read the guide's behaviour-changes list first: some calls now return
> other rows, throw, or cache where 2.0 did not.
>
> → [Upgrade v2.0→v2.1 guide](docs/UPGRADE_v2.0_TO_v2.1.md)

---

## Quick Start

### Zero-Config Search

```php
// Just add the trait and search!
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

class User extends Model
{
    use Searchable;
}

// Search immediately - auto-detects searchable columns
$users = User::search('john')->get();
```

**How auto-detection works:** The package automatically detects common column names in this priority order:
- `name`, `title` (weight: 10)
- `email`, `username` (weight: 8)
- `first_name`, `last_name` (weight: 7)
- `description`, `content`, `body` (weight: 5)
- `bio`, `summary`, `excerpt` (weight: 3)
- `slug`, `sku`, `code` (weight: 2-6)

If none of these exist, it falls back to the model's `$fillable` columns, then to the first remaining column. Only text columns qualify (char, varchar, text of any size, nvarchar, citext, and MySQL enum/set, matched by the exact type name): a numeric, boolean or date column, or a PostgreSQL enum, is never auto-detected, in any branch, so a `$fillable` `user_id` or `total` is skipped. JSON and UUID columns are skipped where the database has a JSON or UUID type, and detected where it stores them as text (JSON on SQLite, MariaDB and SQL Server; a `char(36)` UUID on MySQL and SQLite). It never picks a column the model hides from serialization (`$hidden`, or one outside a non-empty `$visible`), a secret-named column (any name containing `password`; `token` or a name ending in `_token`; `secret`, `api_key` or `private_key` as a whole underscore-separated part of the name (`secret_note`, `stripe_api_key`, `webhook_secret` — not `secretary_name`); or a name ending in `recovery_codes`; a broad `*_key` rule is deliberately left out, so `sort_key` stays searchable), `id` or the timestamps. Names match in any letter case. It reads the model class's default `$hidden` and `$visible`, so a column hidden at runtime with `makeHidden()` is still searched and indexed. A model where no column qualifies (every text column hidden, say) has nothing to search: its search matches nothing (no rows, a count of 0, no event), and so does `FuzzySearch::tableSearch()` on it, while `extended()` throws `SearchableColumnsNotFoundException`. Declare `$searchable['columns']` to search it.

### Manual Column Configuration

You can manually specify which columns to search and their weights:

```php
class User extends Model
{
    use Searchable;

    // Option 1: Define in $searchable property
    protected array $searchable = [
        'columns' => [
            'name' => 10,        // Highest priority
            'email' => 5,        // Medium priority  
            'bio' => 1,          // Lowest priority
        ],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 2,
    ];
}

// Option 2: Specify at query time (merges with / adds to $searchable columns)
$users = User::search('john')
    ->searchIn(['name' => 10, 'email' => 5])
    ->get();

// Option 3: Simple array without weights (all get weight of 1)
$users = User::search('john')
    ->searchIn(['name', 'email', 'username'])
    ->get();
```

### Fluent API

```php
$users = User::search('john doe')
    ->searchIn(['name' => 10, 'email' => 5, 'bio' => 1])  // Field weighting
    ->typoTolerance(2)                                      // Allow 2 typos
    ->withSynonyms(['john' => ['jon', 'johnny']])          // Synonym support
    ->ignoreStopWords(['the', 'and', 'or'])                // Stop-word filtering
    ->accentInsensitive()                                   // Unicode/accent handling
    ->highlight('mark')                                     // Highlighted results
    ->withRelevance()                                       // Relevance scoring
    ->prefixBoost(2.0)                                      // Prefix boosting
    ->debugScore()                                          // Explain scoring
    ->paginate(15);
```

### Chaining Eloquent

`search()` returns a builder that forwards ordinary Eloquent calls, local scopes and eager loads, so it reads like any other query:

```php
$users = User::search('john')
    ->where('is_active', true)
    ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
    ->with('profile')
    ->verified()                 // local scope
    ->query(fn ($q) => $q->where('tenant_id', auth()->user()->tenant_id))
    ->paginate(15);
```

Executing methods that would bypass the search conditions (`delete()`, `exists()`, `pluck()`, `update()`, …) are not forwarded and throw a `BadMethodCallException` — call `get()`, `first()`, `count()` or `paginate()` instead.

`latest()`, `oldest()`, `inRandomOrder()` and `reorder()` are forwarded to the underlying query: they only shape which rows make it into the candidate window, since the relevance `ORDER BY` is appended after them and PHP-side rescoring re-sorts by `_score` whenever `withRelevance` is on (the default) — call `withRelevance(false)` if you want the forwarded order to stick. The builder's own `orderBy()` is different: it replaces the relevance order on every path (see [Pagination](#pagination)). The closure passed to `when()`, `unless()` or `tap()` receives the underlying Eloquent builder, not the `SearchBuilder`.

Under a join (a forwarded `join()`, one inside `query()`, or a global scope's), the model's own searched columns are qualified with its table (or the FROM alias) automatically, so a joined table with a column of the same name is not ambiguous; to search the joined table's column, write it dotted: `searchIn(['teams.name'])`. Select the model's columns (`select('users.*')`) as with any Eloquent join, or the joined table's same-named columns (`id`, `name`) overwrite the model's attributes, and scoring, highlighting and `useInvertedIndex()` read the wrong values.

### Searching Relationships

Dotted column names — `posts.title`, `author.name`, nested paths — search through Eloquent relations (`belongsTo`, `hasMany`, `belongsToMany`) on the LIKE and extended paths, compiling to `whereHas()` (a portable `EXISTS` subquery):

```php
User::search('smith')->searchIn(['posts.title', 'profile.bio'])->get();
```

A dotted segment is followed as a relation only when it is a public, non-static method with no required parameters that neither Laravel nor this package defines, and that either declares a `Relation` return type (`BelongsTo`, `HasMany`, …) or is on a path the model lists in `$searchable['columns']`. Anything else throws `InvalidArgumentException` — `App\Models\Author::company is not a relation: declare a Relation return type or list the path in $searchable['columns']` — before any SQL runs, and the method is never called: `searchIn()` can carry request input, and `searchIn(['unguard.body'])` must not run `unguard()`. Nested paths are checked segment by segment, and the error names the segment that failed. A dotted name whose first segment is not a method at all is still a table-qualified column (`posts.title`). Relations defined by Laravel's own traits (for example `Notifiable::notifications()`) are not followed; wrap one in a method of your own with a return type.

A dotted name whose first part names a table of the query — the FROM table, a joined table, or either one's alias — is always that table's column, and no model method is looked at: `->join('items', …)->searchIn(['items.name'])` works even when the model has an `items()` method. A relation whose name equals a joined table's name is therefore read as that table's column; alias the join (`join('items as i', …)`) if you mean the relation. Such a table's column is searched in SQL; the model row does not carry it, so it adds nothing to `_score` or `_highlighted` unless you select it under the model's own column name.

The BM25 inverted index does not join relations at query time — define `searchableText()` on the model to put related text into the index instead.

`SearchableIndexingObserver` indexes a model only when it has searchable columns — the ones declared in `$searchable['columns']` or, when none are declared, the auto-detected string-like columns. A model with neither is skipped on save, even if it defines `searchableText()`. Auto-detection never selects a column cast to `encrypted` or `hashed`. Nor does it select a column the model hides from serialization (`$hidden`, or any column outside a non-empty `$visible`), or a secret-named column (any name containing `password`; `token` or a name ending in `_token`; `secret`, `api_key` or `private_key` as a whole underscore-separated part of the name (`secret_note`, `stripe_api_key`, `webhook_secret` — not `secretary_name`); or a name ending in `recovery_codes`; a broad `*_key` rule is deliberately left out, so `sort_key` stays searchable), in any letter case. An auto-detected column is indexed as the model's raw attribute value, not through a get accessor, and its `*_metaphone` shadow column is filled from the same value, so an accessor that decrypts or reformats it never reaches the index. Only the index reads the stored value: `suggest()`'s table scan, relevance scoring and highlighting read an auto-detected column through its accessor, as in 2.0, so for a column an accessor decrypts, declare `$searchable['columns']` without it, or hide it (`$hidden`); otherwise `suggest()`'s table scan can return words from the decrypted value of rows the query can see. Declaring a column in `$searchable['columns']`, or overriding `getSearchableColumns()`, is what opts into its accessor. A column you *declare* with the `encrypted` cast has its **decrypted** text written to the index, and `suggest()` and `didYouMean()` serve it.

Auto-detection does not keep a column out of the index in these cases. Put the column in `$hidden`, or declare `$searchable['columns']` without it:

- **A masking accessor** (`Str::mask()` on an email) is bypassed: the index, `suggest()` and `didYouMean()` serve the unmasked value, and the shadow column encodes it.
- **Encryption that decrypts into the model's attributes in memory** (spatie/laravel-ciphersweet does this when a model is retrieved) is invisible to the package: the plaintext is indexed, while the LIKE search matches the ciphertext in the database.
- **A column hidden at runtime** (`makeHidden()`, `setHidden()`, or a `getHidden()` that changes per request) is still searched and indexed: detection reads the model class's default `$hidden` and `$visible`, once per process. Highlighting reads the row's own state, so a column the row hides is not highlighted (see [Highlighted Results](#highlighted-results)).

→ Full guide: [docs/relationships.md](docs/relationships.md)

### Eloquent & Query Builder Support

```php
// Eloquent
User::whereFuzzy('name', 'john')->get();
User::whereFuzzy('name', 'john')->orWhereFuzzy('email', 'john', 'like')->get();
User::whereFuzzyMultiple(['name', 'email'], 'john')->get();
User::where('email', 'like', '%john%')->orderByFuzzy('email', 'john')->get();

// Query Builder
DB::table('users')->whereFuzzy('name', 'john')->get();
DB::table('products')->fuzzySearch(['title', 'description'], 'laptop')->get();
```

The same macros exist on the Eloquent builder and the query builder:

- `whereFuzzy(string $column, string $value, ?string $algorithm = null, ?array $options = [])` — adds the algorithm's predicate (default: `default_algorithm`) with `AND`.
- `orWhereFuzzy(string $column, string $value, ?string $algorithm = null, ?array $options = [])` — the same predicate joined with `OR`, e.g. a second column with a different algorithm.
- `orderByFuzzy(string $column, string $value, string $direction = 'asc')` — orders by the position of `$value` in `$column` (`LOCATE()`/`POSITION()`/`INSTR()`/`CHARINDEX()`), so with `'asc'` the earliest occurrence comes first. A row that does not contain `$value` has position 0 and sorts **before** every match, so filter to rows containing the term first. The position follows the database's case rules: case-insensitive under MySQL's default collation, case-sensitive on PostgreSQL and SQLite. A direction other than `asc`/`desc`, or a column that is not letters, digits, underscores and dots, throws `InvalidArgumentException`.

The macros (and the deprecated `Fuzzy` scopes) use the column as written, like `where()`: under a join, qualify it yourself (`whereFuzzy('users.name', 'john')`), or a joined table's column of the same name makes it ambiguous. A qualified column's table is written unprefixed, as in `where()`; the connection's table prefix is added for you.

---

## Search Algorithms

### Available Algorithms

| Algorithm | Best For | Typo Tolerance | Speed |
|-----------|----------|----------------|-------|
| `fuzzy` | General purpose | High | Fast |
| `levenshtein` | Strict typo matching | Configurable | Medium |
| `soundex` | Phonetic matching (English names) | Phonetic | Fast |
| `metaphone` | Phonetic matching (more accurate) | Phonetic | Fast |
| `trigram` | Similarity matching | High | Medium |
| `similar_text` | Percentage similarity (`similar_text.min_percentage`, default 70) | Medium | Medium |
| `simple` / `like` | Exact substring (LIKE) | None | Fastest |

```php
// Use specific algorithm
User::search('john')->using('levenshtein')->get();
User::search('stephen')->using('soundex')->get();  // Finds "Steven"
User::search('stephen')->using('metaphone')->get(); // More accurate phonetic — see setup below
User::search('laptop')->using('similar_text')->get(); // Contains "laptop", at least 70% similar (min_percentage)
```

> ⚠️ **`metaphone` requires one-time setup.** Unlike the other algorithms, it searches against a precomputed `{column}_metaphone` shadow column. Calling `using('metaphone')` without it throws `RuntimeException`. Run the three commands shown in [Shadow Columns](#shadow-columns) once per searchable column.

### Shadow Columns

Most algorithms compute their score on the fly during the SQL query. **Metaphone is the exception** — PHP's `metaphone()` function isn't available in SQL, so the package precomputes the phonetic code on every save and stores it in a sibling column.

For a `users` table with a `name` column, that means adding a `name_metaphone` column right next to it. Each row's `name_metaphone` holds the phonetic code (e.g. `Stephen` → `STFN`, `Steven` → `STFN`, `Stefan` → `STFN`). At search time, the query is a simple equality check against the precomputed code — fast, no per-row PHP calls.

**Setup (one-time per column):**

```bash
# 1. Generate the migration that adds the shadow column + index
php artisan fuzzy-search:add-shadow-column "App\Models\User" name --type=metaphone

# 2. Apply it
php artisan migrate

# 3. Fill it for the rows you already have (saves fill it from then on)
php artisan fuzzy-search:rebuild "App\Models\User"
```

A rebuild writes only the rows whose shadow value is missing or out of date, one UPDATE per 600 rows, and it rebuilds the model's BM25 index too. `--type` accepts `metaphone` only.

After this, the `SearchableObserver` keeps `name_metaphone` in sync automatically on every `save()` and `update()`.

**What gets generated:**

```php
// database/migrations/{timestamp}_add_name_metaphone_to_users_table.php
Schema::table('users', function (Blueprint $table) {
    $table->string('name_metaphone')->nullable()->after('name');
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

Without `searchIn()` there is no column to search, so a search matches nothing; an empty term still returns the items unsearched. Case is folded in every script, the way `Model::search()` scores (`ÉCOLE` finds `école`, `МОСКВА` finds `москва`), a term is cut at `query.max_term_length` characters (default 128), and `similar_text()` compares at most the first 255 characters of a value and of the term.

---

## Field Weighting & Scoring

### Weighted Columns

```php
User::search('john')
    ->searchIn([
        'name' => 10,       // Highest priority
        'username' => 8,
        'email' => 5,
        'bio' => 1,         // Lowest priority
    ])
    ->get();
```

### Relevance Scoring

```php
$users = User::search('john')
    ->withRelevance()
    ->get();

foreach ($users as $user) {
    echo "{$user->name}: {$user->_score}";
}
```

### Prefix Boosting

```php
// Boost results that start with the search term
User::search('john')
    ->prefixBoost(2.5)  // 2.5x score for prefix matches
    ->get();
```

### Partial Match Support

Substring matching is always on: every pattern-based algorithm searches for `%term%`, so a
partial term matches without any extra call.

```php
User::search('joh')->get();   // matches "john", "johnny", "johanna"
```

`partialMatch()` is kept as a no-op for API compatibility — there is nothing to switch on.
`minMatchLength()` is deprecated since v2.1.0 and does nothing; set the minimum term length with
the `min_search_length` config key (whole term) or `typo_tolerance.min_word_length` (per word).
A plain term shorter than `min_search_length` characters (default 2) matches nothing on every
search API — `get()`, `first()`, `paginate()` (total 0), `simplePaginate()`, `count()`,
`getFacets()`, `FederatedSearch`, `FuzzySearch::on()` and the Scout engine — and fires no event.
`extended()`/`searchBoolean()` queries are not measured, and neither are the query-builder
helpers (the `whereFuzzy`-style macros, the `Fuzzy` scopes and `tableSearch()`). `suggest()` and
`didYouMean()` keep their own two-character floor.

### Custom Scoring Hooks

```php
User::search('john')
    ->customScore(function ($item, $baseScore) {
        // Boost verified users
        if ($item->is_verified) {
            return $baseScore * 1.5;
        }
        // Penalize inactive users
        if (!$item->is_active) {
            return $baseScore * 0.5;
        }
        return $baseScore;
    })
    ->get();
```

A model that uses `Searchable` can also override `getSearchScore(float $baseScore): float` (see the model example under [Per-Model Customization](#per-model-customization)). It runs once per row on every path that scores in PHP — on the LIKE and extended paths it receives the row's column score before `customScore()` and `boostRecent()`, and on `useInvertedIndex()` the BM25 raw score — before normalisation, and results are ranked by what it returns. On the index path a model that overrides it is ranked over the first `max_candidates` matches, as the LIKE path is, so a boosted row can reach page 1. The trait's own method returns the score unchanged.

### Recency Boost

Boost newer records in search results. The boost decays linearly across the window: a row
created this instant gets the full multiplier, one at the end of the window gets none, and the
multiplier in between is

```
1 + (multiplier - 1) x (1 - age_in_days / days)
```

so with `boostRecent(1.5, 'created_at', 30)` a row created today scores x1.5, a 15-day-old row
x1.25 and a 29-day-old row x1.0167. Rows older than the window are untouched (x1.0).

```php
// Newest rows get 1.5x, decaying to 1.0x at 30 days old
User::search('john')
    ->boostRecent(1.5, 'created_at', 30)
    ->get();

// With defaults: 1.5x at age 0, created_at column, 30-day window
User::search('john')
    ->boostRecent()
    ->get();
```

### Search Suggestions / Autocomplete

Get autocomplete suggestions based on search term:

```php
$suggestions = User::search('joh')
    ->searchIn(['name', 'email'])
    ->suggest(5);

// Returns: ['John', 'Johnny', 'Johanna', ...]
```

**Indexed models complete from the dictionary.** When the model has been indexed (a `fuzzy_index_meta` row exists for it — see [BM25 Inverted Index](#bm25-inverted-index)), `suggest()` completes the *last whitespace-separated word* of the term against that model's own dictionary, scoped to its postings and ordered by document count across all indexed models (the dictionary's `doc_count` is global, so a term that is common in another indexed model can head the list); any earlier words are kept as typed and prefixed back. Completions are the dictionary's lower-case terms, not the value as stored in the column:

```php
// Product is indexed; only "mo" is completed, "wireless " is kept as typed
Product::search('wireless mo')->suggest(5);

// Returns: ['wireless monitor', 'wireless mouse', 'wireless modem', ...]
```

`searchIn()` does **not** narrow dictionary completions: they are scoped to the model, not to its columns, so a name box on an indexed model can be offered a fragment that only occurs in an email column. Use `suggestFrom('table')` when the column matters — the table scan respects `searchIn()`.

**Hidden columns are never offered.** A column the model hides (`$hidden`, or one outside a non-empty `$visible`) gives no word to `suggest()`, from the table scan or the dictionary, or to `didYouMean()`. Searches still match it, including their typo and as-you-type expansions, and return only the row's visible attributes. Dictionary postings written before 2.1 carry no column name, so for a model that hides one of its searchable columns they are left out of suggestions too until you run `fuzzy-search:rebuild --fresh`.

**Un-indexed models keep the table scan** — the v2.0 behaviour, proposing column values as stored:

```php
// User has no fuzzy_index_meta row
User::search('joh')->searchIn(['name'])->suggest(5);

// Returns: ['John', 'Johnny', ...] — the value as stored, not lower-cased
```

**What scopes a suggestion.** `filter()` and `filterIn()` never do: they belong to the search itself, which `suggest()` and `didYouMean()` deliberately do not run. The constraints you put on the builder's base query — `where()`, `join()`, `query()` and Eloquent calls forwarded through the builder, plus the model's global scopes — are honoured as follows. What counts as a constraint is a WHERE or a JOIN clause; a `having()`, a union or a from-subquery is not detected, and the dictionary treats such a query as unconstrained.

- **The table scan** (an un-indexed model, `suggestFrom('table')`, or `'auto'` under a constraint) runs on the base query, so every one of those constraints narrows it.
- **`suggest()` in `'auto'` mode on an indexed model** completes from the dictionary only when the base query is unconstrained. A `where()`, a `join()`, a forwarded scope or a global scope switches it to the table scan. The `SoftDeletes` scope does not count, because deleting a row drops its terms from the index. With `indexing.async` (the default) that happens when the queued `IndexModelJob` runs, so until then a trashed row's terms can still be offered; a query-builder `delete()` fires no model events, so its rows' terms stay until you rebuild. Use `suggestFrom('table')` or sync indexing if that matters.
- **`suggestFrom('index')`** always completes from the model's dictionary. It is model-wide and ignores `where()` and every scope, so use it only where every caller may see every row's terms.
- **`didYouMean()`** always offers the model's own dictionary terms only. Under a `where()`, a `join()` or a global scope (`SoftDeletes` excepted, with the same queue lag) it keeps only terms posted for at least one row the query can see. It checks up to `max_candidates` of each term's rows, and a term whose visible rows fall outside those is dropped rather than shown. It checks at most `max($limit * 3, 10)` terms, so a narrow query can get fewer alternatives than `$limit`.
- **A plain query builder** given `useInvertedIndex(Model::class)` follows the same rules with its own `where()`s and joins, which its index search honours too. The model's global scopes apply to its index search and to `didYouMean()`, but not to its table scan (a query builder has no model scopes), so `'auto'` completes model-wide from the dictionary when the builder carries no constraint of its own.

`suggestFrom('auto'|'index'|'table')` overrides which source `suggest()` uses; `'auto'` (the default) picks the dictionary when the model is indexed and the base query is unconstrained (see above), and the table scan otherwise:

```php
User::search('joh')->suggestFrom('index')->suggest(5); // dictionary only, model-wide (ignores where() and scopes) — [] if the model isn't indexed
User::search('joh')->suggestFrom('table')->suggest(5); // table scan only, even on an indexed model
```

### "Did You Mean" Spell Correction

Get alternative spellings when search has typos:

```php
$alternatives = User::search('jonh')->didYouMean(3);  // Typo

// Returns, for example with users named Jon, Jane and John: [
//     ['term' => 'jon', 'distance' => 1, 'confidence' => 0.75],
//     ['term' => 'jane', 'distance' => 2, 'confidence' => 0.5],
//     ['term' => 'john', 'distance' => 2, 'confidence' => 0.5],   // a transposition is two edits
// ]
```

The closest term comes first, then the most common one. How far it reaches scales with the term's length: 1 edit for 2–3 characters, 2 for 4–5, 3 from 6. Alternatives come from the searched model's own terms in the BM25 dictionary (`fuzzy_index_terms`), so the model must be indexed; another model's terms are never offered. `searchIn()` does not narrow them: like dictionary completions, they are the model's terms from every indexed column. A builder with no Eloquent model (a plain query builder, without `useInvertedIndex(Model::class)`) gets `[]`.

### Multi-Model Federation Search

Search across multiple models simultaneously:

```php
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;

$results = FederatedSearch::across([User::class, Product::class, Post::class])
    ->search('laptop')
    ->using('fuzzy')
    ->limit(20)
    ->get();

// Each result includes _model_type and _model_class
foreach ($results as $result) {
    echo $result->_model_type;  // 'User', 'Product', or 'Post'
}

// Get grouped results
$grouped = FederatedSearch::across([User::class, Product::class])
    ->search('test')
    ->getGrouped();

// Get counts per model — match counts, not page sizes: limit() does not shrink them
// (limitPerModel() and max_candidates do, see the note below), and they add up to exactly
// what paginate()->total() reports
$counts = FederatedSearch::across([User::class, Product::class])
    ->search('test')
    ->getCounts();  // ['User' => 5, 'Product' => 3]

// Cap how many rows each model may contribute before merging (default: limit())
$results = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->limitPerModel(5)
    ->limit(20)
    ->get();

// Tie-break order for equal scores (and the whole order when withRelevance(false))
$results = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->orderByModel([Product::class, User::class])
    ->get();

// Page across all models with a real, globally ranked total
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->paginate(15);

// paginate()'s total() counts only reachable rows. One rule: each model contributes the
// smaller of its match count, limitPerModel() and max_candidates (default 1000, see
// "max_candidates Tuning" below — a ranked search reads at most that many candidates per
// model). So the page count never promises more rows than the search can return, and
// getCounts() reports the same per-model numbers total() adds up.
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->limitPerModel(5)
    ->paginate(15);

// Cursor-less "load more" pagination — cheaper than paginate() when you only need hasMorePages()
$page = FederatedSearch::across([User::class, Product::class])
    ->search('laptop')
    ->simplePaginate(15);
```

Each model is searched the way `Model::search()` searches it, with its own `$searchable` configuration — algorithm, typo tolerance, stop words, synonyms, accent handling and options. `using()`, `typoTolerance()` and `options()` on the federated search override them for every model (`options()` takes the same driver options as `SearchBuilder::options()`, such as `max_distance`; `typoTolerance()` wins over its `max_distance`). Narrowing the columns with `searchIn()` overrides only the column list. A model without the `Searchable` trait is searched with LIKE — or, with the `Fuzzy` trait, with its own `getFuzzyAlgorithm()` and `getFuzzyOptions()` (its `$fuzzyAlgorithm`, default `default_algorithm`, and `$fuzzyOptions`), which `using()`, `options()` and `typoTolerance()` override — on the `searchIn()` columns its table has; without `searchIn()`, on its declared `$searchable['columns']`, else — with the `Fuzzy` trait — on `getFuzzySearchableColumns()` (its `$fuzzySearchable`, `name` by default), else on whichever of `name` and `title` its table has, and a model with none of these contributes nothing. Every model, with the trait or without, contributes at most `max_candidates` rows.

Relation columns (`author.name`) are not supported in federated searches yet and are ignored.

### Search Analytics

Per-query debug information about the builder you are holding — nothing is stored. For DB-backed reporting across searches (popular terms, zero-result terms, latency by path) see [Persisted Search Analytics](#persisted-search-analytics).

Get detailed analytics about your search configuration:

```php
$analytics = User::search('john')
    ->searchIn(['name' => 10, 'email' => 5])
    ->using('levenshtein')
    ->typoTolerance(2)
    ->getAnalytics();

// Returns: [
//     'search_term' => 'john',
//     'algorithm' => 'levenshtein',
//     'columns_searched' => ['name', 'email'],
//     'typo_tolerance' => 2,
//     'tokenized' => false,
//     'stop_words_active' => false,
//     ...
// ]
```

---

## Text Processing

- **Stop-word filtering** — `ignoreStopWords()` drops common words from a query; built-in lists cover eight locales, or pass a custom list or file.
- **Synonyms** — `withSynonyms()` and `synonymGroup()` expand a query to related terms.
- **Per-locale stop words** — `ignoreStopWords('de')` picks a locale's list at query time and `$searchable['locale']` picks one for a model's index pipeline. (`locale()` on the builder never selected either; it is deprecated since v2.1.0 and does nothing.)
- **Unicode & accent insensitivity** — on by default (`unicode.accent_insensitive`): a term is also searched in its accent-free form, beside the typed one, so `Müller` finds `Zoë Müller` and `Muller`, and `café` finds `cafe`. The other way round, `cafe` finding `Café` as a substring match (`simple`/`like`) needs the column folded, which the package does only through the database (the typo-tolerant algorithms may still reach `Café` as a one-letter typo): an accent-insensitive collation on MySQL/MariaDB (`utf8mb4_unicode_ci`, `utf8mb4_0900_ai_ci`), or `accentInsensitive()` on PostgreSQL with the unaccent extension and `use_native_functions=true` (see Notes). SQLite, and PostgreSQL without native functions, cannot fold the column side; SQL Server follows the column's collation. `unicodeNormalize()` matches `naïve`/`naive` forms. Text is handled per character, not per byte, so combining marks stay attached to their base letters.
- Index-time options — the tokenizer, per-model pipelines, accent folding on the index, and optional stemming — sit apart from the query-time behavior above; changing any of them needs `php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh`.

→ Full guide: [docs/tokenization.md](docs/tokenization.md)

---

## Result Presentation

### Highlighted Results

```php
$users = User::search('john')
    ->highlight('em')  // Wrap matches in <em> tags
    ->get();

foreach ($users as $user) {
    echo $user->_highlighted['name'];  // "Hello <em>John</em> Doe"
}

// Custom highlight
$users = User::search('john')
    ->highlight('<mark class="highlight">', '</mark>')
    ->get();
```

Set `highlighting.enabled = true` in the config to highlight every search without calling `highlight()`.

Every value in `_highlighted` is safe to render as HTML: a matched column is wrapped in the highlight tag (and escaped first), and — since v2.1.0 — a column that did not match is HTML-escaped too, so the whole array can be echoed with `{!! !!}` without an extra `e()` call.

`_highlighted` and `_matches` hold only columns the row's `toArray()` would show: a column in `$hidden`, or outside a non-empty `$visible`, is still searched and scored but never highlighted. For a relation column the related model's `$hidden`/`$visible` apply too, and so does the parent hiding the relation (`author.name` is left out when `Author` hides `name` or the post hides `author`). The row's own state is read when the search runs, so `makeHidden()`/`makeVisible()` in a `retrieved` listener counts, and `FuzzySearchResource` applies the row's rule again when it renders, so a `makeHidden()` after the search holds there as well. The same rule keeps hidden columns out of `debugScore()`'s `_debug` (`columns`, `weights` and `column_scores`), and `suggest()`'s table scan never offers a word from a hidden column: a column the model hides by `$hidden`/`$visible` is left out of the scan's matching, so rows that match only through it do not crowd out the rows that can yield a suggestion, and a model whose every searchable column is hidden gets `[]` without a query.

### Debug / Explain-Score Mode

```php
$users = User::search('john')
    ->debugScore()
    ->get();

foreach ($users as $user) {
    print_r($user->_debug);
    // For "John Doe" <john@example.com>, with columns name => 10 and email => 5:
    // [
    //     'term' => 'john',
    //     'algorithm' => 'fuzzy',
    //     'typo_tolerance' => 2,
    //     'prefix_boost' => 1.0,
    //     'columns' => ['name', 'email'],
    //     'weights' => ['name' => 10, 'email' => 5],
    //     'column_scores' => ['name' => 800.0, 'email' => 400.0],
    //     'final_score' => 1.0,
    // ]
}
```

`column_scores` is each column's score times its weight (`john` is a prefix of both values, worth `scoring.prefix_match` = 80: 80 × 10 and 80 × 5), and `final_score` is the row's `_score`. `prefix_boost` is what `prefixBoost()` set (1.0 when not called). On `useInvertedIndex()` `column_scores` is empty, because BM25 scores whole documents. `algorithm` is `extended` for an `extended()` query.

---

## Performance & Indexing

### Async Indexing (Queue Support)

```php
// In config/fuzzy-search.php
'indexing' => [
    'enabled' => true,          // without this the indexing observer returns early
    'async' => true,
    'queue' => 'search-indexing',
    'chunk_size' => 500,
    'job' => [
        'tries'   => 3,             // attempts before the index job is marked failed
        'backoff' => [10, 60, 300], // seconds before the 2nd, 3rd, ... attempt
        'timeout' => 120,           // seconds a single job may run
    ],
],

// Re-index a single model (dispatches IndexModelJob to queue)
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
IndexModelJob::dispatch(User::class, $user->id);
```

### Caching

```php
// Cache get() (and first()/simplePaginate(), which go through it) for 60 minutes
User::search('john')->cache(60)->get();

// No argument: the cache.ttl config (seconds)
User::search('john')->cache()->get();

// A key of your own, used as given
User::search('john')->cache(60, 'user-search-john')->get();

// With cache.enabled on, turn caching off for one query
User::search('john')->cache(0)->get();
```

```php
// config/fuzzy-search.php
'cache' => [
    'enabled' => false,          // true: cache every search without calling cache()
    'driver'  => 'default',      // a store from config/cache.php, or 'default' for the app's
    'ttl'     => 3600,           // seconds
    'prefix'  => 'fuzzy_search_',// generated keys start with this
],
```

`cache.ttl` counts **seconds**; `cache($minutes)` counts **minutes** and overrides it for that query; `cache()` or `cache(null)` caches for `cache.ttl` (before 2.1 `cache(null)` meant "not cached"); `cache(0)` turns caching off for that query, also when `cache.enabled` is on. With `cache.enabled` on, "every search" means every `get()`, `first()` and `simplePaginate()`: `paginate()`, `count()` and `getFacets()` are never cached.

> **Before 2.1 the `cache` block was never read.** If your published `config/fuzzy-search.php` has `'enabled' => true` (the old README showed it), 2.1 caches every `get()`, `first()` and `simplePaginate()` for `ttl` **seconds**, and a `ttl` you wrote as minutes is now read as seconds. Set `'enabled' => false` to keep 2.0's behaviour.

A key you pass is used as given, so `Cache::forget('user-search-john')` removes it. A generated key starts with `cache.prefix` and covers everything that changes the result: the term, columns and weights, algorithm and options, filters, forwarded `where()`/joins/scopes (the SQL and its bindings), limit and offset, `orderBy()`, highlight tags, `withRelevance()`, `debugScore()`, the index model class, the model class, the names of its eager loads, where it runs — connection name, driver, host, port, database, table prefix and, on PostgreSQL, the `search_path` (one extra `select current_setting('search_path')` per cached search) — and the whole `fuzzy-search` config. Tenants on separate connections, databases or schemas therefore never share an entry, and changing any config value (`min_percentage`, `max_candidates`, a driver option, `cache.ttl` itself) starts a fresh set of entries; the old ones expire with their TTL. A key you name changes only when you change it. A search with a `customScore()` closure is cached only under a key you name: a closure cannot be part of a generated key.

A cached result stores no relations: each read, hit or miss, carries the current request's eager loads with their constraints (`with(['reviews' => fn ($q) => $q->where('user_id', auth()->id())])` is never served to another user), which costs the eager-load queries on a hit. A cache hit fires no `FuzzySearchExecuted` event and writes no analytics row.

---

## BM25 Inverted Index

A real inverted index for large tables, across four tables: `fuzzy_index_terms`, `fuzzy_index_postings`, `fuzzy_index_documents`, `fuzzy_index_meta`.

```bash
php artisan fuzzy-search:rebuild "App\Models\Post"    # build once, then stays in sync automatically
```

The index follows Eloquent model events, once the save's transaction commits. Writes that fire no model events leave it stale: `Model::query()->update()`, `insert()`, a query-builder `delete()`, `saveQuietly()`, `Model::withoutEvents()` and raw SQL. Re-index those rows afterwards with `php artisan fuzzy-search:rebuild "App\Models\Post"`, or per row with `IndexModelJob::dispatch(Post::class, $id)`.

```php
Post::search('tolkien')->useInvertedIndex()->get();
```

- **Column weights (BM25F-lite)** — `searchIn()` / `$searchable['columns']` weights scale ranking on the index too, not only the LIKE/Levenshtein paths.
- **Typo tolerance & as-you-type** — the index expands each query term through its own term dictionary, so `typoTolerance()` and `asYouType()` work without an exact token match.
- BM25 tends to beat LIKE once a table passes roughly 10k+ rows; below that, LIKE is simpler to operate.
- **Writes** — the indexer indexes the row as it is stored when it writes, two writes for the same row wait for each other instead of counting it twice, and with `indexing.async` off an index error is reported to your exception handler, not thrown from `save()`. On SQL Server, indexing inside an open transaction (Scout with `after_commit` off, or `searchable()` inside `DB::transaction()`) can deadlock; see [Production Setup](docs/bm25.md#production-setup).

→ Full guide: [docs/bm25.md](docs/bm25.md)

---

## Extended Search Syntax

`->extended()` opts a search string into Fuse.js-style query operators for precise matching, instead of a plain substring search.

```php
User::search('=John ^Doe !banned')->extended()->get();
```

Operators: `'include`, `=exact`, `^prefix`, `suffix$`, `!exclude`, `|` (OR), `( )` (grouping), `~typo`, `field:term`, and quoted `"phrases"`.

Extended queries always run on the LIKE path — `->useInvertedIndex()` is ignored when combined with `->extended()`, and `getDebugInfo()['index_ignored']` reports it.

→ Full guide: [docs/extended-syntax.md](docs/extended-syntax.md)

---

## Scout Driver

The Scout engine adapter is bundled in this package and registers automatically when `laravel/scout` is installed — no separate driver package needed. It supports Laravel Scout 10.x and 11.x.

```
SCOUT_DRIVER=fuzzy-search
```

It wraps the same `IndexManager` + `Bm25Scorer` used by `Model::search()->useInvertedIndex()`, so Scout searches share the same index and the same relevance scoring — there is no separate index to keep in sync. Scout's semantic and hybrid search (`semantic()`, `hybrid()`, Scout 11.6+) are not supported by this engine: both throw `NotSupportedException`.

`orderBy()`, `orderByDesc()`, `latest()` and `oldest()` replace the relevance order, as on Scout's database engine: the matches come back in that order (ties by key, descending), and `_score` still carries each one's BM25 score. The query is searched on its first `query.max_term_length` characters (default 128), as `useInvertedIndex()` searches it.

→ Full guide: [docs/integrations.md](docs/integrations.md#scout-driver)

---

## Filament Integration

`HasFuzzyGlobalSearch` swaps a Filament Resource's LIKE-based global search for fuzzy search — typo tolerance, relevance ordering, highlighted details — while everything else about the Resource stays as defined.

```php
use HasFuzzyGlobalSearch; // on the Resource, for global search

FuzzySearch::tableSearch(['name']); // for individual table columns
```

Supports Filament v3, v4 and v5. Filament is a soft dependency — nothing in this package requires it unless you use the trait or helper.

→ Full guide: [docs/integrations.md](docs/integrations.md#filament-integration)

---

## JSON API Resources

`FuzzySearchResource` wraps one result row, and `FuzzySearchCollection::fromBuilder()` wraps a paginated search, into normal Laravel API responses with the package's underscore-prefixed fields alongside the plain attributes.

```php
return FuzzySearchCollection::fromBuilder(User::search($q)->highlight('mark'), perPage: 20);
```

The response's `meta` carries `query`, `algorithm`, `latency_ms` and `suggestions` (populated only when the page is empty). `_highlighted` values are already HTML-escaped — render them directly, don't escape again.

→ Full guide: [docs/integrations.md](docs/integrations.md#json-api-resources)

---

## Livewire Recipe

A search-as-you-type box needs only a client-side debounce — `wire:model.live.debounce.300ms` — plus `->asYouType()` and `->suggest()` on the builder. This replaces `SearchBuilder::debounce()`, deprecated since v2.1.0: by the time the builder runs server-side, the request has already arrived, so a server-side debounce can't do anything.

This is documentation only — no such component ships with the package or the demo app.

→ Full guide: [docs/integrations.md](docs/integrations.md#livewire-recipe)

---

## Pagination

### Stable Ranking

```php
// Results maintain consistent order across pages
$page1 = User::search('john')->stableRanking()->paginate(10, page: 1);
$page2 = User::search('john')->stableRanking()->paginate(10, page: 2);
```

An explicit `orderBy()` replaces the relevance order on every path — LIKE, `extended()` and `useInvertedIndex()` — and on every terminal: rows come back in that order (several calls apply in the order given), `_score` is still attached, and `stableRanking()` adds the primary key ascending as the final tiebreak unless an `orderBy()` already names the key. The relevance order applies only when no `orderBy()` was set. `?page` is read as a whole number of at least 1 on every paginator: `?page=abc`, `?page=0`, `?page=-3` and `?page[]=1` are page 1; likewise `page(0)` or a negative page is page 1 and `skip(-n)` is `skip(0)`.

### Pagination Methods

```php
// Offset pagination
$users = User::search('john')->paginate(15);

// Simple pagination (no total count - faster; best for infinite scroll)
$users = User::search('john')->simplePaginate(15);

// SearchBuilder::paginate() and simplePaginate() clamp perPage to max_candidates (default 1000)
// on every search path — LIKE, extended and BM25 alike — because that is the widest window the
// ranking is built from; a perPage below 1 becomes 1. take()/limit() are your explicit limit and
// are not clamped: on the index path take(n) hydrates n models, while on the LIKE and extended
// paths the max_candidates candidate window still bounds them. FederatedSearch::paginate() and
// simplePaginate() clamp perPage the same way.
$users = User::search('john')->paginate(2000);  // perPage() === 1000

// cursorPaginate() always throws BadMethodCallException — it bypasses PHP-side
// relevance scoring. Use simplePaginate() above instead.

// Manual pagination
$users = User::search('john')
    ->take(10)
    ->skip(20)
    ->get();
```

---

## Reliability & Safety

### Fallback Search Strategy

When the primary algorithm returns no rows, the same search is re-run with each fallback in order until one returns results. Works with `get()`, `first()`, `paginate()`, `simplePaginate()` and `count()`; filters and `where()` constraints carry over to each attempt.

```php
User::search('jonh')
    ->using('simple')        // plain LIKE — no match for the typo
    ->fallback('fuzzy')      // transposition pattern finds "John"
    ->fallback('soundex')    // tried only if fuzzy also finds nothing
    ->get();

// A term the dictionary cannot reach (bm25.fuzzy.candidate_pool) still gets a safety net:
Product::search('smartwach')
    ->useInvertedIndex()
    ->fallback('fuzzy')
    ->get();
```

One `FuzzySearchExecuted` event fires per attempt, so analytics can tell which algorithm answered.

### Query Complexity Limits

```php
User::search($query)
    ->maxPatterns(50)  // Cap the LIKE patterns generated per column (default: performance.max_patterns = 100)
    ->get();
```

> `debounce()` is deprecated and does nothing: a request that has already reached the server cannot be debounced. Debounce on the client instead (`wire:model.live.debounce.300ms` in Livewire, or a timer in JavaScript). It will be removed in v3.0.0.

### SQL Injection Safety

All queries use parameterized bindings. Search terms are automatically sanitized.

```php
// Safe - input is sanitized
User::search("'; DROP TABLE users; --")->get();
```

### Exception Handling

```php
use Ashiqfardus\LaravelFuzzySearch\Exceptions\LaravelFuzzySearchException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidAlgorithmException;

// Catch all fuzzy search exceptions
try {
    $results = User::search($term)->get();
} catch (LaravelFuzzySearchException $e) {
    Log::error('Search failed', $e->toArray());
}

// Catch specific exceptions
try {
    $results = User::search('')->get();
} catch (EmptySearchTermException $e) {
    return response()->json(['error' => 'Please enter a search term']);
}

try {
    $results = User::search('test')->using('invalid')->get();
} catch (InvalidAlgorithmException $e) {
    return response()->json(['error' => $e->getMessage()]);
}
```

**Available Exceptions:**
- `LaravelFuzzySearchException` - Base exception (catch all)
- `EmptySearchTermException` - Search term is empty (or only whitespace) while `allow_empty_search` is false — thrown by every terminal: `get()`, `first()`, `paginate()`, `simplePaginate()`, `count()` and `getFacets()`, on the LIKE and index paths (with `allow_empty_search` on, every one of them lists every row); `extended()`/`searchBoolean()` queries are exempt
- `InvalidAlgorithmException` - Invalid algorithm specified
- `InvalidConfigException` - Configuration error
- `SearchableColumnsNotFoundException` - No searchable columns found
- `QuerySyntaxException` - Invalid `extended()` / `searchBoolean()` query: an unknown or ambiguous field, bad operator syntax (an unbalanced parenthesis, an unterminated quote, a misplaced `~` or `!`), a query with no searchable terms (`|`, `()`, a lone `!`) or a field scope with no term (`name:`), a query that reaches `query.max_tokens` tokens (32 by default, so at most 31: every word, `|` and parenthesis counts), or parentheses nested deeper than `query.max_depth` (16)

---

## Events

### `FuzzySearchExecuted`

Fired after every `->get()` or `->paginate()` call, and — since v2.1 — after every in-memory search too (`FuzzySearch::on($items)->search(...)->get()`). Useful for monitoring search latency and volume in production.

An in-memory search called with an empty term (which returns the items unsearched) or with no `searchIn()` columns (which returns nothing) fires no event: nothing was searched, so there's nothing to log. A term shorter than `min_search_length` fires none on any search API, in memory or not.

```php
use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;

Event::listen(FuzzySearchExecuted::class, function ($event) {
    Log::info('search', [
        'term'      => $event->searchTerm,
        'columns'   => $event->columns,
        'algorithm' => $event->algorithm,
        'count'     => $event->candidateCount,
        'ms'        => $event->latencyMs,
        'results'   => $event->resultCount,
        'path'      => $event->path,
        'model'     => $event->modelClass,
    ]);
});
```

Properties:

- `searchTerm` (string) — the user's query
- `columns` (array) — columns being searched
- `algorithm` (string) — algorithm used: `fuzzy`, `levenshtein`, `soundex`, `metaphone`, `trigram`, `similar_text`, `simple` (`using('like')` reports `simple`), `like` (from `fallback('like')` or `default_algorithm => 'like'`, which keep the name as given), `bm25`, `extended` (extended-syntax searches) or `in_memory` (`FuzzySearch::on()`)
- `candidateCount` (int) — rows fetched from SQL before scoring
- `latencyMs` (float) — total search time in milliseconds
- `resultCount` (int) — rows returned to the caller; `-1` when unknown
- `path` (string) — which code path answered: `like`, `bm25`, `extended`, or `in_memory`
- `modelClass` (`?string`) — the Eloquent model class searched; `null` for query-builder and in-memory searches

---

## Persisted Search Analytics

Opt-in, DB-backed analytics: set `analytics.enabled` to `true` and every executed search writes one row to the `analytics.table` table (`fuzzy_search_logs` by default; the migration creates the table that key names, so set it before `php artisan migrate`).

```php
SearchAnalytics::popular(7, 5);
SearchAnalytics::zeroResults(7, 5);
```

The `SearchAnalytics` facade also reports `averageLatency()` by path and `volume()` per day; `php artisan fuzzy-search:analytics` prints a report and `fuzzy-search:analytics:prune` deletes rows past `retention_days`. Terms are stored (or hashed via `hash_terms`) — no user identity is recorded.

→ Full guide: [docs/analytics.md](docs/analytics.md)

---

## Configuration

### Config File

```php
// config/fuzzy-search.php

return [
    'default_algorithm' => 'fuzzy',
    
    'typo_tolerance' => [
        'enabled' => true,
        'max_distance' => 2,
        'min_word_length' => 4,  // No typo tolerance for short words
    ],
    
    'scoring' => [
        'exact_match' => 100,
        'prefix_match' => 80,
        'contains' => 60,
        'fuzzy_match' => 50,
    ],
    
    'stop_words' => [
        'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at'],
        'de' => ['der', 'die', 'das', 'und', 'oder', 'aber'],
    ],
    
    'synonyms' => [
        // Global synonyms
    ],
    
    'indexing' => [
        'enabled' => false,
        'async' => true,
        'queue' => 'default',
    ],
    
    'cache' => [
        'enabled' => false,
        'driver' => 'default',  // any cache store name, or 'default' for the app's
        'ttl' => 3600,          // seconds
    ],
    
    'max_candidates' => 1000,   // top level, not under 'performance'
    
    'performance' => [
        'max_patterns' => 100,
    ],
];
```

### Config Presets

Presets are **predefined search configurations** for common use cases. Instead of manually configuring multiple options every time, use a single preset name.

**Without preset (verbose):**
```php
Post::search('laravel')
    ->searchIn(['title' => 10, 'body' => 5, 'excerpt' => 3])
    ->using('fuzzy')
    ->typoTolerance(2)
    ->ignoreStopWords('en')
    ->accentInsensitive()
    ->get();
```

**With preset (clean):**
```php
Post::search('laravel')->preset('blog')->get();
```

#### Available Presets

| Preset | Best For | Algorithm | Typo Tolerance | Features |
|--------|----------|-----------|----------------|----------|
| `blog` | Blog posts, articles | fuzzy | 2 | Stop words, accent-insensitive |
| `ecommerce` | Product search | fuzzy | 1 | Weighted product columns, no stop words |
| `users` | User/contact search | levenshtein | 2 | Accent-insensitive |
| `phonetic` | Name pronunciation | soundex | 0 | Phonetic matching |
| `exact` | SKUs, codes, IDs | simple | 0 | Plain LIKE, no typo patterns |

#### Preset Configuration Reference

```php
// config/fuzzy-search.php
'presets' => [
    'blog' => [
        'columns' => ['title' => 10, 'body' => 5, 'excerpt' => 3],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 2,
        'stop_words_enabled' => true,
        'accent_insensitive' => true,
    ],
    
    'ecommerce' => [
        'columns' => ['name' => 10, 'description' => 5, 'sku' => 8, 'brand' => 6],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 1,
        'partial_match' => true,
        'stop_words_enabled' => false,
    ],
    
    'users' => [
        'columns' => ['name' => 10, 'email' => 8, 'username' => 9],
        'algorithm' => 'levenshtein',
        'typo_tolerance' => 2,
        'accent_insensitive' => true,
    ],
    
    'phonetic' => [
        'columns' => ['name' => 10],
        'algorithm' => 'soundex',
        'typo_tolerance' => 0,
    ],
    
    'exact' => [
        'algorithm' => 'simple',
        'typo_tolerance' => 0,
        'partial_match' => true,
    ],
],
```

#### Using Presets

```php
User::search('john')->preset('users')->get();
Post::search('laravel')->preset('blog')->get();
Product::search('laptop')->preset('ecommerce')->get();
Contact::search('steven')->preset('phonetic')->get();  // Finds "Stephen"
Product::search('SKU-12345')->preset('exact')->get();
```

A preset's `columns` are added to the search's columns, the way `searchIn()` adds them, so every column it names must exist on the table: `blog` needs `title`, `body` and `excerpt`; `ecommerce` needs `name`, `description`, `sku` and `brand`; `users` needs `name`, `email` and `username`; `phonetic` needs `name`. On a table without one of them the query fails with an unknown-column error, and a `searchIn()` after the preset cannot take the column away, because `searchIn()` only adds. Publish the config and change the preset's `columns` to your table's, or drop its `columns` key and pass the columns with `searchIn()`:

```php
// config/fuzzy-search.php — the users preset without its columns
'users' => [
    'algorithm' => 'levenshtein',
    'typo_tolerance' => 2,
    'accent_insensitive' => true,
],
```

```php
User::search('john')->preset('users')->searchIn(['name' => 10, 'email' => 8])->get();
```

#### Override Preset Settings

```php
// Use blog preset but with higher typo tolerance
Post::search('laravel')
    ->preset('blog')
    ->typoTolerance(3)  // Override preset's default of 2
    ->get();
```

#### Create Custom Presets

```php
// config/fuzzy-search.php
'presets' => [
    'documents' => [
        'columns' => ['title' => 10, 'content' => 8, 'tags' => 5],
        'algorithm' => 'trigram',
        'typo_tolerance' => 2,
        'stop_words_enabled' => true,
        'locale' => 'en',
    ],
],
```

```php
Document::search('report')->preset('documents')->get();
```

### Per-Model Customization

```php
class Product extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns' => [
            'title' => 10,
            'description' => 5,
            'sku' => 8,
        ],
        'algorithm' => 'fuzzy',
        'typo_tolerance' => 2,
        'stop_words' => ['the', 'a', 'an'],
        'synonyms' => [
            'laptop' => ['notebook', 'computer'],
        ],
        'accent_insensitive' => true,
    ];

    // Custom scoring logic: runs once per row before normalisation, on the LIKE, extended and index paths
    public function getSearchScore(float $baseScore): float
    {
        return $this->is_featured ? $baseScore * 1.5 : $baseScore;
    }
}
```

---

## CLI Tools

### Indexing Commands

```bash
# Build / rebuild BM25 index for a model
php artisan fuzzy-search:rebuild "App\Models\User"

# Rebuild with fresh index (flush first)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh

# Rebuild asynchronously (for large tables)
php artisan fuzzy-search:rebuild "App\Models\User" --fresh --async --queue=indexing

# Remove a model's index entries (the same as fuzzy-search:clear "App\Models\User")
php artisan fuzzy-search:flush "App\Models\User"

# Clear BM25 index for a model
php artisan fuzzy-search:clear "App\Models\User"

# Clear BM25 index for all models
php artisan fuzzy-search:clear --all

# Show index status (row counts, avg doc length, term count per model) and list postings that predate column weighting
php artisan fuzzy-search:status
```

`--async` dispatches a Laravel job batch, which needs the `job_batches` table: create it once with `php artisan make:queue-batches-table` (Laravel 10: `php artisan queue:batches-table`) and `php artisan migrate`. Without it, the command stops before touching the index.

Run `flush`, `clear` and `rebuild --fresh` (which flushes first) while nothing is indexing any model; see [Artisan Commands](docs/bm25.md#artisan-commands) for why.

Every command exits with status 1 when it cannot act on its input: a class that is not an Eloquent model (for `rebuild`, one it cannot index; for `benchmark` and `explain`, one without the `Searchable` trait), `--iterations` below 1, a `--days` below 0 or a `--limit` below 1 (or either one not a whole number), or an `add-shadow-column --type` other than `metaphone`.

### Benchmark & Debug Commands

`fuzzy-search:analytics` and `fuzzy-search:analytics:prune` are documented under [Persisted Search Analytics](#persisted-search-analytics).

```bash
# Benchmark search performance
php artisan fuzzy-search:benchmark "App\Models\User" --term="john" --iterations=100

# Explain a search query
php artisan fuzzy-search:explain User --term="john"
```

---

## Performance & Scaling

### Algorithm Comparison

| Algorithm | Speed | Typo Tolerance | Best For | Dataset Size |
|-----------|-------|----------------|----------|--------------|
| **simple** | Fastest | None | Exact matches, SKUs | Any size |
| **fuzzy** | Very Fast | High | General purpose | < 100K rows |
| **soundex** | Very Fast | Phonetic | Name searches | < 100K rows |
| **trigram** | Fast | Very High | Similarity matching | < 50K rows |
| **levenshtein** | Medium | Configurable | Precise typo matching | < 50K rows |
| **BM25 index** | Fast at scale | Native (dictionary expansion) | Large tables, ranked results | 10K+ rows |

### Indicative Latency (100k-row MySQL 8.0 table)

Indicative medians from a 100k-row MySQL 8.0 table on a commodity VPS with a warm cache — a guide to how the paths compare, not a guaranteed result. The [demo project](https://github.com/ashiqfardus/laravel-fuzzy-search-demo) seeds a comparable dataset, 100k rows per model, with `php artisan db:seed --class="Database\Seeders\LargeDatasetSeeder"` (its `demo:seed` seeds ~150 sample rows, and `--huge` 1M users). Measure your own tables with `php artisan fuzzy-search:benchmark`.

| Search path | Median latency | Notes |
|---|---|---|
| LIKE (`using('simple')`) | ~8 ms | Full table scan |
| Levenshtein (`using('levenshtein')`) | ~45 ms | PHP re-score over 1,000 SQL candidates |
| BM25 inverted index (`useInvertedIndex()`) | ~12 ms | Three parameterised SQL queries + PHP BM25 scoring |
| Extended syntax (`->extended()`) | ~15 ms | Includes AST compilation and multi-operator SQL generation |

**At scale:** The BM25 path uses an indexed term lookup — query time grows with the number of matching postings, not total row count. A well-maintained 1M-row index should therefore stay close to the 100k figures above. Typo expansion adds one dictionary query per query term whose cost grows with dictionary size, and since 2.1 it is scoped to the searched model's own terms: roughly 16 ms per term on PostgreSQL and 50 ms on MySQL, measured at ~220k distinct terms across two models (about 1.6–1.8× the unscoped lookup); call `typoTolerance(0)` on latency-critical searches.

### When to Use BM25 vs LIKE

**Use BM25 (`useInvertedIndex()`) when:**
- Table has 10k+ rows
- Result ranking/relevance quality matters
- You have queue workers running

**Use LIKE / fuzzy when:**
- Small tables (< 10k rows) — LIKE can be faster due to BM25 scoring overhead

### `max_candidates` Tuning

For the LIKE/Levenshtein paths, SQL candidates are fetched then re-scored in PHP. The candidate set size is controlled by `max_candidates` (default: 1000). Lower this on large tables to reduce memory usage:

```php
// config/fuzzy-search.php
'max_candidates' => 500,  // top-level key; fetch fewer candidates on large tables
```

### Recommended Optimizations

```php
// For 100k+ rows: enable BM25 index + cache + limit columns
User::search('john')
    ->useInvertedIndex()
    ->cache(60)
    ->searchIn(['name', 'email'])
    ->maxPatterns(50)
    ->get();
```

Key tips:
1. **Use the BM25 index** for tables with 10k+ rows
2. **Enable caching** for repeated searches
3. **Limit columns** — only search relevant fields
4. **Use `simple` algorithm** when typo tolerance isn't needed
5. **Set `max_candidates`** to prevent excessive memory usage on large tables
6. **Use `take()`** to cap result sets
7. **Eager load relationships** to avoid N+1 queries

### Scaling Recommendations

| Records | Recommended Strategy | Expected Query Time |
|---------|---------------------|---------------------|
| < 50K | Default (no optimization) | < 50ms |
| 50K - 100K | Add DB indexes + cache | < 100ms |
| 100K - 500K | BM25 index + cache | < 150ms |
| 500K - 1M | BM25 index + partitioning + cache | < 200ms |
| 1M - 10M | BM25 + read replicas + tiered cache | < 300ms |
| > 10M | Consider Meilisearch / Typesense | — |

---

## Algorithm × Database Compatibility

This table shows what each algorithm does at the SQL level on each supported database. "Native" = the database's own function. "Pattern fallback" = PHP generates LIKE patterns.

| Algorithm | MySQL 8 | MariaDB 11.4 (CI); 10.6+ expected | PostgreSQL 14 | SQLite | SQL Server |
|---|---|---|---|---|---|
| **simple** / **like** | `LIKE '%term%' ESCAPE '!'` | `LIKE '%term%' ESCAPE '!'` | `ILIKE '%term%'` | `LIKE '%term%' ESCAPE '!'` | `LIKE '%term%' ESCAPE '!'`; case-insensitivity follows the column's collation |
| **fuzzy** | LIKE pattern set (typo patterns, transpositions) | LIKE pattern set | ILIKE pattern set | LIKE pattern set | LIKE pattern set |
| **levenshtein** | Native `LEVENSHTEIN()` UDF if `use_native_functions=true`, else pattern set | Same as MySQL | `similarity()` via pg_trgm if `use_native_functions=true`, else pattern set | Pattern set | Pattern set |
| **trigram** | LIKE pattern set | LIKE pattern set | Native `similarity()` via pg_trgm if `use_native_functions=true`, else ILIKE pattern set | LIKE pattern set | LIKE pattern set |
| **soundex** | Native `SOUNDEX()` — always on, applied to first or last word | Native `SOUNDEX()` — always on | Native `SOUNDEX()` via `fuzzystrmatch` if `use_native_functions=true`, else pattern fallback | Pattern fallback | Pattern fallback |
| **metaphone** | Shadow column `{col}_metaphone` + exact `=` match | Shadow column | Shadow column | Shadow column | Shadow column |
| **similar_text** | `LIKE '%term%'` and `CHAR_LENGTH(col) <= ?` (the `min_percentage` bound); `similar_text()` scores in PHP after fetch | Same | `ILIKE '%term%'` and `CHAR_LENGTH(col) <= ?`; PHP scores | `LIKE` and `LENGTH(col) <= ?` | `LIKE` and `LEN(CAST(col AS NVARCHAR(MAX)) + N'x') - 1 <= ?` |

MariaDB behaves as MySQL 8 for every algorithm (native SOUNDEX/LEVENSHTEIN paths included).

### Notes

- **Literal `%` and `_`:** the package escapes them in a search term so they match themselves. PostgreSQL escapes with a backslash, its LIKE default, and escapes a backslash in the term too. SQLite and SQL Server have no default escape character, and MySQL and MariaDB lose theirs under the `NO_BACKSLASH_ESCAPES` SQL mode, so on all four the package escapes with `!` (a `!` in the term included, and on SQL Server a `[`, which is a wildcard there) and every LIKE a search writes carries `ESCAPE '!'`, which works in every SQL mode, pattern sets included (only the deprecated, unused `getRelevanceExpression()` / `getRelevanceBindings()` driver methods predate this).
- **`use_native_functions`** in `config/fuzzy-search.php` gates optional DB extensions. MySQL `SOUNDEX()` is built-in and always active — no flag needed. The flag is only relevant for: Levenshtein UDF (MySQL), pg_trgm/fuzzystrmatch (PostgreSQL), unaccent (PostgreSQL).
- **Levenshtein UDF (MySQL):** Not installed by default. See [this gist](https://gist.github.com/yohgaki/9315991) or your DB package manager.
- **pg_trgm (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS pg_trgm;`
- **fuzzystrmatch (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;`
- **unaccent (PostgreSQL, for an explicit `accentInsensitive()`):** `CREATE EXTENSION IF NOT EXISTS unaccent;` + `use_native_functions=true`. `unaccent(col) ILIKE unaccent(?)` is then ORed beside the chosen algorithm. It runs only when the search opts in explicitly (`->accentInsensitive()`, `$searchable['accent_insensitive']` or a preset); the global `unicode.accent_insensitive` default never uses it. Without the extension an explicit opt-in fails with `function unaccent(…) does not exist`.
- **MySQL accent insensitive:** Use `utf8mb4_unicode_ci` or `utf8mb4_0900_ai_ci` collation on the column.
- **`similar_text` under an accent-insensitive collation:** on MySQL/MariaDB with `utf8mb4_unicode_ci` or `utf8mb4_0900_ai_ci`, `similar_text`'s LIKE also matches accent variants (`Jöhn` for `john`). The `min_percentage` length bound still applies to them: an accent variant has the same length, so the bound approximates PHP's percentage there.
- **`similar_text.min_percentage`:** a match contains the term, so its `similar_text()` percentage is `200·t / (t + v)` for a `t`-character term and a `v`-character value, counted in characters. PHP's `similar_text()` counts bytes, so for single-byte text the bound is exactly its percentage, and for multibyte text a close approximation. The bound keeps values of at most `t·(200 − p) / p` characters: at the default 70, about 1.86 times the term's length. Under `tokenize()` the whole search term's length sets the bound for every token (whole-value similarity), so `john doe` still finds `John Doe`. On SQL Server a character outside the BMP counts as 2. `0` turns the bound off and restores 2.0's results.
- **Metaphone shadow column:** Run `php artisan fuzzy-search:add-shadow-column {Model} {column} --type=metaphone`, then `php artisan migrate`, then `php artisan fuzzy-search:rebuild {Model}` to fill it for existing rows (see [Shadow Columns](#shadow-columns)).

### PHP-Side Scoring

Regardless of algorithm, after SQL candidates are fetched:

1. `similar_text()` and `levenshtein()` run in PHP on each candidate, on at most the first 255 characters of the value and of the term (both are O(n·m)); a value within that length scores exactly as it always has. An `extended()` query scores each leaf term on its own and adds the scores up; the similarity/Levenshtein floor is spent only while the leaf terms' total length stays within 255 characters (the first leaf always gets it), and the remaining leaves score by their exact, prefix or contains tier alone, so a many-term query costs about one capped comparison per value. While accent folding is on, an accented term and its accent-free form count once: a row scores the better of the two, never their sum, and in an `extended()` query a leaf and its folded form are one leaf for scoring. With folding off, `müller | muller` are two separate terms and add up.
2. Results are re-sorted by the combined PHP score (higher = better), unless `orderBy()` set an explicit order.
3. `limit/offset` is applied on the PHP-sorted collection (not in SQL).

Top-N results are always the most relevant N from the candidate set (not just the first N SQL rows). Candidate set size is controlled by `max_candidates` (default: 1000).

> **Pagination note:** `paginate()` ranks globally across up to `max_candidates` rows before slicing; pages beyond that window use database ordering.

---

## Testing

```bash
# Run tests
composer test

# Run with coverage
composer test-coverage

# Run the Performance test suite (timing and memory bounds; CI does not run it)
composer benchmark
```

To time searches on your own app's tables, use `php artisan fuzzy-search:benchmark "App\Models\User" --term="john"` (see [CLI Tools](#cli-tools)).

---

## Requirements

- PHP 8.1 – 8.5
- Laravel 10.x, 11.x, 12.x or 13.x. CI tests these PHP × Laravel pairs:

  | PHP | Laravel |
  |---|---|
  | 8.1 | 10 |
  | 8.2 | 10, 11, 12 |
  | 8.3 | 10, 11, 12, 13 |
  | 8.4 | 10, 11, 12, 13 |
  | 8.5 | 12, 13 |

  SQLite runs every pair. MySQL 8 and PostgreSQL 14 run the PHP 8.2–8.5 pairs, and MariaDB 11.4 and SQL Server 2022 the PHP 8.3–8.5 pairs, with Laravel 10, 12 and 13.
- Any supported database — MySQL 8+, MariaDB 11.4 (CI); 10.6+ expected, PostgreSQL 14+, SQLite, or SQL Server 2022
- Optional: Laravel Scout 10.x or 11.x for the [Scout driver](#scout-driver) (semantic and hybrid search are not supported); Filament 3.3, 4.x or 5.x for the [Filament integration](#filament-integration)

---

## License

MIT License. See [LICENSE](LICENSE) for more information.

## Credits

- [Md Asikul Islam](https://github.com/ashiqfardus)

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.
