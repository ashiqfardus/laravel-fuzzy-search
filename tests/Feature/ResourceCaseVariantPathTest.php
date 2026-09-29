<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchResource;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class ResourceHiddenRelationPost extends Post
{
    protected $hidden = ['author'];
}

/**
 * L3: a searchIn() path typed in another case ("Author.name") reaches the relation as author(), so
 * FuzzySearchResource must judge it by the relation's real name: hidden stays hidden, visible stays shown.
 */
class ResourceCaseVariantPathTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();
    }

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    private function render(string $class, string $column, bool $hideAtRuntime): array
    {
        $post = $class::search('tolkien')->searchIn([$column])->highlight('b')->get()->firstWhere('title', 'The Ring');
        $this->assertNotNull($post);
        if ($hideAtRuntime) {
            $post->makeHidden('author');
        }

        return (new FuzzySearchResource($post))->toArray(request());
    }

    public function test_runtime_hidden_relation_is_hidden_for_any_case_of_the_path(): void
    {
        foreach (['author.name', 'Author.name', 'AUTHOR.name'] as $column) {
            $out = $this->render(Post::class, $column, true);
            $this->assertArrayNotHasKey($column, $out['_highlighted']);
            $this->assertSame([], $out['_matches'], $column);
        }
    }

    public function test_relation_hidden_by_the_hidden_property_is_hidden_for_any_case_of_the_path(): void
    {
        foreach (['author.name', 'Author.name'] as $column) {
            $out = $this->render(ResourceHiddenRelationPost::class, $column, false);
            $this->assertArrayNotHasKey($column, $out['_highlighted']);
            $this->assertSame([], $out['_matches'], $column);
        }
    }

    public function test_visible_case_variant_path_is_still_highlighted(): void
    {
        foreach (['author.name', 'Author.name'] as $column) {
            $out = $this->render(Post::class, $column, false);
            $this->assertSame('<b>Tolkien</b>', $out['_highlighted'][$column], $column);
            $this->assertCount(1, $out['_matches'], $column);
        }
    }
}
