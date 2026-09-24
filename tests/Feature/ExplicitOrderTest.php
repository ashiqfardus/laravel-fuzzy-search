<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * SearchBuilder::orderBy() was thrown away: the LIKE path re-sorted its rows by _score in PHP,
 * the extended path never applied it and the index path walked the BM25 ranking. An explicit
 * orderBy() now replaces the relevance order on every path and terminal, in the order the calls
 * were made; the relevance order applies only when there is none.
 */
class ExplicitOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(IndexManager::class)->indexBatch(User::all());
    }

    /** @return array<string, \Closure(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder> */
    private function paths(): array
    {
        return [
            'like'     => fn () => User::search('john'),
            'extended' => fn () => User::search('')->extended('john | jane'),
            'index'    => fn () => User::search('john')->useInvertedIndex(),
        ];
    }

    /**
     * @param  \Closure(\Illuminate\Support\Collection): \Illuminate\Support\Collection $sort
     * @param  array<int, array{0: string, 1: string}>                                   $orders
     * @return string[] what came back in the wrong order, by path and terminal
     */
    private function misordered(array $orders, \Closure $sort): array
    {
        $wrong = [];

        foreach ($this->paths() as $path => $make) {
            $ordered = function () use ($make, $orders) {
                $builder = $make();
                foreach ($orders as [$column, $direction]) {
                    $builder->orderBy($column, $direction);
                }
                return $builder;
            };

            $expected = $sort($make()->get())->pluck('name')->all();
            $this->assertGreaterThan(2, count($expected), "{$path}: the fixture needs three matches");

            $got = [
                'get'            => $ordered()->get()->pluck('name')->all(),
                'first'          => [$ordered()->first()?->name],
                'paginate'       => collect([...$ordered()->paginate(2, 'page', 1)->items(), ...$ordered()->paginate(2, 'page', 2)->items()])->pluck('name')->all(),
                'simplePaginate' => collect([...$ordered()->simplePaginate(2, 'page', 1)->items(), ...$ordered()->simplePaginate(2, 'page', 2)->items()])->pluck('name')->all(),
            ];
            $want = [
                'get'            => $expected,
                'first'          => [$expected[0]],
                'paginate'       => array_slice($expected, 0, 4),
                'simplePaginate' => array_slice($expected, 0, 4),
            ];

            foreach ($got as $terminal => $names) {
                if ($names !== $want[$terminal]) {
                    $wrong[] = "{$path} {$terminal}: " . json_encode($names) . ' expected ' . json_encode($want[$terminal]);
                }
            }
        }

        return $wrong;
    }

    public function test_order_by_name_replaces_the_relevance_order_on_every_path(): void
    {
        $this->assertSame([], $this->misordered([['name', 'asc']], fn ($rows) => $rows->sortBy('name')->values()));
    }

    public function test_order_by_id_desc_replaces_the_relevance_order_on_every_path(): void
    {
        $this->assertSame([], $this->misordered([['id', 'desc']], fn ($rows) => $rows->sortByDesc('id')->values()));
    }

    public function test_several_order_by_calls_apply_in_the_order_given(): void
    {
        // Two rows share each created_at, so the second order decides within the first.
        User::query()->whereIn('name', ['John Doe', 'Johnny Bravo'])->update(['created_at' => '2020-01-01 00:00:00']);
        User::query()->whereNotIn('name', ['John Doe', 'Johnny Bravo'])->update(['created_at' => '2021-01-01 00:00:00']);

        $this->assertSame([], $this->misordered(
            [['created_at', 'desc'], ['name', 'desc']],
            fn ($rows) => $rows->sortBy([['created_at', 'desc'], ['name', 'desc']])->values()
        ));
    }

    /** stableRanking() adds the key, ascending, after the explicit order: the tiebreak on every path. */
    public function test_stable_ranking_breaks_ties_in_an_explicit_order_by_key(): void
    {
        User::query()->update(['created_at' => '2020-01-01 00:00:00']);

        foreach ($this->paths() as $path => $make) {
            $expected = $make()->get()->sortBy('id')->pluck('id')->values()->all();

            $this->assertSame($expected, $make()->orderBy('created_at')->stableRanking()->get()->pluck('id')->all(), $path);
            $this->assertSame(
                $expected,
                collect([...$make()->orderBy('created_at')->stableRanking()->paginate(2, 'page', 1)->items(), ...$make()->orderBy('created_at')->stableRanking()->paginate(2, 'page', 2)->items()])->pluck('id')->all(),
                "{$path} paginate"
            );
        }
    }

    public function test_scores_are_still_attached_under_an_explicit_order(): void
    {
        foreach ($this->paths() as $path => $make) {
            $this->assertNotNull($make()->orderBy('name')->get()->first()->_score, $path);
        }
    }
}
