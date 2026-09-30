<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * L2 (round 9). The index path took a query with no where() and no join as unconstrained, and
 * served its ranking as it stood, so total() and count() counted matches the pages never served
 * under a having(), a GROUP BY or a FROM subquery: a fromSub() tenant scope holding one "Doe" had
 * a total of 2, which told the tenant a match existed outside it. Those constrain it too now, and
 * so do unions, which the index path reads as one derived table (a union beside a where() failed:
 * the key read left the union's parts with select lists of different lengths).
 */
class IndexUndetectedConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IndexManager::class)->indexBatch(User::all());
    }

    /** @return array{int, int, string[], string[], string[]} total(), count(), and the names page 1, page 2 and get() serve */
    private static function served(\Closure $make): array
    {
        return [
            $make()->paginate(1)->total(),
            $make()->count(),
            $make()->paginate(1, 'page', 1)->pluck('name')->all(),
            $make()->paginate(1, 'page', 2)->pluck('name')->all(),
            $make()->get()->pluck('name')->all(),
        ];
    }

    public function test_the_total_is_what_the_pages_serve_under_a_from_subquery_or_a_group_by(): void
    {
        $doe      = fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex();
        $expected = [1, 1, ['Jane Doe'], [], ['Jane Doe']];

        $this->assertSame([
            'fromSub() scope'      => $expected,
            'groupBy() + having()' => $expected,
            'where() + union()'    => [2, 2, ['John Doe'], ['Jane Doe'], ['John Doe', 'Jane Doe']],
        ], [
            'fromSub() scope'      => self::served(fn () => $doe()->fromSub(DB::table('users')->where('email', 'jane@example.com'), 'users')),
            'groupBy() + having()' => self::served(fn () => $doe()->select('users.id', 'users.name', 'users.email')->groupBy('users.id', 'users.name', 'users.email')->having('users.email', '=', 'jane@example.com')),
            // The union adds John Doe back: the matches either part holds.
            'where() + union()'    => self::served(fn () => $doe()->where('email', 'jane@example.com')->query(fn ($query) => $query->union(User::query()->where('name', 'John Doe')))),
        ]);
    }

    public function test_the_total_is_what_the_pages_serve_under_a_having_on_a_select_alias(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('HAVING on a select alias without GROUP BY is MySQL/MariaDB syntax; the CI MySQL and MariaDB jobs run this.');
        }

        $this->assertSame([1, 1, ['Jane Doe'], [], ['Jane Doe']], self::served(fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex()
            ->select('users.*')->selectRaw("case when email = 'jane@example.com' then 1 else 0 end as is_jane")->having('is_jane', '=', 1)));
    }

    /** suggest() and didYouMean() offer only the words a fromSub() scope can see, as they do under the where() it holds. */
    public function test_word_suggestions_under_a_from_subquery_scope(): void
    {
        $scoped = fn (string $term) => User::search($term)->fromSub(DB::table('users')->where('email', 'jane@example.com'), 'users');
        $where  = fn (string $term) => User::search($term)->where('email', 'jane@example.com');

        $this->assertSame($where('jo')->suggest(), $scoped('jo')->suggest());
        $this->assertSame(array_column($where('jonh')->didYouMean(), 'term'), array_column($scoped('jonh')->didYouMean(), 'term'));
    }

    /**
     * A union is read as one derived table (ruling ER-125). orderBy() with a union threw in the COUNT of
     * its matches, which named "users"."id" outside the union, and a page in rank order read the
     * union's other parts whole, since the ranked ids restricted only its first.
     */
    public function test_a_union_serves_the_matches_either_part_holds_in_either_order(): void
    {
        $union = fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex()->where('email', 'jane@example.com')
            ->query(fn ($query) => $query->union(User::query()->where('name', 'John Doe')));

        $found = [];
        foreach (['rank order' => $union, 'orderBy()' => fn () => $union()->orderBy('name')] as $order => $make) {
            try {
                $found[$order] = self::served($make);
            } catch (\Illuminate\Database\QueryException $e) {
                $found[$order] = strtok($e->getMessage(), "\n");
            }
        }

        $this->assertSame([
            'rank order' => [2, 2, ['John Doe'], ['Jane Doe'], ['John Doe', 'Jane Doe']],
            'orderBy()'  => [2, 2, ['Jane Doe'], ['John Doe'], ['Jane Doe', 'John Doe']],
        ], $found);
    }

    public function test_a_union_page_hydrates_its_own_rows(): void
    {
        $hydrated = 0;
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: ' . User::class, function () use (&$hydrated) {
            $hydrated++;
        });

        // The union's other part is every row.
        $union = fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex()->query(fn ($query) => $query->union(User::query()));
        $pages = [];

        foreach (['rank order' => $union, 'orderBy()' => fn () => $union()->orderBy('name')] as $order => $make) {
            $hydrated = 0;

            try {
                $pages[$order] = [$make()->paginate(1, 'page', 2)->pluck('name')->all(), $hydrated];
            } catch (\Illuminate\Database\QueryException $e) {
                $pages[$order] = strtok($e->getMessage(), "\n");
            }
        }

        $this->assertSame(['rank order' => [['Jane Doe'], 1], 'orderBy()' => [['John Doe'], 1]], $pages);
    }

    /**
     * L13 (round 10): a union whose parts select no key has none in the derived table the index path
     * reads it as, and every read failed with the database's unknown-column error, which did not say
     * what to do. It throws a LogicException that names the fix; a union whose parts select the key
     * is served, a select() of its own without the key too (ruling D13).
     */
    public function test_a_union_that_selects_no_key_is_rejected_naming_the_fix(): void
    {
        $union = fn (array $columns) => User::search('doe')->typoTolerance(0)->useInvertedIndex()->select($columns)->where('id', '>', 0)
            ->query(fn ($query) => $query->union(User::query()->select($columns)->where('name', 'Alice Smith')));

        $reads = [
            'get'              => fn ($search) => $search->get(),
            'first'            => fn ($search) => $search->first(),
            'count'            => fn ($search) => $search->count(),
            'paginate'         => fn ($search) => $search->paginate(5),
            'orderBy paginate' => fn ($search) => $search->orderBy('name')->paginate(5),
        ];

        foreach ($reads as $read => $run) {
            try {
                $run($union(['name', 'email']));
                $this->fail("{$read}: served a union without the key");
            } catch (\LogicException $e) {
                $this->assertStringContainsString("select the key ('id') in every part of the union", $e->getMessage(), $read);
            }
        }

        $this->assertSame(['Jane Doe', 'John Doe'], $union(['id', 'name', 'email'])->orderBy('name')->get()->pluck('name')->all());
        $this->assertSame(['Jane Doe', 'John Doe'], $union(['users.id', 'name', 'email'])->orderBy('name')->get()->pluck('name')->all());
        $this->assertSame(['Jane Doe', 'John Doe'], $union(['*'])->orderBy('name')->get()->pluck('name')->all());
    }

    /**
     * didYouMean() checks its candidates against the base query's keys (ruling ER-127): under a union
     * base that read added a column to the union's first part only, and failed.
     */
    public function test_did_you_mean_under_a_union_reads_the_union_as_one_table(): void
    {
        $union = fn () => User::query()->where('email', 'like', 'jo%')->union(User::query()->where('name', 'Bob Johnson'));

        $asUnion   = array_column(User::search('jonh')->useInvertedIndex()->query(fn ($query) => $query->where('email', 'like', 'jo%')->union(User::query()->where('name', 'Bob Johnson')))->didYouMean(), 'term');
        $asFromSub = array_column(User::search('jonh')->useInvertedIndex()->fromSub($union(), 'users')->didYouMean(), 'term');

        $this->assertNotSame([], $asFromSub);
        $this->assertSame($asFromSub, $asUnion);
    }

    /**
     * A union's own orderBy() orders nothing once the union is one derived table, and SQL Server
     * rejects an ORDER BY in a derived table without TOP or OFFSET, so it is dropped when the union
     * has no limit or offset of its own.
     */
    public function test_a_union_with_an_order_of_its_own_in_either_order(): void
    {
        $union = fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex()
            ->query(fn ($query) => $query->where('email', 'jane@example.com')->union(User::query()->where('name', 'John Doe'))->orderBy('name', 'desc'));

        $found = [];
        foreach (['rank order' => $union, 'orderBy()' => fn () => $union()->orderBy('name')] as $order => $make) {
            try {
                $found[$order] = [$make()->get()->pluck('name')->all(), $make()->count(), $make()->paginate(1, 'page', 2)->pluck('name')->all()];
            } catch (\Illuminate\Database\QueryException $e) {
                $found[$order] = strtok($e->getMessage(), "\n");
            }
        }

        $this->assertSame([
            'rank order' => [['John Doe', 'Jane Doe'], 2, ['Jane Doe']],
            'orderBy()'  => [['Jane Doe', 'John Doe'], 2, ['John Doe']],
        ], $found);
    }
}
