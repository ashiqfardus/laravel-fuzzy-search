# Query Language Reference

> Available from v2.0.0-alpha.3

The package supports two equivalent query forms:

## Extended (Fuse-style)

Concise, single-line operators. See [EXTENDED_SEARCH.md](EXTENDED_SEARCH.md) for the full reference.

```php
User::search('=John ^Doe !banned')->extended()->get();
```

## Boolean (alias)

`searchBoolean()` is an alias for `extended()` — same parser, same operators. Use whichever name reads better in your code.

```php
User::search('')->searchBoolean('=John ^Doe !banned')->get();
User::search('')->searchBoolean('admin (john | jane)')->get();
```

## Score normalization

Scores returned in `_score` are normalized to the range `[0, 1]`. The original raw score is preserved as `_raw_score`.

If you used unbounded scores in v1.x (e.g. `_score > 50` thresholds), update to:

- `_score > 0.5` for the new normalized scale, OR
- `_raw_score > 50` for the legacy scale

## In-memory mode

For static collections (config arrays, navigation menus), bypass the database entirely:

```php
$navigation = config('app.navigation');

$matches = FuzzySearch::on($navigation)
    ->search('settings')
    ->searchIn(['label'])
    ->take(5)
    ->get();
```

The `max_items` config caps the collection size to prevent accidental memory bombs (default 10000).
