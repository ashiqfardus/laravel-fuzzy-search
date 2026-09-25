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
 * the postings subquery of an ordered or constrained index search as a hash semi-join it could not
 * key on the cast key, and its cost grew with the square of the rows: 0.18 s at 20k matches, 3.6 s
 * at 100k, where one index probe per row (FirstMatch) takes 0.03 s and 0.16 s. The subquery now
 * asks MySQL for FirstMatch. The assertion reads the plan, never a timing.
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
            $table->unsignedInteger('shelf')->default(1);
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
        $reads = [
            ...$this->postingsReads(fn () => SemiJoinArticle::search('alpha')->typoTolerance(0)->useInvertedIndex()->orderBy('title')->paginate(10, 'page', 900)),
            ...$this->postingsReads(fn () => SemiJoinArticle::search('alpha')->typoTolerance(0)->useInvertedIndex()->where('shelf', 1)->paginate(10)),
        ];

        $this->assertCount(3, $reads, 'the ordered COUNT and page, and the constrained key read');

        foreach ($reads as [$sql, $bindings]) {
            $plan = implode("\n", array_map(fn ($row) => (string) current((array) $row), DB::select('EXPLAIN FORMAT=TREE ' . $sql, $bindings)));
            $this->assertStringNotContainsStringIgnoringCase('hash join', $plan, "{$sql}\n{$plan}");
        }
    }
}
