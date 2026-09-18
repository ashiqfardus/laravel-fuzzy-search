<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Drivers\FuzzyDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\LevenshteinDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\SimilarTextDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\SimpleDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\SoundexDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\TrigramDriver;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * A driver's predicate must be self-contained: whatever it adds to the WHERE clause has to
 * stay one unit when the caller chains another constraint after it. A raw predicate with a
 * bare top-level OR breaks that — AND binds tighter, so `soundex(a) OR soundex(b) AND
 * tenant_id = ?` only guards the right arm and the search leaks across the constraint.
 */
class PredicateGroupingTest extends TestCase
{
    use FakesDriverConnections;

    /** MetaphoneDriver is left out: it adds one `where(shadow, code)` equality and needs a real shadow column. */
    private const DRIVERS = [
        FuzzyDriver::class,
        LevenshteinDriver::class,
        SimilarTextDriver::class,
        SimpleDriver::class,
        SoundexDriver::class,
        TrigramDriver::class,
    ];

    public function test_every_driver_predicate_is_self_contained_on_every_dialect(): void
    {
        $checked = 0;

        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $dialect) {
            if (!$this->fakeDriverAvailable($dialect)) {
                continue;
            }

            foreach ([false, true] as $native) {
                $config = array_merge(config('fuzzy-search'), ['use_native_functions' => $native]);

                foreach (self::DRIVERS as $class) {
                    $query = $this->fakeConnectionTable($dialect, 'users');
                    (new $class($config, $dialect))->apply($query, 'name', 'john');
                    $query->where('tenant_id', 7);

                    $sql   = $query->toSql();
                    $where = substr($sql, stripos($sql, ' where ') + 7);

                    $this->assertFalse(
                        $this->hasTopLevelOr($where),
                        class_basename($class) . " on {$dialect} (native=" . var_export($native, true) . ") leaves a bare OR next to a chained constraint: {$where}"
                    );
                    $checked++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(48, $checked);
    }

    public function test_a_constraint_chained_after_a_soundex_search_guards_every_match(): void
    {
        $emails = DB::table('users')
            ->whereFuzzy('name', 'john', 'soundex')
            ->where('email', 'john@example.com')
            ->pluck('email')
            ->all();

        // Jon Snow, Johnny Bravo and Jane Doe all sound like "john" — none may leak past the email constraint.
        $this->assertSame(['john@example.com'], $emails);
    }

    /** True when $where contains an OR outside every pair of parentheses. */
    private function hasTopLevelOr(string $where): bool
    {
        $lower = strtolower($where);
        $depth = 0;

        for ($i = 0, $len = strlen($lower); $i < $len; $i++) {
            if ($lower[$i] === '(') {
                $depth++;
            } elseif ($lower[$i] === ')') {
                $depth--;
            } elseif ($depth === 0 && substr($lower, $i, 4) === ' or ') {
                return true;
            }
        }

        return false;
    }
}
