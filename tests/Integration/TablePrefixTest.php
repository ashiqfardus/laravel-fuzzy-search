<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A connection with a table prefix: the migrations and every index query must reach the
 * prefixed tables. Raw SQL that named fuzzy_index_terms (or the p alias) as written broke
 * `migrate` and the index path. Runs on whichever database DB_TEST_DRIVER selects.
 */
class TablePrefixTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $default = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$default}.prefix", 'pfx_');
    }

    private function index(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
    }

    private function postings(): int
    {
        return DB::table('fuzzy_index_postings')->where('model_type', User::class)->count();
    }

    public function test_migrate_and_rollback_run_on_a_prefixed_connection(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_terms'));
        $this->assertTrue(Schema::hasTable('fuzzy_search_logs'));
        $this->assertSame('pfx_', DB::connection()->getTablePrefix());

        $path = realpath(__DIR__ . '/../../database/migrations');
        $this->artisan('migrate:rollback', ['--path' => $path, '--realpath' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('fuzzy_index_terms'));
        $this->assertFalse(Schema::hasTable('fuzzy_index_postings'));

        $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasTable('fuzzy_index_terms'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'term_length'));
    }

    public function test_indexing_and_bm25_search_use_the_prefixed_tables(): void
    {
        $this->index();
        $this->assertGreaterThan(0, $this->postings());

        // User weighs name 10 and email 5, so the weighted-frequency CASE (the p alias) runs.
        $this->assertSame('John Doe', User::search('john')->useInvertedIndex()->typoTolerance(0)->get()->first()->name);
        $this->assertSame(1, User::search('john')->useInvertedIndex()->typoTolerance(0)->count());
        $this->assertSame(1, User::search('john')->useInvertedIndex()->typoTolerance(0)->paginate(5)->total());

        // Re-indexing a batch (the per-term doc_count decrement) and a single model (the upsert).
        $this->index();
        app(IndexManager::class)->indexModel(User::where('name', 'John Doe')->first());
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));
    }

    /** Ruling ER-82: past one candidate chunk the ordered walk restricts itself with raw SQL on the postings alias and the key. */
    public function test_the_ordered_index_walk_reads_the_prefixed_postings(): void
    {
        $this->index();
        config(['fuzzy-search.bm25.candidate_chunk' => 1]);

        $this->assertSame(['John Doe', 'Johnny Bravo', 'Jon Snow'], User::search('john')->useInvertedIndex()->orderBy('name')->get()->pluck('name')->all());
        $this->assertSame(['Jon Snow'], User::search('john')->useInvertedIndex()->orderBy('name')->paginate(2, 'page', 2)->pluck('name')->all());
    }

    public function test_did_you_mean_and_suggest_read_the_prefixed_dictionary(): void
    {
        $this->index();

        $this->assertContains('john', array_column(User::search('jhon')->didYouMean(), 'term'));
        $this->assertContains('john', User::search('jo')->useInvertedIndex()->suggestFrom('index')->suggest(10));
    }

    public function test_flush_rebuild_and_a_model_delete_on_the_prefixed_tables(): void
    {
        $this->index();

        app(IndexManager::class)->flush(User::class);
        $this->assertSame(0, $this->postings());
        $this->assertSame(0, DB::table('fuzzy_index_terms')->count()); // orphan sweep

        for ($run = 1; $run <= 2; $run++) {
            $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true])->assertExitCode(0);
        }
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));

        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $john = User::where('name', 'John Doe')->first();
        $john->delete();

        $this->assertSame(0, DB::table('fuzzy_index_postings')->where('model_type', User::class)->where('model_id', (string) $john->getKey())->count());
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));
    }
}
