<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

class ExtendedSyntaxTest extends TestCase
{
    public function test_typo_operator_uses_the_builder_typo_tolerance(): void
    {
        $this->assertContains('John Doe', User::search('jonh')->extended('~jonh')->get()->pluck('name')->all());
        $this->assertNotContains('John Doe', User::search('jonh')->extended('~jonh')->typoTolerance(0)->get()->pluck('name')->all());
    }

    public function test_typo_operator_respects_the_global_switch(): void
    {
        config(['fuzzy-search.typo_tolerance.enabled' => false]);

        $this->assertNotContains('John Doe', User::search('jonh')->extended('~jonh')->get()->pluck('name')->all());
    }

    public function test_field_scope_and_typo_combine_and_count_agrees(): void
    {
        $builder = fn () => User::search('jonh')->extended('name:~jonh !email:johnny');

        $names = $builder()->get()->pluck('name')->all();
        $this->assertContains('John Doe', $names);
        $this->assertNotContains('Johnny Bravo', $names); // Johnny Bravo's email is johnny@example.com — excluded through the email scope
        $this->assertSame(count($names), $builder()->count());
    }

    public function test_an_empty_quoted_phrase_does_not_match_every_row(): void
    {
        // "\xFF" cleans to "", name:"" is a field scope with no term, and "" | zzzz reads as | zzzz.
        foreach (['""', "\"\xFF\"", 'name:""', '"" | zzzz'] as $query) {
            try {
                User::search('')->extended($query)->get();
                $this->fail('Expected QuerySyntaxException for ' . bin2hex($query));
            } catch (QuerySyntaxException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, User::search('')->extended('"" zzzz')->count());
    }

    public function test_unknown_field_throws_a_query_syntax_exception(): void
    {
        $this->expectException(QuerySyntaxException::class);
        User::search('john')->extended('nickname:john')->get();
    }

    public function test_debug_info_reports_the_extended_algorithm_and_the_ignored_index(): void
    {
        $info = User::search('john')->extended('name:john')->useInvertedIndex()->getDebugInfo();

        $this->assertSame('extended', $info['algorithm']);
        $this->assertTrue($info['index_ignored']);
    }

    public function test_a_huge_typo_term_is_capped_instead_of_exhausting_memory(): void
    {
        // Without the Lexer's max_term_length cap the fuzzy driver builds 3*8000 patterns of
        // 8000 characters each before capPatterns() trims them — a dead worker.
        $builder = fn () => User::search('x')->extended('~' . str_repeat('a', 8000));

        $this->assertSame($builder()->get()->count(), $builder()->count());

        $longest = max(array_map(fn ($b) => is_string($b) ? strlen($b) : 0, $builder()->getBindings()));
        $this->assertLessThanOrEqual(config('fuzzy-search.query.max_term_length', 128) + 2, $longest);
    }

    public function test_get_facets_runs_the_extended_query(): void
    {
        $emails = User::search('x')->extended('name:john')->get()->pluck('email')->all();
        $facets = User::search('x')->extended('name:john')->facet('email')->getFacets();

        $this->assertNotEmpty($facets['email']);
        $this->assertEqualsCanonicalizing($emails, array_keys($facets['email']));
    }

    public function test_to_sql_and_get_bindings_compile_the_extended_query(): void
    {
        $bindings = User::search('x')->extended('name:~jonh')->getBindings();

        $this->assertContains('%jonh%', $bindings, 'the typo term reached the fuzzy driver');
        $this->assertNotContains('%name:~jonh%', $bindings, 'the raw query string was LIKE-matched');
    }

    public function test_get_analytics_runs_the_extended_query(): void
    {
        $this->assertSame('extended', User::search('x')->extended('name:john')->getAnalytics()['algorithm']);

        // Proof it compiled the AST rather than the LIKE query: only the extended path throws here.
        $this->expectException(QuerySyntaxException::class);
        User::search('x')->extended('nickname:john')->getAnalytics();
    }

    public function test_a_negated_quoted_phrase_excludes_that_phrase(): void
    {
        $names = User::search('x')->extended('!"john doe"')->get()->pluck('name')->all();

        $this->assertNotContains('John Doe', $names);
        $this->assertContains('Jane Doe', $names);
        $this->assertContains('Johnny Bravo', $names);
    }

    public function test_extended_results_are_highlighted_by_the_query_leaf_terms(): void
    {
        $john = User::search('x')->extended('name:john')->highlight('mark')->get()->firstWhere('name', 'John Doe');

        $this->assertNotEmpty($john->_matches);
        $this->assertStringContainsString('<mark>John</mark>', $john->_highlighted['name']);

        // A typo term contributes its own word as the needle, not the raw "~john" string.
        $typo = User::search('x')->extended('~john')->highlight('mark')->get()->firstWhere('name', 'John Doe');
        $this->assertStringContainsString('<mark>John</mark>', $typo->_highlighted['name']);
    }

    public function test_excluded_terms_are_not_highlighted(): void
    {
        // Name carries "Johnny", email does not — so !email:johnny keeps this row.
        User::create(['name' => 'Johnny Cash', 'email' => 'cash@example.com']);

        $cash = User::search('x')->extended('name:john !email:johnny')->highlight('mark')->get()->firstWhere('name', 'Johnny Cash');

        $this->assertSame('<mark>John</mark>ny Cash', $cash->_highlighted['name']);
    }

    public function test_extended_results_are_scored_by_the_query_leaf_terms(): void
    {
        $names = User::search('x')->extended('name:~jonh !email:johnny')->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertContains('Bob Johnson', $names);
        $this->assertLessThan(
            array_search('Bob Johnson', $names, true),
            array_search('John Doe', $names, true),
            'scored against the leaf term "jonh", not the literal query string'
        );
    }

    public function test_max_patterns_caps_the_patterns_of_a_typo_term(): void
    {
        $likes = fn (string $sql) => substr_count(strtolower($sql), 'like ?');

        $uncapped = $likes(User::search('x')->extended('~jonh')->toSql());
        $capped   = $likes(User::search('x')->extended('~jonh')->maxPatterns(2)->toSql());

        $this->assertLessThan($uncapped, $capped);
        $this->assertSame(4, $capped, 'two patterns for each of the two searchable columns');
    }

    public function test_field_scopes_inside_an_or_group(): void
    {
        $names = User::search('x')->extended('name:jane | email:^bob')->get()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Bob Johnson', 'Jane Doe'], $names);
    }

    /**
     * ER-44: a ! inside or at the end of a word is part of the word. It used to start a NOT, so
     * "yahoo!mail" excluded the one row it named and "'wow!" matched "wowX great".
     */
    public function test_a_bang_inside_a_word_is_searched_not_negated(): void
    {
        DB::table('users')->insert(array_map(
            fn (string $name) => ['name' => $name, 'email' => md5($name) . '@bang.test', 'created_at' => now(), 'updated_at' => now()],
            ['Yahoo!Mail', 'Yahoo Mail', 'Yahoo great', 'yahoo', 'wow! great', 'wowX great']
        ));
        $names = fn (string $query) => User::search('')->searchIn(['name'])->extended($query)->get()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Yahoo!Mail'], $names('yahoo!mail'));
        $this->assertSame(['Yahoo!Mail'], $names('"yahoo!mail"'));
        $this->assertSame(['wow! great'], $names("'wow!"));
        $this->assertSame(['wow! great'], $names('wow!'));
        // A ! at the start of a term still negates, in every documented form.
        $this->assertSame(['Yahoo great', 'yahoo'], $names('yahoo !mail'));
        $this->assertSame(['Yahoo great', 'yahoo'], $names('(yahoo) !mail'));
        $this->assertSame(['Yahoo Mail', 'Yahoo great', 'yahoo'], $names('yahoo !^yahoo!'));
        $this->assertSame(['Yahoo Mail', 'Yahoo!Mail', 'yahoo'], $names('yahoo !great$'));
        $this->assertSame(['Yahoo Mail', 'Yahoo great', 'yahoo'], $names('yahoo !name:yahoo!mail'));
        $this->assertSame(['Yahoo Mail', 'Yahoo great', 'yahoo'], $names('yahoo !"yahoo!mail"'));
    }

    public function test_a_query_of_nothing_but_a_bang_or_an_operator_still_throws(): void
    {
        foreach (['!', '! !', '|', '()', '!(john)', 'name:!john'] as $query) {
            try {
                User::search('')->extended($query)->get();
                $this->fail("{$query} should throw");
            } catch (QuerySyntaxException) {
                $this->addToAssertionCount(1);
            }
        }

        // A trailing ! is skipped, as before.
        $this->assertSame(['John Doe'], User::search('')->searchIn(['name'])->extended('"john doe" !')->get()->pluck('name')->all());
    }
}
