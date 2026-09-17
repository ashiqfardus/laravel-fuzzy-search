<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

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

    public function test_relation_matches_are_scored_with_the_column_weight(): void
    {
        // Only the relation column is searched, so no other column adds a fuzzy contribution.
        // Built directly against Post::query() rather than Post::search(): the latter would
        // also inject the model's configured `title` column (searchIn() accumulates, it never
        // replaces), which would add its own fuzzy contribution and muddy this assertion.
        $post = (new SearchBuilder(Post::query(), app(FuzzySearch::class)))
            ->search('tolkien')->searchIn(['author.name' => 10])->using('like')->withRelevance()->get()->first();

        $this->assertSame('The Ring', $post->title);
        // exact match on a weight-10 column: scoring.exact_match (100) × 10 = 1000 raw
        $this->assertEquals(1000.0, (float) $post->_raw_score);
    }

    public function test_to_many_relation_takes_the_best_related_row(): void
    {
        // "The Ring" has tags fantasy + epic; "Harry" has fantasy only. Searching "epic":
        // Built directly against Post::query() rather than Post::search(): the latter would
        // also inject the model's configured `title` column (searchIn() accumulates, it never
        // replaces), which would add its own fuzzy contribution and muddy this assertion.
        $ranked = (new SearchBuilder(Post::query(), app(FuzzySearch::class)))
            ->search('epic')->searchIn(['tags.name'])->using('like')->withRelevance()->get();

        $this->assertSame(['The Ring', 'Winter'], $ranked->pluck('title')->sort()->values()->all());
        foreach ($ranked as $post) {
            $this->assertEquals(100.0, (float) $post->_raw_score, 'exact tag match scores the exact tier, not an average over tags');
        }
    }

    public function test_relation_columns_are_highlighted_and_reported_in_matches(): void
    {
        $post = Post::search('tolk')->searchIn(['title', 'author.name'])->using('like')->highlight('em')->get()->first();

        $this->assertSame('<em>Tolk</em>ien', $post->_highlighted['author.name']);
        $this->assertSame('The Ring', $post->_highlighted['title']); // untouched, no match

        $match = collect($post->_matches)->firstWhere('column', 'author.name');
        $this->assertSame('Tolkien', $match['value']);
        $this->assertSame([[0, 3]], $match['indices']);
    }

    public function test_to_many_highlight_uses_the_matching_related_row(): void
    {
        $post = Post::search('epic')->searchIn(['tags.name'])->using('like')->highlight('em')
            ->get()->firstWhere('title', 'The Ring');

        $this->assertSame('<em>epic</em>', $post->_highlighted['tags.name']);
        $this->assertSame('<em>epic</em>', \Ashiqfardus\LaravelFuzzySearch\SearchBuilder::renderHighlighted($post, 'tags.name', 'em'));
    }

    public function test_blade_helper_renders_a_belongs_to_relation_column(): void
    {
        $post = Post::search('tolkien')->searchIn(['author.name'])->using('like')->highlight('mark')->get()->first();

        $this->assertSame('<mark>Tolkien</mark>', \Ashiqfardus\LaravelFuzzySearch\SearchBuilder::renderHighlighted($post, 'author.name'));
        // A column that was searched but did not match renders escaped, unwrapped text.
        $this->assertSame('The Ring', \Ashiqfardus\LaravelFuzzySearch\SearchBuilder::renderHighlighted($post, 'title'));
    }

    public function test_render_highlighted_escapes_a_non_matching_relation_value(): void
    {
        // The relation column's raw value carries an HTML tag; it must render escaped,
        // not silently truncated (strip_tags() eats an unterminated "<" to the end).
        $author = \Ashiqfardus\LaravelFuzzySearch\Tests\Author::create(['name' => '<b>Tolkien</b>']);
        \Ashiqfardus\LaravelFuzzySearch\Tests\Post::create([
            'author_id' => $author->id, 'title' => 'Special Post', 'body' => null,
        ]);

        $post = Post::search('Special')->searchIn(['title', 'author.name'])->using('like')->highlight('em')->get()->first();

        $this->assertSame('Special Post', $post->title);
        $rendered = SearchBuilder::renderHighlighted($post, 'author.name');
        $this->assertSame('&lt;b&gt;Tolkien&lt;/b&gt;', $rendered);
    }

    public function test_column_values_never_lazy_loads_an_unloaded_relation(): void
    {
        $post = Post::where('title', 'The Ring')->first(); // author relation NOT eager-loaded
        $builder = new SearchBuilder(Post::query(), app(FuzzySearch::class));
        $target  = ['relation' => 'author', 'column' => 'name'];

        DB::enableQueryLog();
        DB::flushQueryLog();

        $values = \Closure::bind(
            fn () => $this->columnValues($post, 'author.name', $target),
            $builder,
            SearchBuilder::class
        )();

        $this->assertSame([], $values);
        $this->assertSame([], DB::getQueryLog(), 'columnValues() must never trigger a lazy-load query.');

        $post->load('author');

        $values = \Closure::bind(
            fn () => $this->columnValues($post, 'author.name', $target),
            $builder,
            SearchBuilder::class
        )();

        $this->assertSame(['Tolkien'], $values);
    }

    public function test_nested_to_many_relation_path_is_scored_and_highlighted(): void
    {
        $results = (new SearchBuilder(Post::query(), app(FuzzySearch::class)))
            ->search('tolkien')->searchIn(['comments.author.name'])->using('like')->highlight('em')
            ->withRelevance()->get();

        $this->assertSame(['Cooking'], $results->pluck('title')->all());

        $post = $results->first();
        // Exact match on an unweighted relation column: scoring.exact_match (100) × 1 = 100 raw.
        $this->assertEquals(100.0, (float) $post->_raw_score);

        $match = collect($post->_matches)->firstWhere('column', 'comments.author.name');
        $this->assertSame('Tolkien', $match['value']);

        $this->assertStringContainsString('<em>Tolkien</em>', $post->_highlighted['comments.author.name']);
    }

    public function test_suggest_proposes_related_values(): void
    {
        $suggestions = Post::search('tol')->searchIn(['title', 'author.name'])->suggest(5);

        $this->assertContains('Tolkien', $suggestions);
    }

    public function test_suggest_proposes_to_many_related_values(): void
    {
        $suggestions = Post::search('fan')->searchIn(['tags.name'])->suggest(5);

        $this->assertContains('fantasy', $suggestions);
    }

    public function test_extended_include_term_matches_a_relation_column(): void
    {
        $titles = Post::search("'tolkien")->extended()->searchIn(['title', 'author.name'])->get()->pluck('title')->all();

        $this->assertSame(['The Ring'], $titles);
    }

    public function test_extended_prefix_and_exact_terms_work_on_relations(): void
    {
        $this->assertSame(['The Ring'], Post::search('^tolk')->extended()->searchIn(['author.name'])->get()->pluck('title')->all());
        $this->assertSame(['The Ring'], Post::search('=tolkien')->extended()->searchIn(['author.name'])->get()->pluck('title')->all());
        $this->assertCount(0, Post::search('=tolk')->extended()->searchIn(['author.name'])->get());
    }

    public function test_extended_not_excludes_rows_whose_relation_matches(): void
    {
        // Every post except the one by Tolkien (Cooking has no author and is kept).
        $titles = Post::search('!tolkien')->extended()->searchIn(['title', 'author.name'])->get()->pluck('title')->sort()->values()->all();

        $this->assertSame(['Cooking', 'Harry', 'Winter'], $titles);
    }

    public function test_extended_or_across_direct_and_to_many_relation(): void
    {
        $titles = Post::search('winter | epic')->extended()->searchIn(['title', 'tags.name'])->get()->pluck('title')->sort()->values()->all();

        $this->assertSame(['The Ring', 'Winter'], $titles);
    }

    public function test_extended_paginate_works_with_relation_columns(): void
    {
        $page = Post::search("'fantasy")->extended()->searchIn(['tags.name'])->paginate(1, 'page', 1);

        $this->assertSame(2, $page->total());
        $this->assertCount(1, $page->items());
    }
}
