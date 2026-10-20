<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\ListColumnsUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\ZeroConfigUser;
use Ashiqfardus\LaravelFuzzySearch\Traits\Fuzzy;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

/** Declares its own typo tolerance, and nothing else but its column. */
class TypoReachDeclaredUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'        => ['name' => 10],
        'typo_tolerance' => 1,
    ];
}

/** No Searchable trait: FederatedSearch searches it through the Fuzzy trait's own algorithm. */
class TypoReachFuzzyOnlyUser extends Model
{
    use Fuzzy;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $fuzzySearchable = ['name'];
    protected string $fuzzyAlgorithm = 'fuzzy';
}

/**
 * ER-204: one effective typo distance on every path. typo_tolerance.max_distance is the
 * builder's default (LIKE fuzzy, the index and extended ~ alike, for declared and zero-config
 * models); an explicit typoTolerance() or $searchable['typo_tolerance'] wins over it;
 * levenshtein.max_distance stays that algorithm's own key; and typo_tolerance.enabled = false
 * stops every typo match, on the fuzzy and levenshtein algorithms too.
 *
 * No row holds "jonh" (name or email), so with no typo tolerance every path finds nothing; one
 * edit away it finds "Jon Snow" on every path (the index's dictionary holds "jon").
 */
class TypoToleranceConfigReachTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([User::class, ZeroConfigUser::class, ListColumnsUser::class, TypoReachDeclaredUser::class] as $model) {
            app(IndexManager::class)->indexBatch($model::all());
        }
    }

    /** @return array<string, \Closure(): SearchBuilder> */
    private function searches(string $term = 'jonh'): array
    {
        return [
            'fuzzy, declared'                    => fn () => User::search($term),
            'fuzzy, declared without tolerance'  => fn () => ListColumnsUser::search($term),
            'fuzzy, zero-config'                 => fn () => ZeroConfigUser::search($term),
            'levenshtein'                        => fn () => User::search($term)->using('levenshtein'),
            'levenshtein, zero-config'           => fn () => ZeroConfigUser::search($term)->using('levenshtein'),
            'extended ~, declared'               => fn () => User::search($term)->extended('~' . $term),
            'extended ~, zero-config'            => fn () => ZeroConfigUser::search($term)->extended('~' . $term),
            'index, declared'                    => fn () => User::search($term)->useInvertedIndex(),
            'index, declared without tolerance'  => fn () => ListColumnsUser::search($term)->useInvertedIndex(),
            'index, zero-config'                 => fn () => ZeroConfigUser::search($term)->useInvertedIndex(),
        ];
    }

    /**
     * The names each terminal returns: get, first, paginate (rows and total), simplePaginate,
     * count, and getFacets (which runs the LIKE path; on the index path it is left out).
     *
     * @param \Closure(): SearchBuilder $search
     */
    private function terminals(\Closure $search, bool $index): array
    {
        $names = fn (iterable $rows) => collect($rows)->pluck('name')->sort()->values()->all();

        $out = [
            'get'            => $names($search()->get()),
            'first'          => $search()->first()?->name,
            'paginate'       => $names($search()->paginate(50)->items()),
            'paginate total' => $search()->paginate(50)->total(),
            'simplePaginate' => $names($search()->simplePaginate(50)->items()),
            'count'          => $search()->count(),
        ];

        if (!$index) {
            $out['getFacets'] = array_sum($search()->facet('name')->getFacets()['name']);
        }

        return $out;
    }

    private function assertNoMatchAnywhere(\Closure $search, string $label): void
    {
        $expected = ['get' => [], 'first' => null, 'paginate' => [], 'paginate total' => 0, 'simplePaginate' => [], 'count' => 0];
        $index    = str_starts_with($label, 'index');

        if (!$index) {
            $expected['getFacets'] = 0;
        }

        $this->assertSame($expected, $this->terminals($search, $index), $label);
    }

    private function assertFindsJonSnowEverywhere(\Closure $search, string $label): void
    {
        $out = $this->terminals($search, str_starts_with($label, 'index'));

        foreach (['get', 'paginate', 'simplePaginate'] as $terminal) {
            $this->assertContains('Jon Snow', $out[$terminal], "{$label}: {$terminal}");
        }
        $this->assertNotNull($out['first'], "{$label}: first");
        $this->assertSame(count($out['get']), $out['paginate total'], "{$label}: paginate total");
        $this->assertSame(count($out['get']), $out['count'], "{$label}: count");
        if (isset($out['getFacets'])) {
            $this->assertSame(count($out['get']), $out['getFacets'], "{$label}: getFacets");
        }
    }

    public function test_enabled_false_stops_typo_matches_on_every_path(): void
    {
        config(['fuzzy-search.typo_tolerance.enabled' => false]);

        foreach ($this->searches() as $label => $search) {
            $this->assertNoMatchAnywhere($search, $label);
        }
    }

    public function test_enabled_false_beats_an_explicit_tolerance_and_the_models_own(): void
    {
        config(['fuzzy-search.typo_tolerance.enabled' => false]);

        $this->assertNoMatchAnywhere(fn () => User::search('jonh')->typoTolerance(2), 'typoTolerance(2)');
        $this->assertNoMatchAnywhere(fn () => User::search('jonh')->extended('~jonh')->typoTolerance(2), 'extended ~, typoTolerance(2)');
        $this->assertNoMatchAnywhere(fn () => User::search('jonh')->useInvertedIndex()->typoTolerance(2), 'index, typoTolerance(2)');
        $this->assertNoMatchAnywhere(fn () => TypoReachDeclaredUser::search('jonh'), "\$searchable['typo_tolerance']");
        $this->assertNoMatchAnywhere(fn () => TypoReachDeclaredUser::search('jonh')->useInvertedIndex(), "index, \$searchable['typo_tolerance']");
    }

    public function test_enabled_false_beats_levenshtein_max_distance(): void
    {
        config(['fuzzy-search.typo_tolerance.enabled' => false, 'fuzzy-search.levenshtein.max_distance' => 3]);

        $this->assertNoMatchAnywhere(fn () => User::search('jonh')->using('levenshtein'), 'levenshtein');

        config(['fuzzy-search.default_algorithm' => 'levenshtein']);
        $this->assertNoMatchAnywhere(fn () => ZeroConfigUser::search('jonh'), 'zero-config, default levenshtein');
    }

    public function test_enabled_false_reaches_the_query_builder_helpers_and_federated_search(): void
    {
        config(['fuzzy-search.typo_tolerance.enabled' => false]);

        $this->assertSame([], User::query()->whereFuzzy('name', 'jonh')->pluck('name')->all(), 'whereFuzzy');
        $this->assertSame([], User::query()->whereFuzzy('name', 'jonh', 'levenshtein', ['max_distance' => 3])->pluck('name')->all(), 'whereFuzzy levenshtein');
        $this->assertSame([], User::query()->whereFuzzyMultiple(['name', 'email'], 'jonh', 'fuzzy')->pluck('name')->all(), 'whereFuzzyMultiple');
        $this->assertSame([], User::query()->fuzzy('jonh')->pluck('name')->all(), 'Fuzzy scope (levenshtein, max_distance 3)');
        $this->assertSame([], User::query()->fuzzyLevenshtein('jonh', null, 2)->pluck('name')->all(), 'fuzzyLevenshtein');

        $federated = fn () => FederatedSearch::across([User::class, TypoReachFuzzyOnlyUser::class])->search('jonh');
        $this->assertSame([], $federated()->get()->pluck('name')->all(), 'federated get');
        $this->assertSame(0, $federated()->paginate(50)->total(), 'federated paginate');
        $this->assertSame([], $federated()->typoTolerance(2)->get()->pluck('name')->all(), 'federated typoTolerance(2)');
        $this->assertSame([], $federated()->useInvertedIndex()->get()->pluck('name')->all(), 'federated index');
    }

    public function test_max_distance_is_the_default_distance_on_every_path(): void
    {
        // levenshtein keeps its own key: set it to 0 too, so every row of searches() reads 0.
        config(['fuzzy-search.typo_tolerance.max_distance' => 0, 'fuzzy-search.levenshtein.max_distance' => 0]);

        foreach ($this->searches() as $label => $search) {
            $this->assertNoMatchAnywhere($search, $label);
        }

        config(['fuzzy-search.typo_tolerance.max_distance' => 1, 'fuzzy-search.levenshtein.max_distance' => 1]);

        foreach ($this->searches() as $label => $search) {
            $this->assertFindsJonSnowEverywhere($search, $label);
        }
    }

    public function test_an_explicit_tolerance_beats_max_distance(): void
    {
        config(['fuzzy-search.typo_tolerance.max_distance' => 0]);

        $explicit = [
            'typoTolerance(1)'                       => fn () => User::search('jonh')->typoTolerance(1),
            'typoTolerance(1), zero-config'          => fn () => ZeroConfigUser::search('jonh')->typoTolerance(1),
            'extended ~, typoTolerance(1)'           => fn () => User::search('jonh')->extended('~jonh')->typoTolerance(1),
            'index, typoTolerance(1)'                => fn () => User::search('jonh')->useInvertedIndex()->typoTolerance(1),
            'index, typoTolerance(1), zero-config'   => fn () => ZeroConfigUser::search('jonh')->useInvertedIndex()->typoTolerance(1),
            "\$searchable['typo_tolerance']"         => fn () => TypoReachDeclaredUser::search('jonh'),
            "extended ~, \$searchable['typo_tolerance']" => fn () => TypoReachDeclaredUser::search('jonh')->extended('~jonh'),
            "index, \$searchable['typo_tolerance']"  => fn () => TypoReachDeclaredUser::search('jonh')->useInvertedIndex(),
        ];

        foreach ($explicit as $label => $search) {
            $this->assertFindsJonSnowEverywhere($search, $label);
        }

        $this->assertSame(1, User::search('jonh')->typoTolerance(1)->getDebugInfo()['typo_tolerance']);
        $this->assertSame(0, User::search('jonh')->getDebugInfo()['typo_tolerance']);
    }

    public function test_levenshtein_reads_its_own_max_distance_on_declared_and_zero_config_models_alike(): void
    {
        config(['fuzzy-search.typo_tolerance.max_distance' => 0, 'fuzzy-search.levenshtein.max_distance' => 1]);

        $this->assertFindsJonSnowEverywhere(fn () => User::search('jonh')->using('levenshtein'), 'declared');
        $this->assertFindsJonSnowEverywhere(fn () => ZeroConfigUser::search('jonh')->using('levenshtein'), 'zero-config');

        config(['fuzzy-search.default_algorithm' => 'levenshtein']);
        $this->assertFindsJonSnowEverywhere(fn () => ZeroConfigUser::search('jonh'), 'zero-config, default levenshtein');
        $this->assertFindsJonSnowEverywhere(fn () => ListColumnsUser::search('jonh'), 'declared, default levenshtein');
    }
}
