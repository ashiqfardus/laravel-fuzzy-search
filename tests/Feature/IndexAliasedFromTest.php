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

        // candidate_chunk 200: the ranked ids are listed; 1: the postings subquery restricts the read.
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

                $this->assertSame(['Jane Doe'], $make()->where('u.email', 'jane@example.com')->get()->pluck('name')->all(), "{$at}: constrained get");
                $this->assertSame(1, $make()->where('u.email', 'jane@example.com')->count(), "{$at}: constrained count");
            }
        }
    }
}
