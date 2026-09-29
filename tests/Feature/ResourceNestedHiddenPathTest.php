<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchResource;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * Ruling ER-118: FuzzySearchResource judges a nested searchIn() path on every loaded row along it, so a
 * relation or column hidden at any depth keeps the entry out of _highlighted and _matches.
 */
class ResourceNestedHiddenPathTest extends TestCase
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

    private function render(string $column, ?callable $hide = null): array
    {
        $post = Post::search('tolkien')->searchIn([$column])->highlight('b')->get()->firstWhere('title', 'Cooking');
        $this->assertNotNull($post);
        $this->assertArrayHasKey($column, $post->_highlighted);
        if ($hide !== null) {
            $hide($post);
        }

        return (new FuzzySearchResource($post))->toArray(request());
    }

    public function test_a_hidden_middle_relation_hides_the_path(): void
    {
        foreach (['comments.author.name', 'Comments.Author.name'] as $column) {
            $out = $this->render($column, fn ($post) => $post->comments->each->makeHidden('author'));
            $this->assertArrayNotHasKey($column, $out['_highlighted']);
            $this->assertSame([], $out['_matches'], $column);
        }
    }

    public function test_a_hidden_leaf_column_on_the_related_model_hides_the_path(): void
    {
        foreach (['comments.author.name', 'Comments.Author.name'] as $column) {
            $out = $this->render($column, fn ($post) => $post->comments->each(fn ($c) => $c->author->makeHidden('name')));
            $this->assertArrayNotHasKey($column, $out['_highlighted']);
            $this->assertSame([], $out['_matches'], $column);
        }
    }

    public function test_a_visible_nested_path_is_still_highlighted(): void
    {
        foreach (['comments.author.name', 'Comments.Author.name'] as $column) {
            $out = $this->render($column);
            $this->assertSame('<b>Tolkien</b>', $out['_highlighted'][$column], $column);
            $this->assertCount(1, $out['_matches'], $column);
        }
    }
}
