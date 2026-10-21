<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneArticle extends Model
{
    use Searchable, SoftDeletes;

    protected $table   = 'prune_articles';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function searchIndexQuery(Builder $query): Builder
    {
        return $query->where('archived', false);
    }
}

class PruneCode extends Model
{
    use Searchable;

    protected $table      = 'prune_codes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/** Single-table inheritance indexed once: the child names the parent as its index type. */
class PruneKindParent extends Model
{
    use Searchable;

    protected $table   = 'prune_kinds';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = ($attributes['kind'] ?? '') === 'child' ? PruneKindChild::class : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class PruneKindChild extends PruneKindParent
{
    protected static function booted(): void
    {
        static::addGlobalScope('child', fn ($query) => $query->where('kind', 'child'));
    }

    public static function searchIndexType(): string
    {
        return PruneKindParent::class;
    }
}

/** Indexed through Scout (its trait and a searchableText() hook), on the prune_articles rows. */
class PruneScoutArticle extends Model
{
    use \Laravel\Scout\Searchable, SoftDeletes;

    protected $table   = 'prune_articles';
    protected $guarded = [];
    public $timestamps = false;

    public function searchableText(): array
    {
        return ['title' => $this->title];
    }
}

/** A tenant scope bound to the request or the command's context: a static flag here. */
class PruneTenantArticle extends Model
{
    use Searchable;

    public static ?string $tenant = null;

    protected $table   = 'prune_tenant_articles';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->when(static::$tenant !== null, fn ($query) => $query->where('tenant', static::$tenant)));
    }
}

/** Single-table inheritance with an index per kind: the child's kind is a global scope. */
class PruneSepParent extends Model
{
    use Searchable;

