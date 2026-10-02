<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RelSuggestAuthor extends Model
{
    protected $table   = 'rel_suggest_authors';
    protected $guarded = [];
    public $timestamps = false;
    protected $hidden  = ['email'];
}

class RelSuggestVisibleAuthor extends RelSuggestAuthor
{
    protected $hidden  = [];
    protected $visible = ['id', 'name'];
}

/** The docs' recipe: searchableText() puts the related column in the index, under its dotted name. */
abstract class RelSuggestBase extends Model
{
    use Searchable;

    protected $table   = 'rel_suggest_posts';
    protected $guarded = [];
    public $timestamps = false;
    protected array $searchable = ['columns' => ['title' => 10, 'author.email' => 5, 'author.name' => 5]];

    public function searchableText(): array
    {
        return ['title' => $this->title, 'author.email' => $this->author?->email, 'author.name' => $this->author?->name];
    }
}

class RelSuggestPost extends RelSuggestBase
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(RelSuggestAuthor::class, 'author_id');
    }
}

/** The related model's $visible leaves email out. Untyped: a declared path may name any relation method. */
class RelSuggestVisPost extends RelSuggestBase
{
    public function author()
    {
        return $this->belongsTo(RelSuggestVisibleAuthor::class, 'author_id');
    }
}

/** The parent hides the relation. */
class RelSuggestHidingPost extends RelSuggestPost
{
    protected $hidden = ['author'];
}

/** The parent's $visible shows the relation, whose model shows name and hides email. */
class RelSuggestListingPost extends RelSuggestPost
{
    protected $visible = ['id', 'title', 'author'];
}

/** The relation typed in another case: hidden by its declared name all the same. */
class RelSuggestCasePost extends RelSuggestPost
{
    protected $hidden = ['author'];
    protected array $searchable = ['columns' => ['title' => 10, 'Author.email' => 5, 'AUTHOR.name' => 5]];

    public function searchableText(): array
    {
        return ['title' => $this->title, 'Author.email' => $this->author?->email, 'AUTHOR.name' => $this->author?->name];
    }
}

/** A morphed-to model that hides its title. */
class RelSuggestVideo extends Model
{
    protected $table   = 'rel_suggest_videos';
    protected $guarded = [];
    public $timestamps = false;
    protected $hidden  = ['title'];
}

/** A morphTo path, indexed by the docs' recipe. */
class RelSuggestComment extends Model
{
    use Searchable;

    protected $table   = 'rel_suggest_comments';
    protected $guarded = [];
    public $timestamps = false;
    protected array $searchable = ['columns' => ['body' => 10, 'commentable.title' => 5]];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function searchableText(): array
    {
        return ['body' => $this->body, 'commentable.title' => $this->commentable?->title];
    }
}

/** Keeps its pivot out of toArray(), the usual way to keep pivot data out of JSON. */
class RelSuggestTag extends Model
{
    protected $table   = 'rel_suggest_tags';
    protected $guarded = [];
    public $timestamps = false;
    protected $hidden  = ['pivot'];
}

class RelSuggestShownTag extends RelSuggestTag
{
    protected $hidden = [];
}

/** A pivot model that hides the note itself. */
class RelSuggestNotePivot extends Pivot
{
    protected $hidden = ['note'];
}

/** A belongsToMany pivot column, indexed by the docs' recipe under its declared dotted name. */
class RelSuggestPivotPost extends Model
{
    use Searchable;

    protected $table   = 'rel_suggest_posts';
    protected $guarded = [];
    public $timestamps = false;
    protected array $searchable = ['columns' => ['title' => 10, 'tags.note' => 5]];

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(RelSuggestTag::class, 'rel_suggest_post_tag', 'post_id', 'tag_id')->withPivot('note');
    }

    public function searchableText(): array
    {
        return ['title' => $this->title, 'tags.note' => $this->tags->pluck('pivot.note')->implode(' ')];
    }
}

/** The related model shows its pivot: the note is in toArray(). */
class RelSuggestShownPivotPost extends RelSuggestPivotPost
{
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(RelSuggestShownTag::class, 'rel_suggest_post_tag', 'post_id', 'tag_id')->withPivot('note');
    }
}

