<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class RelationColumnResolutionTest extends TestCase
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

    public function test_dotted_columns_resolve_to_relations_only_when_the_head_is_a_relation(): void
    {
        $targets = Post::search('x')
            ->searchIn(['title', 'author.name', 'tags.name', 'comments.author.name', 'posts.body'])
            ->getDebugInfo()['column_targets'];

        $this->assertSame(['relation' => null,              'column' => 'title'],      $targets['title']);
        $this->assertSame(['relation' => 'author',          'column' => 'name'],       $targets['author.name']);
        $this->assertSame(['relation' => 'tags',            'column' => 'name'],       $targets['tags.name']);
        $this->assertSame(['relation' => 'comments.author', 'column' => 'name'],       $targets['comments.author.name']);
        // "posts" is the table, not a relation: v2.0 table-qualified column, untouched.
        $this->assertSame(['relation' => null,              'column' => 'posts.body'], $targets['posts.body']);
    }

    public function test_an_unknown_head_with_more_than_two_segments_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[nothing.deep.name]');

        Post::search('x')->searchIn(['nothing.deep.name'])->getDebugInfo();
    }

    public function test_relation_paths_require_an_eloquent_source(): void
    {
        $builder = new SearchBuilder(DB::table('posts'), app(FuzzySearch::class));

        // table.column is fine on a Query Builder …
        $this->assertSame(
            ['relation' => null, 'column' => 'posts.title'],
            $builder->search('x')->searchIn(['posts.title'])->getDebugInfo()['column_targets']['posts.title']
        );

        // … a three-segment path cannot be a table column and there is no model to ask.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[comments.author.name]');
        (new SearchBuilder(DB::table('posts'), app(FuzzySearch::class)))
            ->search('x')->searchIn(['comments.author.name'])->getDebugInfo();
    }

    public function test_column_targets_are_recomputed_after_search_in_is_called_again(): void
    {
        $builder = Post::search('x')->searchIn(['title']);
        $this->assertArrayNotHasKey('author.name', $builder->getDebugInfo()['column_targets']);

        $builder->searchIn(['author.name']);
        $this->assertSame('author', $builder->getDebugInfo()['column_targets']['author.name']['relation']);
    }

    public function test_segments_must_be_identifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Post::search('x')->searchIn(['author.na me']);
    }

    public function test_an_untyped_method_is_rejected_without_being_called(): void
    {
        // Ruling ER-50: brokenRelation() has no Relation return type and its path is not in
        // $searchable['columns'], so isRelationPath() throws before calling it (it would throw a
        // RuntimeException if it ran). It is never mistaken for the v2.0 table.column.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[brokenRelation] on ' . Post::class . ' must be a relation method with a Relation return type');

        Post::search('x')->searchIn(['brokenRelation.name'])->getDebugInfo();
    }

    public function test_trailing_dot_column_throws_from_the_identifier_check(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[author.]');

        Post::search('x')->searchIn(['author.'])->getDebugInfo();
    }
}
