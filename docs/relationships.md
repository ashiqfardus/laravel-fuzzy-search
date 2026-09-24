# Searching Through Relationships

[← Back to the README](../README.md)

---

### Searching Relationships

Dotted column names search through Eloquent relations — `belongsTo`, `hasMany`, `belongsToMany`, and nested paths — on the LIKE and extended paths; the BM25 index stores related text through `searchableText()` instead (see below):

```php
Post::search('tolkien')
    ->searchIn(['title' => 10, 'author.name' => 5, 'tags.name' => 3, 'comments.author.name' => 1])
    ->highlight('mark')
    ->paginate(15);

$post->_highlighted['author.name'];            // "<mark>Tolk</mark>ien"
@fuzzyHighlight($post, 'tags.name')            // the related row that matched
```

- **Filtering** compiles to `whereHas()` (a portable `EXISTS` subquery); nested paths use the same `whereHas('comments.author', …)` Eloquent supports.
- **Scoring** uses the column's `searchIn()` weight; a to-many relation counts its best related row.
- **Highlighting**, `_matches` and `suggest()` include relation columns under the dotted key.
- **Extended syntax** (`'include`, `^prefix`, `=exact`, `!not`, `|`, `~word`, `field:word`) works on relation columns; `!tolkien` excludes rows with any matching related row, and `author.name:tolkien` scopes a term to that one relation column.
- A dotted name is treated as a relation only when its first segment is a relation method on the model. `posts.title` on a model whose table is `posts` stays a table-qualified column, exactly as in v2.0. Relation paths need `Model::search()`; a Query Builder source throws.
- Touched relations are eager-loaded on the results.
- `searchIn()` on a `Model::search()` builder *adds* the listed columns to the model's configured `$searchable['columns']` (it has never replaced them); to search only the listed columns, list them all in `searchIn()` or build the query from `new SearchBuilder(Model::query(), app(FuzzySearch::class))`.
- Relation columns are not part of the SQL relevance `ORDER BY`, so when more than `max_candidates` rows match, rows that match only through a relation may fall outside the rescored window.
- Polymorphic (`morphTo`) relation paths are not supported.

**BM25 index:** relations are not joined at query time. Define `searchableText()` to put related text into the index, eager-load it during rebuilds with `searchIndexQuery()`, declare the foreign key in `reindex_on`, and reindex the children when the parent changes:

```php
class Post extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns'    => ['title' => 10, 'author.name' => 5],
        'reindex_on' => ['author_id'],
    ];

    public function searchableText(): array
    {
        return ['title' => $this->title, 'author' => $this->author?->name, 'tags' => $this->tags->pluck('name')->implode(' ')];
    }

    public function searchIndexQuery(Builder $query): Builder
    {
        return $query->with(['author', 'tags']);
    }
}

class Author extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (Author $author) => Post::reindexRelated('author_id', $author->id));
    }
}
```

Changing a parent row (renaming an author) does **not** reindex its children automatically — that is what the `saved` hook above is for.

`SearchableIndexingObserver` indexes a model only when it has searchable columns — the ones declared in `$searchable['columns']` or, when none are declared, the auto-detected string-like columns. A model with neither is treated as "not indexed" and every save is skipped, even if it defines `searchableText()`. Auto-detection never selects a column cast to `encrypted` or `hashed`. Nor does it select a column the model hides from serialization (`$hidden`, or any column outside a non-empty `$visible`), or a secret-named column (any name containing `password`, ending in `_token` or `_secret`, or named `token`, `secret`, `api_key`, `private_key`, `recovery_codes` or `two_factor_recovery_codes`; a broad `*_key` rule is deliberately left out, so `sort_key` stays searchable), in any letter case. An auto-detected column is indexed as the model's raw attribute value, not through a get accessor, and its `*_metaphone` shadow column is filled from the same value, so an accessor that decrypts or reformats it never reaches the index. Only the index reads the stored value: `suggest()`'s table scan, relevance scoring and highlighting read an auto-detected column through its accessor, as in 2.0, so for a column an accessor decrypts, declare `$searchable['columns']` without it, or hide it (`$hidden`); otherwise `suggest()`'s table scan can return words from the decrypted value of rows the query can see. Declaring a column in `$searchable['columns']`, or overriding `getSearchableColumns()`, is what opts into its accessor. A column you *declare* with the `encrypted` cast has its **decrypted** text written to the index, and `suggest()` and `didYouMean()` serve it.

Auto-detection does not keep a column out of the index in these cases. Put the column in `$hidden`, or declare `$searchable['columns']` without it:

- **A masking accessor** (`Str::mask()` on an email) is bypassed: the index, `suggest()` and `didYouMean()` serve the unmasked value, and the shadow column encodes it.
- **Encryption that decrypts into the model's attributes in memory** (spatie/laravel-ciphersweet does this when a model is retrieved) is invisible to the package: the plaintext is indexed, while the LIKE search matches the ciphertext in the database.
- **A column hidden at runtime** (`makeHidden()`, `setHidden()`, or a `getHidden()` that changes per request) is still searched, indexed and highlighted: detection reads the model class's default `$hidden` and `$visible`, once per process.

