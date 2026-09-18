<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchCollection;
use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchResource;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Http\Request;

class JsonResourcesTest extends TestCase
{
    // Named resourceJson(), not json(): Orchestra\Testbench\TestCase already declares a
    // public json() (MakesHttpRequests), and a private override is a PHP fatal error
    // (access level must not be more restrictive than the parent's).
    private function resourceJson($resource): array
    {
        return json_decode($resource->toResponse(Request::create('/'))->getContent(), true);
    }

    public function test_last_execution_is_recorded_after_get(): void
    {
        $builder = User::search('jonh');
        $this->assertNull($builder->lastExecution());

        $builder->get();

        $this->assertSame('like', $builder->lastExecution()->path);
        $this->assertGreaterThanOrEqual(0, $builder->lastExecution()->latencyMs);
    }

    public function test_the_resource_promotes_underscore_attributes(): void
    {
        // 'john' (not 'jonh'): the default LIKE-path highlighter marks literal substring
        // occurrences, and 'jonh' is a typo match for "John Doe", not a literal one — it
        // would leave _highlighted['name'] untagged. 'john' matches several seeded users
        // (also "Bob Johnson", "Johnny Bravo"), so pick the John Doe row explicitly.
        $user = User::search('john')->highlight('mark')->get()->firstWhere('name', 'John Doe');

        $data = $this->resourceJson(new FuzzySearchResource($user));

        $this->assertSame('John Doe', $data['data']['name']);
        $this->assertArrayNotHasKey('_score', array_diff_key($data['data'], array_flip(['_score', '_raw_score', '_highlighted', '_matches', '_model_type'])));
        // assertIsNumeric, not assertIsFloat: John Doe ties for the top-ranked (normalized)
        // score here, so _score is PHP float 1.0 — json_encode() prints a whole-number float
        // without a decimal point ("1", not "1.0"), and json_decode() reads that back as an
        // int. Round-tripping through the real HTTP response (as this test does) can't keep
        // a clean 1.0 typed as float; meta.latency_ms below is checked the same way.
        $this->assertIsNumeric($data['data']['_score']);
        $this->assertSame('User', $data['data']['_model_type']);
        $this->assertStringContainsString('<mark>', $data['data']['_highlighted']['name']);
        $this->assertIsArray($data['data']['_matches']);
    }

    public function test_the_collection_carries_query_algorithm_latency_and_no_suggestions_when_there_are_results(): void
    {
        $data = $this->resourceJson(FuzzySearchCollection::fromBuilder(User::search('jonh')));

        // 'jonh' is one edit from "Jon Snow" and two from "John Doe" — relevance ordering
        // puts Jon Snow first (see tests/Integration/Filament/GlobalSearchTest.php for the
        // same behaviour), so assert presence, not position.
        $this->assertContains('John Doe', array_column($data['data'], 'name'));
        $this->assertSame('jonh', $data['meta']['query']);
        $this->assertSame('fuzzy', $data['meta']['algorithm']);
        $this->assertIsNumeric($data['meta']['latency_ms']);
        $this->assertSame([], $data['meta']['suggestions']);
    }

    public function test_an_empty_collection_suggests_from_the_dictionary(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $data = $this->resourceJson(FuzzySearchCollection::fromBuilder(User::search('zzzz')->useInvertedIndex()));

        $this->assertSame([], $data['data']);
        $this->assertSame('bm25', $data['meta']['algorithm']);
        $this->assertIsArray($data['meta']['suggestions']); // didYouMean() terms — may be empty for a hopeless term

        // typoTolerance(0) so the BM25 path itself does not silently correct "jonhh" to
        // "john" (its default typo tolerance would otherwise return real hits for this
        // term, leaving nothing to suggest); didYouMean()'s own dictionary lookup uses a
        // fixed radius independent of typoTolerance() and still proposes "john".
        $suggested = $this->resourceJson(FuzzySearchCollection::fromBuilder(User::search('jonhh')->useInvertedIndex()->typoTolerance(0)));
        $this->assertSame([], $suggested['data']);
        $this->assertContains('john', $suggested['meta']['suggestions']);
    }

    public function test_the_collection_can_paginate(): void
    {
        $data = $this->resourceJson(FuzzySearchCollection::fromBuilder(User::search('o'), 2));

        $this->assertCount(2, $data['data']);
        $this->assertSame(2, $data['meta']['per_page']);
        $this->assertArrayHasKey('links', $data);
        $this->assertSame('o', $data['meta']['query']);
    }

    /**
     * Ruling P8-R10: applyHighlighting() now escapes the non-matching branch too, so
     * _highlighted is uniformly safe HTML for API consumers — not just the matched columns.
     */
    public function test_non_matching_highlighted_values_are_html_escaped(): void
    {
        User::create(['name' => '<b>Bob</b>', 'email' => 'bob@xss-test.com']);

        $user = User::search('bob@')->highlight('mark')->get()->firstWhere('name', '<b>Bob</b>');

        $this->assertNotNull($user);
        $this->assertSame('&lt;b&gt;Bob&lt;/b&gt;', $user->_highlighted['name']);
        $this->assertStringContainsString('<mark>', $user->_highlighted['email']);
    }
}
