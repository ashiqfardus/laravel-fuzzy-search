<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * Rows with the same BM25 score come back by model key, ascending. The postings arrive in
 * whatever order the database's plan produces (model_id is a string column, so "10" often
 * sorts before "8"), and that order used to decide ties.
 */
class Bm25TieOrderTest extends TestCase
{
    /** @var list<int|string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Twelve identical documents after the seven seeded users, so their keys cross from one
        // digit to two. Indexed newest first, so posting ids run against key order too.
        for ($i = 1; $i <= 12; $i++) {
            $this->ids[] = User::create(['name' => 'Zebra Tie', 'email' => "t{$i}x@example.com"])->getKey();
        }
        sort($this->ids);

        foreach (array_reverse($this->ids) as $id) {
            app(IndexManager::class)->indexModel(User::find($id));
        }
    }

    public function test_equal_scores_rank_by_model_key_ascending(): void
    {
        $ranked = app(Bm25Scorer::class)->rank(['zebra'], User::class);

        $this->assertCount(1, array_unique($ranked));
        $this->assertEquals($this->ids, array_keys($ranked));
    }

    /**
     * A total order for mixed keys: integers first, by value, then strings, byte-wise. Comparing
     * integers as numbers and everything else as strings made 9 < 10 < "5x" < 9, so the result
     * followed the postings' row order again.
     */
    public function test_ties_between_integer_and_string_keys_have_one_order_whatever_the_row_order(): void
    {
        $termId = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'tiebreak', 'doc_count' => 3, 'term_length' => 8]);

        foreach ([['10', '9', '5x'], ['5x', '10', '9'], ['9', '5x', '10'], ['10', '5x', '9']] as $n => $order) {
            $type = "App\\Models\\Tie{$n}";
            DB::table('fuzzy_index_meta')->insert(['model_type' => $type, 'total_docs' => 3, 'total_tokens' => 3, 'avg_doc_length' => 1]);
            foreach ($order as $id) {
                DB::table('fuzzy_index_documents')->insert(['model_type' => $type, 'model_id' => $id, 'doc_length' => 1]);
                DB::table('fuzzy_index_postings')->insert(['term_id' => $termId, 'model_type' => $type, 'model_id' => $id, 'column_name' => '', 'frequency' => 1]);
            }

            $this->assertSame([9, 10, '5x'], array_keys(app(Bm25Scorer::class)->rank(['tiebreak'], $type)), implode(',', $order));
        }
    }

    public function test_the_index_path_returns_ties_in_key_order(): void
    {
        $search = fn () => User::search('zebra')->useInvertedIndex()->typoTolerance(0);
        $keys   = fn (iterable $models) => array_values(array_map(fn ($m) => $m->getKey(), is_array($models) ? $models : $models->all()));

        $this->assertEquals($this->ids, $keys($search()->get()));
        $this->assertEquals(array_slice($this->ids, 0, 5), $keys($search()->paginate(5)->items()));
        $this->assertEquals(array_slice($this->ids, 5, 5), $keys($search()->paginate(5, 'page', 2)->items()));
        $this->assertEquals($this->ids[0], $search()->first()->getKey());
    }
}
