<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A SearchBuilder is inspectable and reusable: every terminal call compiles its search onto a
 * clone of the base query, so toSql() + get(), two get()s, or count() + paginate() + get() on
 * one builder each execute exactly one search instead of stacking WHEREs and ORDER BYs.
 *
 * The oracle throughout is a second, never-used builder configured identically: whatever the
 * re-used builder executes must carry exactly as many search predicates as that fresh one.
 */
class BuilderReuseTest extends TestCase
{
    /** @return string[] the SQL of every query executed while $run ran */
    private function sqlOf(Closure $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $run();
        } finally {
            DB::disableQueryLog();
        }

        return array_column(DB::getQueryLog(), 'query');
    }

    /** The first SELECT against the searched table executed while $run ran. */
    private function executedSelect(Closure $run): string
    {
        $selects = array_values(array_filter(
            $this->sqlOf($run),
            fn (string $sql) => str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'users')
        ));

        $this->assertNotEmpty($selects, 'no select against the users table was executed');

        return strtolower($selects[0]);
    }

    /**
     * How many times the search itself appears in one statement: the fuzzy LIKE patterns
     * (`like ?` also covers PostgreSQL's `ilike ?`) and the relevance ORDER BY's CASE arms.
     * Driver-independent, unlike comparing whole SQL strings.
     *
     * @return array{patterns: int, score_arms: int}
     */
    private function searchShape(string $sql): array
    {
        $sql = strtolower($sql);

        return [
            'patterns'   => substr_count($sql, 'like ?'),
            'score_arms' => substr_count($sql, 'case when'),
        ];
    }

    public function test_to_sql_then_get_applies_the_search_exactly_once(): void
    {
        $builder = User::search('john')->searchIn(['name']);
        $fresh   = User::search('john')->searchIn(['name']);

        $printed  = $builder->toSql();
        $executed = $this->executedSelect(fn () => $builder->get());

        $this->assertSame($fresh->toSql(), $printed, 'toSql() did not report the search a fresh builder reports');
        $this->assertSame($this->searchShape($printed), $this->searchShape($executed));
        $this->assertGreaterThan(0, $this->searchShape($executed)['patterns']);
    }

    public function test_get_twice_returns_identical_rows_and_identical_sql(): void
    {
        $builder = User::search('john')->searchIn(['name']);

        $first  = null;
        $second = null;

        $firstSql  = $this->executedSelect(function () use ($builder, &$first) { $first = $builder->get(); });
        $secondSql = $this->executedSelect(function () use ($builder, &$second) { $second = $builder->get(); });

        $this->assertSame($firstSql, $secondSql, 'the second get() executed a different query');
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertGreaterThan(0, $first->count());
    }

    public function test_count_then_paginate_then_get_agree_on_one_builder(): void
    {
        $builder = User::search('john')->searchIn(['name']);

        $count = $builder->count();
        $page  = $builder->paginate(10);
        $rows  = $builder->get();

        $this->assertGreaterThan(0, $count);
        $this->assertSame($count, $page->total());
        $this->assertSame($count, $rows->count());
        $this->assertSame(
            collect($page->items())->pluck('id')->all(),
            $rows->pluck('id')->all()
        );
    }

    public function test_the_debugging_helpers_do_not_change_what_get_executes(): void
    {
        $builder = User::search('john')->searchIn(['name']);
        $fresh   = User::search('john')->searchIn(['name']);

        $builder->toSql();
        $builder->getBindings();
        $builder->getAnalytics();
        $builder->count();

        $executed = $this->executedSelect(fn () => $builder->get());

        $this->assertSame($this->searchShape($fresh->toSql()), $this->searchShape($executed));
    }

    public function test_get_facets_does_not_change_what_get_executes(): void
    {
        // withRelevance(false) keeps the relevance ORDER BY off the aggregate: getFacets()
        // groups the prepared query as-is, and MySQL's only_full_group_by (and PostgreSQL)
        // reject a GROUP BY carrying an ORDER BY over ungrouped columns. That is a separate,
        // pre-existing defect — this test is about the facet call not poisoning the builder.
        // @todo cover facet reuse on the default (relevance-on) path once getFacets() drops the
        //       relevance ORDER BY from its aggregate.
        $builder = User::search('john')->searchIn(['name'])->withRelevance(false)->facet('email');
        $fresh   = User::search('john')->searchIn(['name'])->withRelevance(false);

        $this->assertNotEmpty($builder->getFacets());

        $executed = $this->executedSelect(fn () => $builder->get());

        $this->assertSame($this->searchShape($fresh->toSql()), $this->searchShape($executed));
    }

    public function test_constraints_added_after_a_terminal_call_still_apply(): void
    {
        $builder = User::search('john')->searchIn(['name']);

        $this->assertGreaterThan(1, $builder->get()->count());

        $builder->where('email', 'john@example.com');

        $this->assertCount(1, $builder->get(), 'a where() added after the first get() had no effect');
    }

    public function test_inverted_index_builder_is_reusable(): void
    {
        config(['fuzzy-search.indexing.enabled' => true]);
        foreach (User::all() as $user) {
            app(IndexManager::class)->indexModel($user);
        }

        $builder = User::search('john')->useInvertedIndex();

        $builder->toSql(); // the README's debugging helper, right next to get()

        $first  = null;
        $second = null;

        $executed  = $this->executedSelect(function () use ($builder, &$first) { $first = $builder->get(); });
        $secondSql = $this->executedSelect(function () use ($builder, &$second) { $second = $builder->get(); });

        $this->assertSame(
            ['patterns' => 0, 'score_arms' => 0],
            $this->searchShape($executed),
            'the BM25 candidate query inherited the LIKE search from toSql()'
        );
        $this->assertSame($executed, $secondSql);
        $this->assertTrue($first->contains('name', 'John Doe'));
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
    }

    public function test_extended_builder_is_reusable(): void
    {
        $builder = User::search('john')->searchIn(['name'])->extended('^Joh');
        $fresh   = User::search('john')->searchIn(['name'])->extended('^Joh');

        $printed = $builder->toSql();

        $first  = null;
        $second = null;

        $executed  = $this->executedSelect(function () use ($builder, &$first) { $first = $builder->get(); });
        $secondSql = $this->executedSelect(function () use ($builder, &$second) { $second = $builder->get(); });

        $this->assertSame($fresh->toSql(), $printed);
        $this->assertSame($this->searchShape($printed), $this->searchShape($executed));
        $this->assertSame($executed, $secondSql);
        $this->assertTrue($first->contains('name', 'John Doe'));
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
    }

    public function test_fallback_still_runs_and_leaves_a_reusable_builder(): void
    {
        $builder = User::search('jonh')->using('simple')->searchIn(['name'])->fallback('fuzzy');

        $first  = null;
        $second = null;

        $firstSql  = $this->executedSelect(function () use ($builder, &$first) { $first = $builder->get(); });
        $secondSql = $this->executedSelect(function () use ($builder, &$second) { $second = $builder->get(); });

        $this->assertTrue($first->contains('name', 'John Doe'), 'the fallback algorithm did not run');
        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame($firstSql, $secondSql, 'the retried search stacked onto the base query');
    }

    public function test_one_executed_event_per_executed_search(): void
    {
        Event::fake([FuzzySearchExecuted::class]);

        $builder = User::search('john')->searchIn(['name']);
        $builder->toSql();
        $builder->getBindings();
        $builder->getAnalytics();
        $builder->count(); // documented never to dispatch
        $builder->get();

        Event::assertDispatchedTimes(FuzzySearchExecuted::class, 1);
    }

    public function test_first_leaves_the_builder_usable(): void
    {
        $builder  = User::search('john')->searchIn(['name']);
        $expected = User::search('john')->searchIn(['name'])->get()->count();

        $this->assertGreaterThan(1, $expected);
        $this->assertNotNull($builder->first());
        $this->assertCount($expected, $builder->get(), 'first() left the builder limited to one row');
    }

    public function test_first_restores_a_limit_the_caller_set(): void
    {
        $builder = User::search('john')->searchIn(['name'])->limit(3);

        $builder->first();

        $this->assertCount(3, $builder->get(), 'first() overwrote the caller\'s limit');
    }

    public function test_a_nested_terminal_call_starts_from_the_pristine_base(): void
    {
        $builder = User::search('john')->searchIn(['name']);
        $fresh   = User::search('john')->searchIn(['name']);

        // A listener that inspects the builder mid-search: the classic nested terminal call.
        $observed = null;
        Event::listen(FuzzySearchExecuted::class, function () use ($builder, &$observed) {
            $observed = $builder->toSql();
        });

        $builder->get();

        $this->assertSame($fresh->toSql(), $observed, 'the nested toSql() cloned the prepared query, not the base');
    }
}
