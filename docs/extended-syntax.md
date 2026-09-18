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
| `!word` | Exclude (NOT) | `!banned` |
| `!^word` | Inverse prefix | `!^test` |
| `!word$` | Inverse suffix | `!@spam.com$` |
| `\|` | OR | `john \| jane` |
| ` ` (whitespace) | AND (implicit) | `=John ^Doe` |
| `( ... )` | Grouping | `admin (john \| jane)` |
| `"phrase"` | Quoted single token | `"hello world"` |
| `~word` | Typo-tolerant match (uses typoTolerance()) | `~jonh` |
| `field:word` | Limit a term to one column (any operator after the colon) | `email:^admin`, `author.name:smith`, `!name:bob` |

### Typo-tolerant and field-scoped terms

`~word` runs the term through the same typo-tolerant matching as the rest of the package — the level set by `->typoTolerance()` (default 2), or a plain substring when the level is `0` or `config('fuzzy-search.typo_tolerance.enabled')` is `false`. `~` can't combine with `'`, `=`, `^`, a quoted phrase, or a trailing `$`; `~word` stands on its own (a field scope in front is fine — `name:~jonh`).

`field:word` limits a term to one searchable column: a direct column, a table-qualified column matched by its bare name (`users.name` answers to `name:`), or a relation column declared in `searchIn()` / `$searchable['columns']` (`author.name:smith`). Any operator can follow the colon — `email:^admin`, `name:~jonh`, `!name:bob`, `name:"john doe"`. An unknown field throws `QuerySyntaxException` listing the searchable fields (by their bare names); `field:` with nothing after the colon throws too, and so does a bare name that matches two searchable columns (`users.name` and `profiles.name`) — qualify it, `users.name:john`.

Both operators are only recognised at the start of a token (after an optional `!`), so `12:30` and `jo~hn` stay literal — and so does a quoted phrase. Quote a token of the form `word:…`, or one starting with `~`, to keep it literal (`"name:john"`, `"~x"`). The everyday casualties are URLs and mail addresses at the start of a token (`http://example.com`, `mailto:bob@example.com` — `http:` and `mailto:` are read as field scopes) and `Re:` / `Fwd:` subject prefixes (nothing follows the colon, so they throw); quote them — `"http://example.com"`, `"mailto:bob@example.com"`, `"Re:" meeting`.

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

### Limits

| Limit | Default | Config key |
| --- | --- | --- |
| Maximum tokens per query | 32 | `query.max_tokens` |
| Maximum nesting depth | 16 | `query.max_depth` |
| Maximum characters per term | 128 | `query.max_term_length` |

`query.max_term_length` applies to the LIKE path and to every extended-syntax token: a longer
term is silently truncated before the driver generates its LIKE patterns.

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

For safe HTML rendering, use the `@fuzzyHighlight` Blade directive:

```blade
@fuzzyHighlight($user, 'name')
```

The directive automatically escapes user-supplied content and wraps matches in `<mark>` tags.

---