    protected $table   = 'prune_kinds';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = ($attributes['kind'] ?? '') === 'child' ? PruneSepChild::class : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class PruneSepChild extends PruneSepParent
{
    protected static function booted(): void
    {
        static::addGlobalScope('child', fn ($query) => $query->where('kind', 'child'));
    }
}

/**
 * ER-222 (WF-14): a plain fuzzy-search:rebuild indexed the rows the model's index query returns and
 * left every other document in place: those of rows deleted, trashed or scoped out without model
 * events (a query-builder delete(), a reseed), so 2,427 documents stood for 25 users, total_docs
 * counted them, and a federated page said "10 results" over nothing. It now also removes the
 * documents of the type whose rows its index query no longer returns; --async does that in the
 * batch's last job.
 */
class RebuildPruneTest extends TestCase
{
    private const TABLES = ['prune_articles', 'prune_codes', 'prune_kinds', 'prune_tenant_articles', 'job_batches'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('prune_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->boolean('archived')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('prune_codes', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('title');
        });
        Schema::create('prune_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('kind', 16);
        });
        Schema::create('prune_tenant_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('tenant', 8);
        });

        DB::table('prune_articles')->insert(array_map(fn ($w) => ['title' => "quartz {$w}"], ['amber', 'birch', 'cedar', 'delta', 'ember', 'fjord']));
        DB::table('prune_codes')->insert(array_map(fn ($c) => ['code' => $c, 'title' => "quartz code{$c}"], ['b-2', 'a-1', 'C-3', '10', '9']));
        DB::table('prune_kinds')->insert([
            ['title' => 'quartz one', 'kind' => 'child'],
            ['title' => 'quartz two', 'kind' => 'parent'],
            ['title' => 'quartz three', 'kind' => 'child'],
            ['title' => 'quartz four', 'kind' => 'parent'],
        ]);
    }

    protected function tearDown(): void
    {
        PruneTenantArticle::$tenant = null;

        if ($this->status()->isSuccess()) {
            $this->assertModelTermsMirrorPostings(static::class . '::' . $this->name());
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function rebuild(string $class, array $options = []): void
    {
        if (isset($options['--async']) && !Schema::hasTable('job_batches')) {
            // The schema Laravel's make:queue-batches-table migration creates; the sync queue runs the jobs.
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
            config(['queue.batching.database' => config('database.default')]);
        }

        $this->artisan('fuzzy-search:rebuild', ['model' => $class] + $options)->assertExitCode(0);
    }

    /** @return list<string> the keys indexed under $type, as strings, sorted */
    private function indexed(string $type): array
    {
        return DB::table('fuzzy_index_documents')->where('model_type', $type)->pluck('model_id')
            ->map(fn ($id) => (string) $id)->sort(fn ($a, $b) => strcmp($a, $b))->values()->all();
    }

    private function totalDocs(string $type): int
    {
        return (int) DB::table('fuzzy_index_meta')->where('model_type', $type)->value('total_docs');
    }

    private function docCount(string $term): int
    {
        return (int) DB::table('fuzzy_index_terms')->where('term', $term)->value('doc_count');
    }

    /** @return array<string, array{array}> */
    public static function modes(): array
    {
        return ['in process' => [[]], '--async' => [['--async' => true]]];
    }

    /**
     * @dataProvider modes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_plain_rebuild_removes_the_documents_of_rows_gone_trashed_or_scoped_out(array $options): void
    {
        $this->rebuild(PruneArticle::class);
        $this->assertSame(['1', '2', '3', '4', '5', '6'], $this->indexed(PruneArticle::class));

        // Without model events, as a reseed or a bulk cleanup writes them.
        DB::table('prune_articles')->where('id', 1)->delete();
        DB::table('prune_articles')->where('id', 2)->update(['archived' => true]);
        DB::table('prune_articles')->where('id', 3)->update(['deleted_at' => now()]);

        $this->rebuild(PruneArticle::class, $options);

        $this->assertSame(['4', '5', '6'], $this->indexed(PruneArticle::class));
        $this->assertSame(3, $this->totalDocs(PruneArticle::class));
        $this->assertSame(3, $this->docCount('quartz'));
        $this->assertSame(0, $this->docCount('amber'));
        $this->assertSame([], PruneArticle::search('birch')->useInvertedIndex()->typoTolerance(0)->get()->all());
        $this->assertSame(3, PruneArticle::search('quartz')->useInvertedIndex()->typoTolerance(0)->paginate(2)->total());
    }

    /**
     * @dataProvider modes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_string_keys(array $options): void
    {
        $this->rebuild(PruneCode::class);
        $this->assertSame(['10', '9', 'C-3', 'a-1', 'b-2'], $this->indexed(PruneCode::class));

        DB::table('prune_codes')->whereIn('code', ['a-1', '10'])->delete();

        $this->rebuild(PruneCode::class, $options);

        $this->assertSame(['9', 'C-3', 'b-2'], $this->indexed(PruneCode::class));
        $this->assertSame(3, $this->totalDocs(PruneCode::class));
        $this->assertSame(3, $this->docCount('quartz'));
    }

    /**
     * @dataProvider modes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_types_that_share_an_index(array $options): void
    {
        $this->rebuild(PruneKindParent::class);
        $this->assertSame(['1', '2', '3', '4'], $this->indexed(PruneKindParent::class));

        DB::table('prune_kinds')->whereIn('id', [1, 2])->delete(); // a child row and a parent row

        // Through the child: it names the parent's index, which the rebuild reads whole.
        $this->rebuild(PruneKindChild::class, $options);

        $this->assertSame(['3', '4'], $this->indexed(PruneKindParent::class));
        $this->assertSame(2, $this->totalDocs(PruneKindParent::class));
        $this->assertSame(['quartz three'], PruneKindChild::search('quartz')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all());
    }

    /**
     * @dataProvider modes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_table_emptied_leaves_an_empty_index(array $options): void
    {
        $this->rebuild(PruneArticle::class);
        DB::table('prune_articles')->delete();

        $this->rebuild(PruneArticle::class, $options);

        $this->assertSame([], $this->indexed(PruneArticle::class));
        $this->assertSame(0, $this->totalDocs(PruneArticle::class));
        $this->assertSame(0, $this->docCount('quartz'));
    }

    /**
     * --async prunes in steps of PruneIndexJob::CHUNKS chunks of 1,000 documents, each adding the
     * next to the batch: here steps of one chunk, over 2,005 documents, with gone rows in each.
     */
    public function test_the_prune_goes_on_in_steps_from_where_the_last_one_stopped(): void
    {
        DB::table('prune_articles')->delete();
        foreach (array_chunk(range(1, 2005), 500) as $chunk) {
            DB::table('prune_articles')->insert(array_map(fn ($i) => ['title' => "quartz row{$i}"], $chunk));
        }
        $this->rebuild(PruneArticle::class);
        $ids = DB::table('prune_articles')->orderBy('id')->pluck('id')->all();
        $this->assertSame(2005, DB::table('fuzzy_index_documents')->where('model_type', PruneArticle::class)->count());

        // The first and last row and one past the first 1,000 documents (model_id is ordered as a string).
        $gone = [$ids[0], $ids[1500], $ids[2004]];
        DB::table('prune_articles')->whereIn('id', $gone)->delete();

        $manager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
        $after   = null;
        $steps   = 0;
        $pruned  = 0;
        do {
            $pruned += $manager->prune(PruneArticle::class, $after, 1);
            $steps++;
        } while ($after !== null && $steps < 10);

        $this->assertSame(3, $pruned);
        $this->assertSame(3, $steps, 'two full chunks, then the last five documents');
        $this->assertSame(2002, $this->totalDocs(PruneArticle::class));

        // The job, in a batch: each step adds the next.
        DB::table('prune_articles')->whereIn('id', [$ids[1], $ids[1999]])->delete();
        $this->rebuild(PruneArticle::class, ['--async' => true]); // creates the batches table
        DB::table('prune_articles')->whereIn('id', [$ids[2], $ids[1998]])->delete();
        \Illuminate\Support\Facades\Bus::batch([new \Ashiqfardus\LaravelFuzzySearch\Jobs\PruneIndexJob(PruneArticle::class, null, 1)])->dispatch();

        $this->assertSame(1998, $this->totalDocs(PruneArticle::class));
        $this->assertSame(array_map('strval', array_values(array_diff($ids, [...$gone, $ids[1], $ids[1999], $ids[2], $ids[1998]]))), array_map('strval', DB::table('fuzzy_index_documents')->where('model_type', PruneArticle::class)->pluck('model_id')->map(fn ($id) => (int) $id)->sort()->values()->all()));
    }

    /**
     * ER-222b: a row a global scope hides is no row gone. A single-database multi-tenant app runs the
     * rebuild per tenant (tenants:run fuzzy-search:rebuild) under a tenant scope: the pruning read
     * through it removed every other tenant's documents. It reads the rows without global scopes;
     * a row deleted, trashed or left out by searchIndexQuery() is still removed.
     *
     * @dataProvider modes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_rows_a_global_scope_hides_keep_their_documents(array $options): void
    {
        foreach (['a', 'b'] as $tenant) {
            DB::table('prune_tenant_articles')->insert(array_map(fn ($i) => ['title' => "quartz {$tenant}{$i}", 'tenant' => $tenant], [1, 2, 3]));
        }
        $this->rebuild(PruneTenantArticle::class);
        $all = DB::table('prune_tenant_articles')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertSame($all, $this->indexed(PruneTenantArticle::class));

        // One of tenant b's rows is deleted; the rebuild runs for tenant a.
        $goneB = (string) DB::table('prune_tenant_articles')->where('tenant', 'b')->max('id');
        DB::table('prune_tenant_articles')->where('id', $goneB)->delete();
        PruneTenantArticle::$tenant = 'a';

        $this->rebuild(PruneTenantArticle::class, $options);

        PruneTenantArticle::$tenant = null;
        $this->assertSame(array_values(array_diff($all, [$goneB])), $this->indexed(PruneTenantArticle::class), 'tenant b keeps its documents, but the deleted row\'s');
        $this->assertSame(5, $this->totalDocs(PruneTenantArticle::class));
    }

    /**
     * Without global scopes a row is read as its kind: a document filed under a kind's type for a row
     * of another kind now is removed from it.
     *
     * @dataProvider modes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_row_that_changed_kind_leaves_the_type_of_its_old_kind(array $options): void
    {
        $this->rebuild(PruneSepParent::class);
        $this->assertSame(['1', '3'], $this->indexed(PruneSepChild::class));
        $this->assertSame(['2', '4'], $this->indexed(PruneSepParent::class));

        DB::table('prune_kinds')->where('id', 3)->update(['kind' => 'parent']); // without model events

        $this->rebuild(PruneSepChild::class, $options);

        $this->assertSame(['1'], $this->indexed(PruneSepChild::class));
        $this->assertSame(1, $this->totalDocs(PruneSepChild::class));

        $this->rebuild(PruneSepParent::class, $options);
        $this->assertSame(['2', '3', '4'], $this->indexed(PruneSepParent::class));
        $this->assertSame(['1'], $this->indexed(PruneSepChild::class));
    }

    /**
     * With scout.soft_delete on, Scout keeps a trashed row in the index (its __soft_deleted
     * constraint serves withTrashed() and onlyTrashed()), so a plain rebuild of a model on Scout's
     * trait keeps it too. A model without Scout's trait, or scout.soft_delete off, has it pruned.
     */
    public function test_a_scout_model_keeps_its_trashed_rows_while_scout_soft_delete_is_on(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }
        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false, 'scout.soft_delete' => true]);

        PruneScoutArticle::all()->searchable();
        PruneScoutArticle::query()->find(2)->delete(); // Scout's observer re-indexes it as trashed
        $this->assertContains('2', $this->indexed(PruneScoutArticle::class));
        $this->assertSame(['quartz birch'], PruneScoutArticle::search('birch')->withTrashed()->get()->pluck('title')->all());

        $this->rebuild(PruneScoutArticle::class);

        $this->assertSame(['1', '2', '3', '4', '5', '6'], $this->indexed(PruneScoutArticle::class));
        $this->assertSame(['quartz birch'], PruneScoutArticle::search('birch')->withTrashed()->get()->pluck('title')->all());
        $this->assertSame([], PruneScoutArticle::search('birch')->get()->all());

        // A model without Scout's trait has its trashed rows pruned, whatever scout.soft_delete says.
        $this->rebuild(PruneArticle::class);
        DB::table('prune_articles')->where('id', 3)->update(['deleted_at' => now()]);
        $this->rebuild(PruneArticle::class);
        $this->assertSame(['1', '4', '5', '6'], $this->indexed(PruneArticle::class)); // 2 trashed above, 3 now

        // scout.soft_delete off: Scout does not keep them, and neither does the rebuild.
        config(['scout.soft_delete' => false]);
        $this->rebuild(PruneScoutArticle::class);
        $this->assertSame(['1', '4', '5', '6'], $this->indexed(PruneScoutArticle::class));
    }

    public function test_the_rebuild_says_how_many_documents_it_removed(): void
    {
        $this->rebuild(PruneArticle::class);
        DB::table('prune_articles')->whereIn('id', [1, 2])->delete();

        $this->artisan('fuzzy-search:rebuild', ['model' => PruneArticle::class])
            ->expectsOutputToContain('Removed 2 documents')
            ->assertExitCode(0);
    }
}
