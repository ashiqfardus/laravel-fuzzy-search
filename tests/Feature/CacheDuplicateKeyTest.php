<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Keyed by a column two rows share, as a model on a view or a legacy table without a unique key can be. */
class SharedKeyUser extends Model
{
    public $incrementing = false;

    protected $table      = 'users';
    protected $primaryKey = 'name';
    protected $keyType    = 'string';
    protected $guarded    = [];
}

/**
 * Ruling ER-92: rows are cached by key only when each has a key of its own. A re-read by a shared
 * key returns one model for both rows, so the hit repeated it and lost the other.
 */
class CacheDuplicateKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    /** @return array{0: string[], 1: string[]} the miss's rows, then the hit's */
    private function missThenHit(\Closure $query): array
    {
        $run = fn () => (new SearchBuilder($query(), app(FuzzySearch::class)))
            ->search('doe')->searchIn(['name'])->using('like')->cache(60)->get()
            ->map(fn ($row) => $row->name . '/' . ($row->src ?? $row->email))->sort()->values()->all();

        return [$run(), $run()];
    }

    public function test_rows_sharing_a_key_on_the_models_own_table_hit_as_they_missed(): void
    {
        DB::table('users')->insert(['name' => 'Jane Doe', 'email' => 'jane.two@example.com', 'created_at' => now(), 'updated_at' => now()]);

        [$miss, $hit] = $this->missThenHit(fn () => SharedKeyUser::query());

        $this->assertSame(['Jane Doe/jane.two@example.com', 'Jane Doe/jane@example.com', 'John Doe/john@example.com'], $miss);
        $this->assertSame($miss, $hit);
    }

    public function test_a_from_subquery_that_repeats_keys_hits_as_it_missed(): void
    {
        [$miss, $hit] = $this->missThenHit(fn () => User::query()->fromSub(
            DB::table('users')->select('users.*', DB::raw("'a' as src"))->unionAll(DB::table('users')->select('users.*', DB::raw("'b' as src"))),
            'users'
        ));

        $this->assertCount(4, $miss);
        $this->assertSame($miss, $hit);
    }
}
