# Usage guide

Everyday use beyond the Quick Start: Eloquent chaining, relationships, text processing, results, pagination, safety, events and the integrations.

## Columns

### Auto-detection

A model that declares no `$searchable['columns']` is searched on common column names, in the priority order the [Quick Start](../README.md#zero-config-search) lists (`name`, `title`, `email`, …). If none of these exist, it falls back to the first five text columns of the model's `$fillable`, in its order (declare `$searchable['columns']` for more), then to the first remaining column. Only text columns qualify (char, varchar, text of any size, nvarchar, citext, and MySQL enum/set, matched by the exact type name): a numeric, boolean or date column, or a PostgreSQL enum, is never auto-detected, in any branch, so a `$fillable` `user_id` or `total` is skipped. JSON and UUID columns are skipped where the database has a JSON or UUID type, and detected where it stores them as text (JSON on SQLite, MariaDB and SQL Server; a `char(36)` UUID on MySQL and SQLite). A column the model casts to a JSON type (`array`, `json`, `object`, `collection`, `AsArrayObject`, `AsCollection`, or an `encrypted:` form of them) is never detected, on any database. On SQLite, MariaDB and SQL Server, cast a JSON column, or declare `$searchable['columns']`, to keep its JSON text out of a zero-config search and the index. It never picks a column the model hides from serialization (`$hidden`, or one outside a non-empty `$visible`), a secret-named column (any name containing `password`; `token` or a name ending in `_token`; `secret`, `api_key` or `private_key` as a whole underscore-separated part of the name (`secret_note`, `stripe_api_key`, `webhook_secret` — not `secretary_name`); or a name ending in `recovery_codes`; a broad `*_key` rule is deliberately left out, so `sort_key` stays searchable), `id` or the timestamps. Names match in any letter case. It reads the model class's default `$hidden` and `$visible`, so a column hidden at runtime with `makeHidden()` is still searched and indexed. A model where no column qualifies (every text column hidden, say) has nothing to search: its search matches nothing (no rows, a count of 0, no event), and so does `FuzzySearch::tableSearch()` on it, while `extended()` throws `SearchableColumnsNotFoundException`. Declare `$searchable['columns']` to search it.

### Request-supplied columns

A column name passed to `searchIn()`, `filter()`, `filterIn()`, `facet()` and `orderBy()`, or to the Eloquent `whereFuzzy()`, `orWhereFuzzy()`, `whereFuzzyMultiple()`, `orderByFuzzy()` and `fuzzySearch()` macros may come from the request, so two kinds are refused with `InvalidArgumentException` unless `$searchable['columns']` lists them: a column named like a secret (`password`, `token`, `*_token`, `api_key`, …) in all of them, and a column in the model's `$hidden` (or the related model's, for a path like `author.email`) in `searchIn()`, `facet()` and `orderByFuzzy()`, whose matching, counts or similarity order reveal its content. An equality `filter()`/`filterIn()` or a plain `orderBy()` on a hidden column (a `tenant_id`, a sort field) is allowed. Declaring a column needs the `Searchable` trait: a model without it cannot allow a secret-named column in the `whereFuzzy()`-style macros (add the trait, or search a Query Builder). A table-qualified macro column is compared as written, so declare `users.api_key` if you call it that way. `preset()` columns, `searchOn()`'s third argument, a Filament resource's searchable attributes, a Scout `orderBy()` and `FederatedSearch::searchIn()` on a model without the trait are checked the same way. None of this applies to a Query Builder source (`DB::table()`), where no column is checked: allowlist request-supplied columns yourself.

## Querying

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

The search holds for every condition you forward: `->where('status', 'active')->orWhere('featured', true)` reads `(status = active or featured) and <the search>`, as if you had grouped the two yourself, and `filter()` holds for both branches too.

`latest()`, `oldest()`, `inRandomOrder()` and `reorder()` are forwarded to the underlying query: they only shape which rows make it into the candidate window, since the relevance `ORDER BY` is appended after them and PHP-side rescoring re-sorts by `_score` whenever `withRelevance` is on (the default) — call `withRelevance(false)` if you want the forwarded order to stick. The builder's own `orderBy()` is different: it replaces the relevance order on every path (see [Pagination](#pagination)). The closure passed to `when()`, `unless()` or `tap()` receives the underlying Eloquent builder, not the `SearchBuilder`.

