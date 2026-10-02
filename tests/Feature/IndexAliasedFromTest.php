<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * A2 (round 8), ruling ER-107. The index path named the key as the model's table does
 * ("users"."id"), so a search whose FROM is aliased (from('users as u')) or a subquery
 * (fromSub(…, 'u')) threw on every index terminal that reached the table: "no such column",
 * "invalid reference to FROM-clause entry", 1054. The key is now named as the FROM names it.
 */
class IndexAliasedFromTest extends TestCase
{
    /** @return array<string, \Closure(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder> */
    private function sources(): array
    {
        return [
            'from(users as u)' => fn () => User::search('doe')->typoTolerance(0)->from('users as u')->useInvertedIndex(),
            'fromSub(…, u)'    => fn () => User::search('doe')->typoTolerance(0)->fromSub(DB::table('users'), 'u')->useInvertedIndex(),
        ];
    }

    public function test_every_index_terminal_reads_an_aliased_or_subquery_from(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        // candidate_chunk 200: the ranked ids are listed; 1: past one chunk, the whole ranking is
        // listed too, but on SQL Server, where the postings subquery restricts the read (ruling ER-132).
        foreach ([200, 1] as $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);

            foreach ($this->sources() as $label => $make) {
                $at = "{$label}, chunk {$chunk}";

                $this->assertEqualsCanonicalizing(['John Doe', 'Jane Doe'], $make()->get()->pluck('name')->all(), "{$at}: get");

                $page = $make()->paginate(5);
                $this->assertSame(2, $page->total(), "{$at}: paginate total");
                $this->assertEqualsCanonicalizing(['John Doe', 'Jane Doe'], $page->pluck('name')->all(), "{$at}: paginate");

                $ordered = $make()->orderBy('name')->paginate(1, 'page', 2);
                $this->assertSame(2, $ordered->total(), "{$at}: orderBy total");
                $this->assertSame(['John Doe'], $ordered->pluck('name')->all(), "{$at}: orderBy page 2");
                $this->assertSame(2, $make()->orderBy('name')->count(), "{$at}: orderBy count");

                // Ordered by the alias's key: the key is not named again as the tie-break (SQL Server
                // rejects "a column specified more than once in the order by list").
                $byKey = User::query()->whereIn('name', ['John Doe', 'Jane Doe'])->orderByDesc('id')->pluck('name')->all();
                $this->assertSame($byKey, $make()->orderBy('u.id', 'desc')->get()->pluck('name')->all(), "{$at}: orderBy(u.id) get");
                $this->assertSame([$byKey[1]], $make()->orderBy('u.id', 'desc')->paginate(1, 'page', 2)->pluck('name')->all(), "{$at}: orderBy(u.id) page 2");

                $this->assertSame(['Jane Doe'], $make()->where('u.email', 'jane@example.com')->get()->pluck('name')->all(), "{$at}: constrained get");
                $this->assertSame(1, $make()->where('u.email', 'jane@example.com')->count(), "{$at}: constrained count");
            }
        }
    }

    /** A ranking capped at bm25.max_postings_per_term: the postings subquery names the key through the alias too. */
    public function test_an_ordered_read_of_a_capped_ranking_reads_an_aliased_or_subquery_from(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        config(['fuzzy-search.bm25.max_postings_per_term' => 1]);

        $byKey = User::query()->whereIn('name', ['John Doe', 'Jane Doe'])->orderByDesc('id')->pluck('name')->all();

        foreach ($this->sources() as $label => $make) {
            $this->assertSame(['Jane Doe', 'John Doe'], $make()->orderBy('name')->get()->pluck('name')->all(), "{$label}: orderBy get");
            $this->assertSame(2, $make()->orderBy('name')->paginate(1)->total(), "{$label}: orderBy total");
            $this->assertSame([$byKey[1]], $make()->orderBy('u.id', 'desc')->paginate(1, 'page', 2)->pluck('name')->all(), "{$label}: orderBy(u.id) page 2");
            $this->assertSame(['Jane Doe'], $make()->where('u.email', 'jane@example.com')->orderBy('name')->get()->pluck('name')->all(), "{$label}: constrained orderBy");
        }
    }

    /**
     * R11-L3. Laravel counts a grouped query that joins a table and selects nothing as
     * select <its FROM>.*, which is "users as u".* under an alias and fails under a fromSub(): the
     * total of a grouped search with a join under either threw, a capped ranking's too, where S3's
     * own join made every capped ordered read such a query. The count now selects the key's table.
     */
    public function test_a_grouped_read_with_a_join_under_an_aliased_or_subquery_from_counts_its_models(): void
    {
        if (!in_array($this->dbDriver, ['sqlite', 'mysql'], true)) {
            $this->markTestSkipped('A grouped select of every column of a join or a derived table: PostgreSQL, MariaDB and SQL Server reject it, since the grouped key does not determine those columns for them; the CI SQLite and MySQL jobs run this.');
        }

        app(IndexManager::class)->indexBatch(User::all());

        foreach (['whole ranking' => 50000, 'capped ranking' => 1] as $ranking => $cap) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);

            foreach ($this->sources() as $label => $make) {
                foreach (['groupBy()' => fn ($search) => $search, 'a join and groupBy()' => fn ($search) => $search->join('users as w', 'w.id', '=', 'u.id')] as $shape => $scope) {
                    $at     = "{$label}, {$shape}, {$ranking}";
                    $search = fn () => $scope($make())->groupBy('u.id');

                    $page = $search()->orderBy('u.name')->paginate(1, 'page', 2);
                    $this->assertSame(2, $page->total(), "{$at}: orderBy total");
                    $this->assertSame(['John Doe'], $page->pluck('name')->all(), "{$at}: orderBy page 2");
                    $this->assertSame(2, $search()->orderBy('u.name')->count(), "{$at}: orderBy count");

                    // In rank order a capped ranking serves its ranked matches only.
                    $this->assertSame($cap > 1 ? 2 : 1, $search()->paginate(5)->total(), "{$at}: paginate total");
                    $this->assertCount($cap > 1 ? 2 : 1, $search()->get(), "{$at}: get");
                }
            }
        }
    }
}
