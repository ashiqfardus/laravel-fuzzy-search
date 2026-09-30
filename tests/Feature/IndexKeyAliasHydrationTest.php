<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** The users table on Scout's builder. */
class AliasScoutUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::search as scoutSearch;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::bootSearchable as bootScoutSearchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10]];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

/**
 * L11 (round 10), ruling ER-135. The index path read each model's key under an alias beside the
 * select list (ruling D13) and took it out only after get() returned, so a retrieved listener saw a
 * fuzzy_walk_key attribute on every row, and one that kept the attributes (an audit log, a later
 * forceFill()->save()) kept a column that does not exist. The builder now takes the alias off each
 * row before it hydrates it; Scout's engine selects it only where a query() callback's select() may
 * leave the key out or a join may shadow it, and matches every other model by its own key.
 */
class IndexKeyAliasHydrationTest extends TestCase
{
    use CreatesRelationTables;

    /** @var array<string, true> every attribute a retrieved listener saw */
    private array $seen = [];

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    private function listen(): void
    {
        Event::listen('eloquent.retrieved: *', function (string $event, array $models) {
            foreach (array_keys($models[0]->getAttributes()) as $attribute) {
                $this->seen[$attribute] = true;
            }
        });
    }

    public function test_a_retrieved_listener_never_sees_the_key_alias_on_the_builder(): void
    {
        $this->createRelationTables();
        $this->seedRelationFixtures();
        app(IndexManager::class)->indexBatch(User::all());
        app(IndexManager::class)->indexBatch(Post::all());
        $this->listen();

        $doe   = fn () => User::search('doe')->typoTolerance(0)->useInvertedIndex();
        $reads = [
            'get'                   => fn () => $doe()->get(),
            'paginate'              => fn () => $doe()->paginate(1, 'page', 2)->getCollection(),
            'simplePaginate'        => fn () => collect($doe()->simplePaginate(1)->items()),
            'first'                 => fn () => collect([$doe()->first()]),
            'orderBy get'           => fn () => $doe()->orderBy('name')->get(),
            'orderBy paginate'      => fn () => $doe()->orderBy('name')->paginate(1, 'page', 2)->getCollection(),
            'where get'             => fn () => $doe()->where('email', 'like', '%@example.com')->get(),
            'select without key'    => fn () => $doe()->select('name', 'email')->get(),
            'join repeating a row'  => fn () => $doe()->join('users as twin', 'twin.email', 'like', DB::raw("'%@example.com'"))->select('users.*')->get(),
            'union'                 => fn () => $doe()->where('name', 'Jane Doe')->query(fn ($query) => $query->union(User::query()->where('name', 'John Doe')))->get(),
            'cache miss and hit'    => fn () => $doe()->cache(60)->get()->concat($doe()->cache(60)->get()),
            'eager load, withCount' => fn () => Post::search('ring')->typoTolerance(0)->useInvertedIndex()->with('author')->withCount('comments')->get(),
        ];

        foreach ($reads as $read => $run) {
            $rows = $run();
            $this->assertNotSame(0, $rows->count(), "{$read}: served no row");
            foreach ($rows as $row) {
                $this->assertArrayNotHasKey(RankedCandidates::KEY_ALIAS, $row->getAttributes(), "{$read}: the served row");
            }
        }

        $this->assertArrayHasKey('name', $this->seen, 'the retrieved event fired');
        $this->assertArrayNotHasKey(RankedCandidates::KEY_ALIAS, $this->seen, 'a retrieved listener saw the key alias');

        // A select() without the key still scores its rows, and eager loads and withCount() still load.
        $this->assertGreaterThan(0.0, (float) $doe()->select('name', 'email')->first()->_score);
        $post = Post::search('ring')->typoTolerance(0)->useInvertedIndex()->with('author')->withCount('comments')->first();
        $this->assertTrue($post->relationLoaded('author'));
        $this->assertNotNull($post->comments_count);
    }

    public function test_a_retrieved_listener_never_sees_the_key_alias_on_scout(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false]);
        AliasScoutUser::all()->searchable();
        $this->listen();

        foreach ([
            'get'              => fn () => AliasScoutUser::scoutSearch('doe')->get(),
            'paginate'         => fn () => AliasScoutUser::scoutSearch('doe')->paginate(1, 'page', 2)->getCollection(),
            'orderBy get'      => fn () => AliasScoutUser::scoutSearch('doe')->orderBy('name')->get(),
            'where get'        => fn () => AliasScoutUser::scoutSearch('doe')->where('email', 'jane@example.com')->get(),
            'query() where'    => fn () => AliasScoutUser::scoutSearch('doe')->query(fn ($query) => $query->where('email', 'like', '%@example.com'))->get(),
            'query() with key' => fn () => AliasScoutUser::scoutSearch('doe')->query(fn ($query) => $query->select('id', 'name'))->orderBy('name')->get(),
        ] as $read => $run) {
            $this->assertNotSame(0, $run()->count(), "{$read}: served no row");
        }

        $this->assertArrayHasKey('name', $this->seen, 'the retrieved event fired');
        $this->assertArrayNotHasKey(RankedCandidates::KEY_ALIAS, $this->seen, 'a retrieved listener saw the key alias');
    }
}
