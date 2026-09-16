<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class RelationSearchTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();
    }

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    public function test_belongs_to_column_filters_by_the_related_row(): void
    {
        $titles = Post::search('tolkien')->searchIn(['title', 'author.name'])->using('like')->get()->pluck('title')->all();

        $this->assertSame(['The Ring'], $titles);
    }

    public function test_belongs_to_many_column_matches_any_related_row(): void
    {
        $titles = Post::search('fantasy')->searchIn(['tags.name'])->using('like')->get()->pluck('title')->sort()->values()->all();

        $this->assertSame(['Harry', 'The Ring'], $titles);
    }

    public function test_nested_relation_path_is_followed(): void
    {
        $titles = Post::search('tolkien')->searchIn(['comments.author.name'])->using('like')->get()->pluck('title')->all();

        $this->assertSame(['Cooking'], $titles);
    }

    public function test_direct_and_relation_columns_are_ored_together(): void
    {
        // "winter" is a title; "rowling" is an author — both must come back.
        $titles = Post::search('winter')->searchIn(['title', 'author.name'])->using('like')->get()->pluck('title')->all();
        $this->assertSame(['Winter'], $titles);

        $titles = Post::search('rowling')->searchIn(['title', 'author.name'])->using('like')->get()->pluck('title')->all();
        $this->assertSame(['Harry'], $titles);
    }

    public function test_match_all_requires_every_token_across_direct_and_relation_columns(): void
    {
        $titles = Post::search('ring tolkien')->tokenize()->matchAll()
            ->searchIn(['title', 'author.name'])->using('like')->get()->pluck('title')->all();

        $this->assertSame(['The Ring'], $titles);

        $none = Post::search('ring rowling')->tokenize()->matchAll()
            ->searchIn(['title', 'author.name'])->using('like')->get();

        $this->assertCount(0, $none);
    }

    public function test_typo_tolerant_driver_works_through_a_relation(): void
    {
        $titles = Post::search('tolkein')->searchIn(['author.name'])->using('fuzzy')->typoTolerance(2)->get()->pluck('title')->all();

        $this->assertSame(['The Ring'], $titles);
    }

    public function test_relation_columns_are_excluded_from_the_sql_relevance_order_by(): void
    {
        $sql = Post::search('tolkien')->searchIn(['title', 'author.name'])->using('like')->withRelevance()->toSql();

        $this->assertStringContainsString('order by', strtolower($sql));
        $this->assertStringNotContainsString('author.name', $sql);
        $this->assertStringNotContainsString('"author"."name"', $sql);
        $this->assertStringNotContainsString('`author`.`name`', $sql);
    }

    public function test_touched_relations_are_eager_loaded_on_the_results(): void
    {
        $post = Post::search('tolkien')->searchIn(['author.name', 'tags.name'])->using('like')->get()->first();

        $this->assertTrue($post->relationLoaded('author'));
        $this->assertTrue($post->relationLoaded('tags'));
    }

    public function test_table_qualified_columns_still_work(): void
    {
        $titles = Post::search('winter')->searchIn(['posts.title'])->using('like')->get()->pluck('title')->all();

        $this->assertSame(['Winter'], $titles);
    }
}
