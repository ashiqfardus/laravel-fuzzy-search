<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

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
    }

    public function test_field_scopes_inside_an_or_group(): void
    {
        $names = User::search('x')->extended('name:jane | email:^bob')->get()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Bob Johnson', 'Jane Doe'], $names);
    }
}
