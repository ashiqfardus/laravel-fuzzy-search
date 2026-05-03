# Extended Search Syntax

> Available from v2.0.0-alpha.3

Use Fuse.js-style operators inside your search string for precise control over matching.

## Operators

| Token | Meaning | Example |
| --- | --- | --- |
| `word` | Fuzzy substring match (default) | `john` |
| `'word` | Substring include | `'admin` |
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

## Usage

```php
// Exact first name + prefix last name + exclude banned
$users = User::search('=John ^Doe !banned')->extended()->get();

// OR semantics with grouping
$users = User::search('admin (john | jane)')->extended()->get();

// Fluent alternative — pass query directly
$users = User::search('')->extended('=John ^Doe')->get();
```

## Example queries

```php
'=John ^Doe !banned'              // Exact 'John' + prefix 'Doe' + not banned
'admin (john | jane)'             // 'admin' AND (john OR jane)
'Sr$ | Jr$'                       // Names ending in Sr OR Jr
"'manager !@temp.com$"            // Substring 'manager' but not @temp.com emails
```

## Limits

| Limit | Default | Config key |
| --- | --- | --- |
| Maximum tokens per query | 32 | `query.max_tokens` |
| Maximum nesting depth | 16 | `query.max_depth` |

These prevent adversarial DoS queries.

## Match offsets

Results with `->highlight()` enabled (including `extended()` with highlighting) include a `_matches` array:

```php
$first = $results->first();
$first->_matches;
// [
//   ['column' => 'name', 'value' => 'John Doe', 'indices' => [[0, 3]]],
// ]
```

For safe HTML rendering use the `@fuzzyHighlight` Blade directive:

```blade
@fuzzyHighlight($user, 'name')
```

The directive automatically escapes user-supplied content and wraps matches in `<mark>` tags.
