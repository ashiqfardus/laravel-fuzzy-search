<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SnapshotAuthor extends Model
{
    protected $table   = 'snapshot_authors';
    protected $guarded = [];
    public $timestamps = false;
}

/** A note indexed with its author's name, which searchIndexQuery() eager loads. */
class SnapshotNote extends Model
{
    use Searchable;

    protected $table   = 'snapshot_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1, 'author.name' => 1]];

    public function author(): BelongsTo
    {
        return $this->belongsTo(SnapshotAuthor::class, 'author_id');
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
 * An index write inside a transaction of yours that read before it indexed (User::find() then
 * save(), Scout with after_commit off, IndexModelJob::dispatchSync()). On MySQL/MariaDB at REPEATABLE
 * READ that read starts the transaction's snapshot, and another process saves or deletes the same
 * row, and writes its index, before this write claims the row:
 *  - P6-S1 (ruling ER-201): the write read the row's old words from its snapshot, gave the counts
 *    back for the words the row held before the other write, and left the other write's words
 *    counted with no posting, for good;
 *  - ER-202 (P6-S2): it re-read the row from its snapshot too, so it put a deleted row back in the
 *    index, and indexed a saved row's older text.
 * The other process is a PHP process of its own (Fixtures/index-writer.php): SQL Server's ODBC
 * driver is not fork-safe.
 */
class IndexWriteSnapshotTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('snapshot_notes');
        Schema::dropIfExists('snapshot_authors');
        parent::tearDown();
    }

