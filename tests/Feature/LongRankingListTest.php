<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LongListItem extends Model
{
    use SoftDeletes;

    protected $table   = 'long_list_items';
    protected $guarded = [];
}

class LongListCode extends Model
{
    use SoftDeletes;

    protected $table      = 'long_list_codes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $guarded    = [];
}

/** -4e's soft-delete table: deleted_at indexed beside five other indexes, tenant_id among them. */
class LongListSoft extends Model
{
    use SoftDeletes;

    protected $table   = 'long_list_soft';
    protected $guarded = [];
}

/** -4e's soft-delete table with a searchable name, its ranking cut at bm25.max_postings_per_term in the test. */
class LongListCapped extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable, SoftDeletes;

    protected $table   = 'long_list_capped';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 1]];
}

/** A MySQL connection that reports a server older than the VALUES table's floor (8.0.21). */
class MySql8020Pdo extends \PDO
{
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === \PDO::ATTR_SERVER_VERSION ? '8.0.20' : parent::getAttribute($attribute);
    }
}

/**
 * A long ranking checked by key on MySQL. Its range optimizer plans an IN list within
 * range_optimizer_max_mem_size (8 MB by default), and the memory per id grows with the table's
 * secondary indexes, each of which carries the key: at 24M rows and six of them, 8,400 ids past the
 * line, it planned none (warning 3170) and read the table whole, 8 to 11 s for a common word under
 * SoftDeletes. The tables here have the same six indexes, and every statement a check runs is
 * explained on MySQL and MariaDB with no 3170 allowed; the line does not depend on the table's rows.
 * The VALUES table that replaces a long list on MySQL is read with two optimizer switches off
 * (RankedCandidates::planned()), and the plan it runs is checked, as is the session's switch after.
 */
