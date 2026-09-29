<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/** The users table under Scout's builder, SoftDeletes included, with the package's index behind it. */
class SmallRankingScoutUser extends \Illuminate\Database\Eloquent\Model
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
 * H2 (round 9), ruling D10. A constrained search in rank order (a where(), a tenant scope, SoftDeletes,
 * a Scout where() or soft_delete) read the matches its constraint accepts through the postings
 * subquery, which no database can drive from the postings: it compares them with a cast of the key,
 * so the model's table was read whole for every search, 266 ms at 200k rows on MySQL for a term with
 * 5 matches. A ranking of at most max(candidate_chunk, max_candidates) ids is now checked by key, a
 * chunk at a time, bounded by the ranking; only a longer one is read through the subquery, in one
 * query however many rows match.
 */
class ConstrainedSmallRankingTest extends TestCase
{
    private const TENANT = '%@tenant-a.test';

    protected function setUp(): void
    {
        parent::setUp();

        // 30 zebras, every third tenant-a's, and the two Does.
        foreach (range(0, 29) as $i) {
            DB::table('users')->insert(['name' => sprintf('Zebra %02d', $i), 'email' => "z{$i}@tenant-" . ($i % 3 === 0 ? 'a' : 'b') . '.test']);
        }
        DB::table('users')->where('name', 'Zebra 03')->update(['deleted_at' => now()]);

        foreach ([User::class, SoftDeletedUser::class, SmallRankingScoutUser::class] as $class) {
            app(IndexManager::class)->indexBatch($class::withoutGlobalScopes()->get());
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.soft_delete' => true]);
    }

    /** @return array<string, array{\Closure(string): mixed, mixed}> each constrained index read of $term, and what it returns for "zebra" */
    private static function reads(): array
    {
        $tenant  = fn (string $term) => User::search($term)->typoTolerance(0)->useInvertedIndex()->where('email', 'like', self::TENANT);
        $trashed = fn (string $term) => SoftDeletedUser::search($term)->typoTolerance(0)->useInvertedIndex()->where('name', 'like', 'Zebra%');
        $scout   = fn (string $term) => new \Laravel\Scout\Builder(new SmallRankingScoutUser, $term, null, true); // soft_delete, as Scout's search() passes it
        $names   = fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all();
        $tenantA = ['Zebra 00', 'Zebra 03', 'Zebra 06', 'Zebra 09', 'Zebra 12', 'Zebra 15', 'Zebra 18', 'Zebra 21', 'Zebra 24', 'Zebra 27'];

        return [
            'where() get'            => [fn ($term) => $names($tenant($term)->get()), $tenantA],
            'where() first'          => [fn ($term) => $tenant($term)->first()?->email, 'z0@tenant-a.test'],
            'where() paginate'       => [fn ($term) => [($page = $tenant($term)->paginate(4, 'page', 3))->total(), $names($page->items())], [10, ['Zebra 24', 'Zebra 27']]],
            'where() simplePaginate' => [fn ($term) => $names($tenant($term)->simplePaginate(4, 'page', 3)->items()), ['Zebra 24', 'Zebra 27']],
            'where() count'          => [fn ($term) => $tenant($term)->count(), 10],
            'SoftDeletes get'        => [fn ($term) => count($trashed($term)->take(50)->get()), 29],
            'SoftDeletes count'      => [fn ($term) => $trashed($term)->count(), 29],
            'Scout where() get'      => [fn ($term) => $names($scout($term)->where('email', 'z0@tenant-a.test')->get()), ['Zebra 00']],
            'Scout soft_delete get'  => [fn ($term) => count($scout($term)->take(50)->get()), 29],
            'Scout paginate'         => [fn ($term) => $scout($term)->paginate(10)->total(), 29],
        ];
    }

    /** @return array{mixed, string[]} what $read returns, and the statements it runs against the users table */
    private function traced(\Closure $read, string $term): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $read($term);
        $sql    = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn ($sql) => preg_match('/\busers\b/', $sql) === 1));
        DB::disableQueryLog();

        return [$result, $sql];
    }

    public function test_a_small_ranking_is_checked_by_key_and_never_reads_the_postings_against_the_table(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $postings = [];

        foreach (self::reads() as $label => [$read, $expected]) {
            [$result, $sql] = $this->traced($read, 'zebra');

            $this->assertSame($expected, $result, $label);
            $this->assertNotSame([], $sql, $label);
            $postings[$label] = array_values(array_filter($sql, fn ($s) => str_contains($s, 'fuzzy_index_postings')));
        }

        $this->assertSame(array_fill_keys(array_keys(self::reads()), []), $postings, 'the users table is read by key, bounded by the ranking');
    }

    /** Past max(candidate_chunk, max_candidates) ids the postings subquery reads the matches in one query. */
    public function test_a_longer_ranking_is_read_through_the_postings_in_one_query(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['fuzzy-search.bm25.candidate_chunk' => 5, 'fuzzy-search.max_candidates' => 10]);

        foreach (self::reads() as $label => [$read, $expected]) {
            [$result, $sql] = $this->traced($read, 'zebra');

            $this->assertSame($expected, $result, $label);
            $this->assertCount(1, array_filter($sql, fn ($s) => str_contains($s, 'fuzzy_index_postings')), "{$label}: " . implode("\n", $sql));
        }
    }
}
