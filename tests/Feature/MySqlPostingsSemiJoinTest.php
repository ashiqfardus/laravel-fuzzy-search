<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SemiJoinArticle extends Model
{
    use Searchable;

    protected $table   = 'semijoin_articles';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/**
 * MySQL, once model_id is utf8mb4_bin (round 8, M8). With an index on the order column, MySQL ran
 * the postings subquery of an ordered index search as a hash semi-join it could not
 * key on the cast key, and its cost grew with the square of the rows: 0.18 s at 20k matches, 3.6 s
 * at 100k, where one index probe per row (FirstMatch) takes 0.03 s and 0.16 s. The subquery asks
 * MySQL for FirstMatch. The assertions read the SQL and the plan, never a timing: the plan
 * depends on sampled statistics, and without the hint it came out a hash join only about 2 runs in
 * 3 (L14, round 9), so the hint itself is asserted too.
 *
 * Since S3 a capped ranking on the model's own table is read from the postings, joined on the key
 * (CappedOrderedReadTest): one primary-key lookup per match, no hash join either. The subquery,
 * and its hint, are left for a union.
 */
class MySqlPostingsSemiJoinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver !== 'mysql') {
            $this->markTestSkipped('The plan is MySQL\'s (MariaDB ignores optimizer hints and chose no hash join); the CI MySQL jobs run this.');
        }

        Schema::dropIfExists('semijoin_articles');
        Schema::create('semijoin_articles', function ($table) {
            $table->id();
            $table->string('title')->index();
        });

        foreach (array_chunk(range(0, 19999), 1000) as $chunk) {
            DB::table('semijoin_articles')->insert(array_map(fn ($i) => ['title' => sprintf('alpha %05d', $i)], $chunk));
        }

        SemiJoinArticle::query()->chunkById(2000, fn ($articles) => app(IndexManager::class)->indexBatch($articles));
        DB::statement('ANALYZE TABLE semijoin_articles, fuzzy_index_postings, fuzzy_index_terms, fuzzy_index_documents');
    }

    protected function tearDown(): void
    {
        if ($this->dbDriver === 'mysql') {
            Schema::dropIfExists('semijoin_articles');
        }

        parent::tearDown();
    }

    /** @return array<int, array{string, array}> the statements $call runs against the postings subquery */
    private function postingsReads(\Closure $call): array
    {
        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, 'semijoin_articles') && str_contains($query->sql, 'fuzzy_index_postings')) {
                $reads[] = [$query->sql, $query->bindings];
            }
        });

        $call();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $reads;
    }

    public function test_the_postings_subquery_is_never_a_hash_semi_join(): void
    {
        $page = fn () => SemiJoinArticle::search('alpha')->typoTolerance(0)->useInvertedIndex()->orderBy('title')->paginate(10, 'page', 900);

        // A constrained search in rank order checks the ranking by key, not through this subquery
        // (ruling ER-124), and so does an ordered read of a ranking that holds every match (ER-132).
        $this->assertSame([], $this->postingsReads($page), 'a whole ranking');

        // A ranking capped at bm25.max_postings_per_term is read from the postings, joined on the key.
        config(['fuzzy-search.bm25.max_postings_per_term' => 10000]);
        $reads = $this->postingsReads($page);

        $this->assertCount(2, $reads, 'the ordered COUNT and page');

        foreach ($reads as [$sql, $bindings]) {
            $this->assertStringNotContainsString('SEMIJOIN', $sql);

            $plan = $this->plan($sql, $bindings);
            $this->assertStringNotContainsStringIgnoringCase('hash join', $plan, "{$sql}\n{$plan}");
            $this->assertMatchesRegularExpression('/Single-row (covering )?index lookup on semijoin_articles using PRIMARY/', $plan, "{$sql}\n{$plan}");
        }

        // Over a union it is read through the subquery, FirstMatch.
        $union = fn () => SemiJoinArticle::search('alpha')->typoTolerance(0)->useInvertedIndex()->where('id', '<=', 10000)
            ->query(fn ($query) => $query->unionAll(SemiJoinArticle::query()->where('id', '>', 10000)))->orderBy('title')->paginate(10, 'page', 900);
        $reads = $this->postingsReads($union);

        $this->assertCount(2, $reads, 'the ordered COUNT and page over a union');

        foreach ($reads as [$sql, $bindings]) {
            $this->assertStringContainsString('/*+ SEMIJOIN(FIRSTMATCH) */', $sql);

            $plan = $this->plan($sql, $bindings);
            $this->assertStringNotContainsStringIgnoringCase('hash join', $plan, "{$sql}\n{$plan}");
        }
    }

    private function plan(string $sql, array $bindings): string
    {
        return implode("\n", array_map(fn ($row) => (string) current((array) $row), DB::select('EXPLAIN FORMAT=TREE ' . $sql, $bindings)));
    }
}
