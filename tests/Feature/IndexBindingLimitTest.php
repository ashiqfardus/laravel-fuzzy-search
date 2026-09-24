<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * SQL Server takes at most 2,100 bindings per statement. A document with a few hundred distinct
 * tokens (five bindings per posting row, three per dictionary row) went over it in one upsert,
 * and so did a batch's dictionary lookup, so indexing it failed there. Every statement the
 * indexer sends now stays under the limit; this runs on every database by counting bindings.
 */
class IndexBindingLimitTest extends TestCase
{
    private int $most = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function ($query) {
            $this->most = max($this->most, count($query->bindings));
        });
    }

    /** An unsaved model: users.name could not hold the text, and the indexer only reads attributes and the key. */
    private function user(int $id, int $from, int $count): User
    {
        $words = array_map(fn ($i) => 'w' . $i, range($from, $from + $count - 1));

        return (new User(['name' => implode(' ', $words), 'email' => "u{$id}@example.com"]))->forceFill(['id' => $id]);
    }

    public function test_indexing_one_large_document_stays_under_2100_bindings_a_statement(): void
    {
        app(IndexManager::class)->indexModel($this->user(9001, 1, 2200));

        $this->assertLessThanOrEqual(2100, $this->most);
        $this->assertSame(2200 + 3, DB::table('fuzzy_index_postings')->where('model_id', '9001')->count()); // + u9001, example, com
    }

    public function test_indexing_a_batch_stays_under_2100_bindings_a_statement(): void
    {
        app(IndexManager::class)->indexBatch(collect([$this->user(9001, 1, 1100), $this->user(9002, 1101, 1100)]));

        $this->assertLessThanOrEqual(2100, $this->most);
        $this->assertSame(2, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
        $this->assertSame(2 * (1100 + 3), DB::table('fuzzy_index_postings')->where('model_type', User::class)->count());
    }
}
