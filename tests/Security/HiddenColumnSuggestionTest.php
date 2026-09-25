<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class HiddenEmailUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];
    protected $hidden  = ['email'];

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5]];
}

class VisibleNameUser extends HiddenEmailUser
{
    protected $hidden  = [];
    protected $visible = ['id', 'name'];
}

/**
 * Index suggestions never offer a word from a column the model hides (ER-51, ER-63): the
 * dictionary lookups behind suggest() and didYouMean() skip postings of a column in $hidden or
 * left out of a non-empty $visible. "example" is in every seeded email and in no name.
 */
class HiddenColumnSuggestionTest extends TestCase
{
    /** @param class-string<Model> $class */
    private function offered(string $class): array
    {
        return [
            'suggest'    => $class::search('exa')->useInvertedIndex()->suggestFrom('index')->suggest(10),
            'didYouMean' => array_column($class::search('exampel')->didYouMean(), 'term'),
        ];
    }

    public static function hidingModels(): array
    {
        return ['$hidden' => [HiddenEmailUser::class], '$visible' => [VisibleNameUser::class]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hidingModels')]
    public function test_a_hidden_columns_words_are_not_offered_and_a_visible_columns_still_are(string $class): void
    {
        app(IndexManager::class)->indexBatch($class::all());

        $this->assertSame(['suggest' => [], 'didYouMean' => []], $this->offered($class));

        // The same word in a visible column is offered.
        $class::create(['name' => 'Example Person', 'email' => 'person@site.test']);
        app(IndexManager::class)->indexBatch($class::all());

        $this->assertSame(['suggest' => ['example'], 'didYouMean' => ['example']], $this->offered($class));
    }

    /**
     * Matching keeps hidden columns (ER-66): a search's rows are the same on every path, and the
     * LIKE path already matches a declared hidden column. Only the surfaces that hand words back
     * to the caller, suggest() and didYouMean(), leave them out.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hidingModels')]
    public function test_the_typo_and_prefix_expansions_still_match_a_hidden_column(string $class): void
    {
        app(IndexManager::class)->indexBatch($class::all());
        $all = $class::count();

        $this->assertCount($all, $class::search('exampel')->useInvertedIndex()->typoTolerance(2)->get());
        $this->assertCount($all, $class::search('exampl')->useInvertedIndex()->typoTolerance(0)->asYouType()->get());
        $this->assertCount($all, $class::search('exampel')->get()); // the LIKE path agrees

        $this->assertSame(['suggest' => [], 'didYouMean' => []], $this->offered($class));
    }

    public function test_legacy_postings_are_left_out_only_for_a_model_that_hides_a_searchable_column(): void
    {
        foreach ([HiddenEmailUser::class, User::class] as $class) {
            app(IndexManager::class)->indexBatch($class::all());
            // Postings written before 2.1: one per (term, document), with no column name.
            DB::table('fuzzy_index_postings')->where('model_type', $class)->where('column_name', '!=', 'name')->delete();
            DB::table('fuzzy_index_postings')->where('model_type', $class)->update(['column_name' => '']);
        }

        $this->assertNotContains('john', HiddenEmailUser::search('jo')->useInvertedIndex()->suggestFrom('index')->suggest(10));
        $this->assertContains('john', User::search('jo')->useInvertedIndex()->suggestFrom('index')->suggest(10));
    }
}
