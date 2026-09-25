# Extended Search Syntax Reference

[← Back to the README](../README.md)

---

## Extended Search Syntax

Use Fuse.js-style operators inside your search string for precise control over matching.

### Operators

| Token | Meaning | Example |
| --- | --- | --- |
| `word` | Substring match (default) | `john` |
| `'word` | Explicit substring include | `'admin` |
| `=word` | Exact equality | `=John` |
| `^word` | Prefix match | `^Doe` |
| `word$` | Suffix match | `Sr$` |
| `!word` | Exclude (NOT); a row whose searchable columns are NULL is kept | `!banned` |
| `!^word` | Inverse prefix | `!^test` |
| `!word$` | Inverse suffix | `!@spam.com$` |
| `\|` | OR | `john \| jane` |
| ` ` (whitespace) | AND (implicit) | `=John ^Doe` |
| `( ... )` | Grouping | `admin (john \| jane)` |
| `"phrase"` | Quoted single token | `"hello world"` |
| `~word` | Typo-tolerant match (uses typoTolerance()) | `~jonh` |
| `field:word` | Limit a term to one column (any operator after the colon except `!`, which goes before the field) | `email:^admin`, `author.name:smith`, `!name:bob` |

### Typo-tolerant and field-scoped terms

`~word` runs the term through the same typo-tolerant matching as the rest of the package — the level set by `->typoTolerance()` (default 2), or a plain substring when the level is `0` or `config('fuzzy-search.typo_tolerance.enabled')` is `false`. `~` can't combine with `'`, `=`, `^`, a quoted phrase, or a trailing `$`; `~word` stands on its own (a field scope in front is fine — `name:~jonh`).

`field:word` limits a term to one searchable column: a direct column, a table-qualified column matched by its bare name (`users.name` answers to `name:`), or a relation column declared in `searchIn()` / `$searchable['columns']` (`author.name:smith`). Any operator can follow the colon — `email:^admin`, `name:~jonh`, `name:"john doe"` — except `!`: negation goes before the field (`!name:bob`), and `name:!bob` throws `QuerySyntaxException` saying so. An unknown field throws `QuerySyntaxException` listing the searchable fields (by their bare names); `field:` with nothing after the colon throws too, and so does a bare name that matches two searchable columns (`users.name` and `profiles.name`) — qualify it, `users.name:john`.

`!`, `~` and `field:` are only recognised at the start of a token (`~` and `field:` after an optional `!`), so `12:30`, `jo~hn` and `yahoo!mail` stay literal — and so does a quoted phrase. A `!` negates only as the first character of a token; anywhere else (inside or at the end of a word, or after another operator: `^!a` is a prefix search for `!a`, `!!a` excludes `!a`) it is part of the term. The one exception is right after a field scope's colon: `name:!john` throws and asks for `!name:john`, since a scope's negation belongs before the field. Quote a token of the form `word:…`, or one starting with `~` or `!`, to keep it literal (`"name:john"`, `"~x"`, `"!x"`). The everyday casualties are URLs and mail addresses at the start of a token (`http://example.com`, `mailto:bob@example.com` — `http:` and `mailto:` are read as field scopes) and `Re:` / `Fwd:` subject prefixes (nothing follows the colon, so they throw); quote them — `"http://example.com"`, `"mailto:bob@example.com"`, `"Re:" meeting`.

The extended syntax always runs on the LIKE path; `->useInvertedIndex()` is ignored for it — `getDebugInfo()` reports `index_ignored => true` when both are set.

### Usage

```php
// Exact first name + prefix last name + exclude banned
$users = User::search('=John ^Doe !banned')->extended()->get();

// OR semantics with grouping
$users = User::search('admin (john | jane)')->extended()->get();

// More examples
'Sr$ | Jr$'              // Names ending in Sr OR Jr
"'manager !@temp.com$"   // Substring 'manager' but not @temp.com emails
```

Relevance scores each positive leaf term on its own and adds the leaf scores up; an explicit `orderBy()` replaces the relevance order, and `stableRanking()` adds the primary key as the final tiebreak. While accent folding is on, a leaf and its accent-free form count once (the better of the two); with the shipped `unicode.accent_insensitive` default, `Müller` also matches `Muller`, and `!Müller` excludes both forms.

### Limits

| Limit | Default | Config key |
| --- | --- | --- |
| Maximum tokens per query (words, `\|` and parentheses each count) | 32 | `query.max_tokens` |
| Maximum nesting depth | 16 | `query.max_depth` |
| Maximum characters per term | 128 | `query.max_term_length` |

`query.max_term_length` applies to the LIKE path, to every extended-syntax token, and to the `whereFuzzy`-style query macros, the `Fuzzy` scopes and `tableSearch()`: a longer term is silently truncated before the driver generates its LIKE patterns, and no driver builds more than `max_patterns` of them.

A query's LIKE patterns share one budget: a search binds at most 2,000 values of its own, on every database, so SQL Server's 2,100-parameter limit holds. Each `~word` leaf gets up to `max_patterns` patterns per column. When the leaves together would pass the budget, each leaf and column gets an equal share, always including its plain contains pattern. A query too large even for that throws `QuerySyntaxException`. The 32-token `query.max_tokens` limit on two columns fits. The `whereFuzzyMultiple()`-style macros, the `Fuzzy` scopes and `tableSearch()` share the same budget across their columns.

### Pagination with Extended Syntax

`paginate()`, `simplePaginate()` and `get()` all work with `extended()` / `searchBoolean()`. `cursorPaginate()` is still unsupported.

```php
// ✓ Works
User::search('=John ^Doe')->extended()->paginate(15);
User::search('=John ^Doe')->extended()->simplePaginate(15);
User::search('=John ^Doe')->extended()->get();

// ✗ Throws BadMethodCallException
User::search('=John ^Doe')->extended()->cursorPaginate(15);
```

### Match Offsets & Blade Directive

Results with `->highlight()` enabled include a `_matches` array:

```php
$first = $results->first();
$first->_matches;
// [['column' => 'name', 'value' => 'John Doe', 'indices' => [[0, 3]]]]
```

Each `[start, end]` pair is an inclusive **byte** range into `value` — slice it with `substr()`, not `mb_substr()`. Matching is case-insensitive per character, so `привет` marks `ПРИВЕТ` in `ПРИВЕТ мир` as `[[0, 11]]` (six two-byte letters).

For safe HTML rendering, use the `@fuzzyHighlight` Blade directive:

```blade
@fuzzyHighlight($user, 'name')
```

The directive automatically escapes user-supplied content and wraps matches in `<mark>` tags.

---

