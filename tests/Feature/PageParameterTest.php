<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Http\Request;

/**
 * ?page is request input. The index path used it unsanitised: ?page=abc was a TypeError (a 500)
 * and ?page=0 served the last row. Every paginator reads it through one rule: page 1 unless it
 * is a whole number of at least 1, and a page past the integer range is an empty page.
 */
class PageParameterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(IndexManager::class)->indexBatch(User::all());
    }

    /** @return array<string, \Closure(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder> */
    private function shapes(): array
    {
        return [
            'like'     => fn () => User::search('john')->using('like'),
            'index'    => fn () => User::search('john')->useInvertedIndex(),
            'extended' => fn () => User::search("'john")->extended(),
            'nothing'  => fn () => User::search('j'), // below min_search_length
        ];
    }

    public function test_a_bad_page_parameter_is_page_one_on_every_paginator(): void
    {
        $wrong = [];

        foreach (['abc', '0', '-3', ['1']] as $input) {
            $label = json_encode($input);

            foreach ($this->shapes() as $shape => $make) {
                foreach ([
                    'paginate'       => fn ($b, ?int $page = null) => $b->paginate(1, 'page', $page),
                    'simplePaginate' => fn ($b, ?int $page = null) => $b->simplePaginate(1, 'page', $page),
                ] as $paginator => $run) {
                    $this->app->instance('request', Request::create('/search', 'GET'));
                    $expected = collect($run($make(), 1)->items())->pluck('id')->all();

                    $this->app->instance('request', Request::create('/search', 'GET', ['page' => $input]));

                    try {
                        $result = $run($make());
                        $got    = [$result->currentPage(), collect($result->items())->pluck('id')->all()];
                    } catch (\Throwable $e) {
                        $got = get_class($e) . ': ' . $e->getMessage();
                    }

                    if ($got !== [1, $expected]) {
                        $wrong[] = "?page={$label} {$shape} {$paginator}: " . json_encode($got) . ' expected ' . json_encode([1, $expected]);
                    }
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    public function test_a_page_past_the_integer_range_is_an_empty_page_not_an_error(): void
    {
        $this->app->instance('request', Request::create('/search', 'GET', ['page' => '99999999999999999999']));

        foreach ($this->shapes() as $shape => $make) {
            $this->assertSame([], $make()->paginate(1)->items(), "{$shape} paginate");
            $this->assertSame([], $make()->simplePaginate(1)->items(), "{$shape} simplePaginate");
        }
    }
}
