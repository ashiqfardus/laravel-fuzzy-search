<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lists a framework method's path in its own config: a declared path still never calls it. */
class DeclaredSavePost extends Post
{
    protected array $searchable = [
        'columns'   => ['title' => 10, 'save.body' => 5],
        'algorithm' => 'like',
    ];
}

class RequiredParameterPost extends Post
{
    /** Typed to return a relation, but it cannot be called without an argument. */
    public function authorBy(string $foreignKey): BelongsTo
    {
        return $this->belongsTo(Author::class, $foreignKey);
    }
}

/** Declares its untyped relation in another case than the method: the declared route. */
class DeclaredCaseWriterPost extends Post
{
    protected array $searchable = [
        'columns'   => ['title' => 10, 'Writer.name' => 5],
        'algorithm' => 'like',
    ];

    public function writer()
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}

/**
 * Ruling ER-50's two sub-checks no other test isolates: a method the framework or this package
 * declares is never called, even on a path the model lists in $searchable['columns']; and a method
 * with a required parameter is rejected by name rather than called into an ArgumentCountError.
 */
class RelationPathMethodCheckTest extends TestCase
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

    private function rejection(\Closure $run): \Throwable
    {
        try {
            $run();
        } catch (\Throwable $e) {
            return $e;
        }

        $this->fail('the path was accepted');
    }

    public function test_a_framework_method_on_a_declared_path_is_never_called(): void
    {
        $saves = 0;
        DeclaredSavePost::saving(function () use (&$saves) {
            $saves++;
        });

        $e = $this->rejection(fn () => DeclaredSavePost::search('ring')->get());

        $this->assertSame(0, $saves, 'save() was called');
        $this->assertInstanceOf(\InvalidArgumentException::class, $e, $e->getMessage());
        $this->assertStringContainsString(DeclaredSavePost::class . '::save is not a relation', $e->getMessage());
    }

    public function test_a_relation_path_is_resolved_to_each_methods_declared_name(): void
    {
        $this->assertSame(
            ['relation' => 'comments.author', 'column' => 'name'],
            Post::search('tolkien')->searchIn(['COMMENTS.Author.name'])->getDebugInfo()['column_targets']['COMMENTS.Author.name']
        );
        $this->assertSame(
            ['relation' => 'writer', 'column' => 'name'],
            DeclaredCaseWriterPost::search('tolkien')->getDebugInfo()['column_targets']['Writer.name']
        );

        $post = DeclaredCaseWriterPost::search('tolkien')->get()->first();
        $this->assertSame('The Ring', $post->title);
        $this->assertSame(['writer'], array_keys($post->getRelations()));
    }

    public function test_a_method_with_a_required_parameter_is_rejected_by_name(): void
    {
        $e = $this->rejection(fn () => RequiredParameterPost::search('ring')->searchIn(['authorBy.name'])->get());

        $this->assertInstanceOf(\InvalidArgumentException::class, $e, get_class($e) . ': ' . $e->getMessage());
        $this->assertStringContainsString(RequiredParameterPost::class . '::authorBy is not a relation', $e->getMessage());
    }
}
