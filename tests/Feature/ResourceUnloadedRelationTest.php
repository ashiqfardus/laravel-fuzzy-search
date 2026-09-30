<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchResource;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * Round 10, L12: FuzzySearchResource keeps a searchIn() relation path out of _highlighted and
 * _matches when the row no longer holds a related row to show it on: the relation unset after the
 * search, at the head of the path or deeper, or replaced by an empty collection. toArray() shows
 * no such column then.
 */
class ResourceUnloadedRelationTest extends TestCase
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

    private function render(string $column, string $title, ?callable $change = null): array
    {
        $post = Post::search('tolkien')->searchIn([$column])->highlight('b')->get()->firstWhere('title', $title);
        $this->assertNotNull($post);
        $this->assertArrayHasKey($column, $post->_highlighted);
        if ($change !== null) {
            $change($post);
        }

        return (new FuzzySearchResource($post))->toArray(request());
    }

    public function test_a_relation_unset_after_the_search_hides_its_path(): void
    {
        $changes = [
            'unsetRelation()'  => fn ($post) => $post->unsetRelation('author'),
            'setRelations([])' => fn ($post) => $post->setRelations([]),
        ];

        foreach ($changes as $label => $change) {
            foreach (['author.name', 'Author.name'] as $column) {
                $out = $this->render($column, 'The Ring', $change);
                $this->assertArrayNotHasKey($column, $out['_highlighted'], $label);
                $this->assertSame([], $out['_matches'], "{$label}: {$column}");
            }
        }
    }

    public function test_a_nested_path_with_no_related_row_left_is_hidden(): void
    {
        $changes = [
            'the head relation unset'      => fn ($post) => $post->unsetRelation('comments'),
            'the nested relation unset'    => fn ($post) => $post->comments->each->unsetRelation('author'),
            'the head relation left empty' => fn ($post) => $post->setRelation('comments', $post->comments->take(0)),
        ];

        foreach ($changes as $label => $change) {
            $out = $this->render('comments.author.name', 'Cooking', $change);
            $this->assertArrayNotHasKey('comments.author.name', $out['_highlighted'], $label);
            $this->assertSame([], $out['_matches'], $label);
        }
    }

    public function test_a_loaded_relation_path_is_still_highlighted(): void
    {
        $out = $this->render('author.name', 'The Ring');
        $this->assertSame('<b>Tolkien</b>', $out['_highlighted']['author.name']);
        $this->assertCount(1, $out['_matches']);
    }
}
