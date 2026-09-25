<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Comment;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * With orderBy() the index path walks the constrained query in that order. The walk replaced the
 * caller's select list with the key, so an order on a select alias (withCount, selectRaw) failed
 * on MySQL/PostgreSQL/SQL Server and was ignored on SQLite (finding 1); it read the whole ordered
 * table in one buffered result (finding 12); and stableRanking() named the key twice in ORDER BY,
 * which SQL Server rejects (ruling ER-52).
 */
class IndexOrderWalkTest extends TestCase
{
    use CreatesRelationTables;

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    /** Three "saga" posts with 0, 2 and 1 comments. */
    private function seedSagas(): void
    {
        $this->createRelationTables();

        foreach (['Saga One' => 0, 'Saga Two' => 2, 'Saga Three' => 1] as $title => $comments) {
            $post = Post::create(['title' => $title, 'body' => 'x']);
            for ($i = 0; $i < $comments; $i++) {
                Comment::create(['post_id' => $post->id, 'body' => 'c']);
            }
        }

        app(IndexManager::class)->indexBatch(Post::all());
    }

    /** @return array<string, int> candidate_chunk => the walk it takes: ids in the query, or a paged walk of the table */
    private function walks(): array
    {
        return ['ids' => 200, 'paged' => 1];
    }

    public function test_order_by_a_with_count_alias_on_the_index_path(): void
    {
        $this->seedSagas();

        foreach ($this->walks() as $walk => $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);
            $make = fn () => Post::search('saga')->useInvertedIndex()->withCount('comments')->orderBy('comments_count', 'desc');

            $this->assertSame(['Saga Two', 'Saga Three', 'Saga One'], $make()->get()->pluck('title')->all(), "{$walk} get");
            $this->assertSame([2, 1, 0], $make()->get()->pluck('comments_count')->map(fn ($n) => (int) $n)->all(), "{$walk} counts");
            $this->assertSame(
                ['Saga Two', 'Saga Three', 'Saga One'],
                collect([...$make()->paginate(2, 'page', 1)->items(), ...$make()->paginate(2, 'page', 2)->items()])->pluck('title')->all(),
                "{$walk} paginate"
            );
        }
    }

    public function test_order_by_a_select_raw_alias_on_the_index_path(): void
    {
        $this->seedSagas();

        foreach ($this->walks() as $walk => $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);
            $make = fn () => Post::search('saga')->useInvertedIndex()
                ->selectRaw('posts.*, posts.id * 10 as id_rank')->orderBy('id_rank', 'desc');

            $expected = Post::query()->orderByDesc('id')->pluck('title')->all();

            $this->assertSame($expected, $make()->get()->pluck('title')->all(), "{$walk} get");
            $this->assertSame(
                $expected,
                collect([...$make()->paginate(2, 'page', 1)->items(), ...$make()->paginate(2, 'page', 2)->items()])->pluck('title')->all(),
                "{$walk} paginate"
            );
        }
    }

    /**
     * H1 (round 8): a one-to-many join repeats a model once per joined row, so the ordered page is
     * not one offset/limit read there. Each model is served once, at its first row, and total()
     * counts models.
     */
    public function test_a_join_that_repeats_a_model_serves_it_once_in_the_order(): void
    {
        $this->seedSagas();

        foreach ($this->walks() as $walk => $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);
            $make = fn () => Post::search('saga')->useInvertedIndex()
                ->join('comments', 'comments.post_id', '=', 'posts.id')->select('posts.*')->orderBy('posts.title');

            $this->assertSame(['Saga Three', 'Saga Two'], $make()->get()->pluck('title')->all(), "{$walk} get");
            $this->assertSame(2, $make()->count(), "{$walk} count");

            $pages = array_map(fn ($page) => $make()->paginate(1, 'page', $page), [1, 2, 3]);
            $this->assertSame([['Saga Three'], ['Saga Two'], []], array_map(fn ($p) => collect($p->items())->pluck('title')->all(), $pages), "{$walk} pages");
            $this->assertSame(2, $pages[0]->total(), "{$walk} total");
        }
    }

    /**
     * Finding 12: past one candidate chunk the ordered read is bounded, never one unbounded result.
     * H1 (round 8): it reads the page itself, get()'s 15 rows, not 1,000 rows at a time from the first.
     */
    public function test_the_ordered_read_is_bounded_by_the_page(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        config(['fuzzy-search.bm25.candidate_chunk' => 1]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $names = User::search('john')->useInvertedIndex()->orderBy('name')->get()->pluck('name')->all();
        $walk  = $this->walkQueries(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(['John Doe', 'Johnny Bravo', 'Jon Snow'], $names);
        $this->assertNotSame([], $walk);
        foreach ($walk as $sql) {
            $this->assertMatchesRegularExpression('/\blimit 15\b|\btop 15\b|\bfetch next 15 rows\b/i', $sql, "not the page: {$sql}");
        }
    }

    /** Ruling ER-52: stableRanking() names the key in ORDER BY once, on every path. */
    public function test_stable_ranking_never_names_the_key_twice(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        config(['fuzzy-search.bm25.candidate_chunk' => 1]);

        foreach (['id', 'users.id'] as $key) {
            $orders = [
                'like'     => User::search('john')->orderBy($key, 'desc')->stableRanking()->toSql(),
                'extended' => User::search('')->extended('john')->orderBy($key, 'desc')->stableRanking()->toSql(),
            ];

            DB::flushQueryLog();
            DB::enableQueryLog();
            User::search('john')->useInvertedIndex()->orderBy($key, 'desc')->stableRanking()->get();
            $orders['index'] = implode(' | ', $this->walkQueries(DB::getQueryLog()));
            DB::disableQueryLog();

            foreach ($orders as $path => $sql) {
                $orderBy = substr($sql, (int) strripos($sql, 'order by'));
                $this->assertSame(1, preg_match_all('/\bid\b/i', $orderBy), "{$path} orderBy('{$key}'): {$orderBy}");
            }
        }

        // Without an explicit key order it is still added, once.
        $sql = User::search('john')->orderBy('name')->stableRanking()->toSql();
        $this->assertSame(1, preg_match_all('/\bid\b/i', substr($sql, (int) strripos($sql, 'order by'))));
    }

    /** @return string[] the ordered walk's queries: those reading the key through the walk's alias */
    private function walkQueries(array $log): array
    {
        return array_values(array_filter(
            array_column($log, 'query'),
            fn (string $sql) => stripos($sql, 'order by') !== false && stripos($sql, 'fuzzy_walk_key') !== false
        ));
    }
}
