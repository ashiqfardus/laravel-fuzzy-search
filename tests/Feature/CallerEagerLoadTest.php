<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Comment;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class EagerCompany extends Model
{
    protected $table   = 'eager_companies';
    protected $guarded = [];
    public $timestamps = false;
}

class EagerAuthor extends Author
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(EagerCompany::class, 'company_id');
    }
}

class EagerPost extends Post
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(EagerAuthor::class, 'author_id');
    }
}

/**
 * The caller's eager load of a relation that searchIn() also reads was replaced by an unconstrained
 * one: Eloquent's with() overwrites an entry of the same name, and a nested path resets its prefix.
 * `with('author:id,name')` then returned the author's every column, and `with(['comments' => a
 * where])` every comment, the ones the caller filtered out included; suggest() offered their words.
 */
class CallerEagerLoadTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();

        Schema::dropIfExists('eager_companies');
        Schema::create('eager_companies', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::table('authors', function ($table) {
            $table->string('email')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
        });
        $company = EagerCompany::create(['name' => 'Allen Unwin']);
        Author::query()->where('name', 'Tolkien')->update(['email' => 'jrr@example.com', 'company_id' => $company->id]);

        $ring    = Post::query()->where('title', 'The Ring')->value('id');
        $tolkien = Author::query()->where('name', 'Tolkien')->value('id');
        Comment::create(['post_id' => $ring, 'author_id' => $tolkien, 'body' => 'ring lore']);
        Comment::create(['post_id' => $ring, 'author_id' => null, 'body' => 'buy pills now']);

        config(['cache.default' => 'array']);
        Cache::flush();
        app(IndexManager::class)->indexBatch(EagerPost::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('eager_companies');
        $this->dropRelationTables();
        parent::tearDown();
    }

    /** @return array<string, \Closure(\Closure(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder): Collection> */
    private function paths(): array
    {
        return [
            'get'            => fn ($make) => $make()->get(),
            'first'          => fn ($make) => collect([$make()->first()]),
            'paginate'       => fn ($make) => collect($make()->paginate(10)->items()),
            'simplePaginate' => fn ($make) => collect($make()->simplePaginate(10)->items()),
            'index get'      => fn ($make) => $make()->useInvertedIndex()->get(),
            'index paginate' => fn ($make) => collect($make()->useInvertedIndex()->paginate(10)->items()),
            'extended'       => fn ($make) => $make()->extended('ring')->get(),
            'cache hit'      => function ($make) {
                $make()->cache(60)->get();

                return $make()->cache(60)->get();
            },
        ];
    }

    private function ring(Collection $rows, string $path): EagerPost
    {
        $post = $rows->firstWhere('title', 'The Ring');
        $this->assertInstanceOf(EagerPost::class, $post, $path);

        return $post;
    }

    public function test_a_column_select_and_a_where_on_a_searched_relation_hold_on_every_path(): void
    {
        $seen = [];
        foreach ($this->paths() as $path => $run) {
            Cache::flush();
            $post = $this->ring($run(fn () => EagerPost::search('ring')->using('like')
                ->searchIn(['author.name', 'comments.body'])
                ->with(['author:id,name', 'comments' => fn ($q) => $q->whereNotNull('author_id')])), $path);

            $seen[$path] = [$this->attributeNames($post->author), $post->comments->pluck('body')->all()];
        }

        $this->assertSame(array_fill_keys(array_keys($this->paths()), [['id', 'name'], ['ring lore']]), $seen);
    }

    public function test_a_nested_path_keeps_the_callers_constraint_on_its_prefix(): void
    {
        $seen = [];
        foreach ($this->paths() as $path => $run) {
            Cache::flush();
            $post = $this->ring($run(fn () => EagerPost::search('ring')->using('like')
                ->searchIn(['author.company.name'])
                ->with(['author' => fn ($q) => $q->select('id', 'name', 'company_id')])), $path);

            $seen[$path] = [$this->attributeNames($post->author), $post->author->company?->name];
        }

        $this->assertSame(array_fill_keys(array_keys($this->paths()), [['company_id', 'id', 'name'], 'Allen Unwin']), $seen);
    }

    /** @return string[] sorted: the order a database returns the selected columns in varies */
    private function attributeNames(Model $model): array
    {
        $names = array_keys($model->getAttributes());
        sort($names);

        return $names;
    }

    public function test_suggest_offers_no_word_from_a_related_row_the_caller_filtered_out(): void
    {
        $suggestions = EagerPost::search('bu')->searchIn(['comments.body'])
            ->with(['comments' => fn ($q) => $q->whereNotNull('author_id')])
            ->suggestFrom('table')->suggest();

        $this->assertSame([], $suggestions);
        $this->assertContains('buy', EagerPost::search('bu')->searchIn(['comments.body'])->suggestFrom('table')->suggest(), 'unconstrained, it is offered');
    }
}
