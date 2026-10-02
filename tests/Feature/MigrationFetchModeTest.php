<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * TD-2 (round 12). Laravel's documented way to change the fetch mode is a StatementPrepared
 * listener, and it applies to `php artisan migrate` too. The 2.1 migrations read the catalog (which
 * indexes a table has, the columns of the postings key, SQL Server's collation of term) and read
 * each row as an object, so under an app-wide PDO::FETCH_ASSOC `migrate` stopped at the first read
 * ("Attempt to read property … on array") on an upgrade and on a fresh install, with the app's own
 * later migrations not run either, and `migrate:rollback` failed too. Laravel's migrator and schema
 * builder cast each row, and so do the migrations now.
 */
class MigrationFetchModeTest extends TestCase
{
    private const PATH = __DIR__ . '/../../database/migrations';

    /** The number of the package's migrations from $first on, as `migrate:rollback --step` counts them. */
    private function migrationsFrom(string $first): int
    {
        return count(array_filter(glob(self::PATH . '/*.php'), fn (string $file) => basename($file, '.php') >= $first));
    }

    /** @return list<string> the package's migrations the repository records */
    private function recorded(): array
    {
        return DB::table('migrations')->orderBy('migration')->pluck('migration')
            ->filter(fn (string $name) => file_exists(self::PATH . "/{$name}.php"))->values()->all();
    }

    /** @return array<string, list<string>> the index names on each package table, read in the default fetch mode */
    private function indexes(): array
    {
        $out = [];
        foreach (['fuzzy_index_terms', 'fuzzy_index_postings', 'fuzzy_index_documents', 'fuzzy_index_meta', SearchAnalytics::table()] as $table) {
            $name = DB::connection()->getTablePrefix() . $table;
            $rows = match ($this->dbDriver) {
                'sqlite' => DB::select("select name from sqlite_master where type = 'index' and tbl_name = ? and name not like 'sqlite_%'", [$name]),
                'pgsql'  => DB::select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?)) and not i.indisprimary', [$name]),
                'sqlsrv' => DB::select('select name from sys.indexes where object_id = object_id(?) and name is not null and is_primary_key = 0', [$name]),
                default  => DB::select("select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ? and index_name <> 'PRIMARY'", [$name]),
            };
            $names = array_map(fn ($row) => strtolower((string) $row->name), $rows);
            sort($names);
            $out[$table] = $names;
        }

        return $out;
    }

    /** Run $steps with every statement fetching arrays, as an app-wide listener makes it. */
    private function underFetchAssoc(\Closure $steps): void
    {
        Event::listen(StatementPrepared::class, fn (StatementPrepared $event) => $event->statement->setFetchMode(\PDO::FETCH_ASSOC));
        try {
            $steps();
        } finally {
            Event::forget(StatementPrepared::class);
        }
    }

    /** @return array<string, string> the artisan arguments for the package's migrations */
    private function path(array $extra = []): array
    {
        return ['--path' => realpath(self::PATH), '--realpath' => true, ...$extra];
    }

    /** The 2.0.1 → 2.1 upgrade: from the 2.0.1 schema, migrate, roll back and migrate again. */
    public function test_the_upgrade_migrates_and_rolls_back_under_an_array_fetch_mode(): void
    {
        $all     = $this->recorded();
        $indexes = $this->indexes();
        $steps   = $this->migrationsFrom('2026_09_17_000001');
        $this->assertSame(0, $this->artisan('migrate:rollback', $this->path(['--step' => $steps]))->run());

        $this->underFetchAssoc(function () use ($steps) {
            $this->assertSame(0, $this->artisan('migrate', $this->path())->run());
            $this->assertSame(0, $this->artisan('migrate:rollback', $this->path(['--step' => $steps]))->run());
            $this->assertSame(0, $this->artisan('migrate', $this->path())->run());
        });

        $this->assertSame($all, $this->recorded());
        $this->assertSame($indexes, $this->indexes());
    }

    /** A fresh install: from no index table at all, migrate, roll back every migration and migrate again. */
    public function test_a_fresh_install_migrates_and_rolls_back_under_an_array_fetch_mode(): void
    {
        $all     = $this->recorded();
        $indexes = $this->indexes();
        $steps   = $this->migrationsFrom('0');
        $this->assertSame(0, $this->artisan('migrate:rollback', $this->path(['--step' => $steps]))->run());
        $this->assertSame([], $this->recorded());

        $this->underFetchAssoc(function () use ($steps) {
            $this->assertSame(0, $this->artisan('migrate', $this->path())->run());
            $this->assertSame(0, $this->artisan('migrate:rollback', $this->path(['--step' => $steps]))->run());
            $this->assertSame(0, $this->artisan('migrate', $this->path())->run());
        });

        $this->assertSame($all, $this->recorded());
        $this->assertSame($indexes, $this->indexes());
    }
}
