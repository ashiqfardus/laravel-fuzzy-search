<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Query;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser;
use Ashiqfardus\LaravelFuzzySearch\Query\AstCompiler;

class AstCompilerTest extends TestCase
{
    private function runQuery(string $query, array $columns = ['name', 'email']): array
    {
        $tokens   = (new Lexer())->tokenize($query);
        $ast      = (new ExtendedQueryParser())->parse($tokens);
        $compiler = new AstCompiler();

        $builder = $this->app['db']->table('users');
        $compiler->compile($ast, $builder, $columns);
        return $builder->get()->pluck('name')->toArray();
    }

    public function test_fuzzy_term_matches_substring(): void
    {
        $names = $this->runQuery('john');
        $this->assertContains('John Doe', $names);
        $this->assertContains('Johnny Bravo', $names);
    }

    public function test_exact_term_only_matches_full_value(): void
    {
        // Insert an exact-match user named "John" (the seed has "John Doe")
        $this->app['db']->table('users')->insert([
            'name' => 'John', 'email' => 'exact@test.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $names = $this->runQuery('=John');
        $this->assertContains('John', $names);
        // Substring match should NOT pull John Doe
        $this->assertNotContains('John Doe', $names);
    }

    public function test_prefix_matches_at_start(): void
    {
        $names = $this->runQuery('^Jon');
        // "Jon Snow" starts with "Jon"
        $this->assertContains('Jon Snow', $names);
        // "Bob Johnson" should NOT match — "Jon" mid-string, not at start
        $this->assertNotContains('Bob Johnson', $names);
    }

    public function test_suffix_matches_at_end(): void
    {
        $names = $this->runQuery('Doe$');
        $this->assertContains('Jane Doe', $names);
        $this->assertContains('John Doe', $names);
    }

    public function test_negation_excludes_matches(): void
    {
        $names = $this->runQuery('!Doe');
        $this->assertNotContains('John Doe', $names);
        $this->assertNotContains('Jane Doe', $names);
        $this->assertContains('Alice Smith', $names);
    }

    public function test_implicit_and(): void
    {
        $names = $this->runQuery('John Doe');
        // Only rows containing both 'john' AND 'doe'
        $this->assertContains('John Doe', $names);
        $this->assertNotContains('Jane Smith', $names);
        $this->assertNotContains('Alice Smith', $names);
    }

    public function test_or_unions_results(): void
    {
        $names = $this->runQuery('alice | bob');
        $this->assertContains('Alice Smith', $names);
        $this->assertContains('Bob Johnson', $names);
    }
}
