<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * A post whose model carries methods searchIn() must never call (ruling ER-50): an app method
 * typed void or bool, an untyped helper, plus an untyped relation the model itself declares in
 * $searchable['columns'] (trusted config). Every call is counted.
 */
class ProbePost extends Post
{
    public static int $calls = 0;

    protected array $searchable = [
        'columns'   => ['title' => 10, 'writer.name' => 5],
        'algorithm' => 'like',
    ];

    /** Untyped, but its path is declared above. */
    public function writer()
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function purgeEverything(): void
    {
        static::$calls++;
    }

    public function isFlagged(): bool
    {
        static::$calls++;

        return true;
    }

    /** Untyped and undeclared: never called, even though it would return a relation. */
    public function helper()
    {
        static::$calls++;

        return $this->belongsTo(Author::class, 'author_id');
    }
}

class RelationPathSafetyTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();
        ProbePost::$calls = 0;
    }

    protected function tearDown(): void
    {
        Model::reguard();
        $this->dropRelationTables();
        parent::tearDown();
    }

    /** Runs the search and returns what it threw; the caller checks the side effects first. */
    private function attempt(string $column): ?\Throwable
    {
        try {
            ProbePost::search('ring')->searchIn([$column])->get();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function assertRejection(?\Throwable $e): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertStringContainsString('must be a relation method with a Relation return type', $e->getMessage());
    }

    public function test_unguard_is_never_called(): void
    {
        $e = $this->attempt('unguard.body');

        $this->assertFalse(Model::isUnguarded());
        $this->assertRejection($e);
    }

    public function test_save_is_never_called(): void
    {
        $before = Post::query()->count();
        $saves  = 0;
        ProbePost::saving(function () use (&$saves) {
            $saves++;
        });

        $e = $this->attempt('save.body');

        $this->assertSame(0, $saves, 'save() was called');
        $this->assertSame($before, Post::query()->count());
        $this->assertRejection($e);
    }

    public function test_app_methods_typed_void_or_bool_are_never_called(): void
    {
        $void = $this->attempt('purgeEverything.body');
        $bool = $this->attempt('isFlagged.body');

        $this->assertSame(0, ProbePost::$calls);
        $this->assertRejection($void);
        $this->assertRejection($bool);
    }

    public function test_an_untyped_undeclared_method_is_never_called(): void
    {
        $e = $this->attempt('helper.name');

        $this->assertSame(0, ProbePost::$calls);
        $this->assertRejection($e);
    }

    public function test_every_path_rejects_before_calling(): void
    {
        foreach ([
            'first'     => fn ($b) => $b->first(),
            'paginate'  => fn ($b) => $b->paginate(5),
            'count'     => fn ($b) => $b->count(),
            'extended'  => fn ($b) => $b->extended('ring')->get(),
            'debugInfo' => fn ($b) => $b->getDebugInfo(),
        ] as $path => $run) {
            try {
                $run(ProbePost::search('ring')->searchIn(['purgeEverything.body']));
                $this->fail("{$path} accepted the path");
            } catch (\Throwable $e) {
                $this->assertSame(0, ProbePost::$calls, $path);
                $this->assertRejection($e);
            }
        }
    }

    public function test_a_typed_relation_works(): void
    {
        $this->assertSame(['The Ring'], Post::search('tolkien')->searchIn(['author.name'])->get()->pluck('title')->all());
    }

    public function test_an_untyped_relation_declared_in_searchable_columns_works(): void
    {
        $this->assertSame(['The Ring'], ProbePost::search('tolkien')->get()->pluck('title')->all());
        $this->assertSame(['The Ring'], ProbePost::searchOn(ProbePost::query(), 'tolkien', ['writer.name'])->get()->pluck('title')->all());
    }

    public function test_a_nested_typed_path_works(): void
    {
        $this->assertSame(['Cooking'], Post::search('tolkien')->searchIn(['comments.author.name'])->get()->pluck('title')->all());
    }

    public function test_a_name_that_is_not_a_method_is_still_a_table_column(): void
    {
        $this->assertSame(
            ['relation' => null, 'column' => 'posts.body'],
            ProbePost::search('ring')->searchIn(['posts.body'])->getDebugInfo()['column_targets']['posts.body']
        );
    }
}
