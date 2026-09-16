<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Concerns;

use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Comment;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\Tag;
use Illuminate\Support\Facades\Schema;

/**
 * authors ─< posts >─< post_tag >─ tags ; posts ─< comments >─ authors
 *
 * Fixture (seedRelationFixtures):
 *   Author Tolkien: post "The Ring" (tags: fantasy, epic) — body "one ring"
 *   Author Rowling: post "Harry"    (tags: fantasy)        — body "wizard school"
 *   Author Martin:  post "Winter"   (tags: epic)           — body "winter is coming"
 *   No author:      post "Cooking"  (no tags)              — body "recipes"; one comment by Tolkien: "great recipe"
 */
trait CreatesRelationTables
{
    protected function createRelationTables(): void
    {
        $this->dropRelationTables();

        Schema::create('authors', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('posts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('title');
            $table->string('body')->nullable();
        });
        Schema::create('tags', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('post_tag', function ($table) {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
        });
        Schema::create('comments', function ($table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('body');
        });
    }

    protected function seedRelationFixtures(): void
    {
        $tolkien = Author::create(['name' => 'Tolkien']);
        $rowling = Author::create(['name' => 'Rowling']);
        $martin  = Author::create(['name' => 'Martin']);

        $fantasy = Tag::create(['name' => 'fantasy']);
        $epic    = Tag::create(['name' => 'epic']);

        $ring    = Post::create(['author_id' => $tolkien->id, 'title' => 'The Ring', 'body' => 'one ring']);
        $harry   = Post::create(['author_id' => $rowling->id, 'title' => 'Harry',    'body' => 'wizard school']);
        $winter  = Post::create(['author_id' => $martin->id,  'title' => 'Winter',   'body' => 'winter is coming']);
        $cooking = Post::create(['author_id' => null,         'title' => 'Cooking',  'body' => 'recipes']);

        $ring->tags()->attach([$fantasy->id, $epic->id]);
        $harry->tags()->attach([$fantasy->id]);
        $winter->tags()->attach([$epic->id]);

        Comment::create(['post_id' => $cooking->id, 'author_id' => $tolkien->id, 'body' => 'great recipe']);
    }

    protected function dropRelationTables(): void
    {
        foreach (['comments', 'post_tag', 'tags', 'posts', 'authors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
