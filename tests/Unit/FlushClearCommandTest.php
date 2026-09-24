<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/** fuzzy-search:flush <model> is fuzzy-search:clear <model>: one code path, one result. */
class FlushClearCommandTest extends TestCase
{
    public function test_flush_runs_clear_for_the_model(): void
    {
        foreach (['fuzzy-search:flush', 'fuzzy-search:clear'] as $command) {
            app(IndexManager::class)->indexBatch(User::all());
            $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());

            $this->artisan($command, ['model' => User::class])
                ->expectsOutputToContain('Cleared BM25 index for [' . User::class . ']')
                ->assertExitCode(0);

            $this->assertSame(0, DB::table('fuzzy_index_postings')->count(), $command);
            $this->assertSame(0, DB::table('fuzzy_index_meta')->count(), $command);
        }
    }
}
