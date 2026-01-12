<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Search Index Feature Tests
 * 
 * Tests for the search index table feature.
 * Note: Tests are designed for SQLite compatibility (no fulltext).
 */
class SearchIndexTest extends TestCase
{
    protected string $indexTable = 'search_index';

    protected function setUp(): void
    {
        parent::setUp();
        
        config(['fuzzy-search.indexing.enabled' => true]);
        config(['fuzzy-search.indexing.table' => $this->indexTable]);
        config(['fuzzy-search.indexing.async' => false]);
        
        // Create table manually for SQLite compatibility
        $this->createIndexTable();
    }

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

    /*
    |--------------------------------------------------------------------------
    | Index Table Tests
    |--------------------------------------------------------------------------
    */

    public function test_index_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable($this->indexTable));
    }

    public function test_index_table_has_required_columns(): void
    {
        $columns = Schema::getColumnListing($this->indexTable);
        
        $this->assertContains('id', $columns);
        $this->assertContains('model', $columns);
        $this->assertContains('model_id', $columns);
        $this->assertContains('content', $columns);
    }

    /*
    |--------------------------------------------------------------------------
    | Manual Reindex Tests
    |--------------------------------------------------------------------------
    */

    public function test_perform_reindex_populates_index(): void
    {
        DB::table($this->indexTable)->truncate();
        
        User::performReindex();

        $indexCount = DB::table($this->indexTable)->count();
        $userCount = DB::table('users')->count();
        
        $this->assertEquals($userCount, $indexCount);
    }

    public function test_reindex_stores_correct_model_class(): void
    {
        DB::table($this->indexTable)->truncate();
        
        User::performReindex();

        $record = DB::table($this->indexTable)->first();
        
        $this->assertNotNull($record);
        $this->assertEquals(User::class, $record->model);
    }

    public function test_reindex_content_contains_searchable_data(): void
    {
        DB::table($this->indexTable)->truncate();
        
        User::performReindex();

        $record = DB::table($this->indexTable)->first();
        
        $this->assertNotNull($record);
        $this->assertNotEmpty($record->content);
    }

    public function test_reindex_clears_existing_entries(): void
    {
        // First reindex
        User::performReindex();
        $countBefore = DB::table($this->indexTable)->count();

        // Second reindex should not double the entries
        User::performReindex();
        $countAfter = DB::table($this->indexTable)->count();

        $this->assertEquals($countBefore, $countAfter);
    }

    /*
    |--------------------------------------------------------------------------
    | Index Query Tests
    |--------------------------------------------------------------------------
    */

    public function test_index_can_be_queried(): void
    {
        DB::table($this->indexTable)->truncate();
        User::performReindex();

        $results = DB::table($this->indexTable)
            ->where('content', 'LIKE', '%John%')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_index_stores_model_id(): void
    {
        DB::table($this->indexTable)->truncate();
        User::performReindex();

        $record = DB::table($this->indexTable)->first();
        
        $this->assertNotNull($record->model_id);
        $this->assertGreaterThan(0, $record->model_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->indexTable);
        parent::tearDown();
    }
}
