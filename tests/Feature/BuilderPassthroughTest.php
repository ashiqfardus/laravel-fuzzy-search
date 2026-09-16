<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class BuilderPassthroughTest extends TestCase
{
    public function test_where_is_forwarded_and_returns_the_search_builder(): void
    {
        $builder = User::search('john')->where('email', 'like', '%example.com');

        $this->assertInstanceOf(SearchBuilder::class, $builder);
        $this->assertGreaterThan(0, $builder->get()->count());
    }

    public function test_where_narrows_results(): void
    {
        $all      = User::search('john')->using('like')->get();
        $narrowed = User::search('john')->using('like')->where('name', 'John Doe')->get();

        $this->assertGreaterThan($narrowed->count(), $all->count());
        $this->assertSame(['John Doe'], $narrowed->pluck('name')->all());
    }

    public function test_where_in_and_where_not_null_are_forwarded(): void
    {
        $ids = User::query()->whereIn('name', ['John Doe', 'Jon Snow'])->pluck('id')->all();

        $results = User::search('jo')->using('like')->whereIn('id', $ids)->whereNotNull('email')->get();

        $this->assertEqualsCanonicalizing(['John Doe', 'Jon Snow'], $results->pluck('name')->all());
    }

    public function test_local_scopes_are_forwarded(): void
    {
        $results = User::search('jo')->using('like')->emailDomain('example.com')->get();
        $this->assertGreaterThan(0, $results->count());

        $none = User::search('jo')->using('like')->emailDomain('nowhere.test')->get();
        $this->assertCount(0, $none);
    }

    public function test_when_is_forwarded(): void
    {
        $results = User::search('jo')->using('like')
            ->when(true, fn ($q) => $q->where('name', 'Jon Snow'))
            ->get();

        $this->assertSame(['Jon Snow'], $results->pluck('name')->all());
    }

    public function test_query_hook_receives_the_underlying_builder(): void
    {
        $results = User::search('jo')->using('like')
            ->query(function ($q) {
                $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $q);
                $q->where('name', 'Jon Snow');
            })
            ->get();

        $this->assertSame(['Jon Snow'], $results->pluck('name')->all());
    }

    public function test_forwarded_constraints_apply_on_the_extended_path(): void
    {
        $results = User::search("'jo")->extended()->where('name', 'Jon Snow')->get();
        $this->assertSame(['Jon Snow'], $results->pluck('name')->all());
    }

    public function test_forwarded_constraints_apply_on_the_bm25_path(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        foreach (User::all() as $user) {
            app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)->indexModel($user);
        }

        $results = User::search('john')->useInvertedIndex()->where('name', 'John Doe')->get();
        $this->assertSame(['John Doe'], $results->pluck('name')->all());
    }

    public function test_terminal_methods_are_not_forwarded(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/delete\(\).*get\(\)/s');

        User::search('john')->delete();
    }

    public function test_unknown_methods_throw_bad_method_call(): void
    {
        $this->expectException(\BadMethodCallException::class);
        User::search('john')->definitelyNotAMethod();
    }

    public function test_query_builder_source_also_forwards(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class));
        $results = $builder->search('jo')->searchIn(['name'])->using('like')->where('name', 'Jon Snow')->get();

        $this->assertSame(['Jon Snow'], $results->pluck('name')->all());
    }
}
