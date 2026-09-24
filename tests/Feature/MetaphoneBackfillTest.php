<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A metaphone shadow column added to a table that already has rows starts out empty: only a
 * save fills it. fuzzy-search:rebuild (sync, and each --async batch job) fills it for every
 * existing row, through a query update so no model events fire.
 */
class MetaphoneBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The seven seeded users were inserted before the column existed.
        Schema::table('users', fn ($table) => $table->string('name_metaphone')->nullable());
        SearchableObserver::resetColumnCache();
    }

    private function assertBackfilled(): void
    {
        $rows = DB::table('users')->get(['name', 'name_metaphone']);

        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $this->assertSame(metaphone($row->name), $row->name_metaphone, $row->name);
        }

        // SoftDeletedUser: the same users table, searchable on name only (User also searches email).
        $found = SoftDeletedUser::search('Jon Doe')->using('metaphone')->get()->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['John Doe', 'Jane Doe'], $found); // both JNT
    }

    public function test_rebuild_fills_the_shadow_column_for_existing_rows_without_firing_model_events(): void
    {
        $this->assertSame(7, DB::table('users')->whereNull('name_metaphone')->count());

        $events = 0;
        User::saving(function () use (&$events) { $events++; });
        User::updating(function () use (&$events) { $events++; });

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true])->assertExitCode(0);

        $this->assertBackfilled();
        $this->assertSame(0, $events);
    }

    /** One CASE UPDATE per chunk and shadow column; a row whose shadow value is right is left alone. */
    public function test_rebuild_writes_one_update_per_chunk_and_none_when_the_shadow_values_are_current(): void
    {
        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (preg_match('/^\s*update\b/i', $query->sql) && str_contains($query->sql, 'users')) {
                $updates++;
            }
        });

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);
        $this->assertBackfilled();
        $this->assertSame(1, $updates); // seven rows, one chunk

        $updates = 0;
        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);
        $this->assertSame(0, $updates);

        // A row whose value changed behind the model's back (a query-builder update) is refreshed.
        DB::table('users')->where('name', 'Jon Snow')->update(['name' => 'Stephen Snow']);
        $updates = 0;
        (new RebuildIndexJob(User::class, User::pluck('id')->all()))->handle(app(IndexManager::class));
        $this->assertSame(1, $updates);
        $this->assertSame(metaphone('Stephen Snow'), DB::table('users')->where('name', 'Stephen Snow')->value('name_metaphone'));
    }

    public function test_a_save_back_to_the_previous_value_still_updates_the_shadow_column(): void
    {
        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);
        $user = User::where('name', 'John Doe')->first(); // name_metaphone loaded

        $user->update(['name' => 'Stephen']);
        $user->update(['name' => 'John Doe']);

        $this->assertSame(metaphone('John Doe'), DB::table('users')->where('id', $user->getKey())->value('name_metaphone'));
        $this->assertSame(metaphone('John Doe'), $user->name_metaphone);
    }

    public function test_the_async_rebuild_job_fills_it_too(): void
    {
        (new RebuildIndexJob(User::class, User::pluck('id')->all()))->handle(app(IndexManager::class));

        $this->assertBackfilled();
    }
}