    /**
     * Opens a transaction, reads user $id, lets another process save the user as "Zzchild
     * Alphaword" or delete it ($action, its index written by the observer, committed), then runs
     * $write inside the transaction and commits it.
     */
    private function afterAnotherWrite(int $id, \Closure $write, string $action = 'save'): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite has one writer: the other process waits for this transaction.');
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('The other writer needs a process of its own: proc_open().');
        }

        $base = sys_get_temp_dir() . '/fuzzy-snapshot-' . getmypid();
        file_put_contents("{$base}.json", json_encode([
            'connection' => config('database.connections.' . config('database.default')),
            'go'         => "{$base}.go",
            'steps'      => [[$action, User::class, [$id]]],
            'names'      => ['Zzchild Alphaword'],
        ]));

        try {
            DB::beginTransaction();
            $this->assertSame('John Doe', User::query()->find($id)->name); // the snapshot starts here on MySQL/MariaDB

            $proc = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/index-writer.php', "{$base}.json"], [1 => ['pipe', 'w'], 2 => ['file', "{$base}.err", 'w']], $pipes);
            fgets($pipes[1]); // "ready"
            touch("{$base}.go");
            $output = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            proc_close($proc);
            $lines = explode("\n", $output);
            $this->assertSame(['failed' => [], 'rollbacks' => 0], json_decode((string) end($lines), true), "the other writer: {$output} " . @file_get_contents("{$base}.err"));

            $write();
            DB::commit();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (glob("{$base}*") ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    /** Every word's doc_count, and each model type's, equals the documents its postings hold. */
    private function assertCountsExact(string $after): void
    {
        $documents = [];
        foreach (DB::table('fuzzy_index_postings')->select('term_id', 'model_type_id', 'model_id')->distinct()->get() as $row) {
            $row = (object) $row;
            $documents[(int) $row->term_id] = ($documents[(int) $row->term_id] ?? 0) + 1;
        }
        $counts = DB::table('fuzzy_index_terms')->pluck('doc_count', 'id')->map(fn ($count) => (int) $count)->all();
        ksort($documents);
        ksort($counts);

        $this->assertSame($documents, array_filter($counts), "the dictionary's doc_count against the postings, after {$after}");
        $this->assertModelTermsMirrorPostings($after);
    }

    /** The words the user's postings hold, sorted. */
    private function postedWords(int $id): array
    {
        return DB::table('fuzzy_index_postings as p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type_id', IndexManager::modelTypeId(User::class))->where('p.model_id', (string) $id)
            ->distinct()->orderBy('t.term')->pluck('t.term')->all();
    }

    public function test_a_write_in_a_transaction_that_read_first_gives_back_the_words_the_row_holds_now(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $id = (int) User::query()->where('name', 'John Doe')->value('id');

        $this->afterAnotherWrite($id, function () use ($id) {
            DB::table('users')->where('id', $id)->update(['name' => 'Zzparent Betaword']);
            app(IndexManager::class)->indexModel(User::query()->find($id));
        });

        $this->assertSame(['betaword', 'com', 'example', 'john', 'zzparent'], $this->postedWords($id));
        $this->assertCountsExact('the write');
        // Before the fix: 'doe' (Jane Doe holds it too) was given back twice, by the other write and
        // again by this one, and the other write's words kept their count with no posting.
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'alphaword')->value('doc_count'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'doe')->value('doc_count'));
    }

    public function test_a_removal_in_a_transaction_that_read_first_gives_back_the_words_the_row_holds_now(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $id = (int) User::query()->where('name', 'John Doe')->value('id');

        $this->afterAnotherWrite($id, fn () => app(IndexManager::class)->removeFromIndex(User::class, $id));

        $this->assertSame([], $this->postedWords($id));
        $this->assertCountsExact('the removal');
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'zzchild')->value('doc_count'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'doe')->value('doc_count'));
    }
    public function test_a_write_in_a_transaction_that_read_first_does_not_put_back_a_row_deleted_since(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $id = (int) User::query()->where('name', 'John Doe')->value('id');

        // The app's UPDATE finds no row, and its IndexModelJob::dispatchSync() writes the row's index.
        $this->afterAnotherWrite($id, function () use ($id) {
            DB::table('users')->where('id', $id)->update(['name' => 'Zzparent Betaword']);
            app(IndexManager::class)->syncModel(User::class, $id);
        }, 'delete');

        $this->assertFalse(DB::table('users')->where('id', $id)->exists());
        $this->assertSame([], $this->postedWords($id));
        $this->assertFalse(DB::table('fuzzy_index_documents')->where('model_type', User::class)->where('model_id', (string) $id)->exists());
        $this->assertCountsExact('the write');
    }

    public function test_a_write_in_a_transaction_that_read_first_indexes_the_text_saved_since(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $id = (int) User::query()->where('name', 'John Doe')->value('id');

        // The app indexes the row without changing it.
        $this->afterAnotherWrite($id, fn () => app(IndexManager::class)->syncModel(User::class, $id));

        $this->assertSame(['alphaword', 'com', 'example', 'john', 'zzchild'], $this->postedWords($id));
        $this->assertCountsExact('the write');
    }

    /**
     * ER-202: on MySQL/MariaDB a write inside a transaction of yours re-reads the row, and the relation
     * its searchIndexQuery() eager loads, with shared locks before it claims the row; a write in a
     * transaction of its own, and every write elsewhere, locks none of your rows.
     */
    public function test_a_write_in_a_transaction_of_yours_rereads_the_row_and_its_relations_with_a_lock_before_its_claim(): void
    {
        Schema::dropIfExists('snapshot_notes');
        Schema::dropIfExists('snapshot_authors');
        Schema::create('snapshot_authors', fn ($table) => [$table->id(), $table->string('name')]);
        Schema::create('snapshot_notes', fn ($table) => [$table->id(), $table->string('title'), $table->unsignedBigInteger('author_id')]);
        $author = SnapshotAuthor::query()->create(['name' => 'Quill']);
        $note   = SnapshotNote::query()->create(['title' => 'Harbor lantern', 'author_id' => $author->id]);

        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = $query->sql;
        });
        $locked = function (string $table) use (&$log): array { // the positions of the locking reads of $table in the log
            return array_keys(array_filter($log, fn (string $sql) => str_contains($sql, $table) && preg_match('/^\s*select\b/i', $sql) === 1 && stripos($sql, 'lock in share mode') !== false));
        };
        $claim  = function () use (&$log): ?int { // the claim's first statement
            return array_key_first(array_filter($log, fn (string $sql) => str_contains($sql, 'fuzzy_index_documents')));
        };

        DB::transaction(fn () => app(IndexManager::class)->indexModel($note));
        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->assertCount(1, $locked('snapshot_notes'), 'the row');
            $this->assertCount(1, $locked('snapshot_authors'), 'its eager-loaded author');
            $this->assertLessThan($claim(), max([...$locked('snapshot_notes'), ...$locked('snapshot_authors')]), 'both before the claim');
        } else {
            $this->assertSame([], [...$locked('snapshot_notes'), ...$locked('snapshot_authors')]);
        }

        $log = [];
        app(IndexManager::class)->indexModel($note);
        $this->assertSame([], [...$locked('snapshot_notes'), ...$locked('snapshot_authors')], 'a write in a transaction of its own');
        $this->assertSame(['harbor', 'lantern', 'quill'], DB::table('fuzzy_index_postings as p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type_id', IndexManager::modelTypeId(SnapshotNote::class))->distinct()->orderBy('t.term')->pluck('t.term')->all());
    }
}
