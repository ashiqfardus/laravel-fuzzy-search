<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * A save that changes the primary key indexes the new key and takes the old key's document and
 * postings out, so paginate()->total() and the word statistics do not carry a row that has no key.
 */
class KeyChangeIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            $this->markTestSkipped('SQL Server cannot update an IDENTITY column: a key change is not possible on the users table there. CI covers the other databases.');
        }
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
    }

    private function documents(): array
    {
        return DB::table('fuzzy_index_documents')->where('model_type', User::class)->pluck('model_id')->map(fn ($k) => (int) $k)->sort()->values()->all();
    }

    public function test_a_key_change_moves_the_row_in_the_index(): void
    {
        $user = User::query()->create(['name' => 'Zzkey Alphaword', 'email' => 'k@example.com']);
        $old  = $user->id;
        $new  = $old + 1000;

        $user->id = $new;
        $user->save();

        $search = fn () => User::search('alphaword')->useInvertedIndex();
        $this->assertSame(
            ['documents' => [$new], 'found' => [$new], 'total' => 1, 'count' => 1],
            [
                'documents' => $this->documents(),
                'found'     => $search()->get()->pluck('id')->map(fn ($k) => (int) $k)->all(),
                'total'     => $search()->paginate(10)->total(),
                'count'     => $search()->count(),
            ]
        );
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'alphaword')->value('doc_count'));
        $this->assertSame(0, DB::table('fuzzy_index_postings')->where('model_id', (string) $old)->count());
    }

    /** N-B7: with indexing off, a save that changed the key lets go of the old key it noted at updating. */
    public function test_a_key_change_with_indexing_off_keeps_no_old_key(): void
    {
        $user = User::query()->create(['name' => 'Zzkey Alphaword', 'email' => 'k@example.com']);
        config(['fuzzy-search.indexing.enabled' => false]);

        $user->id += 1000;
        $user->save();

        $oldKeys = (new \ReflectionProperty(\Ashiqfardus\LaravelFuzzySearch\Observers\SearchableIndexingObserver::class, 'oldKeys'))->getValue();
        $this->assertFalse(isset($oldKeys[$user]));
    }

    public function test_a_key_change_queues_a_sync_for_both_keys(): void
    {
        config(['fuzzy-search.indexing.async' => true]);
        Queue::fake();
        $user = User::query()->create(['name' => 'Zzkey Alphaword', 'email' => 'k@example.com']);
        $old  = $user->id;
        Queue::fake(); // forget the create

        $user->id = $old + 1000;
        $user->save();

        $keys = [];
        Queue::assertPushed(IndexModelJob::class, function ($job) use (&$keys) {
            $keys[] = (int) $job->modelId;

            return true;
        });
        sort($keys);
        $this->assertSame([$old, $old + 1000], $keys);
    }
}
