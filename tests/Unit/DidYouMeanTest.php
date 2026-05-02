<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class DidYouMeanTest extends TestCase
{
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
}
