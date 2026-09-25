<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

class DebugHiddenEmailUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];
    protected $hidden  = ['email'];

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5]];
}

/**
 * F4 (ruling ER-87). getDebugInfo()['index_terms'] listed every term the index query ran, so a
 * typo or prefix expansion taken from a hidden column showed its word ("exampel" gave "example",
 * a word only the hidden email holds) while didYouMean() and suggest() leave it out. The debug
 * copy lists only terms posted under a visible column; matching still reads hidden columns (ER-66).
 */
class DebugIndexTermsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(IndexManager::class)->indexBatch(DebugHiddenEmailUser::all());
    }

    public function test_index_terms_lists_only_words_posted_under_a_visible_column(): void
    {
        $searches = [
            'typo'       => fn () => DebugHiddenEmailUser::search('exampel')->useInvertedIndex(),
            'asYouType'  => fn () => DebugHiddenEmailUser::search('examp')->useInvertedIndex()->asYouType(),
        ];

        foreach ($searches as $label => $make) {
            foreach (['get' => fn ($b) => $b->get(), 'paginate' => fn ($b) => collect($b->paginate()->items())] as $terminal => $run) {
                $builder = $make();
                $rows    = $run($builder);

                $this->assertCount(7, $rows, "{$label} {$terminal}: matching still reads the hidden column");
                $this->assertArrayNotHasKey('example', $builder->getDebugInfo()['index_terms'], "{$label} {$terminal}");
            }
        }

        // An expansion a visible column holds is listed.
        $builder = DebugHiddenEmailUser::search('jonh')->useInvertedIndex();
        $builder->get();
        $this->assertArrayHasKey('john', $builder->getDebugInfo()['index_terms']);
    }
}