/** The related model shows its pivot, whose model hides the note. */
class RelSuggestHiddenNotePost extends RelSuggestPivotPost
{
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(RelSuggestShownTag::class, 'rel_suggest_post_tag', 'post_id', 'tag_id')->using(RelSuggestNotePivot::class)->withPivot('note');
    }
}

/**
 * SF-5. A relation column indexed through searchableText() is posted under its dotted name, which
 * the model itself never hides, so dictionary suggest() and didYouMean() offered the words of a
 * column the related model hides ($hidden, or $visible without it) or of a relation the model
 * hides. They now leave such a column out, by the walk suggest()'s table scan already makes: each
 * relation on the model that holds it, then the leaf on the related model. A relation column the
 * models show is still offered.
 */
class HiddenRelationColumnSuggestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('rel_suggest_posts');
        Schema::dropIfExists('rel_suggest_authors');
        Schema::create('rel_suggest_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });
        Schema::create('rel_suggest_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('author_id')->nullable();
        });

        $author = DB::table('rel_suggest_authors')->insertGetId(['name' => 'Zanzibar Quill', 'email' => 'quentin.private@corp.example']);
        DB::table('rel_suggest_posts')->insert([['title' => 'Alpha post', 'author_id' => $author], ['title' => 'Quiet morning', 'author_id' => null]]);
    }

    protected function tearDown(): void
    {
        foreach (['rel_suggest_posts', 'rel_suggest_authors', 'rel_suggest_videos', 'rel_suggest_comments', 'rel_suggest_tags', 'rel_suggest_post_tag'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /** @param class-string<RelSuggestBase> $class */
    private function offered(string $class): array
    {
        app(IndexManager::class)->indexBatch($class::all());

        return [
            'suggest qu'      => $class::search('qu')->suggest(10),
            'index qu'        => $class::search('qu')->useInvertedIndex()->suggestFrom('index')->suggest(10),
            'suggest za'      => $class::search('za')->suggest(10),
            'didYouMean'      => array_column($class::search('quentn')->didYouMean(), 'term'),
            'didYouMean name' => array_column($class::search('zanzibr')->didYouMean(), 'term'),
            'debug terms'     => (function () use ($class) {
                $search = $class::search('quentn zanzibr')->useInvertedIndex();
                $search->get();

                return array_map('strval', array_keys($search->getDebugInfo()['index_terms']));
            })(),
        ];
    }

    public static function models(): array
    {
        return [
            'the related model hides the leaf ($hidden)' => [RelSuggestPost::class, ['quentin'], ['zanzibar']],
            'the related model leaves it out of $visible' => [RelSuggestVisPost::class, ['quentin'], ['zanzibar']],
            'the model hides the relation'                => [RelSuggestHidingPost::class, ['quentin', 'zanzibar'], []],
            'the relation typed in another case'          => [RelSuggestCasePost::class, ['quentin', 'zanzibar'], []],
            'the model lists the relation in $visible'    => [RelSuggestListingPost::class, ['quentin'], ['zanzibar']],
        ];
    }

    /**
     * @param list<string> $hidden words never offered
     * @param list<string> $shown  words still offered
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_a_hidden_relation_columns_words_are_not_offered(string $class, array $hidden, array $shown): void
    {
        $offered = $this->offered($class);
        $all     = array_merge(...array_values($offered));

        foreach ($hidden as $word) {
            $this->assertNotContains($word, $all, json_encode($offered));
        }
        foreach ($shown as $word) {
            $this->assertContains($word, $offered['suggest za'], json_encode($offered));
            $this->assertContains($word, $offered['didYouMean name'], json_encode($offered));
            $this->assertContains($word, $offered['debug terms'], json_encode($offered));
        }
        $this->assertContains('quiet', $offered['suggest qu']); // the model's own column
        $this->assertContains('quiet', $offered['index qu']);

        // Matching keeps every column (ER-66): the hidden word still finds its row.
        $this->assertSame(['Alpha post'], $class::search('quentin')->useInvertedIndex()->get()->pluck('title')->all());
    }

    /**
     * TF-1. A morphTo path was judged on the related model of a fresh instance's relation, which for a
     * morphTo with no type is the model itself: `commentable.title` was judged as the comment's own
     * title, never the video's, so a title the video hides was offered. The morphed-to class is the
     * row's own, unknown to the dictionary, and docs/relationships.md calls morphTo paths
     * unsupported: such a column's words are not offered.
     */
    public function test_a_morph_to_paths_words_are_not_offered(): void
    {
        foreach (['rel_suggest_videos', 'rel_suggest_comments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('rel_suggest_videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
        Schema::create('rel_suggest_comments', function (Blueprint $table) {
            $table->id();
            $table->string('body');
            $table->string('commentable_type')->nullable();
            $table->unsignedBigInteger('commentable_id')->nullable();
        });
        $video = DB::table('rel_suggest_videos')->insertGetId(['title' => 'Quokka unlisted video']);
        DB::table('rel_suggest_comments')->insert(['body' => 'quaint remark', 'commentable_type' => RelSuggestVideo::class, 'commentable_id' => $video]);

        app(IndexManager::class)->indexBatch(RelSuggestComment::all());
        $search = RelSuggestComment::search('quokkka unlistd')->useInvertedIndex();
        $search->get();
        $offered = [
            'index qu'    => RelSuggestComment::search('qu')->suggestFrom('index')->suggest(10),
            'didYouMean'  => array_column(RelSuggestComment::search('quokkka')->didYouMean(), 'term'),
            'debug terms' => array_map('strval', array_keys($search->getDebugInfo()['index_terms'])),
        ];
        $all = array_merge(...array_values($offered));

        foreach (['quokka', 'unlisted'] as $word) {
            $this->assertNotContains($word, $all, json_encode($offered));
        }
        $this->assertSame(['quaint'], $offered['index qu'], 'the model\'s own column'); // the morphed title's "quokka" left out
        $this->assertSame(['quaint remark'], RelSuggestComment::search('quokka')->useInvertedIndex()->get()->pluck('body')->all(), 'matching keeps it (ER-66)');
    }

    public static function pivotPosts(): array
    {
        return [
            'the related model hides its pivot' => [RelSuggestPivotPost::class, false],
            'the pivot model hides the column'  => [RelSuggestHiddenNotePost::class, false],
            'both show it'                      => [RelSuggestShownPivotPost::class, true],
        ];
    }

    /**
     * TF-10. A belongsToMany pivot column (`tags.note`, withPivot('note')) was judged as the leaf on
     * the related model, which has no such attribute: with the tag hiding its `pivot`, toArray() never
     * shows the note, yet its words were offered. It is now judged as toArray() serialises it: the
     * related model's pivot accessor, then the column on the pivot model.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pivotPosts')]
    public function test_a_pivot_columns_words_are_offered_only_where_the_pivot_shows_them(string $class, bool $shown): void
    {
        foreach (['rel_suggest_tags', 'rel_suggest_post_tag'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('rel_suggest_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('rel_suggest_post_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('note')->nullable();
        });
        $post = DB::table('rel_suggest_posts')->where('title', 'Alpha post')->value('id');
        $tag  = DB::table('rel_suggest_tags')->insertGetId(['name' => 'kestrel']);
        DB::table('rel_suggest_post_tag')->insert(['post_id' => $post, 'tag_id' => $tag, 'note' => 'zephyr internal']);

        app(IndexManager::class)->indexBatch($class::all());
        $search = $class::search('zephir internl')->useInvertedIndex();
        $search->get();
        $offered = [
            'index ze'    => $class::search('ze')->suggestFrom('index')->suggest(10),
            'didYouMean'  => array_column($class::search('zephir')->didYouMean(), 'term'),
            'debug terms' => array_map('strval', array_keys($search->getDebugInfo()['index_terms'])),
        ];
        $serialised = json_encode($class::with('tags')->find($post)->toArray());

        $this->assertSame($shown, str_contains($serialised, 'zephyr'), $serialised);
        foreach ([['index ze', 'zephyr'], ['didYouMean', 'zephyr'], ['debug terms', 'internal']] as [$path, $word]) {
            $this->assertSame($shown, in_array($word, $offered[$path], true), $path . ': ' . json_encode($offered));
        }
        $this->assertSame(['Alpha post'], $class::search('zephyr')->useInvertedIndex()->get()->pluck('title')->all(), 'matching keeps it (ER-66)');
    }
}
