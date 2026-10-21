<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
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
 * copy keeps the user's own words and synonyms and lists only expansions posted under a visible
 * column; matching still reads hidden columns (ER-66).
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

    /** ER-87 as amended: the user's own words and their synonyms are always listed; only expansions are filtered. */
    public function test_the_users_own_words_and_synonyms_are_always_listed(): void
    {
        $builder = DebugHiddenEmailUser::search('exampel')->useInvertedIndex();
        $builder->get();
        $terms = $builder->getDebugInfo()['index_terms'];

        $this->assertSame(1.0, $terms['exampel'] ?? null, 'the typed word, which no row holds');
        $this->assertArrayNotHasKey('example', $terms, 'the hidden-only typo expansion');

        // A synonym the caller declared is theirs too, even one no visible column holds.
        $builder = DebugHiddenEmailUser::search('sample')->useInvertedIndex()->typoTolerance(0)->withSynonyms(['sample' => ['example']]);
        $builder->get();
        $terms = $builder->getDebugInfo()['index_terms'];

        $this->assertSame(['sample', 'example'], array_map('strval', array_keys($terms)));
    }

    /**
     * TE-10. debugScore() on useInvertedIndex() named the LIKE path's 'fuzzy' as the algorithm, where
     * lastExecution() (the FuzzySearchExecuted event) says 'bm25': the explanation named a path that
     * did not run. Every index terminal now reports 'bm25'; column_scores stays empty, as BM25 scores
     * whole documents (README's Debug section).
     */
    public function test_debug_score_on_the_index_path_names_bm25(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        foreach (['john', 'jonh'] as $term) {
            $search = fn () => User::search($term)->useInvertedIndex()->debugScore();
            $runs   = [
                'get'            => fn ($builder) => $builder->get(),
                'paginate'       => fn ($builder) => collect($builder->paginate(5)->items()),
                'simplePaginate' => fn ($builder) => collect($builder->simplePaginate(5)->items()),
                'first'          => fn ($builder) => collect([$builder->first()]),
            ];
            foreach ($runs as $label => $run) {
                $builder = $search();
                $debug   = $run($builder)->first()->_debug;
                $this->assertSame('bm25', $debug['algorithm'], "{$term} {$label}");
                $this->assertSame([], $debug['column_scores'], "{$term} {$label}");
                if (in_array($label, ['get', 'paginate'], true)) {
                    $this->assertSame($builder->lastExecution()->algorithm, $debug['algorithm'], "{$term} {$label}");
                }
            }
        }
    }
}
