<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * A flush keeps the ids of the words it handled packed in a string and counts those words again at
 * its end. Word ids are bigint, so an id past 2^32 must come back whole, and a list longer than
 * one 1,000-id transaction must be read in full.
 */
class RecountWordsBigIdTest extends TestCase
{
    /** Inserts rows with explicit ids; SQL Server allows that only with IDENTITY_INSERT on. */
    private function withIds(\Closure $insert): void
    {
        $sqlsrv = DB::connection()->getDriverName() === 'sqlsrv';
        $sqlsrv && DB::unprepared('SET IDENTITY_INSERT fuzzy_index_terms ON');
        try {
            $insert();
        } finally {
            $sqlsrv && DB::unprepared('SET IDENTITY_INSERT fuzzy_index_terms OFF');
        }
    }

    public function test_word_ids_past_2_to_the_32_are_counted_again(): void
    {
        $ids  = [5, 4_294_967_301, 1_099_511_627_776];
        $type = DB::table('fuzzy_index_model_types')->insertGetId(['model_type' => 'App\\Models\\BigIdNote']);
        foreach ($ids as $i => $id) {
            $this->withIds(fn () => DB::table('fuzzy_index_terms')->insert(['id' => $id, 'term' => "word{$i}", 'term_length' => 5, 'doc_count' => 99]));
            DB::table('fuzzy_index_model_terms')->insert(['model_type_id' => $type, 'term_id' => $id, 'term' => "word{$i}", 'term_length' => 5, 'doc_count' => $i + 1]);
        }

        $recount = new \ReflectionMethod(IndexManager::class, 'recountWords');
        $recount->invoke(app(IndexManager::class), pack('J*', ...$ids));

        $this->assertSame([5 => 1, 4_294_967_301 => 2, 1_099_511_627_776 => 3],
            DB::table('fuzzy_index_terms')->orderBy('id')->pluck('doc_count', 'id')->map(fn ($c) => (int) $c)->all());
    }

    public function test_a_list_past_one_transaction_is_read_in_full(): void
    {
        $ids = range(1, 1_005);
        foreach (array_chunk($ids, 400) as $chunk) { // 400 rows x 4 columns stays under SQL Server's 2,100 bindings
            $this->withIds(fn () => DB::table('fuzzy_index_terms')->insert(array_map(fn ($id) => ['id' => $id, 'term' => "w{$id}", 'term_length' => 4, 'doc_count' => 7], $chunk)));
        }

        $recount = new \ReflectionMethod(IndexManager::class, 'recountWords');
        $recount->invoke(app(IndexManager::class), pack('J*', ...$ids));

        $this->assertSame(0, DB::table('fuzzy_index_terms')->where('doc_count', '<>', 0)->count());
    }
}
