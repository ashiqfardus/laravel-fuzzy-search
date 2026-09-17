<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class RelationIndexingTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        // Static toggles on the fixture model below leak across tests (the class isn't
        // reloaded between them) — reset to the default hook behaviour every time.
        IndexedPost::$blank    = false;
        IndexedPost::$textMode = null;
    }

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    private function termsFor(Model $model): array
    {
        return DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', $model::class)->where('p.model_id', $model->getKey())
            ->distinct()
            ->orderBy('t.term')->pluck('t.term')->map(fn ($t) => (string) $t)->all();
    }

    public function test_searchable_text_hook_defines_what_is_indexed(): void
    {
        $post = IndexedPost::whereTitle('The Ring')->first();
        app(IndexManager::class)->indexModel($post);

        $this->assertSame(['epic', 'fantasy', 'ring', 'tolkien'], $this->termsFor($post));
    }

    public function test_index_batch_uses_the_hook_too(): void
    {
        app(IndexManager::class)->indexBatch(IndexedPost::with('author', 'tags')->get());

        $harry = IndexedPost::whereTitle('Harry')->first();
        $this->assertSame(['fantasy', 'harry', 'rowling'], $this->termsFor($harry));
    }

    public function test_bm25_search_finds_a_post_by_its_related_text(): void
    {
        app(IndexManager::class)->indexBatch(IndexedPost::with('author', 'tags')->get());

        $titles = IndexedPost::search('tolkien')->useInvertedIndex()->get()->pluck('title')->all();

        $this->assertSame(['The Ring'], $titles);
    }

    public function test_reindex_related_reindexes_every_row_pointing_at_the_related_id(): void
    {
        app(IndexManager::class)->indexBatch(IndexedPost::with('author', 'tags')->get());
        $tolkien = Author::whereName('Tolkien')->first();
        $harry   = IndexedPost::whereTitle('Harry')->first();
        $winter  = IndexedPost::whereTitle('Winter')->first();
        $harryTermsBefore  = $this->termsFor($harry);
        $winterTermsBefore = $this->termsFor($winter);

        $tolkien->update(['name' => 'Professor']);
        $count = IndexedPost::reindexRelated('author_id', $tolkien->id);

        $this->assertSame(1, $count);
        $ring = IndexedPost::whereTitle('The Ring')->first();
        $this->assertContains('professor', $this->termsFor($ring));
        $this->assertNotContains('tolkien', $this->termsFor($ring));
        // reindexRelated() must only touch rows pointing at the changed author.
        $this->assertSame($harryTermsBefore, $this->termsFor($harry));
        $this->assertSame($winterTermsBefore, $this->termsFor($winter));
    }

    public function test_reindex_related_dispatches_jobs_when_async(): void
    {
        config(['fuzzy-search.indexing.async' => true, 'fuzzy-search.indexing.queue' => 'indexing']);
        Queue::fake();
        $tolkien = Author::whereName('Tolkien')->first();

        $count = IndexedPost::reindexRelated('author_id', $tolkien->id);

        $this->assertSame(1, $count);
        Queue::assertPushed(IndexModelJob::class, 1);
        Queue::assertPushed(IndexModelJob::class, fn ($job) => $job->queue === 'indexing');
    }

    public function test_index_model_job_reloads_through_search_index_query(): void
    {
        $post = IndexedPost::whereTitle('Winter')->first();
        IndexedPost::$hookCalls = 0;

        (new IndexModelJob(IndexedPost::class, $post->id))->handle(app(IndexManager::class));

        $this->assertSame(1, IndexedPost::$hookCalls, 'single-row reindex must eager-load through searchIndexQuery()');
        $this->assertSame(['epic', 'martin', 'winter'], $this->termsFor($post));
    }

    public function test_indexing_an_empty_hook_result_clears_stale_postings(): void
    {
        $post = IndexedPost::whereTitle('The Ring')->first();
        app(IndexManager::class)->indexModel($post);
        $this->assertNotEmpty($this->termsFor($post));

        IndexedPost::$blank = true;
        app(IndexManager::class)->indexModel($post);

        $this->assertSame([], $this->termsFor($post));
    }

    public function test_searchable_text_collection_value_indexes_its_scalar_items(): void
    {
        IndexedPost::$textMode = 'collection';
        $post = IndexedPost::with('tags')->whereTitle('The Ring')->first();

        app(IndexManager::class)->indexModel($post);

        $this->assertSame(['epic', 'fantasy'], $this->termsFor($post));
    }

    public function test_bm25_results_eager_load_the_searched_relation_path(): void
    {
        app(IndexManager::class)->indexBatch(IndexedPost::with('author', 'tags')->get());

        // Built directly against IndexedPost::query() rather than IndexedPost::search():
        // the fixture's configured 'author_name' accessor column (getAuthorNameAttribute()
        // reads $this->author) would lazy-load and cache the author relation as a side
        // effect, masking whether BM25 hydration itself eager-loaded it.
        $results = (new SearchBuilder(IndexedPost::query(), app(FuzzySearch::class)))
            ->search('ring')->searchIn(['title', 'author.name'])
            ->useInvertedIndex()->highlight('em')->get();

        $first = $results->firstWhere('title', 'The Ring');
        $this->assertNotNull($first);
        $this->assertTrue($first->relationLoaded('author'), 'BM25 hydration must eager-load searchIn() relation paths');
        // "ring" matches the title, not the author's name, so author.name renders
        // untagged — but @fuzzyHighlight still needs the key present to render it at all.
        $this->assertArrayHasKey('author.name', $first->_highlighted);
        $this->assertSame('Tolkien', $first->_highlighted['author.name']);
    }

    public function test_searchable_text_non_scalar_value_throws(): void
    {
        IndexedPost::$textMode = 'invalid';
        $post = IndexedPost::whereTitle('The Ring')->first();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"bad"/');

        app(IndexManager::class)->indexModel($post);
    }
}

class IndexedPost extends Model
{
    use Searchable;

    public static int $hookCalls = 0;

    /** When true, searchableText() returns [] — simulates all indexable text disappearing. */
    public static bool $blank = false;

    /** null = default hook body; 'collection' / 'invalid' switch to the variants below. */
    public static ?string $textMode = null;

    protected $table   = 'posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = [
        'columns'    => ['title' => 10, 'author_name' => 5],
        'reindex_on' => ['author_id'],
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\Ashiqfardus\LaravelFuzzySearch\Tests\Tag::class, 'post_tag', 'post_id', 'tag_id');
    }

    public function getAuthorNameAttribute(): ?string
    {
        return $this->author?->name;
    }

    /** Everything the BM25 index should contain for this row. */
    public function searchableText(): array
    {
        if (static::$blank) {
            return [];
        }

        if (static::$textMode === 'collection') {
            // A Collection of scalars, left un-imploded — IndexManager must normalise it.
            return ['tags' => $this->tags->pluck('name')];
        }

        if (static::$textMode === 'invalid') {
            return ['bad' => new \stdClass()];
        }

        return [
            'title'  => $this->title,
            'author' => $this->author?->name,
            'tags'   => $this->tags->pluck('name')->implode(' '),
        ];
    }

    public function searchIndexQuery(Builder $query): Builder
    {
        static::$hookCalls++;
        return $query->with(['author', 'tags']);
    }
}
