<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** An Eloquent model without the Searchable trait. */
class PlainCommandUser extends Model
{
    protected $table = 'users';
}

/**
 * Every command fails (exit code 1) on input it cannot act on, instead of reporting success or
 * crashing on it.
 */
class CommandExitCodesTest extends TestCase
{
    public function test_rebuild_fails_on_a_missing_class_a_non_model_and_a_model_without_the_trait(): void
    {
        $this->artisan('fuzzy-search:rebuild', ['model' => 'App\\Models\\Nope'])->expectsOutputToContain('not found')->assertExitCode(1);
        $this->artisan('fuzzy-search:rebuild', ['model' => \stdClass::class])->expectsOutputToContain('is not an Eloquent model')->assertExitCode(1);
        $this->artisan('fuzzy-search:rebuild', ['model' => PlainCommandUser::class])->expectsOutputToContain('Searchable')->assertExitCode(1);
        $this->assertSame(0, DB::table('fuzzy_index_meta')->count());

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);
    }

    public function test_flush_and_clear_fail_on_a_non_model_and_accept_any_model(): void
    {
        $this->artisan('fuzzy-search:flush', ['model' => \stdClass::class])->expectsOutputToContain('is not an Eloquent model')->assertExitCode(1);
        $this->artisan('fuzzy-search:clear', ['model' => \stdClass::class])->expectsOutputToContain('is not an Eloquent model')->assertExitCode(1);
        $this->artisan('fuzzy-search:flush', ['model' => 'App\\Models\\Nope'])->assertExitCode(1);

        // A model that no longer uses the trait may still have index rows to remove.
        $this->artisan('fuzzy-search:flush', ['model' => PlainCommandUser::class])->assertExitCode(0);
        $this->artisan('fuzzy-search:clear', ['model' => PlainCommandUser::class])->assertExitCode(0);
    }

    public function test_benchmark_rejects_a_non_positive_iteration_count_and_a_model_without_the_trait(): void
    {
        foreach (['0', '-3', 'abc'] as $iterations) {
            $this->artisan('fuzzy-search:benchmark', ['model' => User::class, '--iterations' => $iterations, '--term' => 'john'])
                ->expectsOutputToContain('--iterations')
                ->assertExitCode(1);
        }

        $this->artisan('fuzzy-search:benchmark', ['model' => \stdClass::class])->assertExitCode(1);
        $this->artisan('fuzzy-search:benchmark', ['model' => PlainCommandUser::class])->expectsOutputToContain('Searchable')->assertExitCode(1);
        $this->artisan('fuzzy-search:benchmark', ['model' => User::class, '--iterations' => 2, '--term' => 'john'])->assertExitCode(0);
    }

    public function test_explain_fails_on_a_non_model_and_a_model_without_the_trait(): void
    {
        $this->artisan('fuzzy-search:explain', ['model' => \stdClass::class])->assertExitCode(1);
        $this->artisan('fuzzy-search:explain', ['model' => PlainCommandUser::class])->expectsOutputToContain('Searchable')->assertExitCode(1);
    }

    public function test_the_deprecated_index_command_fails_on_a_non_model(): void
    {
        $this->artisan('fuzzy-search:index', ['model' => \stdClass::class])->expectsOutputToContain('is not an Eloquent model')->assertExitCode(1);
    }

    public function test_analytics_commands_reject_a_non_integer_day_count(): void
    {
        DB::table('fuzzy_search_logs')->insert([
            'term' => 'john', 'normalized_term' => 'john', 'model_type' => null, 'algorithm' => 'fuzzy', 'path' => 'like',
            'result_count' => 1, 'latency_ms' => 1, 'day' => now()->subDays(40)->toDateString(), 'created_at' => now()->subDays(40),
        ]);

        // (int) 'abc' is 0, and a 0-day window deleted every row.
        $this->artisan('fuzzy-search:analytics:prune', ['--days' => 'abc'])->expectsOutputToContain('--days')->assertExitCode(1);
        $this->assertSame(1, DB::table('fuzzy_search_logs')->count());

        $this->artisan('fuzzy-search:analytics', ['--days' => 'abc'])->expectsOutputToContain('--days')->assertExitCode(1);
        $this->artisan('fuzzy-search:analytics', ['--limit' => '0'])->expectsOutputToContain('--limit')->assertExitCode(1);
    }
}
