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
            foreach (['fuzzy_index_postings', 'fuzzy_index_documents', 'fuzzy_index_meta'] as $table) {
                $rows = DB::table($table)->where('model_type', User::class)->get()->map(fn ($r) => array_merge((array) $r, ['model_type' => $gone]));
                foreach ($rows as $row) {
                    unset($row['id']);
                    DB::table($table)->insert($row);
                }
            }
            // Its share of the dictionary's doc_count too: every term is User's alone here, so it doubles.
            DB::table('fuzzy_index_terms')->update(['doc_count' => DB::raw('doc_count * 2')]);
            $postings = DB::table('fuzzy_index_postings')->where('model_type', User::class)->count();
            $this->assertGreaterThan(0, $postings);
            $this->assertSame($postings, DB::table('fuzzy_index_postings')->where('model_type', $gone)->count());

            $this->artisan($command, ['model' => $gone])
                ->expectsOutputToContain("Cleared BM25 index for [{$gone}]")
                ->assertExitCode(0);

            $this->assertSame(0, DB::table('fuzzy_index_postings')->where('model_type', $gone)->count(), $command);
            $this->assertSame(0, DB::table('fuzzy_index_meta')->where('model_type', $gone)->count(), $command);
            $this->assertSame($postings, DB::table('fuzzy_index_postings')->where('model_type', User::class)->count(), "{$command}: the other model's rows stay");
            $this->assertSame(0, DB::table('fuzzy_index_terms')->where('doc_count', '<=', 0)->count(), "{$command}: the terms User still uses keep their count");
            $this->assertGreaterThan(0, User::search('john')->useInvertedIndex()->get()->count(), "{$command}: the other model still searches");

            $this->artisan($command, ['model' => 'App\\Models\\NeverIndexed'])
                ->expectsOutputToContain('Model class [App\\Models\\NeverIndexed] not found.')
                ->assertExitCode(1);

            app(IndexManager::class)->flush(User::class);
        }
    }
}
