<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\Comment;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Scout's trait for the index name, the package's for indexing (the documented dual-trait recipe). */
class ScoutSaga extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}

/**
 * M1: Scout's ordered walk kept only the key in its select list, so an orderBy() on an alias
 * that the query() callback selects (withCount's comments_count, a selectRaw column) failed on
 * MySQL, PostgreSQL and SQL Server and was ignored on SQLite. As e06d5f5 did for the builder's walk.
 */
class ScoutOrderAliasTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->createRelationTables();

        // Relevance runs the other way from both orders below: One > Three > Two.
        foreach (['Saga One saga saga' => 0, 'Saga Two' => 2, 'Saga Three saga' => 1] as $title => $comments) {
            $post = ScoutSaga::create(['title' => $title, 'body' => 'x']);
            for ($i = 0; $i < $comments; $i++) {
                Comment::create(['post_id' => $post->id, 'body' => 'c']);
            }
        }

        (new FuzzySearchEngine(new IndexManager(new WhitespaceTokenizer(), new NullStemmer()), new Bm25Scorer()))
            ->update(ScoutSaga::all());
        config(['scout.driver' => 'fuzzy-search']);
    }

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    /** @return array<string, int> candidate_chunk => the walk: ids in the query, or past one chunk */
    private function walks(): array
    {
        return ['within one chunk' => 200, 'past one chunk' => 1];
    }

    private function titles(iterable $results): array
    {
        return collect($results instanceof \Illuminate\Contracts\Pagination\Paginator ? $results->items() : $results)
            ->pluck('title')->all();
    }

    private function assertOrdered(\Closure $make, string $walk): void
    {
        $expected = ['Saga Two', 'Saga Three saga', 'Saga One saga saga'];

        $this->assertSame($expected, $this->titles($make()->get()), "{$walk} get");
        $this->assertSame(
            $expected,
            [...$this->titles($make()->paginate(2, 'page', 1)), ...$this->titles($make()->paginate(2, 'page', 2))],
            "{$walk} paginate"
        );
        $this->assertSame(3, $make()->paginate(2, 'page', 1)->total(), "{$walk} total");
    }

    public function test_order_by_a_with_count_alias_from_the_query_callback(): void
    {
        foreach ($this->walks() as $walk => $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);
            $make = fn () => (new \Laravel\Scout\Builder(new ScoutSaga, 'saga'))
                ->query(fn ($q) => $q->withCount('comments'))->orderBy('comments_count', 'desc');

            $this->assertOrdered($make, $walk);
            $this->assertSame([2, 1, 0], $make()->get()->pluck('comments_count')->map(fn ($n) => (int) $n)->all(), "{$walk} counts");
        }
    }

    public function test_order_by_a_select_raw_alias_from_the_query_callback(): void
    {
        foreach ($this->walks() as $walk => $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);
            $make = fn () => (new \Laravel\Scout\Builder(new ScoutSaga, 'saga'))
                ->query(fn ($q) => $q->selectRaw('posts.*, case posts.title when ? then 2 when ? then 1 else 0 end as saga_rank', ['Saga Two', 'Saga Three saga']))
                ->orderBy('saga_rank', 'desc');

            $this->assertOrdered($make, $walk);
        }
    }
}
