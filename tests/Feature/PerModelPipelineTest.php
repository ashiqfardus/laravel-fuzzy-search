<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CjkDoc extends Model
{
    use Searchable;
    protected $table = 'cjk_docs';
    protected $guarded = [];
    public $timestamps = false;
    protected array $searchable = [
        'columns'   => ['title' => 10],
        'tokenizer' => ScriptAwareTokenizer::class,
    ];
}

class FrenchDoc extends Model
{
    use Searchable;
    protected $table = 'cjk_docs';
    protected $guarded = [];
    public $timestamps = false;
    protected array $searchable = [
        'columns'          => ['title' => 10],
        'stemmer'          => PorterStemmer::class,
        'stemmer_language' => 'French',
        'locale'           => 'fr',
    ];
}

class PerModelPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('cjk_docs', function ($table) {
            $table->id();
            $table->string('title');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cjk_docs');
        parent::tearDown();
    }

    public function test_a_model_can_opt_into_the_script_aware_tokenizer_while_others_keep_whitespace(): void
    {
        $doc = CjkDoc::create(['title' => '東京タワー tour']);
        app(IndexManager::class)->indexModel($doc);
        app(IndexManager::class)->indexBatch(User::all());

        $terms = DB::table('fuzzy_index_terms')->join('fuzzy_index_postings', 'fuzzy_index_terms.id', '=', 'fuzzy_index_postings.term_id')
            ->where('model_type', CjkDoc::class)->pluck('term')->sort()->values()->all();
        $this->assertSame(['tour', 'タワ', 'ワー', '京タ', '東京'], $terms);

        $this->assertSame([$doc->id], CjkDoc::search('東京')->useInvertedIndex()->get()->pluck('id')->all());
        $this->assertSame([], CjkDoc::search('大阪')->useInvertedIndex()->get()->pluck('id')->all());

        // Users still tokenize on whitespace: "John Doe" is two whole words, not bigrams.
        $userTerms = DB::table('fuzzy_index_terms')->join('fuzzy_index_postings', 'fuzzy_index_terms.id', '=', 'fuzzy_index_postings.term_id')
            ->where('model_type', User::class)->pluck('term')->all();
        $this->assertContains('john', $userTerms);
        $this->assertNotContains('jo', $userTerms);
    }

    public function test_stemmer_language_and_locale_come_from_the_model(): void
    {
        $doc = FrenchDoc::create(['title' => 'les chevaux mangent']);
        app(IndexManager::class)->indexModel($doc);

        $terms = DB::table('fuzzy_index_terms')->join('fuzzy_index_postings', 'fuzzy_index_terms.id', '=', 'fuzzy_index_postings.term_id')
            ->where('model_type', FrenchDoc::class)->pluck('term')->sort()->values()->all();

        $this->assertNotContains('les', $terms);                 // fr stop word
        // Snowball French: chevaux -> cheval as expected; mangent stays mangent (the library's
        // actual output, verified directly against Wamania\Snowball\French — not "mang").
        $this->assertSame(['cheval', 'mangent'], $terms);
        $this->assertSame([$doc->id], FrenchDoc::search('cheval')->useInvertedIndex()->get()->pluck('id')->all());
    }

    public function test_pipelines_are_cached_per_class_and_resettable(): void
    {
        $manager = app(IndexManager::class);

        $this->assertSame($manager->pipelineFor(CjkDoc::class), $manager->pipelineFor(CjkDoc::class));
        $this->assertNotSame($manager->pipelineFor(CjkDoc::class), $manager->pipelineFor(User::class));

        IndexManager::resetPipelineCache();
        $this->assertInstanceOf(ScriptAwareTokenizer::class, $manager->pipelineFor(CjkDoc::class)->tokenizer());
    }

    public function test_an_unknown_tokenizer_class_is_rejected_with_the_model_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(CjkDoc::class);

        config(['fuzzy-search.indexing.tokenizer' => ScriptAwareTokenizer::class]); // irrelevant to the model override
        $bad = new class extends CjkDoc { protected array $searchable = ['columns' => ['title' => 1], 'tokenizer' => 'Nope\\Tokenizer']; };
        app(IndexManager::class)->pipelineFor($bad::class);
    }
}
