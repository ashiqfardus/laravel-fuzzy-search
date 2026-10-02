<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchResource;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PivotTag extends Model
{
    protected $table   = 'pivot_tags';
    protected $guarded = [];
    public $timestamps = false;
}

class PivotHiddenTag extends PivotTag
{
    protected $hidden = ['pivot'];
}

class PivotPost extends Model
{
    use Searchable;

    protected $table   = 'pivot_posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(PivotTag::class, 'pivot_post_tag', 'post_id', 'tag_id')->withPivot('note');
    }

    public function hiddenTags(): BelongsToMany
    {
        return $this->belongsToMany(PivotHiddenTag::class, 'pivot_post_tag', 'post_id', 'tag_id')->withPivot('note');
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(PivotTag::class, 'pivot_post_tag', 'post_id', 'tag_id')->withPivot('note')->as('membership');
    }
}

/**
 * TF-5. searchIn(['tags.note']) on a belongsToMany declared withPivot('note') matches through the
 * pivot table's join in SQL, but Eloquent moves pivot columns off the related model into its
 * `pivot` relation, so PHP read the leaf off the tag and found nothing: the column scored 0 at any
 * weight, was never highlighted, and suggest()'s table scan offered none of its words. The leaf is
 * now read from the loaded pivot when the related row has no such attribute; a pivot the related
 * model hides is still matched and never shown.
 */
class PivotColumnSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['pivot_posts', 'pivot_tags', 'pivot_post_tag'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('pivot_posts', fn ($table) => [$table->id(), $table->string('title')]);
        Schema::create('pivot_tags', fn ($table) => [$table->id(), $table->string('name')]);
        Schema::create('pivot_post_tag', fn ($table) => [
            $table->id(), $table->unsignedBigInteger('post_id'), $table->unsignedBigInteger('tag_id'), $table->string('note')->nullable(),
        ]);

        $alpha = DB::table('pivot_posts')->insertGetId(['title' => 'Alpha']);
        $beta  = DB::table('pivot_posts')->insertGetId(['title' => 'Beta']);
        DB::table('pivot_posts')->insert(['title' => 'Zephyrus notes']);
        $kestrel = DB::table('pivot_tags')->insertGetId(['name' => 'kestrel']);
        $osprey  = DB::table('pivot_tags')->insertGetId(['name' => 'osprey']);
        DB::table('pivot_post_tag')->insert([
            ['post_id' => $alpha, 'tag_id' => $kestrel, 'note' => 'zephyr pivot note'],
            ['post_id' => $beta, 'tag_id' => $osprey, 'note' => 'plain'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['pivot_posts', 'pivot_tags', 'pivot_post_tag'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_a_pivot_column_scores_and_is_highlighted_on_every_terminal(): void
    {
        $weights = ['title' => 1, 'tags.note' => 10];
        $paths   = [
            'get'            => fn () => PivotPost::search('zephyr')->searchIn($weights)->highlight()->get()->all(),
            'paginate'       => fn () => PivotPost::search('zephyr')->searchIn($weights)->highlight()->paginate(10)->items(),
            'simplePaginate' => fn () => PivotPost::search('zephyr')->searchIn($weights)->highlight()->simplePaginate(10)->items(),
            'extended'       => fn () => PivotPost::search('')->searchIn($weights)->extended('zephyr')->highlight()->get()->all(),
        ];

        foreach ($paths as $label => $rows) {
            $rows = $rows();
            // The weight-10 pivot match ranks above the weight-1 title match.
            $this->assertSame(['Alpha', 'Zephyrus notes'], array_map(fn ($row) => $row->title, $rows), $label);
            $this->assertSame('<em>zephyr</em> pivot note', $rows[0]->_highlighted['tags.note'], $label);
            $this->assertSame('tags.note', collect($rows[0]->_matches)->firstWhere('column', 'tags.note')['column'] ?? null, $label);
        }

        $first = PivotPost::search('zephyr')->searchIn($weights)->debugScore()->first();
        $this->assertSame('Alpha', $first->title);
        $this->assertGreaterThan(0, $first->_debug['column_scores']['tags.note']);

        // The pivot under another name (->as('membership')).
        $row = PivotPost::search('zephyr')->searchIn(['memberships.note'])->highlight()->get()->firstWhere('title', 'Alpha');
        $this->assertSame('<em>zephyr</em> pivot note', $row->_highlighted['memberships.note']);
    }

    public function test_suggest_and_the_resource_read_a_pivot_column(): void
    {
        // "zephyr" is only in the pivot note ("Zephyrus notes" is a title).
        $this->assertContains('zephyr', PivotPost::search('zep')->searchIn(['tags.note'])->suggestFrom('table')->suggest(5));

        $row = PivotPost::search('zephyr')->searchIn(['tags.note'])->highlight()->get()->firstWhere('title', 'Alpha');
        $this->assertSame('<em>zephyr</em> pivot note', FuzzySearchResource::make($row)->toArray(request())['_highlighted']['tags.note']);

        $this->assertSame('<mark>zephyr</mark> pivot note', \Ashiqfardus\LaravelFuzzySearch\SearchBuilder::renderHighlighted($row, 'tags.note'));

        // Hidden after the search: the resource leaves it out.
        $row->tags->each->makeHidden('pivot');
        $this->assertArrayNotHasKey('tags.note', FuzzySearchResource::make($row)->toArray(request())['_highlighted']);
    }

    public function test_a_pivot_the_related_model_hides_is_matched_and_never_shown(): void
    {
        $make = fn () => PivotPost::search('zephyr')->searchIn(['hiddenTags.note'])->highlight();

        $this->assertSame(2, $make()->count()); // Alpha through the pivot, "Zephyrus notes" by its title
        $row = $make()->get()->firstWhere('title', 'Alpha');
        $this->assertArrayNotHasKey('hiddenTags.note', $row->_highlighted);
        $this->assertNull(collect($row->_matches)->firstWhere('column', 'hiddenTags.note'));
        $this->assertNotContains('zephyr', PivotPost::search('zep')->searchIn(['hiddenTags.note'])->suggestFrom('table')->suggest(5));
    }
}
