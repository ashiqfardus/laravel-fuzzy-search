<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/** A model on a second connection: its transaction is not the index's. */
class SecondConnectionNote extends Model
{
    use Searchable;

    protected $connection = 'second';
    protected $table      = 'txn_notes';
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['body' => 10]];
}

/**
 * The observer indexes (and un-indexes) a model once its transaction commits. A rolled-back
 * row is never indexed, and a queued job is never pushed before the row it reads is committed,
 * where a worker could run it first and miss the row.
 *
 * Every assertion runs after its transaction has closed: a failing assertion inside an open one
 * leaves it holding locks, and PostgreSQL's next DROP TABLE then waits on it forever.
 */
class IndexAfterCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        // Same settings as the default connection, but its own PDO and so its own transactions.
        // On SQLite :memory: that is a separate database, so the table is created there.
        config(['database.connections.second' => config('database.connections.' . config('database.default'))]);
        Schema::connection('second')->dropIfExists('txn_notes');
        Schema::connection('second')->create('txn_notes', function ($table) {
            $table->id();
            $table->string('body');
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('second')->dropIfExists('txn_notes');
        DB::purge('second');

        parent::tearDown();
    }

    private function postings(string $class, $key): int
    {
        return DB::table('fuzzy_index_postings')->where('model_type', $class)->where('model_id', (string) $key)->count();
    }

    public function test_a_create_that_rolls_back_leaves_no_postings(): void
    {
        DB::beginTransaction();
        $user = User::create(['name' => 'Rolled Back', 'email' => 'rb@example.com']);
        DB::rollBack();

        $this->assertSame(0, $this->postings(User::class, $user->getKey()));
        $this->assertSame(0, DB::table('fuzzy_index_terms')->where('term', 'rolled')->count());

        // The model's own connection rolls back while the index, on the default one, has no
        // transaction to undo: indexing mid-transaction left the rolled-back row searchable.
        DB::connection('second')->beginTransaction();
        $note = SecondConnectionNote::create(['body' => 'phantom widget']);
        DB::connection('second')->rollBack();

        $this->assertSame(0, $this->postings(SecondConnectionNote::class, $note->getKey()));
    }

    public function test_a_create_is_indexed_when_its_transaction_commits(): void
    {
        DB::beginTransaction();
        $user          = User::create(['name' => 'Committed Row', 'email' => 'cr@example.com']);
        $beforeCommit  = $this->postings(User::class, $user->getKey());
        DB::commit();

        $this->assertSame(0, $beforeCommit);
        $this->assertGreaterThan(0, $this->postings(User::class, $user->getKey()));

        DB::connection('second')->transaction(fn () => SecondConnectionNote::create(['body' => 'real widget']));
        $this->assertSame(1, SecondConnectionNote::search('widget')->useInvertedIndex()->typoTolerance(0)->count());

        // Outside a transaction nothing waits.
        $this->assertGreaterThan(0, $this->postings(User::class, User::create(['name' => 'Plain Row', 'email' => 'pr@example.com'])->getKey()));
    }

    public function test_a_delete_that_rolls_back_keeps_the_postings(): void
    {
        $note = SecondConnectionNote::create(['body' => 'kept widget']);
        $this->assertGreaterThan(0, $this->postings(SecondConnectionNote::class, $note->getKey()));

        DB::connection('second')->beginTransaction();
        $note->delete();
        DB::connection('second')->rollBack();

        $this->assertGreaterThan(0, $this->postings(SecondConnectionNote::class, $note->getKey()));
    }

    public function test_the_async_job_is_pushed_only_after_the_commit(): void
    {
        config(['fuzzy-search.indexing.async' => true]);
        Queue::fake();

        DB::beginTransaction();
        User::create(['name' => 'Queued Row', 'email' => 'qr@example.com']);
        $pushedInside = Queue::pushed(IndexModelJob::class)->count();
        DB::rollBack();

        $this->assertSame(0, $pushedInside);
        Queue::assertNothingPushed();

        DB::beginTransaction();
        $user = User::create(['name' => 'Queued Row', 'email' => 'qr@example.com']);
        $user->delete();
        $pushedInside = Queue::pushed(IndexModelJob::class)->count();
        DB::commit();

        $this->assertSame(0, $pushedInside);
        Queue::assertPushed(IndexModelJob::class, 2);
    }
}
