<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Indexes its text under a name the hook gives, not a declared column. */
class SpellHookNote extends Model
{
    use Searchable;

    protected $table   = 'spell_hook_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function searchableText(): array
    {
        return ['body' => $this->title];
    }
}

/**
 * R12-L3. TA-1's suggestion filter compared the class as useInvertedIndex() was given it with the
 * canonical index type, so a class spelled with a leading backslash or in another letter case was
 * taken for a single-table-inheritance child searched through its parent's index, and offered only
 * its declared columns: suggest() and didYouMean() went empty for a searchableText() model while
 * its search still found the row. The class is now compared as PHP names it.
 */
class IndexSuggestionClassSpellingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('spell_hook_notes');
        Schema::create('spell_hook_notes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
        DB::table('spell_hook_notes')->insert(['title' => 'kitten mittens']);
        app(IndexManager::class)->indexBatch(SpellHookNote::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('spell_hook_notes');

        parent::tearDown();
    }

    public static function spellings(): array
    {
        return [
            'as declared'           => [SpellHookNote::class],
            'with a leading slash'  => ['\\' . SpellHookNote::class],
            'in lower case'         => [strtolower(SpellHookNote::class)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('spellings')]
    public function test_the_suggestions_do_not_depend_on_how_the_class_is_spelled(string $class): void
    {
        $search = fn (string $term) => SpellHookNote::search($term)->useInvertedIndex($class);

        $this->assertSame(['kitten mittens'], $search('kitten')->get()->pluck('title')->all());
        $this->assertSame(['kitten'], $search('kit')->suggestFrom('index')->suggest(5));
        $this->assertSame(['kitten'], array_column($search('kiten')->didYouMean(), 'term'));
    }
}
