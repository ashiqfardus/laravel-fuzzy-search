<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Query;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser;
use Ashiqfardus\LaravelFuzzySearch\Query\AstCompiler;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;

class AstCompilerTest extends TestCase
{
    private function runQuery(string $query, array $columns = ['name', 'email'], int $typoDistance = 2): array
    {
        $tokens   = (new Lexer())->tokenize($query);
        $ast      = (new ExtendedQueryParser())->parse($tokens);
        $compiler = new AstCompiler($this->app['db']->connection()->getDriverName(), $typoDistance);

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

    public function test_search_builder_extended_method_works_end_to_end(): void
    {
        $fuzzy = app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class);
        $builder = new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder(
            $this->app['db']->table('users'),
            $fuzzy
        );

        $results = $builder
            ->extended('alice | bob')
            ->searchIn(['name'])
            ->get();

        $names = $results->pluck('name')->toArray();
        $this->assertContains('Alice Smith', $names);
        $this->assertContains('Bob Johnson', $names);
    }

    public function test_search_boolean_alias_works(): void
    {
        $fuzzy = app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class);
        $builder = new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder(
            $this->app['db']->table('users'),
            $fuzzy
        );

        $results = $builder
            ->searchBoolean('alice | bob')
            ->searchIn(['name'])
            ->get();

        $names = $results->pluck('name')->toArray();
        $this->assertContains('Alice Smith', $names);
        $this->assertContains('Bob Johnson', $names);
    }

    public function test_relation_columns_compile_to_where_has_groups(): void
    {
        require_once __DIR__ . '/../../RelationModels.php';

        $ast     = (new \Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser())
            ->parse((new \Ashiqfardus\LaravelFuzzySearch\Query\Lexer())->tokenize("'ring !tolkien"));
        $builder = \Ashiqfardus\LaravelFuzzySearch\Tests\Post::query();

        (new \Ashiqfardus\LaravelFuzzySearch\Query\AstCompiler($builder->getConnection()->getDriverName()))
            ->compile($ast, $builder, ['title'], ['author' => ['name']]);

        $sql = strtolower($builder->toSql());
        $this->assertSame(2, substr_count($sql, 'exists (select * from'), 'one EXISTS per leaf term that touches the relation');
        $this->assertStringContainsString('not (', $sql);
        $this->assertContains('%ring%', $builder->getBindings());
        $this->assertContains('%tolkien%', $builder->getBindings());
    }

    public function test_typo_term_finds_near_misses_through_the_fuzzy_driver(): void
    {
        $this->assertContains('John Doe', $this->runQuery('~jonh'));
        $this->assertNotContains('John Doe', $this->runQuery('~jonh', ['name', 'email'], 0)); // tolerance 0 = plain substring
        $this->assertNotContains('John Doe', $this->runQuery('!~jonh'));
    }

    public function test_field_scope_limits_the_term_to_one_column(): void
    {
        // Seed a user whose email, but not name, contains "john"
        $this->app['db']->table('users')->insert([
            'name' => 'Field Scope', 'email' => 'john.scope@test.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertContains('Field Scope', $this->runQuery('email:john'));
        $this->assertNotContains('Field Scope', $this->runQuery('name:john'));
        $this->assertContains('John Doe', $this->runQuery('name:^Jo'));
        $this->assertNotContains('John Doe', $this->runQuery('!name:john'));
    }

    public function test_table_qualified_columns_match_a_bare_field_name(): void
    {
        $this->assertContains('John Doe', $this->runQuery('name:john', ['users.name', 'users.email']));
    }

    public function test_unknown_field_is_rejected_with_the_known_list(): void
    {
        $this->expectException(QuerySyntaxException::class);
        $this->expectExceptionMessage('Searchable fields: name, email');
        $this->runQuery('nickname:john');
    }

    public function test_the_unknown_field_message_lists_bare_column_names(): void
    {
        $this->expectException(QuerySyntaxException::class);
        $this->expectExceptionMessage('Searchable fields: name, email');
        $this->runQuery('nickname:john', ['users.name', 'users.email']);
    }

    public function test_a_bare_field_matching_two_columns_is_ambiguous(): void
    {
        $this->expectException(QuerySyntaxException::class);
        $this->expectExceptionMessage('The field "name" is ambiguous (users.name, products.name). Use the table-qualified form, for example users.name:john.');
        $this->runQuery('name:john', ['users.name', 'products.name']);
    }
}
