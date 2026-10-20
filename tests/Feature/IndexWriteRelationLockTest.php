<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IwrlComment extends Model
{
    protected $table   = 'iwrl_comments';
    protected $guarded = [];
    public $timestamps = false;
}

class IwrlPost extends Model
{
    use Searchable;

    protected $table   = 'iwrl_posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 2, 'comments' => 1]];

    public function comments(): HasMany
    {
        return $this->hasMany(IwrlComment::class, 'post_id');
    }

    public function searchIndexQuery(Builder $query): Builder
    {
        return $query->with('comments');
    }

    public function searchableText(): array
    {
        return ['title' => $this->title, 'comments' => $this->comments->pluck('body')->implode(' ')];
    }
}

/**
 * MySQL/MariaDB: an index write inside a transaction of yours reads its row with a shared lock
 * (ER-202), and the relations it eager loads without one. A shared read of the relations took
 * next-key and gap locks in your own tables, so inserting a comment near the indexed post's, or
 * changing another post's, waited for your commit (P7R-4).
 */
class IndexWriteRelationLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('iwrl_comments');
        Schema::dropIfExists('iwrl_posts');
        Schema::create('iwrl_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
        Schema::create('iwrl_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id')->index();
            $table->string('body');
        });
        foreach ([1, 2, 3] as $p) {
            DB::table('iwrl_posts')->insert(['id' => $p * 10, 'title' => "post {$p} harbor"]);
            DB::table('iwrl_comments')->insert(['post_id' => $p * 10, 'body' => "comment on {$p} lantern"]);
        }
        app(IndexManager::class)->indexBatch(IwrlPost::all());
    }

    protected function tearDown(): void
    {
        DB::purge('iwrl_other');
        Schema::dropIfExists('iwrl_comments');
        Schema::dropIfExists('iwrl_posts');

        parent::tearDown();
    }

    public function test_an_index_write_in_your_transaction_leaves_the_related_tables_unlocked(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The shared re-read is MySQL/MariaDB\'s (ER-202): the other databases read the rows as they are now without it.');
        }
        config(['database.connections.iwrl_other' => DB::connection()->getConfig()]);
        $other = DB::connection('iwrl_other');
        $other->statement('set session innodb_lock_wait_timeout = 1');

        $post = IwrlPost::find(20);
        $out  = [];
        DB::beginTransaction();
        try {
            DB::table('iwrl_posts')->count(); // your transaction read first
            app(IndexManager::class)->indexModel($post);

            $try = function (string $label, \Closure $write) use (&$out, $other) {
                try {
                    $other->transaction($write);
                    $out[$label] = 'ok';
                } catch (\Illuminate\Database\QueryException $e) {
                    $out[$label] = 'waited: ' . $e->getMessage();
                }
            };
            $try('a comment for post 25, none yet', fn () => $other->table('iwrl_comments')->insert(['post_id' => 25, 'body' => 'x']));
            $try('a comment for post 10', fn () => $other->table('iwrl_comments')->insert(['post_id' => 10, 'body' => 'x']));
            $try('a comment for post 30', fn () => $other->table('iwrl_comments')->insert(['post_id' => 30, 'body' => 'x']));
            $try('an update of post 30\'s comment', fn () => $other->table('iwrl_comments')->where('post_id', 30)->update(['body' => 'y']));
            $try('an update of post 30', fn () => $other->table('iwrl_posts')->where('id', 30)->update(['title' => 'z']));
        } finally {
            DB::rollBack();
        }

        $this->assertSame(array_fill_keys(array_keys($out), 'ok'), $out);
        $this->assertCount(5, $out);
    }
}
