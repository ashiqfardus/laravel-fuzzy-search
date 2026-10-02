<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ReplicaAuthor extends Model
{
    protected $connection = 'replica_split';
    protected $table      = 'replica_authors';
    protected $guarded    = [];
    public $timestamps    = false;
}

/** On a connection with Laravel's read/write split; the index lives on the default connection. */
class ReplicaDoc extends Model
{
    use Searchable;

    protected $connection = 'replica_split';
    protected $table      = 'replica_docs';
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['title' => 1, 'author.name' => 1]];

    public function author(): BelongsTo
    {
        return $this->belongsTo(ReplicaAuthor::class, 'author_id');
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
 * TA-4. Every index write re-reads the row under its claim (ER-68), through the model's query. On a
 * connection with a read replica that read went to the replica outside a transaction on that
 * connection: in a queue worker, and in-process without `sticky`. A replica that had not applied the
 * commit yet gave the old row, so a delete's job re-indexed the deleted row and a save's job indexed
 * the old text, and so did the relations searchIndexQuery() eager loads. The re-read and its eager
 * loads now use the write connection. The replica here is a second SQLite file that never applies
 * the primary's writes: replication lag, frozen.
 */
class ReadReplicaIndexWriteTest extends TestCase
{
    private string $primary;
    private string $replica;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $base          = sys_get_temp_dir() . '/fuzzy-replica-' . getmypid() . '-' . uniqid();
        $this->primary = "{$base}-primary.sqlite";
        $this->replica = "{$base}-replica.sqlite";
        $app['config']->set('database.connections.replica_split', [
            'driver'                  => 'sqlite',
            'read'                    => ['database' => $this->replica],
            'write'                   => ['database' => $this->primary],
            'sticky'                  => false,
            'prefix'                  => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([$this->primary, $this->replica] as $file) {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->exec('create table replica_authors (id integer primary key autoincrement, name varchar(255) not null)');
            $pdo->exec('create table replica_docs (id integer primary key autoincrement, title varchar(255) not null, author_id integer null)');
            $pdo->exec("insert into replica_authors (name) values ('Tolkien')");
            $pdo->exec("insert into replica_docs (title, author_id) values ('alpha one', 1), ('alpha two', null), ('alpha three', null)");
        }

        app(IndexManager::class)->indexBatch(ReplicaDoc::all());
        $this->assertSame([1, 2, 3], $this->indexed());
    }

    protected function tearDown(): void
    {
        DB::purge('replica_split');
        parent::tearDown();

        @unlink($this->primary);
        @unlink($this->replica);
    }

    /** @return list<int> */
    private function indexed(): array
    {
        return DB::table('fuzzy_index_documents')->where('model_type', ReplicaDoc::class)->orderBy('model_id')->pluck('model_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @return list<int> the ids whose postings hold $term */
    private function holders(string $term): array
    {
        return DB::table('fuzzy_index_postings as p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', ReplicaDoc::class)->where('t.term', $term)
            ->pluck('p.model_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    public function test_a_sync_delete_without_sticky_removes_the_row(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        ReplicaDoc::query()->useWritePdo()->find(2)->delete(); // the primary loses row 2; the replica still has it

        $this->assertSame([1, 3], $this->indexed(), 'the deleted row leaves the index');
        $this->assertSame(2, ReplicaDoc::search('alpha')->useInvertedIndex()->typoTolerance(0)->count());
    }

    public function test_a_queued_job_in_a_worker_indexes_the_committed_row(): void
    {
        // A worker process: nothing written on this connection in it, so sticky would not apply either.
        DB::connection('replica_split')->table('replica_docs')->where('id', 3)->update(['title' => 'omega three']);
        DB::connection('replica_split')->table('replica_docs')->where('id', 2)->delete();
        DB::connection('replica_split')->table('replica_authors')->where('id', 1)->update(['name' => 'Pratchett']);
        DB::connection('replica_split')->forgetRecordModificationState();

        foreach ([1, 2, 3] as $id) {
            (new IndexModelJob(ReplicaDoc::class, $id))->handle(app(IndexManager::class));
        }

        $this->assertSame([1, 3], $this->indexed(), 'the deleted row leaves the index');
        $this->assertSame([3], $this->holders('omega'), 'the saved text is indexed');
        $this->assertSame([1], $this->holders('alpha'), 'the old text is not');
        $this->assertSame([1], $this->holders('pratchett'), 'the eager-loaded relation is read from the write connection too');
        $this->assertSame([], $this->holders('tolkien'));
    }
}
