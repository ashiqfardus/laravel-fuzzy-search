<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Company extends Model
{
    protected $table   = 'companies';
    protected $guarded = [];
    public $timestamps = false;
}

class ProbeAuthor extends Author
{
    public static int $calls = 0;

    /** Untyped: reachable only on a path the searched model lists in $searchable['columns']. */
    public function company()
    {
        static::$calls++;

        return $this->belongsTo(Company::class, 'company_id');
    }
}

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

    /** Typed, so its head segment is always reachable; ProbeAuthor::company() is not typed. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(ProbeAuthor::class, 'author_id');
    }

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

/** Searchable with no $searchable: its columns are auto-detected, which is not declared config. */
class ZeroConfigProbePost extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    public static int $calls = 0;

    protected $table   = 'posts';
    protected $guarded = [];

    public function helper()
    {
        static::$calls++;

        return $this->belongsTo(Author::class, 'author_id');
    }
}

/** Lists the nested path whose middle segment (company) has no return type. */
class ListedProbePost extends ProbePost
{
    protected array $searchable = [
        'columns'   => ['title' => 10, 'author.company.name' => 5],
        'algorithm' => 'like',
    ];
}

class RelationPathSafetyTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();

        Schema::dropIfExists('companies');
        Schema::create('companies', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::table('authors', function ($table) {
            $table->unsignedBigInteger('company_id')->nullable();
        });
        Author::query()->where('name', 'Tolkien')->update(['company_id' => Company::create(['name' => 'Allen Unwin'])->id]);

        ProbePost::$calls   = 0;
        ProbeAuthor::$calls = 0;
    }

    protected function tearDown(): void
    {
        Model::reguard();
        Schema::dropIfExists('companies');
        $this->dropRelationTables();
        parent::tearDown();
    }

    /**
     * Runs the search and returns what it threw; the caller checks the side effects first. The
     * rejection must come before any SQL: never a raw "no such column" from the database.
     */
    private function trySearchIn(string $column, string $model = ProbePost::class): ?\Throwable
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $model::searchOn($model::query(), 'ring', [$column])->get();
        } catch (\Throwable $e) {
            return $e;
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame([], $queries, "SQL ran for [{$column}]");
        }

        return null;
    }

    private function assertRejection(?\Throwable $e, string $segment): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertStringContainsString(
            "{$segment} is not a relation: declare a Relation return type or list the path in \$searchable['columns']",
            $e->getMessage()
        );
    }

    public function test_unguard_is_never_called(): void
    {
        $e = $this->trySearchIn('unguard.body');

        $this->assertFalse(Model::isUnguarded());
        $this->assertRejection($e, ProbePost::class . '::unguard');
    }

    public function test_save_is_never_called(): void
    {
        $before = Post::query()->count();
        $saves  = 0;
        ProbePost::saving(function () use (&$saves) {
            $saves++;
        });

        $e = $this->trySearchIn('save.body');

        $this->assertSame(0, $saves, 'save() was called');
        $this->assertSame($before, Post::query()->count());
        $this->assertRejection($e, ProbePost::class . '::save');
    }

    public function test_app_methods_typed_void_or_bool_are_never_called(): void
    {
        $void = $this->trySearchIn('purgeEverything.body');
        $bool = $this->trySearchIn('isFlagged.body');

        $this->assertSame(0, ProbePost::$calls);
        $this->assertRejection($void, ProbePost::class . '::purgeEverything');
        $this->assertRejection($bool, ProbePost::class . '::isFlagged');
    }

    public function test_an_untyped_undeclared_method_is_never_called(): void
    {
        $e = $this->trySearchIn('helper.name');

        $this->assertSame(0, ProbePost::$calls);
        $this->assertRejection($e, ProbePost::class . '::helper');
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
                $this->assertRejection($e, ProbePost::class . '::purgeEverything');
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

    public function test_a_nested_path_is_rejected_at_its_untyped_segment_unless_the_model_lists_it(): void
    {
        $e = $this->trySearchIn('author.company.name');

        $this->assertSame(0, ProbeAuthor::$calls, 'company() was called');
        $this->assertRejection($e, ProbeAuthor::class . '::company');

        $this->assertSame(['The Ring'], ListedProbePost::search('allen')->get()->pluck('title')->all());
    }

    public function test_a_name_that_is_not_a_method_is_still_a_table_column(): void
    {
        $this->assertSame(
            ['relation' => null, 'column' => 'posts.body'],
            ProbePost::search('ring')->searchIn(['posts.body'])->getDebugInfo()['column_targets']['posts.body']
        );
    }
    /** Finding 10: the segment that is missing is the one named, with the fix. */
    public function test_a_missing_segment_is_named_with_the_fix(): void
    {
        foreach ([
            'author.nosuch.name' => Author::class . '::nosuch is not a relation: declare a Relation return type',
            'nothing.deep.name'  => Post::class . '::nothing is not a relation: declare a Relation return type',
        ] as $column => $message) {
            try {
                Post::search('ring')->searchIn([$column])->getDebugInfo();
                $this->fail("[{$column}] was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $column);
            }
        }
    }

    /** Finding 14: auto-detected columns are not declared config, so no schema SQL runs before the rejection. */
    public function test_a_model_without_declared_columns_rejects_before_any_sql(): void
    {
        \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::reset();
        $builder = (new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder(ZeroConfigProbePost::query(), app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class)))
            ->search('ring')->searchIn(['helper.name']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $builder->get();
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('::helper is not a relation', $e->getMessage());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }

        $this->assertSame([], array_column($queries, 'query'));
        $this->assertSame(0, ZeroConfigProbePost::$calls);
    }
}
