<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A column qualified with the model's own table, as a model searched under joins declares it. */
class QualifiedColumnPost extends Model
{
    use Searchable;

    protected $table   = 'qualified_posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['qualified_posts.title' => 10, 'body' => 1]];
}

/**
 * VC-5. relationships.md: a name whose first segment is no method of the model "stays a
 * table-qualified column". The LIKE path searched it, but the indexer read `qualified_posts.title`
 * as an attribute, which no model has: no posting and no document were written, the index search,
 * suggest() from the index and didYouMean() found nothing, and a rebuild reported nothing wrong.
 * It is read as the model's own column now, and posted under the name declared, so its weight and
 * its visibility apply as declared.
 */
class IndexQualifiedColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('qualified_posts');
        Schema::create('qualified_posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('body')->nullable();
        });
        DB::table('qualified_posts')->insert([
            ['title' => 'Quartz river', 'body' => 'stones'],
            ['title' => 'Granite hill', 'body' => 'zebra crossing'],
            ['title' => 'Zebra meadow', 'body' => 'grass'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('qualified_posts');

        parent::tearDown();
    }

    private function titles($rows): array
    {
        return collect($rows)->pluck('title')->all();
    }

    public function test_a_column_qualified_with_the_models_table_is_indexed(): void
    {
        $this->artisan('fuzzy-search:rebuild', ['model' => QualifiedColumnPost::class, '--fresh' => true])
            ->expectsOutputToContain('Done. Indexed 3 of 3 records.')
            ->assertExitCode(0);

        $search = fn (string $term) => QualifiedColumnPost::search($term)->useInvertedIndex()->typoTolerance(0);
        $this->assertSame(['Quartz river'], $this->titles($search('quartz')->get()));
        $this->assertSame($this->titles(QualifiedColumnPost::search('quartz')->get()), $this->titles($search('quartz')->get()), 'as the LIKE path finds it');
        $this->assertSame(1, $search('quartz')->count());
        $this->assertSame(['Zebra meadow', 'Granite hill'], $this->titles($search('zebra')->get()), 'the title weighs 10, the body 1');

        $this->assertContains('quartz', QualifiedColumnPost::search('quar')->suggestFrom('index')->suggest());
        $this->assertContains('quartz', array_column(QualifiedColumnPost::search('quarz')->didYouMean(), 'term'));

        // A save through the observer writes it too.
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $post = QualifiedColumnPost::create(['title' => 'Quill valley']);
        $this->assertSame(['Quill valley'], $this->titles($search('quill')->get()));
        $post->update(['title' => 'Quiet valley']);
        $this->assertSame(['Quiet valley'], $this->titles($search('quiet')->get()));
        $this->assertSame([], $this->titles($search('quill')->get()));
    }
}
