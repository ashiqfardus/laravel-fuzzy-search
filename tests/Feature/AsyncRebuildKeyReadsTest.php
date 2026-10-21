<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class KeyReadsIntItem extends Model
{
    use Searchable;

    protected $table      = 'er230_ints';
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 1]];
}

/** An integer column whose key the model casts to a string. */
class KeyReadsCastItem extends KeyReadsIntItem
{
    protected $casts = ['id' => 'string'];
}

class KeyReadsUlidItem extends Model
{
    use HasUlids, Searchable;

    protected $table   = 'er230_ulids';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * rebuild --async read its keys one chunk_size page (500 rows) at a time: one query per job. It now
 * reads larger pages and splits them into chunk_size jobs, so the jobs carry the same keys in the
 * same order and the key reads fall to a tenth or less.
 */
class AsyncRebuildKeyReadsTest extends TestCase
{
    private const ROWS = 5500;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['er230_ints', 'er230_ulids'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('er230_ints', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('er230_ulids', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
        });
        SearchableObserver::resetColumnCache();
        config(['queue.batching' => ['database' => config('database.default'), 'table' => 'er230_job_batches']]);
        Schema::dropIfExists('er230_job_batches');
        Schema::create('er230_job_batches', function (Blueprint $table) {
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
    }

    protected function tearDown(): void
    {
        foreach (['er230_ints', 'er230_ulids', 'er230_job_batches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function fill(string $table, int $rows, bool $ulid = false): void
    {
        $data = [];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = ($ulid ? ['id' => (string) \Illuminate\Support\Str::ulid()] : []) + ['name' => "Item {$i}"];
        }
        foreach (array_chunk($data, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * @return array{0: list<list<int|string>>, 1: int} the key set of each dispatched job, and the
     *         number of queries that read keys from the model's table
     */
    private function dispatch(string $modelClass, int $chunkSize): array
    {
        Bus::fake();
        config(['fuzzy-search.indexing.chunk_size' => $chunkSize]);
        $table = (new $modelClass)->getTable();
        $reads = 0;
        DB::listen(function ($query) use (&$reads, $table) {
            if (preg_match('/^\s*select\b/i', $query->sql) && str_contains($query->sql, $table)) {
                $reads++;
            }
        });

        $this->artisan('fuzzy-search:rebuild', ['model' => $modelClass, '--async' => true])->assertExitCode(0);

        $sets = [];
        Bus::assertBatched(function ($batch) use (&$sets) {
            foreach ($batch->jobs as $job) {
                if ($job instanceof RebuildIndexJob) {
                    $sets[] = $job->modelIds;
                }
            }

            return true;
        });

        return [$sets, $reads];
    }

    /** @return list<list<int|string>> what the jobs must carry: every key in key order, chunk_size to a job */
    private function expected(string $modelClass, int $chunkSize): array
    {
        $model = new $modelClass;
        $keys  = $model->newQuery()->orderBy($model->getKeyName())->get()->map(fn ($m) => $m->getKey())->all();

        return array_chunk($keys, $chunkSize);
    }

    /** @return array<string, array{0: class-string}> */
    public static function keyTypes(): array
    {
        return [
            'int key'            => [KeyReadsIntItem::class],
            'key with a cast'    => [KeyReadsCastItem::class],
            'ulid key'           => [KeyReadsUlidItem::class],
        ];
    }

    #[DataProvider('keyTypes')]
    public function test_the_jobs_carry_the_same_keys_and_the_key_reads_fall_to_a_tenth(string $modelClass): void
    {
        $this->fill((new $modelClass)->getTable(), self::ROWS, $modelClass === KeyReadsUlidItem::class);

        [$sets, $reads] = $this->dispatch($modelClass, 100);

        $this->assertSame($this->expected($modelClass, 100), $sets);
        if ($modelClass === KeyReadsCastItem::class) {
            $this->assertIsString($sets[0][0]);
        }
        // One read per job (55 pages of 100, and the empty page that ends the walk) before.
        $this->assertLessThanOrEqual(5, $reads);
    }

    public function test_a_chunk_size_that_does_not_divide_the_page_still_splits_the_keys_the_same(): void
    {
        $this->fill('er230_ints', 20000);

        [$sets, $reads] = $this->dispatch(KeyReadsIntItem::class, 1500);

        $this->assertSame($this->expected(KeyReadsIntItem::class, 1500), $sets);
        $this->assertLessThanOrEqual(4, $reads);
    }
}
