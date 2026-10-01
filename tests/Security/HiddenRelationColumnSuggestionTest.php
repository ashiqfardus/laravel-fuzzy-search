<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        Schema::dropIfExists('rel_suggest_posts');
        Schema::dropIfExists('rel_suggest_authors');

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
}
