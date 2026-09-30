<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * L10 (round 9), ruling D13. The index path matched the rows it read to their ranked ids by the
 * models' keys, so a select() that left the key out (a slim autocomplete payload) served no row,
 * while total() counted them. The key is now read under an alias of the read's own, which never
 * reaches the models: not their attributes, not their original, and not a cached copy of them.
 */
class IndexSelectWithoutKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IndexManager::class)->indexBatch(User::all());
    }

    /** @return array<string, \Closure(\Closure(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder): iterable> each terminal's rows */
    private static function terminals(): array
    {
        return [
            'get'                => fn ($make) => $make()->get(),
            'first'              => fn ($make) => array_filter([$make()->first()]),
            'paginate'           => fn ($make) => $make()->paginate(1, 'page', 2)->items(),
            'simplePaginate'     => fn ($make) => $make()->simplePaginate(1, 'page', 2)->items(),
            'orderBy get'        => fn ($make) => $make()->orderBy('name', 'desc')->get(),
            'constrained get'    => fn ($make) => $make()->where('email', 'like', '%@example.com')->get(),
            'constrained page'   => fn ($make) => $make()->where('email', 'like', '%@example.com')->paginate(1, 'page', 2)->items(),
            'cache miss and hit' => fn ($make) => [...$make()->cache(10)->get(), ...$make()->cache(10)->get()],
        ];
    }

    public function test_a_select_without_the_key_serves_every_row_it_counts(): void
    {
        $make = fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex()->query(fn ($query) => $query->select('name', 'email'));

        $this->assertSame(2, $make()->paginate(1)->total());
        $this->assertSame(2, $make()->count());

        // Scored as the rows of the same search with every column.
        $scores = fn ($rows) => collect($rows)->map(fn ($user) => [$user->name, $user->_raw_score, $user->_score])->all();
        $this->assertSame($scores(User::search('doe')->typoTolerance(0)->useInvertedIndex()->get()), $scores($make()->get()));
        $this->assertGreaterThan(0, $make()->get()->first()->_raw_score);

        $served = array_map(fn ($rows) => collect($rows)->map(fn ($user) => [$user->name, array_keys($user->getAttributes()), array_keys($user->getOriginal()), array_keys($user->toArray())])->all(), array_map(fn ($terminal) => $terminal($make), self::terminals()));

        $row = fn (string $name) => [$name, ['name', 'email', '_raw_score', '_score'], ['name', 'email'], ['name', 'email', '_raw_score', '_score']];

        $this->assertSame([
            'get'                => [$row('John Doe'), $row('Jane Doe')],
            'first'              => [$row('John Doe')],
            'paginate'           => [$row('Jane Doe')],
            'simplePaginate'     => [$row('Jane Doe')],
            'orderBy get'        => [$row('John Doe'), $row('Jane Doe')],
            'constrained get'    => [$row('John Doe'), $row('Jane Doe')],
            'constrained page'   => [$row('Jane Doe')],
            'cache miss and hit' => [$row('John Doe'), $row('Jane Doe'), $row('John Doe'), $row('Jane Doe')],
        ], $served);
    }

    /**
     * A read's row whose key is NULL matches no ranked id, and is skipped: a ROLLUP's summary row
     * failed the key reads (array_flip() of a null) and, on PHP 8.5, used null as an array offset.
     * The ids a read is restricted to keep every other NULL key out (a right join's row, say).
     *
     * L15a (round 10): the served rows are the two matches, on every PHP version. Without models()'
     * null check a summary row is filed under the '' key on PHP 8.4 and earlier, which no ranked id
     * has, so it is still not served there (an equivalent mutant); the PHP 8.5 CI cells fail on the
     * deprecation instead.
     */
    public function test_a_row_without_a_key_is_skipped(): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite has no ROLLUP; the CI MySQL, MariaDB, PostgreSQL and SQL Server jobs run this.');
        }

        $rollup = in_array($this->dbDriver, ['mysql', 'mariadb'], true) ? 'users.id with rollup' : 'rollup(users.id)';
        $make   = fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex()
            ->select('users.id')->selectRaw('max(users.name) as name')->groupByRaw($rollup);

        $page = $make()->paginate(1, 'page', 2);

        // The summary row (a NULL key, and on MariaDB the last group's key under the alias) is not served.
        $this->assertSame(User::whereIn('name', ['John Doe', 'Jane Doe'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            $make()->get()->map(fn ($user) => (int) $user->getKey())->sort()->values()->all());

        $this->assertSame([['John Doe', 'Jane Doe'], 2, 2, ['Jane Doe']], [
            $make()->get()->pluck('name')->all(),
            $make()->count(),
            $page->total(),
            $page->pluck('name')->all(),
        ]);
    }
}