Under a join (a forwarded `join()`, one inside `query()`, or a global scope's), the model's own searched columns are qualified with its table (or the FROM alias) automatically, so a joined table with a column of the same name is not ambiguous; to search the joined table's column, write it dotted: `searchIn(['teams.name'])`. Select the model's columns (`select('users.*')`) as with any Eloquent join, or the joined table's same-named columns (`id`, `name`) overwrite the model's attributes, and scoring, highlighting and `useInvertedIndex()` read the wrong values.

### Searching Relationships

Dotted column names — `posts.title`, `author.name`, nested paths — search through Eloquent relations (`belongsTo`, `hasMany`, `belongsToMany`) on the LIKE and extended paths, compiling to `whereHas()` (a portable `EXISTS` subquery):

```php
User::search('smith')->searchIn(['posts.title', 'profile.bio'])->get();
```

A dotted segment is followed as a relation only when it is a public, non-static method with no required parameters that neither Laravel nor this package defines, and that either declares a `Relation` return type (`BelongsTo`, `HasMany`, …) or is on a path the model lists in `$searchable['columns']`. Anything else throws `InvalidArgumentException` — `App\Models\Author::company is not a relation: declare a Relation return type or list the path in $searchable['columns']` — before any SQL runs, and the method is never called: `searchIn()` can carry request input, and `searchIn(['unguard.body'])` must not run `unguard()`. That check stops method calls, not column access: a secret-named column, or one in the model's `$hidden`, is refused by `searchIn()` unless `$searchable['columns']` lists it (see Manual Column Configuration); any other column is searched, so still check request-supplied columns against an allowlist. Nested paths are checked segment by segment, and the error names the segment that failed. A dotted name whose first segment is not a method at all is still a table-qualified column (`posts.title`). Relations defined by Laravel's own traits (for example `Notifiable::notifications()`) are not followed; wrap one in a method of your own with a return type. An undotted `searchIn()` name is never a method call either. On every path (highlighting on `useInvertedIndex()`, PHP scoring, `suggest()` and `FuzzySearch::on()` over models), it is read from the row as an attribute, a cast or an accessor, or as a relation that is already loaded: `searchIn(['reindex'])` reads nothing and runs nothing.

A relation you eager-load yourself keeps your constraint: `->with('author:id,name')` or `->with(['comments' => fn ($q) => $q->where('approved', true)])` is what the results carry, and the search eager-loads only the paths you did not load. Write the relation in `with()` as its method is declared (`author`, not `Author`): Eloquent treats another spelling as a second relation, and the search's own load of `author` is then unconstrained. A `searchIn()` path, by contrast, is matched to its methods whatever its case: `Author.name` is `author.name`.

Your constraint limits what is **loaded**, not which rows **match**. Relation matching sees every related row, so a post still matches through an unapproved comment. To match approved comments only, `searchIn()` a relation method that carries the constraint (`approvedComments.body`, where `approvedComments()` returns `$this->hasMany(Comment::class)->where('approved', true)`).

A column select must include the searched column, and for a nested path the key the next relation needs (`author:id,name,company_id` for `author.company.name`). A column the select leaves out scores nothing and is left out of `_highlighted` and `_matches`.

A relation `searchIn()` reads is loaded even after `without()`. To keep it out of a response, constrain it with `with()`, hide it (`$hidden`/`makeHidden()`), or shape the response with an API resource.

A dotted name whose first part names a table of the query — the FROM table, a joined table, or either one's alias — is always that table's column, and no model method is looked at: `->join('items', …)->searchIn(['items.name'])` works even when the model has an `items()` method. A relation whose name equals a joined table's name is therefore read as that table's column; alias the join (`join('items as i', …)`) if you mean the relation. Such a table's column is searched in SQL; the model row does not carry it, so it adds nothing to `_score` or `_highlighted` unless you select it under the model's own column name.

The BM25 inverted index does not join relations at query time — define `searchableText()` on the model to put related text into the index instead.

`SearchableIndexingObserver` indexes a model only when it has searchable columns — the ones declared in `$searchable['columns']` or, when none are declared, the auto-detected string-like columns. A model with neither is skipped on save, even if it defines `searchableText()`. Auto-detection never selects a column cast to `encrypted` or `hashed`. Nor does it select a column the model hides from serialization (`$hidden`, or any column outside a non-empty `$visible`), or a secret-named column (any name containing `password`; `token` or a name ending in `_token`; `secret`, `api_key` or `private_key` as a whole underscore-separated part of the name (`secret_note`, `stripe_api_key`, `webhook_secret` — not `secretary_name`); or a name ending in `recovery_codes`; a broad `*_key` rule is deliberately left out, so `sort_key` stays searchable), in any letter case. An auto-detected column is indexed as the model's raw attribute value, not through a get accessor, and its `*_metaphone` shadow column is filled from the same value, so an accessor that decrypts or reformats it never reaches the index. Only the index reads the stored value: `suggest()`'s table scan, relevance scoring and highlighting read an auto-detected column through its accessor, as in 2.0, so for a column an accessor decrypts, declare `$searchable['columns']` without it, or hide it (`$hidden`); otherwise `suggest()`'s table scan can return words from the decrypted value of rows the query can see. Declaring a column in `$searchable['columns']`, or overriding `getSearchableColumns()`, is what opts into its accessor. A column you *declare* with the `encrypted` cast has its **decrypted** text written to the index, and `suggest()` and `didYouMean()` serve it.

Auto-detection does not keep a column out of the index in these cases. Put the column in `$hidden`, or declare `$searchable['columns']` without it:

- **A masking accessor** (`Str::mask()` on an email) is bypassed: the index, `suggest()` and `didYouMean()` serve the unmasked value, and the shadow column encodes it.
- **Encryption that decrypts into the model's attributes in memory** (spatie/laravel-ciphersweet does this when a model is retrieved) is invisible to the package: the plaintext is indexed, while the LIKE search matches the ciphertext in the database.
- **A column hidden at runtime** (`makeHidden()`, `setHidden()`, or a `getHidden()` that changes per request) is still searched and indexed: detection reads the model class's default `$hidden` and `$visible`, once per process. Highlighting reads the row's own state, so a column the row hides is not highlighted (see [Highlighted Results](#highlighted-results)).

→ Full guide: [docs/relationships.md](relationships.md)

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

`whereFuzzyMultiple()`, `fuzzySearch()`, the `Fuzzy` scopes and `FuzzySearch::tableSearch()` bind at most 2,000 values of their own, as a search does, so SQL Server's 2,100-parameter limit holds; your own `where()` values are not counted. Each column gets up to `max_patterns` LIKE patterns. When the columns together would pass 2,000, each gets an equal share and always keeps its plain contains pattern. A call with too many columns for even one pattern each throws `QuerySyntaxException` ("The query is too complex…"). `whereFuzzy()` on one column is bounded by `max_patterns` alone.

---

## Text Processing

- **Stop-word filtering** — `ignoreStopWords()` drops common words from a query: pass a locale (`'de'`; built-in lists cover eight locales) or an array of words. For a list kept in a file, point a `stop_words.{locale}` config entry at it (an absolute path, one word per line) and pass that locale. An `extended()`/`searchBoolean()` query drops no stop words: every word in it stays a term of the query.
- **Synonyms** — `withSynonyms()` and `synonymGroup()` expand a query to related terms. The `synonyms` config key sets the default synonyms (word => its synonyms; case is ignored, so `'Laptop'` and `'laptop'`, or `'Ägypten'` and `'ägypten'`, are the same word, and the later one set wins; `synonymGroup()` words ignore case too) of every `SearchBuilder` search — `Model::search()`, a query-builder source, a `Searchable` model in `FederatedSearch`, Filament global search — as if `withSynonyms()` were called first. A model's `$searchable['synonyms']` and a query's `withSynonyms()` are merged on top, and a word they also set takes their synonyms. An `extended()`/`searchBoolean()` query applies no synonyms, from any of these sources: its terms are the ones the user wrote, and expanding them would change what its AND requires. The Scout engine, `FuzzySearch::on()` and the query-builder macros (`whereFuzzy()`, the `Fuzzy` scopes, `tableSearch()`) apply none either.
- **Per-locale stop words** — `ignoreStopWords('de')` picks a locale's list at query time and `$searchable['locale']` picks one for a model's index pipeline. The top-level `locale` config key (`'en'` by default) picks the list every other model's index drops (and the query-time list of a preset with `stop_words_enabled`), so out of the box the index drops the 14 English words of `stop_words.en`; to index every word, set `locale` to a code with no `stop_words` entry, or empty that locale's list, and rebuild. (`locale()` on the builder never selected either; it is deprecated since v3.0.0 and does nothing.)
- **Unicode & accent insensitivity** — on by default (`unicode.accent_insensitive`): a term is also searched in its accent-free form, beside the typed one, so `Müller` finds `Zoë Müller` and `Muller`, and `café` finds `cafe`. The other way round, `cafe` finding `Café` as a substring match (`simple`/`like`) needs the column folded, which the package does only through the database (the typo-tolerant algorithms may still reach `Café` as a one-letter typo): an accent-insensitive collation on MySQL/MariaDB (`utf8mb4_unicode_ci`, `utf8mb4_0900_ai_ci`), or `accentInsensitive()` on PostgreSQL with the unaccent extension and `use_native_functions=true` (see Notes). SQLite, and PostgreSQL without native functions, cannot fold the column side; SQL Server follows the column's collation. Where the database folded the match, scoring and highlighting fold the value too, so `jose` ranks `José Ramírez` as the prefix match it is and highlights `José` (the fold also takes `ł`, `đ`, `æ`, `œ` and `þ` to `l`, `d`, `ae`, `oe` and `th`, as MySQL 8's `utf8mb4_0900_ai_ci`, MariaDB's `uca1400_ai_ci` or `unaccent()` does, so `lukasz` highlights `Łukasz`): on MySQL/MariaDB when the connection's `collation` is accent-insensitive (any but a `_bin`, `_cs` or `_as_` one; it is the connection's setting that is read, not each column's), and on PostgreSQL with that opt-in. On SQL Server an `_AI` collation is not read, so a row it matched that way is scored and highlighted against the term as typed: it ranks lower and is untagged. `unicodeNormalize()` NFC-normalises the term (needs ext-intl), so a decomposed `naïve` finds a stored precomposed `naïve`; `naïve` finding `naive` comes from the accent folding above. Text is handled per character, not per byte, so combining marks stay attached to their base letters.
- Index-time options — the tokenizer, per-model pipelines, accent folding on the index, and optional stemming — sit apart from the query-time behavior above; changing any of them needs `php artisan fuzzy-search:rebuild "App\Models\YourModel" --fresh`.

→ Full guide: [docs/tokenization.md](tokenization.md)

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

Set `highlighting.enabled = true` in the config to highlight every search without calling `highlight()`. A value is highlighted at up to `highlighting.max_matches` occurrences (100; `0` for no limit), and the rest of it follows untagged, so a long text that repeats the term cannot blow up `_matches` and `_highlighted`.

Every value in `_highlighted` is safe to render as HTML: a matched column is wrapped in the highlight tag (and escaped first), and — since v3.0.0 — a column that did not match is HTML-escaped too, so the whole array can be echoed with `{!! !!}` without an extra `e()` call.

`_highlighted` and `_matches` hold only columns the row's `toArray()` would show: a column in `$hidden`, or outside a non-empty `$visible`, is still searched and scored but never highlighted. For a relation column the related model's `$hidden`/`$visible` apply too, and so does the parent hiding the relation (`author.name` is left out when `Author` hides `name` or the post hides `author`). The row's own state is read when the search runs, so `makeHidden()`/`makeVisible()` in a `retrieved` listener counts, and `FuzzySearchResource` applies the row's rule again when it renders, so a `makeHidden()` after the search holds there as well, and so does an `unsetRelation()`: a relation path the row no longer holds a related row for is left out. The same rule keeps hidden columns out of `debugScore()`'s `_debug` (`columns`, `weights` and `column_scores`), and `suggest()`'s table scan never offers a word from a hidden column: a column the model hides by `$hidden`/`$visible` is left out of the scan's matching, so rows that match only through it do not crowd out the rows that can yield a suggestion, and a model whose every searchable column is hidden gets `[]` without a query.

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

`column_scores` is each column's score times its weight (`john` is a prefix of both values, worth `scoring.prefix_match` = 80: 80 × 10 and 80 × 5), and `final_score` is the row's `_score`. `prefix_boost` is what `prefixBoost()` set (1.0 when not called). On `useInvertedIndex()` `algorithm` is `bm25` and `column_scores` is empty, because BM25 scores whole documents. `algorithm` is `extended` for an `extended()` query.

---

## Pagination

### Stable Ranking

```php
// Results maintain consistent order across pages
$page1 = User::search('john')->stableRanking()->paginate(10, page: 1);
$page2 = User::search('john')->stableRanking()->paginate(10, page: 2);
```

An explicit `orderBy()` replaces the relevance order on every path — LIKE, `extended()` and `useInvertedIndex()` — and on every terminal: rows come back in that order (several calls apply in the order given), `_score` is still attached, and `stableRanking()` adds the primary key ascending as the final tiebreak unless an `orderBy()` already names the key (on the LIKE and extended paths, under a join that repeats a model, the selected columns follow it: see the pagination note). The relevance order applies only when no `orderBy()` was set. `?page` is read as a whole number of at least 1 on every paginator: `?page=abc`, `?page=0`, `?page=-3` and `?page[]=1` are page 1; likewise `page(0)` or a negative page is page 1 and `skip(-n)` is `skip(0)`. On the LIKE and extended paths `get()` and `first()` of such a search, and of one with `withRelevance(false)`, read only the rows up to the end of the page, where a relevance-ranked search reads up to `max_candidates` to rescore them; `_score` is then normalised over the rows read.

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

When the primary algorithm matches no rows, the same search is re-run with each fallback in order until one matches. A page past the primary's matches, such as `simplePaginate()` page 2 or a `skip()`, is empty: a fallback runs only when the primary matched nothing at all, never to fill a page. Works with `get()`, `first()`, `paginate()`, `simplePaginate()` and `count()`; filters and `where()` constraints carry over to each attempt.

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

One search binds at most 2,000 values of its own, on every database, so SQL Server's 2,100-parameter limit holds, with 100 to spare for your own `where()` and `filter()` values; those are not counted. Each term (every word under `tokenize()`, every `extended()` leaf, its accent-free form, and each synonym, which an `extended()` query does not add) is matched on each column with up to `max_patterns` LIKE patterns. When the patterns would pass 2,000 bindings, every term-and-column pair gets an equal share and always keeps its plain contains pattern, so a very long query matches fewer typo variants instead of failing. A query that needs more than 2,000 values even at one pattern per pair throws `QuerySyntaxException` ("The query is too complex…"). A query within the budget builds the same SQL as before. The multi-column helpers (`whereFuzzyMultiple()`, `fuzzySearch()`, the `Fuzzy` scopes, `tableSearch()`) share the same budget across their columns.

> `debounce()` is deprecated and does nothing: a request that has already reached the server cannot be debounced. Debounce on the client instead (`wire:model.live.debounce.300ms` in Livewire, or a timer in JavaScript). It will be removed in v4.0.0.

### Multi-tenant isolation

A tenant `where()` or `filter()` scopes the rows a search returns, but the BM25 index statistics and the posting cap are model-wide, so tenants sharing one index can influence each other's ranking and learn whether a word occurs. `suggestFrom('index')` is model-wide too: it ignores `where()`, `filter()`, `filterIn()` and every scope, so use it only where every caller may see every row's terms (the default `'auto'` honours them). `searchIndexType()` cannot name a per-tenant index (it throws for anything but the model's own class or a same-table parent). For strict isolation do not rank across tenants on one index: use the LIKE algorithms, a database or schema per tenant, or a model class per tenant; see [Multi-tenant isolation](bm25.md#multi-tenant-isolation). Search analytics stores raw terms and has no tenant column: see its [privacy note](analytics.md#privacy).

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
- `EmptySearchTermException` - Search term is empty (or only whitespace) while `allow_empty_search` is false — thrown by every terminal: `get()`, `first()`, `paginate()`, `simplePaginate()`, `count()` and `getFacets()`, on the LIKE and index paths, and by `FuzzySearch::on()`'s `get()` (with `allow_empty_search` on, every one of them lists every row); `extended()`/`searchBoolean()` queries are exempt
- `InvalidAlgorithmException` - Invalid algorithm specified
- `InvalidConfigException` - Configuration error
- `SearchableColumnsNotFoundException` - No searchable columns found
- `QuerySyntaxException` - Invalid `extended()` / `searchBoolean()` query: an unknown or ambiguous field, bad operator syntax (an unbalanced parenthesis, an unterminated quote, a misplaced `~` or `!`), a query with no searchable terms (`|`, `()`, a lone `!`) or a field scope with no term (`name:`), a query of more than `query.max_tokens` tokens (32 by default; every word, `|` and parenthesis counts), or parentheses nested deeper than `query.max_depth` (16)

---

## Events

### `FuzzySearchExecuted`

Fired after every `->get()` or `->paginate()` call, and — since v3.0 — after every in-memory search too (`FuzzySearch::on($items)->search(...)->get()`). Useful for monitoring search latency and volume in production.

An in-memory search called with an empty term (which throws, or with `allow_empty_search` returns the items unsearched) or with no `searchIn()` columns (which returns nothing) fires no event: nothing was searched, so there's nothing to log. A term shorter than `min_search_length` fires none on any search API, in memory or not. Scout searches never fire it, and neither does a `FederatedSearch` model without the `Searchable` trait (the query macros it is searched with fire no event).

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
- `candidateCount` (int) — matches before the page/limit cut: on `get()` (so `first()` and `simplePaginate()` too), the rows fetched for rescoring (at most `max_candidates`), or on `useInvertedIndex()` the index's ranked matches; on `paginate()`, the total match count
- `latencyMs` (float) — total search time in milliseconds
- `resultCount` (int) — rows returned to the caller; `-1` when unknown
- `path` (string) — which code path answered: `like`, `bm25`, `extended`, or `in_memory`
- `modelClass` (`?string`) — the Eloquent model class searched; `null` for query-builder and in-memory searches

---
