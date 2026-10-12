<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class MorphReplicaAuthor extends Model
{
    protected $connection = 'morph_split';
    protected $table      = 'morph_replica_authors';
    protected $guarded    = [];
    public $timestamps    = false;
}

class MorphReplicaTag extends Model
{
    protected $connection = 'morph_split';
    protected $table      = 'morph_replica_tags';
    protected $guarded    = [];
    public $timestamps    = false;
}

class MorphReplicaDoc extends Model
{
    use Searchable;

    protected $connection = 'morph_split';
    protected $table      = 'morph_replica_docs';
    protected $guarded    = [];
    public $timestamps    = false;

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(MorphReplicaTag::class, 'morph_replica_doc_tag', 'doc_id', 'tag_id');
    }

    public function searchableText(): array
    {
        return ['title' => $this->title, 'subject.name' => $this->subject?->name, 'tags.name' => $this->tags->pluck('name')->implode(' ')];
    }

    public function searchIndexQuery(Builder $query): Builder
    {
        return $query->with(['subject', 'tags']);
    }
}

/**
 * UF-1. On a connection with a read/write split, a write re-reads the row and the relations
 * searchIndexQuery() eager loads from the write side (TA-4), but Laravel runs a morphTo eager load as
 * a fresh query per type, which never saw the relation's useWritePdo(): a queue worker indexed the
 * replica's old name of a polymorphic parent. The whole re-read now reads from the write side.
 */
class ReadReplicaMorphIndexTest extends TestCase
{
    private string $primary;
    private string $replica;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $base          = sys_get_temp_dir() . '/morph-replica-' . getmypid() . '-' . uniqid();
        $this->primary = "{$base}-primary.sqlite";
        $this->replica = "{$base}-replica.sqlite";
        $app['config']->set('database.connections.morph_split', [
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

        // Two databases that never replicate: the replica keeps the rows as first written.
        foreach ([$this->primary, $this->replica] as $file) {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->exec('create table morph_replica_authors (id integer primary key autoincrement, name varchar(255) not null)');
            $pdo->exec('create table morph_replica_tags (id integer primary key autoincrement, name varchar(255) not null)');
            $pdo->exec('create table morph_replica_doc_tag (doc_id integer, tag_id integer)');
            $pdo->exec('create table morph_replica_docs (id integer primary key autoincrement, title varchar(255) not null, subject_type varchar(255) null, subject_id integer null)');
            $pdo->exec("insert into morph_replica_authors (name) values ('Tolkien')");
            $pdo->exec("insert into morph_replica_tags (name) values ('kestrel')");
            $pdo->exec('insert into morph_replica_doc_tag (doc_id, tag_id) values (1, 1)');
            $pdo->exec("insert into morph_replica_docs (title, subject_type, subject_id) values ('alpha one', " . $pdo->quote(MorphReplicaAuthor::class) . ', 1)');
        }
        app(IndexManager::class)->indexBatch(MorphReplicaDoc::all());
    }

    protected function tearDown(): void
    {
        DB::purge('morph_split');
        parent::tearDown();
        @unlink($this->primary);
        @unlink($this->replica);
    }

    /** @return list<int> the documents whose postings hold $term */
    private function holders(string $term): array
    {
        return $this->namedPostings('p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', MorphReplicaDoc::class)->where('t.term', $term)
            ->pluck('p.model_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function test_a_worker_indexes_a_morph_to_parent_as_the_primary_holds_it(): void
    {
        $this->assertSame([1], $this->holders('tolkien'));

        DB::connection('morph_split')->table('morph_replica_authors')->where('id', 1)->update(['name' => 'Pratchett']);
        DB::connection('morph_split')->table('morph_replica_tags')->where('id', 1)->update(['name' => 'osprey']);
        DB::connection('morph_split')->forgetRecordModificationState(); // a queue worker: a process of its own

        (new IndexModelJob(MorphReplicaDoc::class, 1))->handle(app(IndexManager::class));

        $this->assertSame([1], $this->holders('pratchett'), 'the morphTo parent read from the primary');
        $this->assertSame([], $this->holders('tolkien'));
        $this->assertSame([1], $this->holders('osprey'), 'the belongsToMany one, as before');
        $this->assertFalse((fn () => $this->readOnWriteConnection)->call(DB::connection('morph_split')), 'the connection reads as it did after the write');
    }
}
