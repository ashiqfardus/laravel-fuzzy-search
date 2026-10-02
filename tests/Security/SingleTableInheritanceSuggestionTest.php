<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A single-table-inheritance parent whose children share its index (searchIndexType()). */
class StiSuggestParent extends Model
{
    use Searchable;

    protected $table   = 'sti_suggest_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = match ($attributes['kind'] ?? '') {
            'child'   => StiSuggestChild::class,
            'sibling' => StiSuggestSibling::class,
            default   => static::class,
        };
        $model = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

/** Declares a searchable column of its own and hides it. */
class StiSuggestChild extends StiSuggestParent
{
    protected $hidden = ['secret'];

    protected array $searchable = ['columns' => ['title' => 1, 'secret' => 1]];

    protected static function booted(): void
    {
        static::addGlobalScope('kind', fn ($query) => $query->where('kind', 'child'));
    }

    public static function searchIndexType(): string
    {
        return StiSuggestParent::class;
    }
}

/** Declares a column the child knows nothing of. */
class StiSuggestSibling extends StiSuggestParent
{
    protected array $searchable = ['columns' => ['title' => 1, 'note' => 1]];

    protected static function booted(): void
    {
        static::addGlobalScope('kind', fn ($query) => $query->where('kind', 'sibling'));
    }

    public static function searchIndexType(): string
    {
        return StiSuggestParent::class;
    }
}

/**
 * TA-1. Under searchIndexType(), dictionary suggest(), didYouMean() and getDebugInfo()['index_terms']
 * judged hidden columns on the index type, the parent: a searchable column a child hides, and a
 * column only a sibling declares, were offered from a child's search, which its table scan and its
 * rows never show. They are now judged on the searched class, and a class searched through another
 * type's index is offered only the columns it declares and shows. The postings stay the type's.
 */
class SingleTableInheritanceSuggestionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sti_suggest_items');
        Schema::create('sti_suggest_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('secret')->nullable();
            $table->string('note')->nullable();
            $table->string('kind', 16);
        });
        DB::table('sti_suggest_items')->insert([
            ['title' => 'alpha one', 'secret' => 'quixotic', 'note' => null, 'kind' => 'child'],
            ['title' => 'alpha two', 'secret' => null, 'note' => null, 'kind' => 'parent'],
            ['title' => 'alpha three', 'secret' => 'quixotry', 'note' => null, 'kind' => 'child'],
            ['title' => 'alpha four', 'secret' => null, 'note' => 'zephyrine', 'kind' => 'sibling'],
        ]);

        $this->artisan('fuzzy-search:rebuild', ['model' => StiSuggestParent::class, '--fresh' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sti_suggest_items');

        parent::tearDown();
    }

    public function test_a_childs_suggestions_leave_out_the_columns_it_hides_or_does_not_declare(): void
    {
        $this->assertSame(['StiSuggestParent' => 4], DB::table('fuzzy_index_documents')->pluck('model_type')->map(fn ($t) => class_basename($t))->countBy()->all());

        $debug = function (string $term) {
            $search = StiSuggestChild::search($term)->useInvertedIndex()->typoTolerance(2);
            $search->get();

            return array_map('strval', array_keys($search->getDebugInfo()['index_terms']));
        };
        $offered = [
            'suggest qui'     => StiSuggestChild::search('qui')->suggest(10),
            'index qui'       => StiSuggestChild::search('qui')->suggestFrom('index')->suggest(10),
            'didYouMean'      => array_column(StiSuggestChild::search('quixotec')->didYouMean(), 'term'),
            'debug terms'     => $debug('quixotec'),
            'suggest zep'     => StiSuggestChild::search('zep')->suggestFrom('index')->suggest(10),
            'debug terms zep' => $debug('zephyrin'),
        ];
        $all = array_merge(...array_values($offered));

        foreach (['quixotic', 'quixotry', 'zephyrine'] as $word) {
            $this->assertNotContains($word, $all, json_encode($offered));
        }
        $this->assertSame([], StiSuggestChild::search('qui')->suggestFrom('table')->suggest(10), 'the table scan offers none either');

        // The child still gets the words of the columns it declares and shows, from the parent's index.
        $this->assertSame(['alpha'], StiSuggestChild::search('alp')->suggestFrom('index')->suggest(10));
        $this->assertSame(['three'], array_column(StiSuggestChild::search('thre')->didYouMean(), 'term'));

        // Matching keeps every column (ER-66): the hidden word still finds its row.
        $this->assertSame(['alpha one'], StiSuggestChild::search('quixotic')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all());
        // And the sibling, which declares and shows its note, is offered it.
        $this->assertSame(['zephyrine'], StiSuggestSibling::search('zep')->suggestFrom('index')->suggest(10));
    }
}
