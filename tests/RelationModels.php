<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    protected $table   = 'authors';
    protected $guarded = [];
    public $timestamps = false;

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }
}

class Tag extends Model
{
    protected $table   = 'tags';
    protected $guarded = [];
    public $timestamps = false;

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag', 'tag_id', 'post_id');
    }
}

class Post extends Model
{
    use Searchable;

    protected $table   = 'posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = [
        'columns'   => ['title' => 10],
        'algorithm' => 'like',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /** Untyped method that fails when invoked: isRelationPath() must reject it without calling it (ER-50). */
    public function brokenRelation()
    {
        throw new \RuntimeException('relation construction failed');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}

class Comment extends Model
{
    protected $table   = 'comments';
    protected $guarded = [];
    public $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}
