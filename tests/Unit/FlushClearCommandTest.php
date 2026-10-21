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

    /**
     * Deep review RC-5. clear and flush refused a model class that no longer exists, so the index
     * rows of a renamed or deleted model could only go with clear --all, which destroys every other
     * model's index too. A model_type the index holds is cleared whether or not its class exists;
     * a name that is neither still fails.
     */
    public function test_a_model_whose_class_is_gone_is_cleared_and_other_models_stay(): void
    {
        foreach (['fuzzy-search:flush', 'fuzzy-search:clear'] as $command) {
            app(IndexManager::class)->indexBatch(User::all());
            $gone = 'App\\Models\\RenamedAway';

            // The rows a model left behind before its class was renamed: User's rows under its old name.
            foreach (['fuzzy_index_postings' => 'model_type_id', 'fuzzy_index_documents' => 'model_type', 'fuzzy_index_meta' => 'model_type'] as $table => $column) {
                [$from, $to] = $column === 'model_type' ? [User::class, $gone] : [$this->modelTypeId(User::class), $this->modelTypeId($gone)];
                $rows = DB::table($table)->where($column, $from)->get()->map(fn ($r) => array_merge((array) $r, [$column => $to]));
                foreach ($rows as $row) {
                    unset($row['id']);
                    DB::table($table)->insert($row);
                }
            }
            // Its share of the dictionary's doc_count too: every term is User's alone here, so it doubles.
            DB::table('fuzzy_index_terms')->update(['doc_count' => DB::raw('doc_count * 2')]);
            $postings = $this->namedPostings()->where('model_type', User::class)->count();
            $this->assertGreaterThan(0, $postings);
            $this->assertSame($postings, $this->namedPostings()->where('model_type', $gone)->count());

            $this->artisan($command, ['model' => $gone])
                ->expectsOutputToContain("Cleared BM25 index for [{$gone}]")
                ->assertExitCode(0);

            $this->assertSame(0, $this->namedPostings()->where('model_type', $gone)->count(), $command);
            $this->assertSame(0, DB::table('fuzzy_index_meta')->where('model_type', $gone)->count(), $command);
            $this->assertSame($postings, $this->namedPostings()->where('model_type', User::class)->count(), "{$command}: the other model's rows stay");
            $this->assertSame(0, DB::table('fuzzy_index_terms')->where('doc_count', '<=', 0)->count(), "{$command}: the terms User still uses keep their count");
            $this->assertGreaterThan(0, User::search('john')->useInvertedIndex()->get()->count(), "{$command}: the other model still searches");

            $this->artisan($command, ['model' => 'App\\Models\\NeverIndexed'])
                ->expectsOutputToContain('Model class [App\\Models\\NeverIndexed] not found.')
                ->assertExitCode(1);

            app(IndexManager::class)->flush(User::class);
        }
    }

    /**
     * A flush that fails part way (MySQL/MariaDB commit it in batches) leaves documents whose
     * postings are gone: a rebuild without --fresh counts their words a second time. The commands
     * say to run the same command again first.
     */
    public function test_a_failed_clear_or_rebuild_fresh_says_to_run_it_again_before_anything_else(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $failing = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failing) {
            if ($failing && preg_match('/^\s*(delete from|truncate( table)?) [`"\[]?fuzzy_index_/i', $sql)) {
                throw new \RuntimeException('connection lost');
            }
        });

        $this->artisan('fuzzy-search:clear', ['model' => User::class])
            ->expectsOutputToContain('The clear did not finish: connection lost')
            ->expectsOutputToContain('fuzzy-search:clear "' . User::class . '" again before any other index command')
            ->assertExitCode(1);
        $this->artisan('fuzzy-search:clear', ['--all' => true])
            ->expectsOutputToContain('fuzzy-search:clear --all again before any other index command')
            ->assertExitCode(1);
        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true])
            ->expectsOutputToContain('The flush did not finish: connection lost')
            ->expectsOutputToContain('fuzzy-search:rebuild "' . User::class . '" --fresh again before any other index command')
            ->assertExitCode(1);
        $failing = false;
    }

    /**
     * A batched flush marks the model's meta row first (fuzzy_index_meta.flushing_since) and the
     * transaction that ends it deletes the row. A mark that is still set belongs to a flush that
     * died: a plain rebuild refuses, status says so, and the flush run again clears it.
     */
    public function test_a_plain_rebuild_refuses_while_a_flush_of_the_model_is_unfinished(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        DB::table('fuzzy_index_meta')->where('model_type', User::class)->update(['flushing_since' => now()]);
        $postings = DB::table('fuzzy_index_postings')->count();

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])
            ->expectsOutputToContain('did not finish')
            ->expectsOutputToContain('before any other index command')
            ->assertExitCode(1);
        $this->artisan('fuzzy-search:status')
            ->expectsOutputToContain('did not finish')
            ->assertExitCode(0);

        $this->artisan('fuzzy-search:clear', ['model' => User::class])->assertExitCode(0);
        $this->assertSame(0, DB::table('fuzzy_index_meta')->whereNotNull('flushing_since')->count());
        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);
        $this->assertSame($postings, DB::table('fuzzy_index_postings')->count());
    }

    public function test_the_flushing_since_migration_runs_down_and_up(): void
    {
        $migration = require __DIR__ . '/../../database/migrations/2026_10_22_000001_add_flushing_since_to_fuzzy_index_meta_table.php';

        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('fuzzy_index_meta', 'flushing_since'));
        $migration->down(); // a second run finds nothing to drop
        $migration->up();
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('fuzzy_index_meta', 'flushing_since'));
    }
}
