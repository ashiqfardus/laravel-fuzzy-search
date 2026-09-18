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

`SearchableIndexingObserver` indexes a model only when it has searchable columns — the ones declared in `$searchable['columns']` or, when none are declared, the auto-detected string-like columns. A model with neither is treated as "not indexed" and every save is skipped, even if it defines `searchableText()`.

