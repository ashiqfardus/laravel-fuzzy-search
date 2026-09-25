<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Fuzzy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** 25 text columns, c1 … c25, all of them its Fuzzy trait's columns. */
class WideRow extends Model
{
    use Fuzzy;

    protected $table   = 'wide_rows';
    protected $guarded = [];
    public $timestamps = false;

    public function getFuzzySearchableColumns(): array
    {
        return WideColumns::names();
    }
}

final class WideColumns
{
    /** @return string[] */
    public static function names(): array
    {
        return array_map(fn (int $i) => 'c' . $i, range(1, 25));
    }
}

/**
 * Ruling ER-91: the multi-column entry points — whereFuzzyMultiple() and the fuzzySearch() macro,
 * the Fuzzy scopes, FuzzySearch::tableSearch() — bind at most 2,000 values of their own, as a
 * search does (ER-86). Each column got up to max_patterns patterns: 25 columns of a 40-letter
 * term bound 2,500 values, past SQL Server's 2,100.
 */
class MultiColumnBindingBudgetTest extends TestCase
{
    use FakesDriverConnections;

    private const LIMIT = 2000;

    /** 40 distinct letters and digits: the fuzzy driver has 100 patterns for it. */
    private const TERM = 'abcdefghijklmnopqrstuvwxyz0123456789wxyz';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('wide_rows');
        Schema::create('wide_rows', function ($table) {
            $table->id();
            foreach (WideColumns::names() as $column) {
                $table->string($column)->nullable();
            }
        });

        WideRow::query()->create(['c3' => 'plain']);
        WideRow::query()->create(['c7' => 'x ' . self::TERM . ' y']);                       // contains it
        WideRow::query()->create(['c25' => substr(self::TERM, 0, 5) . substr(self::TERM, 6)]); // one letter left out
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wide_rows');
        parent::tearDown();
    }

    /** @return int[] the binding count of every statement $run executes */
    private function bindingCounts(\Closure $run): array
    {
        $counts = [];
        DB::listen(function ($query) use (&$counts) {
            $counts[] = count($query->bindings);
        });
        $run();

        return $counts;
    }

    /** @return array<string, \Closure(): \Illuminate\Database\Eloquent\Builder> every multi-column entry point, over the 25 columns */
    private function entryPoints(): array
    {
        return [
            'whereFuzzyMultiple' => fn () => WideRow::query()->whereFuzzyMultiple(WideColumns::names(), self::TERM),
            'fuzzySearch macro'  => fn () => WideRow::query()->fuzzySearch(WideColumns::names(), self::TERM),
            'Fuzzy scope'        => fn () => WideRow::query()->fuzzy(self::TERM, null, 'fuzzy'),
            'tableSearch'        => fn () => FuzzySearch::tableSearch(WideColumns::names(), 'fuzzy')(WideRow::query(), self::TERM),
        ];
    }

    public function test_every_multi_column_entry_point_runs_within_the_limit(): void
    {
        foreach ($this->entryPoints() as $name => $make) {
            $counts = $this->bindingCounts(function () use ($make, &$rows) {
                $rows = $make()->orderBy('id')->pluck('id')->count();
            });

            $this->assertSame(2, $rows, $name);
            $this->assertLessThanOrEqual(self::LIMIT, max($counts), $name);
        }
    }

    public function test_a_query_builder_on_every_grammar_binds_the_same_values_within_the_limit(): void
    {
        $shapes = [
            'whereFuzzyMultiple' => fn ($q) => $q->whereFuzzyMultiple(WideColumns::names(), self::TERM),
            'levenshtein'        => fn ($q) => $q->whereFuzzyMultiple(WideColumns::names(), self::TERM, 'levenshtein'),
            'fuzzySearch macro'  => fn ($q) => $q->fuzzySearch(WideColumns::names(), self::TERM),
        ];

        foreach ($shapes as $name => $shape) {
            $bindings = [];
            foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
                if (!$this->fakeDriverAvailable($driver)) {
                    continue;
                }
                $bindings[$driver] = count($shape($this->fakeConnectionTable($driver, 'wide_rows'))->getBindings());
                $this->assertLessThanOrEqual(self::LIMIT, $bindings[$driver], "{$name} on {$driver}");
            }
            $this->assertCount(1, array_unique($bindings), "{$name}: " . json_encode($bindings));
            $this->assertGreaterThan(self::LIMIT / 2, $bindings['sqlite'], "{$name} still uses the budget");
        }
    }

    /** The caller's own values are theirs: a where() before the call does not shrink its patterns. */
    public function test_the_callers_own_bindings_do_not_shrink_the_patterns(): void
    {
        $ids   = range(1, 1500);
        $plain = count(WideRow::query()->whereFuzzyMultiple(WideColumns::names(), self::TERM)->getBindings());

        $this->assertSame($plain + 1500, count(WideRow::query()->whereIn('id', $ids)->whereFuzzyMultiple(WideColumns::names(), self::TERM)->getBindings()));
    }

    public function test_more_columns_than_even_one_pattern_each_can_bind_throws(): void
    {
        $columns = array_map(fn (int $i) => 'c' . $i, range(1, 2001));

        foreach ([
            'whereFuzzyMultiple' => fn () => DB::table('wide_rows')->whereFuzzyMultiple($columns, 'abc', 'like'),
            'tableSearch'        => fn () => FuzzySearch::tableSearch($columns, 'like')(WideRow::query(), 'abc'),
        ] as $name => $call) {
            try {
                $call();
                $this->fail("{$name} built the query");
            } catch (QuerySyntaxException $e) {
                $this->assertStringContainsString('too complex', $e->getMessage(), $name);
            }
        }
    }
}
