<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\InspectsPostings;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Scout's delete($models) removes the whole collection in one write, as update() writes one: the
 * claim, the postings read, the dictionary and the meta counts are done once, not once per model
 * (300 models took 3,300 statements and 300 commits).
 */
class ScoutBatchDeleteTest extends TestCase
{
    use InspectsPostings;

    private function statements(\Closure $work): int
    {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count) {
            $count++;
        });
        $work();

        return $count;
    }

    private function seedUsers(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            User::create(['name' => "Batch Person {$i}", 'email' => "batch{$i}@example.com"]);
        }
        app(IndexManager::class)->indexBatch(User::all());
    }

    public function test_a_collection_is_removed_in_a_handful_of_statements(): void
    {
        $this->seedUsers(40);
        $engine = app(FuzzySearchEngine::class);
        $one    = $this->statements(fn () => $engine->delete(User::query()->orderBy('id')->limit(1)->get()));
        $many   = $this->statements(fn () => $engine->delete(User::query()->orderBy('id')->limit(30)->get()));

        $this->assertLessThanOrEqual($one * 2, $many, "one model: {$one} statements, thirty: {$many}");
    }

    public function test_the_index_after_a_batch_removal_is_the_index_of_the_rows_left(): void
    {
        $this->seedUsers(40);
        $gone = User::query()->orderBy('id')->limit(25)->get();

        app(FuzzySearchEngine::class)->delete($gone);

        foreach ($gone as $user) {
            $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_id', (string) $user->getKey())->count());
            $this->assertSame(0, DB::table('fuzzy_index_postings')->where('model_id', (string) $user->getKey())->count());
        }
        $left = User::count() - $gone->count();
        $this->assertSame($left, DB::table('fuzzy_index_documents')->count());
        $this->assertSame($left, (int) DB::table('fuzzy_index_meta')->value('total_docs'));
        $this->assertModelTermsMirrorPostings('a batch removal');

        // The words only the removed rows held are gone from the counts.
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));
    }

    public function test_a_key_listed_twice_or_never_indexed_is_removed_once(): void
    {
        $this->seedUsers(5);
        $first = User::query()->orderBy('id')->first();
        $never = new User(['name' => 'Ghost']);
        $never->id = 99999;

        app(FuzzySearchEngine::class)->delete(collect([$first, $first, $never]));

        $this->assertSame(User::count() - 1, DB::table('fuzzy_index_documents')->count());
        $this->assertModelTermsMirrorPostings('a duplicate key');
    }
}
