<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * SF-6 (ruling ER-150). On PostgreSQL with use_native_functions, using('levenshtein') ran
 * pg_trgm's similarity() against a threshold that no one-edit typo of a short word reaches, so
 * "jonh" found no "john", and "smith" no "Alice Smith". Levenshtein keeps its pattern set there
 * whatever the flag. MySQL and MariaDB are skipped: the flag sends them to the LEVENSHTEIN() UDF,
 * which the test databases do not install (DriverDialectSqlTest pins that SQL); on SQLite and SQL
 * Server the flag never changed levenshtein.
 */
class LevenshteinNativeFlagTest extends TestCase
{
    public function test_typos_and_substrings_are_found_with_native_functions_on(): void
    {
        if (DbDialect::isMySqlFamily(DB::connection()->getDriverName())) {
            $this->markTestSkipped('use_native_functions sends MySQL and MariaDB to the LEVENSHTEIN() UDF, which is not installed here.');
        }

        DB::table('users')->insert([
            ['name' => 'john', 'email' => 'w1@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Catherine', 'email' => 'w2@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Margaret', 'email' => 'w3@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([false, true] as $native) {
            config(['fuzzy-search.use_native_functions' => $native]);
            $label = $native ? 'native' : 'pattern';

            foreach (['jonh' => 'john', 'jahn' => 'john', 'katherine' => 'Catherine', 'margret' => 'Margaret', 'smith' => 'Alice Smith'] as $term => $name) {
                $make = fn () => User::search($term)->searchIn(['name'])->using('levenshtein');

                $this->assertContains($name, $make()->get()->pluck('name')->all(), "{$label} {$term}");
                $this->assertSame($make()->get()->count(), $make()->count(), "{$label} {$term} count");
                $this->assertSame($make()->count(), $make()->paginate(50)->total(), "{$label} {$term} paginate");
            }
            $this->assertContains('john', DB::table('users')->whereFuzzy('name', 'jonh', 'levenshtein')->pluck('name')->all(), "{$label} macro");
        }
    }
}
