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
 * so do unions, whose keys are read from the union's rows (a union beside a where() failed: the key
 * read left the union's parts with select lists of different lengths).
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
}
