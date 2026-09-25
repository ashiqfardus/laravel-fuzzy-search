<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShadowKeyItem extends Model
{
    use Searchable;

    protected $table      = 'shadow_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * The rebuild's shadow-column backfill keyed its values by model key, and PHP turns an all-digit
 * array key ('12') into an int. Bound as an int against a string key column, SQL Server converts
 * the whole nvarchar column to int and fails on 'abc' (22018), so a string key is bound as a
 * string, on the sync rebuild and in each --async batch job.
 */
class ShadowBackfillStringKeyTest extends TestCase
{
    /** @var list<array<int, mixed>> the bindings of each shadow-column UPDATE */
    private array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shadow_key_items');
        Schema::create('shadow_key_items', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name');
            $table->string('name_metaphone')->nullable();
        });
        SearchableObserver::resetColumnCache();

        // Inserted without model events, as rows saved before the shadow column existed.
        DB::table('shadow_key_items')->insert([['code' => '12', 'name' => 'Smith'], ['code' => 'abc', 'name' => 'Jones']]);

        DB::listen(function ($query) {
            if (preg_match('/^\s*update\b/i', $query->sql) && str_contains($query->sql, 'shadow_key_items')) {
                $this->updates[] = $query->bindings;
            }
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shadow_key_items');

        parent::tearDown();
    }

    private function assertBackfilledWithStringKeys(): void
    {
        $this->assertSame(
            ['12' => metaphone('Smith'), 'abc' => metaphone('Jones')],
            DB::table('shadow_key_items')->orderBy('code')->pluck('name_metaphone', 'code')->all()
        );

        // One UPDATE: case code when ? then ? ... end where code in (?, ?); '12' bound as a string
        // in the CASE and in the IN list alike (assertNotContains() compares by identity).
        $this->assertCount(1, $this->updates);
        [$bindings] = $this->updates;
        $this->assertCount(6, $bindings);
        $this->assertSame(2, count(array_keys($bindings, '12', true)));
        $this->assertNotContains(12, $bindings);
    }

    public function test_the_rebuild_backfills_string_keys_that_mix_digits_and_letters(): void
    {
        $this->artisan('fuzzy-search:rebuild', ['model' => ShadowKeyItem::class])->assertExitCode(0);

        $this->assertBackfilledWithStringKeys();
    }

    public function test_the_async_rebuild_job_binds_them_as_strings_too(): void
    {
        (new RebuildIndexJob(ShadowKeyItem::class, ['12', 'abc']))->handle(app(IndexManager::class));

        $this->assertBackfilledWithStringKeys();
    }
}
