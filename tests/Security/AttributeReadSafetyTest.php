<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\ReindexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Queue;

/**
 * A user whose model carries methods a searchIn() name must never call (ruling ER-84): an app
 * method, and through the trait reindex() and bootSearchable(). Every call is counted.
 */
class MethodProbeUser extends Model
{
    use Searchable;

    public static int $calls = 0;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'like',
    ];

    public function purgeEverything(): void
    {
        static::$calls++;
    }

    /** An accessor with no column behind it: a searchIn() name may still read it. */
    protected function nickname(): Attribute
    {
        return Attribute::make(get: fn () => 'Captain ' . ($this->attributes['name'] ?? ''));
    }

    /** A legacy get-accessor over a real column. */
    public function getEmailAttribute($value): string
    {
        return 'mail:' . $value;
    }
}

/** A related model with a method a relation leaf must never call. */
class MethodProbeAuthor extends Model
{
    public static int $calls = 0;

    protected $table   = 'authors';
    protected $guarded = [];
    public $timestamps = false;

    public function purgeEverything(): void
    {
        static::$calls++;
    }
}

class MethodProbePost extends Model
{
    use Searchable;

    protected $table   = 'posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10], 'algorithm' => 'like'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(MethodProbeAuthor::class, 'author_id');
    }
}

/**
 * Ruling ER-84 (F1): reading a model's searchIn() column never falls back to a relation or a
 * method call. Undotted names reach getAttribute()'s relation fallback, which calls any method
 * named like the column; the index path and InMemorySearch read columns that never went
 * through SQL, so nothing failed first.
 */
class AttributeReadSafetyTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        MethodProbeUser::$calls   = 0;
        MethodProbeAuthor::$calls = 0;
        app(IndexManager::class)->indexBatch(MethodProbeUser::all());
    }

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    /** @return array<string, \Closure(string): mixed> every index terminal that highlights */
    private function indexPaths(): array
    {
        $search = fn (string $column) => MethodProbeUser::search('john')->searchIn([$column])->useInvertedIndex()->highlight();

        return [
            'get'            => fn (string $c) => $search($c)->get(),
            'first'          => fn (string $c) => $search($c)->first(),
            'paginate'       => fn (string $c) => $search($c)->paginate(10),
            'simplePaginate' => fn (string $c) => $search($c)->simplePaginate(10),
        ];
    }

    private function savedListeners(): int
    {
        return count(app('events')->getListeners('eloquent.saved: ' . MethodProbeUser::class));
    }

    public function test_an_app_method_is_never_called_on_the_index_path(): void
    {
        foreach ($this->indexPaths() as $path => $run) {
            $run('purgeEverything');
            $this->assertSame(0, MethodProbeUser::$calls, $path);
        }
    }

    public function test_reindex_is_never_called_on_the_index_path(): void
    {
        Queue::fake();

        foreach ($this->indexPaths() as $path => $run) {
            $run('reindex');
        }

        Queue::assertNotPushed(ReindexModelJob::class);
    }

    public function test_boot_searchable_is_never_called_on_the_index_path(): void
    {
        $before = $this->savedListeners();

        foreach ($this->indexPaths() as $path => $run) {
            $run('bootSearchable');
            $this->assertSame($before, $this->savedListeners(), $path);
        }
    }

    public function test_in_memory_search_never_calls_a_method(): void
    {
        Queue::fake();
        $before = $this->savedListeners();

        foreach (['purgeEverything', 'reindex', 'bootSearchable', 'purgeEverything.name', 'reindex.x'] as $column) {
            FuzzySearch::on(MethodProbeUser::all())->search('john')->searchIn(['name', $column])->get();
        }

        $this->assertSame(0, MethodProbeUser::$calls);
        $this->assertSame($before, $this->savedListeners());
        Queue::assertNotPushed(ReindexModelJob::class);
    }

    /** N2: the leaf of a typed relation path is read off the related row's attributes, never through getAttribute(). */
    public function test_a_relation_leaf_never_calls_the_related_models_method(): void
    {
        $this->createRelationTables();
        $this->seedRelationFixtures();
        app(IndexManager::class)->indexBatch(MethodProbePost::all());

        $search = fn () => MethodProbePost::search('ring')->searchIn(['title', 'author.purgeEverything'])->useInvertedIndex()->highlight();
        $runs   = [
            'index get'      => fn () => $search()->get(),
            'index paginate' => fn () => collect($search()->paginate(10)->items()),
            'in memory'      => fn () => FuzzySearch::on(MethodProbePost::with('author')->get())->search('ring')->searchIn(['title', 'author.purgeEverything'])->get(),
        ];

        foreach ($runs as $path => $run) {
            $this->assertSame(['The Ring'], $run()->pluck('title')->all(), $path);
            $this->assertSame(0, MethodProbeAuthor::$calls, $path);
        }
    }

    public function test_accessors_are_still_read(): void
    {
        $row = MethodProbeUser::search('john')->searchIn(['nickname'])->useInvertedIndex()->highlight()->first();
        $this->assertSame('Captain <em>John</em> Doe', $row->_highlighted['nickname']);
        $this->assertSame('mail:<em>john</em>@example.com', $row->_highlighted['email']);

        $found = FuzzySearch::on(MethodProbeUser::all())->search('captain jon snow')->searchIn(['nickname'])->get();
        $this->assertSame('Jon Snow', $found->first()->name);

        $mail = FuzzySearch::on(MethodProbeUser::all())->search('mail:alice@example.com')->searchIn(['email'])->get();
        $this->assertSame('Alice Smith', $mail->first()->name);
    }

    public function test_a_loaded_relation_is_still_read_in_memory(): void
    {
        $users = MethodProbeUser::all()->each(fn ($u) => $u->setRelation('manager', new MethodProbeUser(['name' => 'Zed Boss'])));

        $found = FuzzySearch::on($users)->search('zed boss')->searchIn(['manager.name'])->get();

        $this->assertCount(7, $found);
    }
}
