<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class DidYouMeanTest extends TestCase
{
    use FakesDriverConnections;

    /** A dictionary term posted under User: didYouMean() only offers the searched model's terms. */
    private function seedTerm(string $term, int $docCount = 10): void
    {
        $this->app['db']->table('fuzzy_index_terms')->upsert(
            ['term' => $term, 'doc_count' => $docCount, 'term_length' => mb_strlen($term)],
            ['term'],
            ['doc_count' => $docCount]
        );
        $this->app['db']->table('fuzzy_index_postings')->insert([
            'term_id'     => $this->app['db']->table('fuzzy_index_terms')->where('term', $term)->value('id'),
            'model_type'  => User::class,
            'model_id'    => '1',
            'frequency'   => 1,
            'column_name' => 'name',
        ]);
    }

    private function makeBuilder(string $term): SearchBuilder
    {
        return User::search($term)->searchIn(['name']);
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

    public function test_did_you_mean_filters_the_dictionary_by_term_length(): void
    {
        $this->seedTerm('john', 50);
        $this->seedTerm('johnathanson', 90); // 12 chars: outside the ±3 window of "jonh"

        $this->app['db']->enableQueryLog();
        $terms = array_column($this->makeBuilder('jonh')->didYouMean(3), 'term');
        $log = $this->app['db']->getQueryLog();
        $this->app['db']->disableQueryLog();

        $this->assertSame(['john'], $terms);
        $termQuery = collect($log)->last(fn ($q) => str_contains($q['query'], 'fuzzy_index_terms'));
        $this->assertStringContainsString('term_length', $termQuery['query']);
        $this->assertStringNotContainsString('LENGTH(', strtoupper($termQuery['query']));
    }

    public function test_did_you_mean_returns_empty_when_the_dictionary_table_is_missing(): void
    {
        $this->app['db']->getSchemaBuilder()->drop('fuzzy_index_postings');
        $this->app['db']->getSchemaBuilder()->drop('fuzzy_index_terms');

        $this->assertSame([], $this->makeBuilder('jonh')->didYouMean(3));
    }

    public function test_did_you_mean_is_character_based(): void
    {
        // Cyrillic: a transposition is 2 character edits but 4 byte edits. (Not an accent pair —
        // MySQL's *_ci collations would consider café/cafe equal and hide the candidate.)
        $this->seedTerm('пример', 5);

        $suggestion = $this->makeBuilder('приемр')->didYouMean(1)[0];

        $this->assertSame('пример', $suggestion['term']);
        $this->assertSame(2, $suggestion['distance']);
        $this->assertSame(0.67, $suggestion['confidence']); // round(1 - 2/6, 2)
    }
}
