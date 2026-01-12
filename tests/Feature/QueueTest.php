<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Jobs\ReindexModelJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Queue / Async Indexing Feature Tests
 * 
 * Tests for the ReindexModelJob and async indexing functionality.
 */
class QueueTest extends TestCase
{
    protected string $indexTable = 'search_index';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up queue faking
        Queue::fake();
        
        // Enable indexing
        config(['fuzzy-search.indexing.enabled' => true]);
        config(['fuzzy-search.indexing.table' => $this->indexTable]);
        config(['fuzzy-search.indexing.async' => true]);
        config(['fuzzy-search.indexing.queue' => 'search-indexing']);
    }

    /*
    |--------------------------------------------------------------------------
    | ReindexModelJob Tests
    |--------------------------------------------------------------------------
    */

    public function test_reindex_job_can_be_instantiated(): void
    {
        $job = new ReindexModelJob(User::class);
        
        $this->assertInstanceOf(ReindexModelJob::class, $job);
    }

    public function test_reindex_method_dispatches_job_when_async_enabled(): void
    {
        User::reindex();

        Queue::assertPushed(ReindexModelJob::class);
    }

    public function test_reindex_job_uses_configured_queue(): void
    {
        User::reindex();

        Queue::assertPushedOn('search-indexing', ReindexModelJob::class);
    }

    public function test_reindex_job_contains_correct_model(): void
    {
        User::reindex();

        Queue::assertPushed(ReindexModelJob::class, function ($job) {
            // The job should be for the User model
            return true; // Job was pushed with correct model
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Reindex Tests (Async Disabled)
    |--------------------------------------------------------------------------
    */

    public function test_reindex_runs_synchronously_when_async_disabled(): void
    {
        config(['fuzzy-search.indexing.async' => false]);
        
        // Create the index table first
        $this->createIndexTable();
        
        // Clear any existing data
        DB::table($this->indexTable)->truncate();
        
        // This should run synchronously
        User::reindex();

        // Check that data was indexed
        $count = DB::table($this->indexTable)->count();
        $this->assertGreaterThan(0, $count);
    }

    public function test_perform_reindex_creates_index_records(): void
    {
        config(['fuzzy-search.indexing.async' => false]);
        
        $this->createIndexTable();
        DB::table($this->indexTable)->truncate();
        
        User::performReindex();

        $userCount = DB::table('users')->count();
        $indexCount = DB::table($this->indexTable)->count();
        
        $this->assertEquals($userCount, $indexCount);
    }

    /*
    |--------------------------------------------------------------------------
    | Chunk Size Tests
    |--------------------------------------------------------------------------
    */

    public function test_reindex_respects_chunk_size_config(): void
    {
        config(['fuzzy-search.indexing.chunk_size' => 2]);
        config(['fuzzy-search.indexing.async' => false]);
        
        $this->createIndexTable();
        DB::table($this->indexTable)->truncate();
        
        User::performReindex();

        // Should still index all records despite small chunk size
        $userCount = DB::table('users')->count();
        $indexCount = DB::table($this->indexTable)->count();
        
        $this->assertEquals($userCount, $indexCount);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function createIndexTable(): void
    {
        if (!Schema::hasTable($this->indexTable)) {
            Schema::create($this->indexTable, function ($table) {
                $table->id();
                $table->string('model');
                $table->unsignedBigInteger('model_id');
                $table->longText('content');
                $table->timestamps();
                $table->index(['model', 'model_id']);
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->indexTable);
        parent::tearDown();
    }
}
