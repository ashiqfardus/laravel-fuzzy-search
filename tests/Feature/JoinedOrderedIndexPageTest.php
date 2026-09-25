<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * A3 (round 8), ruling ER-108. A one-to-many join repeats a model once per joined row, and an
 * ordered index page serves each model once, at its first row. It found them by walking the
 * joined rows 1,000 at a time from the first, so a deep page re-ran the ordered join once per
 * 1,000 rows before it (20 s on MySQL, 51 s on PostgreSQL at page 4999). Now:
 *  - an order on the model's own columns pages the model's table, restricted to the joined
 *    query's keys: one offset/limit read;
 *  - an order on a joined column keeps each model's first row with ROW_NUMBER(): one read;
 *  - only an order on a select alias over a join still walks.
 */
class JoinedOrderedIndexPageTest extends TestCase
{
    use CreatesRelationTables;

    /** post title => its comment bodies, in insertion order */
    private array $comments = [];

    protected function tearDown(): void
    {
        $this->dropRelationTables();

        parent::tearDown();
    }

    /** $n "Saga NNNN" posts, inserted shuffled, with 0–3 comments each (bodies spread over the alphabet), indexed. */
    private function seedSagas(int $n): void
    {
        $this->createRelationTables();

        $numbers = range(0, $n - 1);
        mt_srand(108);
        shuffle($numbers);

        foreach (array_chunk($numbers, 250) as $chunk) {
            DB::table('posts')->insert(array_map(fn ($i) => ['title' => sprintf('Saga %04d', $i), 'body' => 'x'], $chunk));
        }

        $comments = [];
        foreach (DB::table('posts')->orderBy('id')->get(['id', 'title']) as $post) {
            $i = (int) substr($post->title, 5);
            for ($c = 1; $c <= $i % 4; $c++) { // 0 to 3 comments
                $body = sprintf('c%05d', ($i * 7919 + $c * 104729) % 99991);
                $comments[] = ['post_id' => $post->id, 'body' => $body];
                $this->comments[$post->title][] = $body;
            }
        }

        foreach (array_chunk($comments, 250) as $chunk) {
            DB::table('comments')->insert($chunk);
        }

        app(IndexManager::class)->indexBatch(Post::all());
    }

    /** @return string[] the titles of the posts with a comment, by title */
    private function byTitle(): array
    {
        $titles = array_keys($this->comments);
        sort($titles);

        return $titles;
    }

    /** @return string[] the titles of the posts with a comment, by their first comment body, then key */
    private function byFirstComment(): array
    {
        $ids   = DB::table('posts')->pluck('id', 'title')->all();
        $posts = array_keys($this->comments);
        usort($posts, fn ($a, $b) => [min($this->comments[$a]), $ids[$a]] <=> [min($this->comments[$b]), $ids[$b]]);

        return $posts;
    }

    /** @return string[] the titles of the posts with a comment, by comment count descending, then key */
    private function byCommentCount(): array
    {
        $ids   = DB::table('posts')->pluck('id', 'title')->all();
        $posts = array_keys($this->comments);
        usort($posts, fn ($a, $b) => [count($this->comments[$b]), $ids[$a]] <=> [count($this->comments[$a]), $ids[$b]]);

        return $posts;
    }

    private function search()
    {
        return Post::search('saga')->typoTolerance(0)->useInvertedIndex()
            ->join('comments', 'comments.post_id', '=', 'posts.id')->select('posts.*');
    }

    /** @return array<string, array{\Closure, \Closure(): string[]}> case => [the search, the expected titles] */
    private function cases(): array
    {
        return [
            'own column'    => [fn () => $this->search()->orderBy('posts.title'), fn () => $this->byTitle()],
            'joined column' => [fn () => $this->search()->orderBy('comments.body'), fn () => $this->byFirstComment()],
            'select alias'  => [fn () => $this->search()->withCount('comments')->orderBy('comments_count', 'desc'), fn () => $this->byCommentCount()],
        ];
    }

    public function test_each_model_is_served_once_at_its_first_row_and_total_counts_models(): void
    {
        $this->seedSagas(60);

        // candidate_chunk 200: the ranked ids are listed; 5: the postings subquery restricts the read.
        foreach ([200, 5] as $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);

            foreach ($this->cases() as $case => [$make, $expected]) {
                $expected = $expected();
                $at       = "{$case}, chunk {$chunk}";
                $served   = [];

                for ($page = 1; $page <= 7; $page++) {
                    $paginator = $make()->paginate(7, 'page', $page);
                    $this->assertSame(count($expected), $paginator->total(), "{$at}: total on page {$page}");
                    $served = [...$served, ...collect($paginator->items())->pluck('title')->all()];
                }

                $this->assertSame($expected, $served, "{$at}: every model once, at its first row");
                $this->assertSame(count($expected), $make()->count(), "{$at}: count");
                $this->assertSame(array_slice($expected, 10, 5), $make()->skip(10)->take(5)->get()->pluck('title')->all(), "{$at}: get");
            }
        }
    }

    /**
     * A select with bindings of its own (a constrained withCount()) beside the read of the keys:
     * the key read drops that select, and so must its bindings, or every later one binds one
     * place early.
     */
    public function test_a_select_with_bindings_survives_the_key_reads(): void
    {
        $this->seedSagas(60);
        config(['fuzzy-search.bm25.candidate_chunk' => 5]);

        $make = fn ($search) => $search
            ->withCount(['comments as late_comments' => fn ($q) => $q->where('body', '>=', 'c50000')])
            ->where('posts.title', '>=', 'Saga 0030');
        $posts = fn () => Post::search('saga')->typoTolerance(0)->useInvertedIndex();

        $late = fn (string $title) => count(array_filter($this->comments[$title] ?? [], fn ($body) => $body >= 'c50000'));

        $cases = [
            'rank order' => fn () => $make($posts()),
            'own column' => fn () => $make($posts()->join('comments', 'comments.post_id', '=', 'posts.id')->select('posts.*'))->orderBy('posts.title'),
        ];

        foreach ($cases as $case => $search) {
            $rows = $search()->take(100)->get();
            $this->assertNotEmpty($rows, $case);

            foreach ($rows as $post) {
                $this->assertGreaterThanOrEqual('Saga 0030', $post->title, $case);
                $this->assertSame($late($post->title), (int) $post->late_comments, "{$case}: {$post->title}");
            }
        }
    }

    /** @return int the number of statements $call runs */
    private function queries(\Closure $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** Past 1,000 joined rows the walk took one more ordered query per 1,000 rows before the page. */
    public function test_a_deep_joined_page_takes_as_many_queries_as_page_one_on_an_own_or_a_joined_column(): void
    {
        $this->seedSagas(1400);

        foreach (['own column', 'joined column'] as $case) {
            [$make, $expected] = $this->cases()[$case];
            $expected = $expected();
            $make()->paginate(15); // warms the once-per-process reads, such as MySQL's model_id collation

            $first = $this->queries(fn () => $this->assertSame(array_slice($expected, 0, 15), $make()->paginate(15, 'page', 1)->pluck('title')->all(), "{$case}: page 1"));
            $deep  = $this->queries(fn () => $this->assertSame(array_slice($expected, 900, 15), $make()->paginate(15, 'page', 61)->pluck('title')->all(), "{$case}: page 61"));

            $this->assertSame($first, $deep, "{$case}: page 61 against page 1");
        }
    }
}
