<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../TestModels.php';

/**
 * ER-43: MySQL and MariaDB read a backslash as the LIKE escape only while the NO_BACKSLASH_ESCAPES
 * SQL mode is off. The package escapes with ! and sends ESCAPE '!' there, which no SQL mode
 * changes, so a literal %, _, \ or ! finds its row under that mode and under the default modes.
 */
class NoBackslashEscapesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!DbDialect::isMySqlFamily(DB::connection()->getDriverName())) {
            $this->markTestSkipped('NO_BACKSLASH_ESCAPES is a MySQL and MariaDB SQL mode.');
        }

        foreach (['50% off', '500 off', 'snake_case', 'snakeXcase', 'back\\slash', 'backslash', 'wow! deal', 'wow deal'] as $i => $name) {
            DB::table('users')->insert(['name' => $name, 'email' => "nbe{$i}@example.com", 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return array<string, array{bool, string, string, string}> mode on, term, its row, a prefix of that row */
    public static function literals(): array
    {
        $cases = [];

        foreach (['default modes' => false, 'NO_BACKSLASH_ESCAPES' => true] as $mode => $on) {
            $cases["{$mode}: percent"]    = [$on, '50%', '50% off', '50%'];
            $cases["{$mode}: underscore"] = [$on, 'snake_case', 'snake_case', 'snake_'];
            $cases["{$mode}: backslash"]  = [$on, 'back\\slash', 'back\\slash', 'back\\'];
            $cases["{$mode}: bang"]       = [$on, 'wow!', 'wow! deal', 'wow!'];
        }

        return $cases;
    }

    #[DataProvider('literals')]
    public function test_a_literal_character_finds_its_row_on_every_path(bool $noBackslashEscapes, string $term, string $row, string $prefix): void
    {
        // Saved and restored on the server, unprepared: no binding or quoting depends on the mode.
        DB::unprepared('SET @fuzzy_sql_mode = @@SESSION.sql_mode');

        try {
            if ($noBackslashEscapes) {
                DB::unprepared("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'NO_BACKSLASH_ESCAPES')");
                $this->assertStringContainsString('NO_BACKSLASH_ESCAPES', DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode);
            }

            $names = fn ($search) => $search->get()->pluck('name')->all();

            $this->assertSame([$row], $names(User::search($term)->using('simple')), 'simple');
            $this->assertSame([$row], $names(User::search($term)->typoTolerance(0)), 'default algorithm, typoTolerance(0)');
            $this->assertSame($row, $names(User::search($term))[0] ?? null, 'default algorithm ranks it first');
            $this->assertSame([$row], $names(User::search('')->extended('"' . $term . '"')), 'extended()');
            $this->assertSame([$row], DB::table('users')->whereFuzzy('name', $term, 'like')->pluck('name')->all(), 'whereFuzzy macro');
            $this->assertSame([$row], User::search($prefix)->suggestFrom('table')->suggest(), 'suggest() table scan');
        } finally {
            DB::unprepared('SET SESSION sql_mode = @fuzzy_sql_mode');
        }
    }
}
