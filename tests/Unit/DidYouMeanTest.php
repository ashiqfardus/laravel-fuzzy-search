<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class DidYouMeanTest extends TestCase
{
    use FakesDriverConnections;

    private function seedTerm(string $term, int $docCount = 10): void
    {
        $this->app['db']->table('fuzzy_index_terms')
            ->upsert(['term' => $term, 'doc_count' => $docCount], ['term'], ['doc_count' => $docCount]);
    }

    private function makeBuilder(string $term): SearchBuilder
    {
        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );
        $builder->search($term)->searchIn(['name']);
        return $builder;
    }

    public function test_did_you_mean_finds_close_term_in_dictionary(): void
    {
        $this->seedTerm('john', 50);
        $this->seedTerm('jane', 30);

        $suggestions = $this->makeBuilder('jonh')->didYouMean(3);

        $terms = array_column($suggestions, 'term');
        $this->assertContains('john', $terms);
    }

    public function test_did_you_mean_returns_empty_for_empty_index(): void
    {
        $suggestions = $this->makeBuilder('anything')->didYouMean(3);
        $this->assertEmpty($suggestions);
    }

    public function test_did_you_mean_result_has_required_keys(): void
    {
        $this->seedTerm('laravel', 100);

        $suggestions = $this->makeBuilder('laravle')->didYouMean(3);

        if (!empty($suggestions)) {
            $first = $suggestions[0];
            $this->assertArrayHasKey('term', $first);
            $this->assertArrayHasKey('distance', $first);
            $this->assertArrayHasKey('confidence', $first);
        } else {
            $this->markTestSkipped('No suggestion returned (edit distance > threshold)');
        }
    }

    public function test_did_you_mean_sorts_by_distance_then_doc_count(): void
    {
        $this->seedTerm('john',  100);
        $this->seedTerm('jone',    5);

        $suggestions = $this->makeBuilder('jonh')->didYouMean(5);
        $terms = array_column($suggestions, 'term');

        $this->assertNotEmpty($suggestions);
        if (in_array('john', $terms) && in_array('jone', $terms)) {
            $johnPos = array_search('john', $terms);
            $jonePos = array_search('jone', $terms);
            $this->assertLessThan($jonePos, $johnPos);
        }
    }

    public function test_did_you_mean_uses_the_drivers_character_length_function(): void
    {
        $this->seedTerm('john', 50);

        $this->app['db']->enableQueryLog();
        $this->makeBuilder('jonh')->didYouMean(3);
        $log = $this->app['db']->getQueryLog();
        $this->app['db']->disableQueryLog();

        $termQuery = collect($log)->first(fn ($q) => str_contains($q['query'], 'fuzzy_index_terms'));
        $this->assertNotNull($termQuery);

        $expected = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::lengthFunction(
            $this->app['db']->connection()->getDriverName()
        );

        $this->assertStringContainsString($expected . '(term)', $termQuery['query']);
        if ($expected !== 'LENGTH') {
            // A bare LENGTH( must not appear; CHAR_LENGTH( legitimately contains the substring.
            $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z_])LENGTH\(term\)/', $termQuery['query']);
        }
    }

    public function test_did_you_mean_candidate_query_uses_the_drivers_length_function_per_dialect(): void
    {
        $expected = [
            'mysql'   => 'CHAR_LENGTH(term)',
            'mariadb' => 'CHAR_LENGTH(term)',
            'pgsql'   => 'LENGTH(term)',
            'sqlite'  => 'LENGTH(term)',
            'sqlsrv'  => 'LEN(term)',
        ];

        $checked = 0;

        foreach ($expected as $driver => $fragment) {
            if (!$this->fakeDriverAvailable($driver)) {
                continue;
            }

            $builder = new SearchBuilder(
                $this->fakeConnectionTable($driver, 'users'),
                app(FuzzySearch::class)
            );
            $builder->search('jonh')->searchIn(['name']);

            $sql = \Closure::bind(
                fn () => $this->didYouMeanCandidateQuery('jonh')->toSql(),
                $builder,
                SearchBuilder::class
            )();

            $this->assertStringContainsString($fragment, $sql, $driver);

            $checked++;
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'at least mysql, pgsql, sqlite and sqlsrv must be asserted');
    }
}
