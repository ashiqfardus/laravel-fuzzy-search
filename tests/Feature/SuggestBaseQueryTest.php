<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use ReflectionMethod;

/**
 * suggest() and didYouMean() answer "what else could you have typed", so they must run beside
 * the search, not inside it: the WHEREs a previous get() compiled must not reach them — while
 * constraints the caller put on the query themselves still scope what can be suggested.
 *
 * This is the docs/integrations.md Livewire recipe: one builder, get(), then suggest() when
 * the page came back empty.
 */
class SuggestBaseQueryTest extends TestCase
{
    private function candidateSql(SearchBuilder $builder, string $term): string
    {
        $method = new ReflectionMethod($builder, 'suggestCandidateQuery');
        $method->setAccessible(true);

        return $method->invoke($builder, $term)->toSql();
    }

    public function test_suggest_query_is_identical_before_and_after_get(): void
    {
        $builder = User::search('joh')->searchIn(['name']);

        $before = $this->candidateSql($builder, 'joh');
        $builder->get();
        $after = $this->candidateSql($builder, 'joh');

        $this->assertSame($before, $after, 'suggest() ran inside the previous search');
    }

    public function test_suggest_after_get_matches_a_fresh_builder(): void
    {
        $builder = User::search('joh')->searchIn(['name'])->limit(10);
        $fresh   = User::search('joh')->searchIn(['name'])->limit(10);

        $builder->get();

        $this->assertNotEmpty($fresh->suggest(5));
        $this->assertSame($fresh->suggest(5), $builder->suggest(5));
    }

    public function test_suggest_still_completes_when_the_search_page_is_empty(): void
    {
        // filter() is one of the search's own predicates: it is compiled with the search and
        // must not reach the suggestions — that empty page is exactly when the recipe asks
        // for them.
        $builder = User::search('joh')->searchIn(['name'])->filter('email', 'nobody@example.com');

        $this->assertEmpty($builder->get(), 'precondition: the page is empty');
        $this->assertNotEmpty($builder->suggest(5));
    }

    public function test_constraints_the_caller_applied_still_scope_the_suggestions(): void
    {
        $scoped = User::search('joh')->searchIn(['name'])->where('email', 'john@example.com');
        $scoped->get();

        // Only John Doe is in scope, so Johnny Bravo and Bob Johnson must not be suggested.
        // (searchIn() adds to the model's configured columns, so email is a source too.)
        $this->assertSame(['John', 'John Doe', 'john@example.com'], $scoped->suggest(5));

        $elsewhere = User::search('joh')->searchIn(['name'])->where('email', 'alice@example.com');
        $elsewhere->get();

        $this->assertSame([], $elsewhere->suggest(5));
    }

    public function test_did_you_mean_is_unaffected_by_the_previous_search(): void
    {
        $this->app['db']->table('fuzzy_index_terms')->insert(
            ['term' => 'john', 'doc_count' => 50, 'term_length' => 4]
        );

        $builder = User::search('jonh')->searchIn(['name']);

        $before = $builder->didYouMean(3);
        $builder->get();
        $after = $builder->didYouMean(3);

        $this->assertContains('john', array_column($before, 'term'));
        $this->assertSame($before, $after);
    }
}
