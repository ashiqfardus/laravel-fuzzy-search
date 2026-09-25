<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

/** The README's getSearchScore() override: one row's score times 10. Every call is counted. */
class BoostedUser extends Model
{
    use Searchable;

    public static int $calls = 0;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'fuzzy',
    ];

    public function getSearchScore($baseScore): float
    {
        static::$calls++;

        return $this->name === 'Johnny Bravo' ? $baseScore * 10 : $baseScore;
    }
}

/** The same table without the trait. */
class ScoreHookPlainUser extends Model
{
    protected $table   = 'users';
    protected $guarded = [];
}

/**
 * Searchable::getSearchScore() was documented as an override point and never called. Every path
 * that computes _score in PHP now passes the row's score through it — the LIKE rescoring, the
 * extended path and the BM25 raw score — before normalisation, and ranks by the result.
 */
class SearchScoreHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BoostedUser::$calls = 0;
        app(IndexManager::class)->indexBatch(User::all());
        app(IndexManager::class)->indexBatch(BoostedUser::all());
    }

    /** @return array<string, \Closure(class-string): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder> */
    private function paths(): array
    {
        return [
            'like'     => fn (string $model) => $model::search('john'),
            'extended' => fn (string $model) => $model::search('')->extended('john'),
            'index'    => fn (string $model) => $model::search('john')->useInvertedIndex(),
        ];
    }

    public function test_the_boosted_row_ranks_first_on_every_path_and_terminal(): void
    {
        $wrong = [];

        foreach ($this->paths() as $path => $make) {
            $first = [
                'get'            => $make(BoostedUser::class)->get()->first()?->name,
                'first'          => $make(BoostedUser::class)->first()?->name,
                'paginate'       => $make(BoostedUser::class)->paginate(1)->items()[0]->name ?? null,
                'simplePaginate' => $make(BoostedUser::class)->simplePaginate(1)->items()[0]->name ?? null,
            ];

            foreach ($first as $terminal => $name) {
                if ($name !== 'Johnny Bravo') {
                    $wrong[] = "{$path} {$terminal}: " . json_encode($name);
                }
            }
        }

        $this->assertSame([], $wrong);
    }

    public function test_the_hook_scales_the_raw_score_before_normalisation(): void
    {
        foreach ($this->paths() as $path => $make) {
            $plain   = $make(User::class)->get()->pluck('_raw_score', 'name')->all();
            $boosted = $make(BoostedUser::class)->get();

            $this->assertSame('Johnny Bravo', $boosted->first()->name, $path);
            $this->assertEqualsWithDelta(1.0, $boosted->first()->_score, 1e-6, $path);

            foreach ($boosted as $row) {
                $expected = $row->name === 'Johnny Bravo' ? $plain[$row->name] * 10 : $plain[$row->name];
                $this->assertEqualsWithDelta($expected, $row->_raw_score, 0.1, "{$path} {$row->name}"); // _raw_score is rounded
            }
        }
    }

    public function test_the_hook_runs_once_per_rescored_row(): void
    {
        $results = BoostedUser::search('john')->get();

        $this->assertSame($results->count(), BoostedUser::$calls);
    }

    /** The trait's own getSearchScore() is the identity: a model that does not override it scores as a model without the trait. */
    public function test_the_default_implementation_leaves_scores_unchanged(): void
    {
        $withTrait = User::search('john')->get()->pluck('_raw_score', 'name')->all();
        $noTrait   = (new SearchBuilder(ScoreHookPlainUser::query(), app(FuzzySearch::class)))->search('john')->searchIn(['name' => 10, 'email' => 5])->using('fuzzy')->get()->pluck('_raw_score', 'name')->all();

        $this->assertSame($noTrait, $withTrait);
    }
}
