<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * min_search_length used to govern get() alone: User::search('j')->get() returned [] while
 * paginate(), count(), getFacets(), FederatedSearch, FuzzySearch::on() and the Scout engine
 * searched the same term normally. A plain term shorter than the minimum now matches nothing
 * on every search API; an extended()/searchBoolean() query is not measured.
 */
class MinSearchLengthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['fuzzy-search.min_search_length' => 3]);
        // 'jo' is a whole indexed token here, so the BM25 paths would find it too.
        User::create(['name' => 'Jo Malone', 'email' => 'jo@example.com']);
        User::create(['name' => 'মোবাইলফোন', 'email' => 'bn@example.com']);
        app(IndexManager::class)->indexBatch(User::all());
    }

    /** @return array<string, array{string, Closure(object): mixed, mixed}> */
    public static function terminals(): array
    {
        return [
            'get'                      => ['builder', fn ($b) => $b->get()->all(), []],
            'first'                    => ['builder', fn ($b) => $b->first(), null],
            'paginate'                 => ['builder', fn ($b) => [($p = $b->paginate(5))->total(), $p->items()], [0, []]],
            'simplePaginate'           => ['builder', fn ($b) => $b->simplePaginate(5)->items(), []],
            'count'                    => ['builder', fn ($b) => $b->count(), 0],
            'getFacets'                => ['builder', fn ($b) => $b->facet('email')->getFacets(), ['email' => []]],
            'federated get'            => ['federated', fn ($f) => $f->get()->all(), []],
            'federated paginate'       => ['federated', fn ($f) => [($p = $f->paginate(5))->total(), $p->items()], [0, []]],
            'federated simplePaginate' => ['federated', fn ($f) => $f->simplePaginate(5)->items(), []],
            'federated getCounts'      => ['federated', fn ($f) => $f->getCounts(), ['User' => 0, 'MinLengthPlainUser' => 0]],
            'federated getGrouped'     => ['federated', fn ($f) => $f->getGrouped()->all(), []],
            'in-memory get'            => ['in-memory', fn ($m) => $m->get()->all(), []],
        ];
    }

    #[DataProvider('terminals')]
    public function test_a_term_below_the_minimum_matches_nothing_and_fires_no_event(string $subject, Closure $run, mixed $expected): void
    {
        $events = 0;
        Event::listen(FuzzySearchExecuted::class, function () use (&$events) {
            $events++;
        });

        $makers = match ($subject) {
            'builder' => [
                'like'                         => fn () => User::search('jo'),
                'inverted index'               => fn () => User::search('jo')->useInvertedIndex(),
                'fallback'                     => fn () => User::search('jo')->fallback('levenshtein'),
                'cached'                       => fn () => User::search('jo')->cache(5),
                'multibyte (2 chars, 6 bytes)' => fn () => User::search('ফো'),
            ],
            // The plain model takes the whereFuzzyMultiple() branch, not a SearchBuilder.
            'federated' => ['federated' => fn () => FederatedSearch::across([User::class, MinLengthPlainUser::class])->search('jo')->searchIn(['name', 'email'])],
            'in-memory' => ['in-memory' => fn () => FuzzySearch::on([['name' => 'John Doe'], ['name' => 'Jo Malone']])->search('jo')->searchIn(['name'])],
        };

        foreach ($makers as $path => $make) {
            $this->assertSame($expected, $run($make()), $path);
        }

        $this->assertSame(0, $events, 'nothing ran, so nothing reaches FuzzySearchExecuted listeners or the analytics log');
    }

    public function test_a_term_at_the_minimum_and_an_extended_query_still_search(): void
    {
        $makers = [
            'at the minimum'           => fn () => User::search('doe'),
            'at the minimum, index'    => fn () => User::search('doe')->useInvertedIndex(),
            'multibyte at the minimum' => fn () => User::search('ফোন'), // 3 characters, 9 bytes
            'extended()'               => fn () => User::search('jo')->extended(),
            'extended(term)'           => fn () => User::search('')->extended('jo'),
            'searchBoolean(term)'      => fn () => User::search('')->searchBoolean('jo'),
        ];

        foreach ($makers as $path => $make) {
            $this->assertNotEmpty($make()->get(), "{$path} get");
            $this->assertGreaterThan(0, $make()->paginate(5)->total(), "{$path} paginate");
            $this->assertGreaterThan(0, $make()->count(), "{$path} count");
            $this->assertNotEmpty($make()->facet('email')->getFacets()['email'], "{$path} getFacets");
        }

        $federated = FederatedSearch::across([User::class, MinLengthPlainUser::class])->search('doe')->searchIn(['name', 'email']);
        $this->assertNotEmpty($federated->get());
        $this->assertSame(['User' => 2, 'MinLengthPlainUser' => 2], $federated->getCounts());
        $this->assertCount(2, FuzzySearch::on([['name' => 'John Doe'], ['name' => 'Jane Doe']])->search('doe')->searchIn(['name'])->get());
    }

    /** The short-circuit's empty paginator used max(1, $perPage), not paginate()'s max_candidates clamp. */
    public function test_paginate_clamps_the_page_size_when_the_search_matches_nothing(): void
    {
        config(['fuzzy-search.max_candidates' => 10]);

        foreach (['doe' => 'a real search', 'jo' => 'below the minimum', "\xFF" => 'invalid bytes only'] as $term => $case) {
            $this->assertSame(10, User::search($term)->paginate(50)->perPage(), $case);
            $this->assertSame(1, User::search($term)->paginate(0)->perPage(), $case);
        }
    }

    public function test_the_scout_engine_matches_nothing_below_the_minimum(): void
    {
        if (!class_exists(\Laravel\Scout\Builder::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = new FuzzySearchEngine(new IndexManager(new WhitespaceTokenizer(), new NullStemmer()), new Bm25Scorer());
        $engine->update(User::all());

        $search = fn (string $term) => $engine->search(new \Laravel\Scout\Builder(new User(), $term));
        $page   = fn (string $term) => $engine->paginate(new \Laravel\Scout\Builder(new User(), $term), 10, 1);

        foreach (['search' => $search, 'paginate' => $page] as $method => $run) {
            $this->assertSame(0, $engine->getTotalCount($run('jo')), "{$method} 'jo'");
            $this->assertSame([], $engine->mapIds($run('jo'))->all(), "{$method} 'jo'");
            $this->assertSame(2, $engine->getTotalCount($run('doe')), "{$method} 'doe' is at the minimum");
        }
    }
}

/** No Searchable trait: FederatedSearch searches it through whereFuzzyMultiple(). */
class MinLengthPlainUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
}
