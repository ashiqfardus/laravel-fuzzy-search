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

/** A MySQL connection that reports a server older than VALUES ROW() (8.0.19). */
class MySql8018Pdo extends \PDO
{
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === \PDO::ATTR_SERVER_VERSION ? '8.0.18' : parent::getAttribute($attribute);
    }
}

/**
 * A long ranking checked by key on MySQL. Its range optimizer plans an IN list within
 * range_optimizer_max_mem_size (8 MB by default), and the memory per id grows with the table's
 * secondary indexes, each of which carries the key: at 24M rows and six of them, 8,400 ids past the
 * line, it planned none (warning 3170) and read the table whole, 8 to 10 s for a common word under
 * SoftDeletes. The tables here have the same six indexes, and every statement a check runs is
 * explained on MySQL and MariaDB with no 3170 allowed; the line does not depend on the table's rows.
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
            // As text: after a server-side prepared statement SHOW WARNINGS returns only 1295 (not
            // supported in the prepared statement protocol), whatever the statement raised.
            $connection = DB::connection();
            $pdo        = $connection->getPdo();
            $emulated   = $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);

            try {
                foreach ($log as $statement) {
                    $explain = $pdo->prepare('explain ' . $statement['query']);
                    $connection->bindValues($explain, $connection->prepareBindings($statement['bindings']));
                    $explain->execute();
                    $explain->fetchAll();
                    $codes = array_map('intval', array_column($pdo->query('show warnings')->fetchAll(\PDO::FETCH_ASSOC), 'Code'));

                    $this->assertNotContains(3170, $codes, 'Planned without the range optimizer: ' . substr($statement['query'], 0, 200));
                }
            } finally {
                $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emulated);
            }
        }

        return $result;
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

    public function test_only_mysql_from_8_0_19_reads_values_rows(): void
    {
        foreach (['8.0.19' => true, '9.6.0' => true, '8.0.36-0ubuntu0.22.04.1' => true, '8.0.18' => false, '5.7.44' => false, '5.5.5-10.11.6-MariaDB' => false, '11.4.2-MariaDB-ubu2404' => false] as $version => $reads) {
            $this->assertSame($reads, \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::readsValuesRows((string) $version), (string) $version);
        }
    }

    /** A list of more than 1,000 ids is a VALUES table on MySQL from 8.0.19 only; a shorter one, and any elsewhere, keeps its list. */
    public function test_a_list_past_1000_ids_is_a_values_table_on_mysql_only(): void
    {
        $sql = fn (int $length) => RankedCandidates::matches(LongListItem::query(), self::ranking($length, false), ['item'], LongListItem::class, [], ['share' => 0.001, 'cut' => false])->toSql();

        $this->assertStringNotContainsString('values', $sql(1000));
        $this->assertStringContainsString('in (1, 100001, 100002', $sql(1000));
        $this->assertSame($this->dbDriver === 'mysql', str_contains($sql(1001), 'in (select `column_0` from (values row(1), row(100001), row(100002)'));

        if ($this->dbDriver === 'mysql') {
            $config = DB::connection()->getConfig();
            DB::connection()->setPdo(new MySql8018Pdo("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']));

            $this->assertStringNotContainsString('values', $sql(1001));
            $this->assertStringContainsString('in (1, 100001, 100002', $sql(1001));
        }
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
