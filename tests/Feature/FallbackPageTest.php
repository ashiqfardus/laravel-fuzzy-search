<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * L1. get() ran fallback() whenever its page was empty, so a simplePaginate() or skip() page past
 * the primary algorithm's matches was filled with another algorithm's rows: "ring" has one LIKE
 * match, and simplePaginate(1) pages 2 and 3 came from Levenshtein while get() and paginate()
 * said there was one. The fallback now runs only when the primary matched nothing at all.
 */
class FallbackPageTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();
        app(IndexManager::class)->indexBatch(Post::all());
    }

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    public function test_pages_past_the_primary_matches_never_show_fallback_rows(): void
    {
        $paths = [
            'like'  => fn (string $term) => Post::search($term)->using('like')->fallback('levenshtein'),
            'index' => fn (string $term) => Post::search($term)->useInvertedIndex()->typoTolerance(0)->fallback('levenshtein'),
        ];

        foreach ($paths as $path => $make) {
            $this->assertSame(['The Ring'], $make('ring')->get()->pluck('title')->all(), "{$path} get");

            $first = $make('ring')->simplePaginate(1, 'page', 1);
            $this->assertSame(['The Ring'], collect($first->items())->pluck('title')->all(), "{$path} simplePaginate page 1");
            $this->assertFalse($first->hasMorePages(), "{$path} simplePaginate page 1 has no next page");

            foreach ([2, 3] as $page) {
                $this->assertSame([], $make('ring')->simplePaginate(1, 'page', $page)->items(), "{$path} simplePaginate page {$page}");
            }
            $this->assertSame([], $make('ring')->skip(1)->get()->all(), "{$path} skip(1)");
            $this->assertNull($make('ring')->skip(1)->first(), "{$path} skip(1)->first()");
        }
    }

    public function test_a_primary_without_matches_still_pages_through_the_fallback(): void
    {
        $fallback = Post::search('rign')->using('levenshtein')->get()->pluck('title')->all();
        $this->assertNotSame([], $fallback);

        $make  = fn () => Post::search('rign')->using('like')->fallback('levenshtein');
        $pages = [];
        foreach (range(1, count($fallback)) as $page) {
            $pages = [...$pages, ...collect($make()->simplePaginate(1, 'page', $page)->items())->pluck('title')->all()];
        }

        $this->assertSame($fallback, $pages);
    }
}
