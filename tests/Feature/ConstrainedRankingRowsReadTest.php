<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/** The users table under Scout's builder, with SoftDeletes. */
class RowsReadScoutUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable, \Illuminate\Database\Eloquent\SoftDeletes {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * H2 (round 9), ruling D10, measured where it showed: MySQL. A constrained search for a rare term
 * read the whole model table, one index probe of the postings per row under the SEMIJOIN(FIRSTMATCH)
 * hint, whatever the ranking held: 266 ms at 200k rows for a term with 5 matches. The rows the
 * storage engine reads (the Handler_read_* counters) are now bounded by the ranking, not the table.
 */
class ConstrainedRankingRowsReadTest extends TestCase
{
    private const ROWS = 3000;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The Handler_read_* counters are MySQL\'s and MariaDB\'s; the CI MySQL and MariaDB jobs run this. ConstrainedSmallRankingTest checks the read\'s shape on every database.');
        }

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        // ROWS items, 5 of them quokkas.
        foreach (array_chunk(range(1, self::ROWS), 500) as $chunk) {
            DB::table('users')->insert(array_map(fn ($i) => [
                'name'  => ($i % (self::ROWS / 5) === 0 ? 'Quokka ' : '') . sprintf('Item %04d', $i),
                'email' => "i{$i}@tenant-" . ($i % 2) . '.test',
            ], $chunk));
        }

        foreach ([User::class, SoftDeletedUser::class, RowsReadScoutUser::class] as $class) {
            $class::query()->chunkById(1000, fn ($rows) => app(IndexManager::class)->indexBatch($rows));
        }

        DB::statement('ANALYZE TABLE users, fuzzy_index_postings, fuzzy_index_terms, fuzzy_index_documents');
        config(['scout.driver' => 'fuzzy-search', 'scout.soft_delete' => true]);
    }

    /** The rows the server's storage engines have read on this session so far. */
    private function handlerReads(): int
    {
        return array_sum(array_map(fn ($row) => (int) ((array) $row)['Value'], DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'")));
    }

    /** The rows $call reads, less what reading the counters costs. */
    private function rowsRead(\Closure $call): int
    {
        $idle   = -$this->handlerReads() + $this->handlerReads();
        $before = $this->handlerReads();
        $call();

        return $this->handlerReads() - $before - $idle;
    }

    public function test_a_rare_term_reads_rows_bounded_by_its_ranking_not_the_table(): void
    {
        $scout = fn () => new \Laravel\Scout\Builder(new RowsReadScoutUser, 'quokka', null, true); // soft_delete, as Scout's search() passes it
        $reads = [
            'SoftDeletes get'       => fn () => $this->assertCount(5, SoftDeletedUser::search('quokka')->typoTolerance(0)->useInvertedIndex()->get()),
            'SoftDeletes paginate'  => fn () => $this->assertSame(5, SoftDeletedUser::search('quokka')->typoTolerance(0)->useInvertedIndex()->paginate(15)->total()),
            'SoftDeletes count'     => fn () => $this->assertSame(5, SoftDeletedUser::search('quokka')->typoTolerance(0)->useInvertedIndex()->count()),
            'where() get'           => fn () => $this->assertCount(5, User::search('quokka')->typoTolerance(0)->useInvertedIndex()->where('email', 'like', '%.test')->get()),
            'Scout soft_delete get' => fn () => $this->assertCount(5, $scout()->get()),
            'Scout paginate'        => fn () => $this->assertSame(5, $scout()->paginate(15)->total()),
            'Scout query()'         => fn () => $this->assertCount(5, $scout()->query(fn ($query) => $query->where('email', 'like', '%.test'))->get()),
        ];

        foreach ($reads as $read) {
            $read(); // warms the once-per-process reads, such as the model_id collation
        }

        $rows = array_map(fn (\Closure $read) => $this->rowsRead($read), $reads);

        // A ranking of 5: the postings, the dictionary and the 5 rows, a few reads each. The table has 3,007 rows.
        $this->assertSame(array_fill_keys(array_keys($reads), true), array_map(fn (int $read) => $read < 200, $rows), json_encode($rows));
    }
}
