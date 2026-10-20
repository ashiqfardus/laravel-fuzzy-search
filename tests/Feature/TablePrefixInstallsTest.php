<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ER-216. The index names the migrations give themselves are led by the connection's table prefix
 * under prefix_indexes (Laravel's own rule for the names it makes), so installs with different prefixes
 * share one schema: PostgreSQL, SQLite and SQL Server need an index or constraint name to be unique
 * there, and a second install stopped at `relation "postings_model_idx" already exists`. An install made
 * before, whose names carry no prefix, migrates up and down all the same.
 */
class TablePrefixInstallsTest extends TestCase
{
    private const PATH = __DIR__ . '/../../database/migrations';

    /** The package's names that a later migration drops or checks by name. */
    private const NAMED = [
        'postings_unique_idx', 'postings_term_model_idx', 'postings_model_idx', 'fuzzy_index_terms_term_length_index',
        'search_logs_term_idx', 'search_logs_created_idx', 'search_logs_results_idx', 'search_logs_day_idx',
    ];

    /** @var list<string> */
    private array $connections = [];
    private ?string $file = null;
    private string $default;

    protected function setUp(): void
    {
        parent::setUp();
        $this->default = DB::getDefaultConnection();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->default);
        foreach (array_reverse($this->connections) as $connection) {
            try {
                Artisan::call('migrate:reset', ['--database' => $connection, '--path' => realpath(self::PATH), '--realpath' => true, '--force' => true]);
                Schema::connection($connection)->dropIfExists('users');
                Schema::connection($connection)->dropIfExists('migrations');
            } catch (\Throwable) {
                // the test failed part way; the next run's leftover cleanup takes the rest
            }
            DB::purge($connection);
        }
        if ($this->file !== null) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    /** A connection to the test database with $prefix and prefix_indexes on (Laravel's default for mysql, pgsql, sqlsrv). */
    private function connection(string $prefix): string
    {
        $config = config('database.connections.' . $this->default);
        if ($config['driver'] === 'sqlite') { // :memory: is a database per connection: share a file
            $this->file ??= sys_get_temp_dir() . '/fuzzy_prefix_' . getmypid() . '.sqlite';
            touch($this->file);
            $config['database'] = $this->file;
        }
        $name = 'prefixed_' . rtrim($prefix, '_');
        config(["database.connections.{$name}" => array_merge($config, ['prefix' => $prefix, 'prefix_indexes' => true])]);
        $this->connections[] = $name;

        return $name;
    }

    private function artisanOn(string $connection, string $command, array $options = []): void
    {
        $this->assertSame(0, Artisan::call($command, ['--database' => $connection, '--path' => realpath(self::PATH), '--realpath' => true, '--force' => true] + $options), "{$command} on {$connection}: " . Artisan::output());
    }

    /** Run $work with $connection as the default connection, as an app with that prefix runs. */
    private function on(string $connection, \Closure $work): mixed
    {
        DB::setDefaultConnection($connection);
        try {
            return $work();
        } finally {
            DB::setDefaultConnection($this->default);
        }
    }

    /** Users with an index on $connection, and what its searches serve. */
    private function populate(string $connection, array $names): void
    {
        $this->on($connection, function () use ($names) {
            Schema::dropIfExists('users');
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->text('bio')->nullable();
                $table->timestamps();
            });
            foreach ($names as $name) {
                DB::table('users')->insert(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test']);
            }
            $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => User::class]));
        });
    }

    private function serves(string $connection): array
    {
        return $this->on($connection, fn () => [
            User::search('john')->useInvertedIndex()->get()->pluck('name')->sort()->values()->all(),
            User::search('jonh')->useInvertedIndex()->didYouMean(),
            (int) DB::table('fuzzy_index_documents')->count(),
        ]);
    }

    /** [table => [index names]] of the package's tables on $connection, from the catalog. */
    private function indexes(string $connection): array
    {
        $db     = DB::connection($connection);
        $prefix = $db->getTablePrefix();
        $out    = [];
        foreach (['fuzzy_index_postings', 'fuzzy_index_terms', 'fuzzy_search_logs'] as $table) {
            $rows = match ($db->getDriverName()) {
                'sqlite' => $db->select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$prefix . $table]),
                'pgsql'  => $db->select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$prefix . $table]),
                'sqlsrv' => $db->select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$prefix . $table]),
                default  => $db->select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$prefix . $table]),
            };
            $out[$table] = array_map(fn ($row) => (string) ((object) $row)->name, $rows);
        }

        return $out;
    }

    /** Rename $connection's package indexes in NAMED to the names as written, as an install made before ER-216 has them. */
    private function unprefixNames(string $connection): array
    {
        $db       = DB::connection($connection);
        $prefix   = $db->getTablePrefix();
        $renamed  = [];
        foreach ($this->indexes($connection) as $table => $names) {
            foreach ($names as $name) {
                $bare = substr($name, strlen($prefix));
                if (!str_starts_with($name, $prefix) || !in_array($bare, self::NAMED, true)) {
                    continue;
                }
                match ($db->getDriverName()) {
                    'sqlite' => (function () use ($db, $name, $bare) {
                        $sql = (string) ((object) $db->selectOne("select sql from sqlite_master where type = 'index' and name = ?", [$name]))->sql;
                        $db->statement('drop index "' . $name . '"');
                        $db->statement(preg_replace('/"' . preg_quote($name, '/') . '"/', '"' . $bare . '"', $sql, 1));
                    })(),
                    'pgsql'  => $db->statement('alter index "' . $name . '" rename to "' . $bare . '"'),
                    'sqlsrv' => $db->statement("exec sp_rename N'" . $prefix . $table . '.' . $name . "', N'" . $bare . "', N'INDEX'"),
                    default  => $db->statement('alter table `' . $prefix . $table . '` rename index `' . $name . '` to `' . $bare . '`'),
                };
                $renamed[] = $bare;
            }
        }

        return $renamed;
    }

    public function test_two_installs_with_different_prefixes_share_one_schema(): void
    {
        $a = $this->connection('zfa_');
        $b = $this->connection('zfb_');

        $this->artisanOn($a, 'migrate');
        $this->populate($a, ['John Doe', 'Johnny Bravo', 'Jane Roe']);
        $served = $this->serves($a);
        $this->assertSame(['John Doe', 'Johnny Bravo'], $served[0]);

        $this->artisanOn($b, 'migrate'); // stopped at "postings_model_idx already exists" on PostgreSQL and SQLite
        $this->populate($b, ['John Smith', 'Bob Johnson']);
        $this->assertSame(['John Smith'], $this->serves($b)[0]); // 'johnson' is three edits from 'john'
        $this->assertSame($served, $this->serves($a), 'install zfa_ after zfb_ was installed');

        foreach ($this->indexes($b) as $table => $names) {
            foreach (array_intersect(array_map(fn ($n) => 'zfb_' . $n, self::NAMED), $names) as $name) {
                $this->assertStringStartsWith('zfb_', $name);
            }
        }
        $this->assertContains('zfb_postings_model_idx', $this->indexes($b)['fuzzy_index_postings']);

        $this->artisanOn($b, 'migrate:reset');
        $this->assertSame($served, $this->serves($a), 'install zfa_ after zfb_ was reset');
    }

    public function test_an_install_whose_names_carry_no_prefix_migrates_up_and_down(): void
    {
        // The unprefixed install the suite migrates holds the names as written, which PostgreSQL and
        // SQLite let only one table of the schema have.
        Artisan::call('migrate:reset', ['--path' => realpath(self::PATH), '--realpath' => true, '--force' => true]);
        $c = $this->connection('zfc_');

        // As an install made before ER-216 leaves it after 2026_09_30: the later migrations rolled
        // back, the named indexes renamed to the names as written. Then the upgrade.
        $this->artisanOn($c, 'migrate');
        $this->artisanOn($c, 'migrate:rollback', ['--step' => 5]); // 2026_10_01 … 2026_10_20
        $this->assertNotSame([], $this->unprefixNames($c));
        $this->artisanOn($c, 'migrate');
        $after = $this->indexes($c);
        $this->assertNotContains('postings_term_model_idx', $after['fuzzy_index_postings'], '2026_10_01 drops the name as written');
        $this->assertNotContains('fuzzy_index_terms_term_length_index', $after['fuzzy_index_terms']);
        $this->assertContains('zfc_postings_model_idx', $after['fuzzy_index_postings']);
        $this->populate($c, ['John Doe', 'Jane Roe']);
        $this->assertSame(['John Doe'], $this->serves($c)[0]);

        // And a rollback from the names as written.
        $this->artisanOn($c, 'migrate:rollback', ['--step' => 5]);
        $this->assertNotSame([], $this->unprefixNames($c));
        $this->artisanOn($c, 'migrate:reset');
        $this->assertFalse(Schema::connection($c)->hasTable('fuzzy_index_postings'));
    }
}