class LongRankingListTest extends TestCase
{
    private const ROWS = 300;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver === 'sqlsrv') {
            $this->markTestSkipped('SQL Server reads a long ranking through the postings (RankedCandidates::accepted(), matches()), which these synthetic rankings have none of.');
        }

        foreach (['long_list_items' => fn ($table) => $table->id(), 'long_list_codes' => fn ($table) => $table->string('code', 36)->primary()] as $name => $key) {
            Schema::dropIfExists($name);
            Schema::create($name, function ($table) use ($key) {
                $key($table);
                $table->string('name');
                $table->string('sku', 32)->unique();
                $table->unsignedInteger('category_id')->index();
                $table->unsignedInteger('tenant_id')->index();
                $table->unsignedInteger('price')->index();
                $table->timestamp('created_at')->nullable()->index();
                $table->timestamp('deleted_at')->nullable();
            });
        }

        foreach (array_chunk(range(1, self::ROWS), 100) as $chunk) {
            $rows = array_map(fn (int $i) => [
                'name'        => "Item {$i}",
                'sku'         => sprintf('SKU-%05d', $i),
                'category_id' => $i % 7,
                'tenant_id'   => $i % 3,
                'price'       => ($i * 37) % 101,
                'created_at'  => now(),
                'deleted_at'  => $i % 9 === 0 ? now() : null,
            ], $chunk);

            DB::table('long_list_items')->insert($rows);
            DB::table('long_list_codes')->insert(array_map(fn (array $row, int $i) => ['code' => self::code($i)] + $row, $rows, $chunk));
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('long_list_items');
        Schema::dropIfExists('long_list_codes');
        Schema::dropIfExists('long_list_soft');
        Schema::dropIfExists('long_list_capped');

        parent::tearDown();
    }

    private static function code(int $i): string
    {
        return sprintf('c-%05d', $i);
    }

    /**
     * $length ids, best first: the table's ROWS ids spread through it, the rest ids no row has.
     *
     * @return array<int|string, float>
     */
    private static function ranking(int $length, bool $strings): array
    {
        $ranked = [];
        $step   = intdiv($length, self::ROWS);

        for ($i = 0; $i < $length; $i++) {
            $row = $i % $step === 0 ? intdiv($i, $step) + 1 : null;
            $id  = $row !== null && $row <= self::ROWS ? $row : 100000 + $i;

            $ranked[$strings ? self::code($id) : $id] = (float) ($length - $i);
        }

        return $ranked;
    }

    /**
     * The ids of $ranked the table holds and SoftDeletes shows, in rank order.
     *
     * @return array<int|string>
     */
    private static function shown(array $ranked, bool $strings): array
    {
        $shown = array_map(fn (int $i) => $strings ? self::code($i) : $i, array_filter(range(1, self::ROWS), fn (int $i) => $i % 9 !== 0));

        return array_values(array_intersect(array_keys($ranked), $shown));
    }

    /** Runs $read and fails on any statement it ran that MySQL plans without its range optimizer (warning 3170). */
    private function assertPlannedInRange(\Closure $read): mixed
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $read();
        } finally {
            $log = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $this->assertNotEmpty($log);

        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            foreach ($log as $statement) {
                [, $codes] = $this->explained('explain', $statement['query'], $statement['bindings']);

                $this->assertNotContains(3170, $codes, 'Planned without the range optimizer: ' . substr($statement['query'], 0, 200));
            }
        }

        return $result;
    }

    /**
     * $explain (explain, explain format=tree) of $sql, sent as text, with the warnings it raised:
     * after a server-side prepared statement SHOW WARNINGS returns only 1295 (not supported in the
     * prepared statement protocol), and MySQL does not prepare EXPLAIN FORMAT=TREE.
     *
     * @return array{0: string, 1: list<int>} the plan's text and the warnings' codes
     */
    private function explained(string $explain, string $sql, array $bindings): array
    {
        $connection = DB::connection();
        $pdo        = $connection->getPdo();
        $emulated   = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $statement = $pdo->prepare($explain . ' ' . $sql);
            $connection->bindValues($statement, $connection->prepareBindings($bindings));
            $statement->execute();
            $plan = implode("\n", array_map(fn (array $row) => implode(' ', array_map('strval', $row)), $statement->fetchAll(\PDO::FETCH_NUM)));

            return [$plan, array_map('intval', array_column($pdo->query('show warnings')->fetchAll(\PDO::FETCH_ASSOC), 'Code'))];
        } finally {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emulated);
        }
    }

    public function test_accepted_checks_a_long_integer_ranking_without_a_table_scan(): void
    {
        $ranked   = self::ranking(50000, false);
        $accepted = $this->assertPlannedInRange(fn () => RankedCandidates::accepted(LongListItem::query(), $ranked, ['item'], LongListItem::class, []));

        $this->assertSame(self::shown($ranked, false), array_keys($accepted));
        $this->assertSame(array_intersect_key($ranked, $accepted), $accepted);
    }

    public function test_accepted_checks_a_long_string_ranking_without_a_table_scan(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A long string ranking is checked by key on MySQL and MariaDB only; elsewhere through the postings.');
        }

        $ranked   = self::ranking(30000, true);
        $accepted = $this->assertPlannedInRange(fn () => RankedCandidates::accepted(LongListCode::query(), $ranked, ['item'], LongListCode::class, []));

        $this->assertSame(self::shown($ranked, true), array_keys($accepted));
    }

    public function test_only_mysql_from_8_0_21_reads_values_rows(): void
    {
        $versions = [
            '8.0.21' => true, '8.0.21-log' => true, '9.6.0' => true, '8.4.3' => true, '8.0.36-0ubuntu0.22.04.1' => true, '8.0.35-27' => true,
            '8.0.mysql_aurora.3.04.0' => true,
            '8.0.20' => false, '8.0.19' => false, '8.0.19-log' => false, '5.7.44' => false, '5.7.44-log' => false,
            '5.5.5-10.11.6-MariaDB' => false, '11.4.2-MariaDB-ubu2404' => false, '11.4.13-MariaDB-ubu2404' => false,
            '8.0.31-Vitess' => false, '5.7.25-TiDB-v7.5.0' => false, '8.0.11-TiDB-v8.1.0' => false, '' => false,
        ];

        foreach ($versions as $version => $reads) {
            $this->assertSame($reads, \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::readsValuesRows((string) $version), (string) $version);
        }
    }

    /** A list of more than 1,000 ids is a VALUES table on MySQL from 8.0.21 only; a shorter one, and any elsewhere, keeps its list. */
    public function test_a_list_past_1000_ids_is_a_values_table_on_mysql_only(): void
    {
        $sql    = fn (int $length) => RankedCandidates::matches(LongListItem::query(), self::ranking($length, false), ['item'], LongListItem::class, [], ['share' => 0.001, 'cut' => false])->toSql();
        $values = $this->dbDriver === 'mysql' && \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::readsValuesRows((string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION));

        $this->assertStringNotContainsString('values', $sql(1000));
        $this->assertStringContainsString('in (1, 100001, 100002', $sql(1000));
        $this->assertSame($values, str_contains($sql(1001), 'in (select `column_0` from (values row(1), row(100001), row(100002)'));

        if ($values) {
            $config = DB::connection()->getConfig();
            DB::connection()->setPdo(new MySql8020Pdo("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']));

            $this->assertStringNotContainsString('values', $sql(1001));
            $this->assertStringContainsString('in (1, 100001, 100002', $sql(1001));
        }
    }

    /** Whether this connection reads a long list as a VALUES table (RankedCandidates::whereKeyIn()). */
    private function readsValues(): bool
    {
        return $this->dbDriver === 'mysql'
            && \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::readsValuesRows((string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION));
    }

    /**
     * Runs $read and returns, beside its result, the plan (EXPLAIN FORMAT=TREE) and the session's
     * optimizer_switch of each statement it ran with a VALUES table, read right after it ran, in its
     * session as it was then.
     *
     * @return array{0: mixed, 1: list<array{plan: string, switch: string}>}
     */
    private function whileReading(\Closure $read, string $marker = '(values row('): array
    {
        $seen    = [];
        $reading = false;
        $active  = true; // a listener stays registered: it reads only while $read runs

        DB::listen(function ($query) use (&$seen, &$reading, &$active, $marker) {
            if (!$active || $reading || !str_contains($query->sql, $marker)) {
                return;
            }

            $reading = true;
            $seen[]  = [
                'plan'   => $this->explained('explain format=tree', $query->sql, $query->bindings)[0],
                'switch' => (string) DB::scalar('select @@session.optimizer_switch'),
            ];
            $reading = false;
        });

        try {
            return [$read(), $seen];
        } finally {
            $active = false;
        }
    }

    /**
     * With a selective constraint beside an indexed soft-delete column, MySQL intersected the two
     * indexes over the VALUES table and read the soft-delete index whole (0.5 s at 4M rows, where the
     * tenant's index took 7 ms): the read is planned with index_merge_intersection off.
     */
    public function test_a_constrained_long_ranking_reads_no_soft_delete_index_whole(): void
    {
        if (!$this->readsValues()) {
            $this->markTestSkipped('The plans are MySQL\'s (8.0.21 or later), read with EXPLAIN FORMAT=TREE.');
        }

        Schema::dropIfExists('long_list_soft');
        Schema::create('long_list_soft', function ($table) {
            $table->id();
            $table->unsignedInteger('tenant_id')->index();
            $table->unsignedInteger('price')->index();
            $table->unsignedInteger('cat')->index();
            $table->unsignedInteger('brand')->index();
            $table->unsignedInteger('stock')->index();
            $table->timestamp('deleted_at')->nullable()->index();
        });

        foreach (array_chunk(range(1, 20000), 1000) as $chunk) {
            DB::table('long_list_soft')->insert(array_map(fn (int $j) => [
                'tenant_id' => $j % 997, 'price' => ($j * 7919) % 100000, 'cat' => $j % 50, 'brand' => $j % 300, 'stock' => $j % 1000,
            ], $chunk));
        }

        DB::statement('ANALYZE TABLE long_list_soft');

        $ids    = range(1, 20000, 13); // 1,539 ids, spread through the table
        $ranked = array_combine($ids, array_map(fn (int $i) => (float) (20000 - $i), $ids));
        $tenant = array_values(array_filter($ids, fn (int $id) => $id % 997 === 7));
        $base   = fn () => LongListSoft::query()->where('tenant_id', 7);
        $query  = RankedCandidates::matches($base(), $ranked, ['item'], LongListSoft::class, [], ['share' => 0.001, 'cut' => false]);

        [$total, $counted]   = $this->whileReading(fn () => RankedCandidates::countModels($query));
        [$page, $paged]      = $this->whileReading(fn () => RankedCandidates::orderedKeys((clone $query)->orderBy('price')->orderBy('id')->toBase(), 'long_list_soft.id', 0, 20));
        [$accepted, $listed] = $this->whileReading(fn () => array_keys(RankedCandidates::accepted($base(), $ranked, ['item'], LongListSoft::class, [])));

        foreach ([...$counted, ...$paged, ...$listed] as $statement) {
            $this->assertStringNotContainsString('Intersect rows', $statement['plan']);
            $this->assertStringNotContainsString('long_list_soft_deleted_at_index', $statement['plan']);
        }

        $prices = LongListSoft::query()->whereIn('id', $tenant)->pluck('price', 'id')->all();
        usort($tenant, fn (int $a, int $b) => [$prices[$a], $a] <=> [$prices[$b], $b]);

        $this->assertCount(3, [...$counted, ...$paged, ...$listed]);
        $this->assertSame(count($tenant), $total);
        $this->assertEqualsCanonicalizing($tenant, $accepted);
        $this->assertSame(array_slice($tenant, 0, 20), $page);
    }

    /**
     * Over a capped ranking the matches are restricted through the postings subquery
     * (RankedCandidates::whereMatches()), and beside a selective constraint and an indexed soft-delete
     * column MySQL intersected the two indexes there too, reading the soft-delete index whole (a
     * count at 24M rows: 2.4 s, 60 ms by the tenant's index), or walked the order column's index for
     * a page (0.57 s, 39 ms): the count and the page are read with both switches off.
     */
    public function test_a_constrained_capped_ranking_reads_no_soft_delete_index_whole(): void
    {
        if (!$this->readsValues()) {
            $this->markTestSkipped('The plans are MySQL\'s (8.0.21 or later), read with EXPLAIN FORMAT=TREE.');
        }

        Schema::dropIfExists('long_list_capped');
        Schema::create('long_list_capped', function ($table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('tenant_id')->index();
            $table->unsignedInteger('price')->index();
            $table->unsignedInteger('cat')->index();
            $table->unsignedInteger('brand')->index();
            $table->unsignedInteger('stock')->index();
            $table->timestamp('deleted_at')->nullable()->index();
        });

        foreach (array_chunk(range(1, 20000), 1000) as $chunk) {
            DB::table('long_list_capped')->insert(array_map(fn (int $j) => [
                'name' => "Quokka {$j}", 'tenant_id' => $j % 997, 'price' => ($j * 7919) % 100000, 'cat' => $j % 50, 'brand' => $j % 300, 'stock' => $j % 1000,
            ], $chunk));
        }

        LongListCapped::query()->chunkById(2000, fn ($items) => app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)->indexBatch($items));
        DB::statement('ANALYZE TABLE long_list_capped, fuzzy_index_postings, fuzzy_index_terms, fuzzy_index_documents');

        $tenant = array_values(array_filter(range(1, 20000), fn (int $j) => $j % 997 === 7));
        $query  = RankedCandidates::matches(LongListCapped::query()->where('tenant_id', 7), [1 => 1.0], ['quokka'], LongListCapped::class, [], ['share' => 1.0, 'cut' => true]);

        [$total, $counted] = $this->whileReading(fn () => RankedCandidates::countModels($query), 'fuzzy_index_postings');
        [$page, $paged]    = $this->whileReading(fn () => RankedCandidates::orderedKeys((clone $query)->orderBy('price')->orderBy('id')->toBase(), 'long_list_capped.id', 0, 20), 'fuzzy_index_postings');

        foreach ([...$counted, ...$paged] as $statement) {
            $this->assertStringNotContainsString('Intersect rows', $statement['plan']);
            $this->assertStringNotContainsString('long_list_capped_deleted_at_index', $statement['plan']);
            $this->assertStringContainsString('index_merge_intersection=off', $statement['switch']);
            $this->assertStringContainsString('prefer_ordering_index=off', $statement['switch']);
        }

        $prices = LongListCapped::query()->whereIn('id', $tenant)->pluck('price', 'id')->all();
        usort($tenant, fn (int $a, int $b) => [$prices[$a], $a] <=> [$prices[$b], $b]);

        $this->assertCount(2, [...$counted, ...$paged]);
        $this->assertSame(count($tenant), $total);
        $this->assertSame(array_slice($tenant, 0, 20), $page);
    }

    /** planned() puts the session's optimizer_switch back as it was, after a read and after a read that throws, and has it set while the read runs. */
    public function test_the_session_optimizer_switch_is_put_back_after_a_long_ranking_read(): void
    {
        if (!$this->readsValues()) {
            $this->markTestSkipped('The switches are MySQL\'s (8.0.21 or later).');
        }

        $switch = fn () => (string) DB::scalar('select @@session.optimizer_switch');
        $before = $switch();
        $query  = RankedCandidates::matches(LongListItem::query(), self::ranking(50000, false), ['item'], LongListItem::class, [], ['share' => 0.001, 'cut' => false]);

        [, $counted] = $this->whileReading(fn () => RankedCandidates::countModels($query));
        [, $listed]  = $this->whileReading(fn () => RankedCandidates::accepted(LongListItem::query(), self::ranking(50000, false), ['item'], LongListItem::class, []));
        [, $ordered] = $this->whileReading(fn () => RankedCandidates::orderedKeys((clone $query)->orderBy('price')->orderBy('id')->toBase(), 'long_list_items.id', 0, 20));

        foreach ([...$counted, ...$listed, ...$ordered] as $statement) {
            $this->assertStringContainsString('index_merge_intersection=off', $statement['switch']);
            $this->assertStringContainsString('prefer_ordering_index=off', $statement['switch']);
        }

        $this->assertCount(3, [...$counted, ...$listed, ...$ordered]);
        $this->assertSame($before, $switch());

        try {
            RankedCandidates::accepted(LongListItem::query()->where('no_such_column', 1), self::ranking(50000, false), ['item'], LongListItem::class, []);
            $this->fail('The read should have thrown.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('no_such_column', $e->getMessage());
        }

        $this->assertSame($before, $switch());
    }

    /**
     * A session lost inside the window: the read runs on the new one, which never had the switches,
     * and nothing is put back, neither on it nor on the old one, which the server has closed.
     */
    public function test_a_reconnect_inside_a_long_ranking_read_keeps_its_rows(): void
    {
        if (!$this->readsValues()) {
            $this->markTestSkipped('The switches are MySQL\'s (8.0.21 or later).');
        }

        $default     = (string) DB::scalar('select @@session.optimizer_switch');
        $ranked      = self::ranking(50000, false);
        $before      = DB::connection()->getPdo();
        $config      = DB::connection()->getConfig();
        $reconnected = false;

        DB::connection()->beforeExecuting(function (string $sql) use (&$reconnected, $config) {
            if (!$reconnected && str_contains($sql, '(values row(')) {
                $reconnected = true;
                $old         = (int) DB::scalar('select connection_id()');
                DB::reconnect();
                (new \PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']))->exec("kill {$old}");
            }
        });

        $accepted = RankedCandidates::accepted(LongListItem::query(), $ranked, ['item'], LongListItem::class, []);

        $this->assertTrue($reconnected);
        $this->assertNotSame($before, DB::connection()->getPdo());
        $this->assertSame(self::shown($ranked, false), array_keys($accepted));
        $this->assertSame($default, (string) DB::scalar('select @@session.optimizer_switch'));
    }

    /** A session the server closed before the window: reading the switch reconnects, and the read runs and puts it back on the new one. */
    public function test_a_session_lost_before_a_long_ranking_read_is_reconnected(): void
    {
        if (!$this->readsValues()) {
            $this->markTestSkipped('The switches are MySQL\'s (8.0.21 or later).');
        }

        $default = (string) DB::scalar('select @@session.optimizer_switch');
        $ranked  = self::ranking(50000, false);
        $session = (int) DB::scalar('select connection_id()');
        $config  = DB::connection()->getConfig();

        (new \PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']))->exec("kill {$session}");

        $accepted = RankedCandidates::accepted(LongListItem::query(), $ranked, ['item'], LongListItem::class, []);

        $this->assertSame(self::shown($ranked, false), array_keys($accepted));
        $this->assertNotSame($session, (int) DB::scalar('select connection_id()'));
        $this->assertSame($default, (string) DB::scalar('select @@session.optimizer_switch'));
    }

    public function test_matches_lists_a_long_integer_ranking_without_a_table_scan(): void
    {
        $this->assertMatchesListed(LongListItem::class, false, 50000);
    }

    public function test_matches_lists_a_long_string_ranking_without_a_table_scan(): void
    {
        $this->assertMatchesListed(LongListCode::class, true, 30000);
    }

    /** matches()' list of a $length ranking, as an ordered page and its total read it. */
    private function assertMatchesListed(string $class, bool $strings, int $length): void
    {
        $ranked = self::ranking($length, $strings);
        $shown  = self::shown($ranked, $strings);
        $key    = (new $class)->getKeyName();
        $query  = RankedCandidates::matches($class::query(), $ranked, ['item'], $class, [], ['share' => 0.001, 'cut' => false]);

        $total = $this->assertPlannedInRange(fn () => RankedCandidates::countModels($query));
        $page  = $this->assertPlannedInRange(fn () => (clone $query)->orderBy('price')->orderBy($key)->limit(20)->pluck($key)->all());

        $prices = $class::query()->pluck('price', $key)->all();
        usort($shown, fn ($a, $b) => [$prices[$a], $a] <=> [$prices[$b], $b]);

        $this->assertSame(count($shown), $total);
        $this->assertSame(array_slice($shown, 0, 20), $page);
    }
}
