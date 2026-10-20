<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Exceptions\LaravelFuzzySearchException;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class AfterCommitAuthor extends Model
{
    protected $table   = 'after_commit_authors';
    protected $guarded = [];
    public $timestamps = false;
}

/** A note indexed with its author's name, docs/relationships.md's recipe. */
class AfterCommitNote extends Model
{
    use Searchable;

    protected $table   = 'after_commit_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1, 'author.name' => 1], 'reindex_on' => ['author_id']];

    public function author(): BelongsTo
    {
        return $this->belongsTo(AfterCommitAuthor::class, 'author_id');
    }

    public function searchableText(): array
    {
        return ['title' => $this->title, 'author.name' => $this->author?->name];
    }

    public function searchIndexQuery(Builder $query): Builder
    {
        return $query->with('author');
    }
}

/**
 * When an index write runs, relative to the transaction of the save that asked for it.
 *
 * WC-1 (ruling ER-218): Searchable::reindexRelated() queued its jobs at once, inside the caller's
 * transaction and after a rollback too, so a worker could index the old related text and the
 * committed rename was never indexed; with indexing.async off it wrote the index inside the
 * transaction, its shared locks on every related row held to the commit (1.5 s waits on
 * MySQL/MariaDB). It now schedules each row's write as the observer does: after the commit, and a
 * failed in-process write is reported, naming the model and key, not thrown.
 *
 * WC-2: Laravel attaches an after-commit callback to the last open transaction of any connection,
 * so a save committed at once on its own connection while another connection's transaction was
 * open was indexed only at that commit, and never if it rolled back. Only a transaction of the
 * model's own connection holds the write back now.
 */
class IndexAfterCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('after_commit_notes');
        Schema::dropIfExists('after_commit_authors');
        Schema::create('after_commit_authors', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('after_commit_notes', function ($table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('author_id');
        });

        config(['fuzzy-search.indexing.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('after_commit_notes');
        Schema::dropIfExists('after_commit_authors');

        parent::tearDown();
    }

    /** @return string[] the words indexed for note $id */
    private function words(int $id): array
    {
        return DB::table('fuzzy_index_postings as p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type_id', IndexManager::modelTypeId(AfterCommitNote::class))->where('p.model_id', (string) $id)
            ->distinct()->orderBy('t.term')->pluck('t.term')->all();
    }

    /** @return array{AfterCommitAuthor, AfterCommitNote, AfterCommitNote} */
    private function authorWithNotes(): array
    {
        $author = AfterCommitAuthor::query()->create(['name' => 'Quill']);

        return [
            $author,
            AfterCommitNote::query()->create(['title' => 'Harbor', 'author_id' => $author->id]),
            AfterCommitNote::query()->create(['title' => 'Lantern', 'author_id' => $author->id]),
        ];
    }

    /** @return list<\Throwable> filled with what is reported from here on */
    private function &reports(): array
    {
        $reported = [];
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler {
            public function __construct(private array &$reported) {}
            public function report(\Throwable $e) { $this->reported[] = $e; }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw $e; }
            public function renderForConsole($output, \Throwable $e) { throw $e; }
        });

        return $reported;
    }

    public function test_async_reindex_related_queues_its_jobs_at_the_commit_and_none_after_a_rollback(): void
    {
        config(['fuzzy-search.indexing.async' => true]);
        Queue::fake();
        [$author] = $this->authorWithNotes();
        Queue::assertPushed(IndexModelJob::class, 2); // the notes' own saves

        $scheduled = null;
        DB::transaction(function () use ($author, &$scheduled) {
            $author->update(['name' => 'Quince']);
            $scheduled = AfterCommitNote::reindexRelated('author_id', $author->id);

            Queue::assertPushed(IndexModelJob::class, 2);
        });

        $this->assertSame(2, $scheduled, 'the rows scheduled');
        Queue::assertPushed(IndexModelJob::class, 4);

        try {
            DB::transaction(function () use ($author) {
                $author->update(['name' => 'Rolled']);
                AfterCommitNote::reindexRelated('author_id', $author->id);

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertPushed(IndexModelJob::class, 4);
    }

    public function test_sync_reindex_related_writes_after_the_commit_and_nothing_after_a_rollback(): void
    {
        config(['fuzzy-search.indexing.async' => false]);
        [$author, $harbor, $lantern] = $this->authorWithNotes();
        $this->assertSame(['harbor', 'quill'], $this->words($harbor->id));

        DB::transaction(function () use ($author, $harbor) {
            $author->update(['name' => 'Quince']);
            $this->assertSame(2, AfterCommitNote::reindexRelated('author_id', $author->id));

            // Not written inside the transaction: on MySQL/MariaDB that write read each related row
            // with a shared lock held until this commit.
            $this->assertSame(['harbor', 'quill'], $this->words($harbor->id), 'inside the transaction');
        });

        $this->assertSame(['harbor', 'quince'], $this->words($harbor->id));
        $this->assertSame(['lantern', 'quince'], $this->words($lantern->id));

        try {
            DB::transaction(function () use ($author) {
                $author->update(['name' => 'Rolled']);
                AfterCommitNote::reindexRelated('author_id', $author->id);

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(['harbor', 'quince'], $this->words($harbor->id));
        $this->assertSame(0, AfterCommitNote::reindexRelated('author_id', 0), 'no related row');
    }

    public function test_a_failed_related_write_is_reported_with_its_model_and_key_not_thrown(): void
    {
        config(['fuzzy-search.indexing.async' => false]);
        [$author, $harbor, $lantern] = $this->authorWithNotes();

        $this->app->instance(IndexManager::class, new class(new WhitespaceTokenizer(), new NullStemmer()) extends IndexManager {
            public function syncModel(string $modelClass, int|string $key): void
            {
                throw new \RuntimeException("write of {$key} failed");
            }
        });
        $reported = &$this->reports();

        $this->assertSame(2, AfterCommitNote::reindexRelated('author_id', $author->id));

        $this->assertCount(2, $reported, 'each row, the second after the first failed');
        foreach ([$harbor, $lantern] as $i => $note) {
            $this->assertInstanceOf(LaravelFuzzySearchException::class, $reported[$i]);
            $this->assertSame(['model' => AfterCommitNote::class, 'key' => $note->getKey()], $reported[$i]->context());
            $this->assertSame("write of {$note->getKey()} failed", $reported[$i]->getPrevious()->getMessage());
        }
    }

    private function otherConnection(): \Illuminate\Database\Connection
    {
        // Another database entirely (a landlord or central database, say).
        config(['database.connections.after_commit_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $other = DB::connection('after_commit_other');
        $other->statement('create table ledger (id integer primary key, note text)');

        return $other;
    }

    private function userIndexed(int $id): bool
    {
        return DB::table('fuzzy_index_documents')->where('model_type', User::class)->where('model_id', (string) $id)->exists();
    }

    public function test_a_save_committed_at_once_is_indexed_at_once_while_another_connection_has_a_transaction_open(): void
    {
        config(['fuzzy-search.indexing.async' => false]);
        $other = $this->otherConnection();

        $other->beginTransaction();
        $other->table('ledger')->insert(['note' => 'bookkeeping']);
        $kept = User::query()->create(['name' => 'Ledger Alphaword', 'email' => 'a@example.com']);
        $this->assertSame(0, User::query()->getConnection()->transactionLevel(), 'committed at once');
        $this->assertTrue($this->userIndexed($kept->id), 'indexed before the other connection ends');
        $other->rollBack();

        $this->assertTrue($this->userIndexed($kept->id), 'and after it rolls back');

        $other->beginTransaction();
        $later = User::query()->create(['name' => 'Ledger Betaword', 'email' => 'b@example.com']);
        $this->assertTrue($this->userIndexed($later->id));
        $other->commit();

        // Its own connection's transaction still holds it back.
        $other->beginTransaction();
        DB::beginTransaction();
        $held = User::query()->create(['name' => 'Ledger Gammaword', 'email' => 'c@example.com']);
        $this->assertFalse($this->userIndexed($held->id), 'not inside its own transaction');
        DB::commit();
        $this->assertTrue($this->userIndexed($held->id), 'at its commit');
        $other->rollBack();
    }

    public function test_reindex_related_runs_at_once_while_another_connection_has_a_transaction_open(): void
    {
        config(['fuzzy-search.indexing.async' => false]);
        [$author, $harbor] = $this->authorWithNotes();
        $other = $this->otherConnection();

        $other->beginTransaction();
        $author->update(['name' => 'Quince']);
        AfterCommitNote::reindexRelated('author_id', $author->id);
        $this->assertSame(['harbor', 'quince'], $this->words($harbor->id));
        $other->rollBack();

        $this->assertSame(['harbor', 'quince'], $this->words($harbor->id));
    }
}
