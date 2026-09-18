<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A crafted `?q=jo%C3hn` is invalid UTF-8. PostgreSQL (SQLSTATE 22021) and SQL Server (IMSSP)
 * reject such bytes in a bind parameter, so every entry point that takes a search term drops
 * them first: each one must search exactly what the cleaned term 'john' searches.
 */
class InvalidUtf8TermTest extends TestCase
{
    private const DIRTY = "jo\xC3hn";
    private const CLEAN = 'john';

    public function test_the_query_and_eloquent_macros_and_the_fuzzy_scopes_search_the_cleaned_term(): void
    {
        $ids = [
            'whereFuzzy'         => fn ($t) => User::query()->whereFuzzy('name', $t),
            'orWhereFuzzy'       => fn ($t) => User::query()->where('id', 0)->orWhereFuzzy('name', $t),
            'whereFuzzyMultiple' => fn ($t) => User::query()->whereFuzzyMultiple(['name', 'email'], $t, 'levenshtein'),
            'fuzzySearch'        => fn ($t) => User::query()->fuzzySearch(['name', 'email'], $t),
            'orderByFuzzy'       => fn ($t) => User::query()->orderByFuzzy('name', $t)->orderBy('id'),
            'query builder'      => fn ($t) => DB::table('users')->whereFuzzy('name', $t)->orderByFuzzy('email', $t, 'desc'),
            'scopeFuzzy'         => fn ($t) => User::fuzzy($t),
            'scopeFuzzySoundex'  => fn ($t) => User::fuzzySoundex($t),
        ];

        foreach ($ids as $entry => $query) {
            $expected = $query(self::CLEAN)->pluck('id')->all();
            $this->assertNotEmpty($expected, $entry);
            $this->assertSame($expected, $query(self::DIRTY)->pluck('id')->all(), $entry);
        }
    }

    public function test_the_filament_table_search_closure_searches_the_cleaned_term(): void
    {
        $apply = fn (string $search) => User::query()
            ->where(fn ($q) => FuzzySearch::tableSearch(['name', 'email'])($q, $search))
            ->orderBy('id')->pluck('id')->all();

        $this->assertNotEmpty($apply(self::CLEAN));
        $this->assertSame($apply(self::CLEAN), $apply(self::DIRTY));
        // Nothing valid left is an empty search: no constraint, as for ''.
        $this->assertSame($apply(''), $apply("\xFF"));
    }

    public function test_the_search_builder_paths_search_the_cleaned_term(): void
    {
        $ids = fn ($builder) => $builder->get()->pluck('id')->all();

        $this->assertNotEmpty($ids(User::search(self::CLEAN)));
        $this->assertSame($ids(User::search(self::CLEAN)), $ids(User::search(self::DIRTY)));
        $this->assertSame($ids(User::search("'" . self::CLEAN)->extended()), $ids(User::search('')->extended("'" . self::DIRTY)));
        $this->assertSame($ids(User::search("'" . self::CLEAN)->extended()), $ids(User::search('')->searchBoolean("'" . self::DIRTY)));
        app(IndexManager::class)->indexBatch(User::all());
        $this->assertNotEmpty($ids(User::search(self::CLEAN)->useInvertedIndex()));
        $this->assertSame($ids(User::search(self::CLEAN)->useInvertedIndex()), $ids(User::search(self::DIRTY)->useInvertedIndex()));
        $this->assertSame(User::query()->searchFuzzy(self::CLEAN)->get()->pluck('id')->all(), User::query()->searchFuzzy(self::DIRTY)->get()->pluck('id')->all());

        // suggest() and didYouMean() read the builder's (cleaned) term.
        $this->assertSame(User::search('jon')->searchIn(['name'])->suggest(), User::search("jo\xC3n")->searchIn(['name'])->suggest());
        $this->assertSame(User::search('jonh')->didYouMean(), User::search("jo\xFFnh")->didYouMean());
    }

    public function test_federated_search_searches_the_cleaned_term(): void
    {
        $keys = fn (string $term) => FederatedSearch::across([User::class])
            ->search($term)->searchIn(['name', 'email'])->get()
            ->map(fn ($m) => $m->_model_class . ':' . $m->getKey())->all();

        $this->assertNotEmpty($keys(self::CLEAN));
        $this->assertSame($keys(self::CLEAN), $keys(self::DIRTY));
    }

    public function test_the_in_memory_search_matches_the_cleaned_term(): void
    {
        $items = [['name' => 'John Doe'], ['name' => 'Jane Roe'], ['name' => 'Bob Johnson']];
        $names = fn (string $term) => FuzzySearch::on($items)->search($term)->searchIn(['name'])->get()->pluck('name')->all();

        $this->assertNotEmpty($names(self::CLEAN));
        $this->assertSame($names(self::CLEAN), $names(self::DIRTY));
    }

    public function test_the_scout_engine_searches_the_cleaned_term(): void
    {
        if (!class_exists(\Laravel\Scout\Builder::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = new FuzzySearchEngine(new IndexManager(new WhitespaceTokenizer(), new NullStemmer()), new Bm25Scorer());
        $engine->update(User::all());

        $search = fn (string $term) => $engine->mapIds($engine->search(new \Laravel\Scout\Builder(new User(), $term)))->all();
        $page   = fn (string $term) => $engine->mapIds($engine->paginate(new \Laravel\Scout\Builder(new User(), $term), 10, 1))->all();

        $this->assertNotEmpty($search(self::CLEAN));
        $this->assertSame($search(self::CLEAN), $search(self::DIRTY));
        $this->assertSame($page(self::CLEAN), $page(self::DIRTY));
    }

    /**
     * `?q=%FF` is not an empty request, so an app's filled('q') guard lets it through. Cleaned it
     * is '', but it must not behave like '' (EmptySearchTermException from get(), every row from
     * paginate()/count()): like a term below min_search_length, it matches nothing.
     *
     * @return array<string, array{string, Closure(object): mixed, mixed}>
     */
    public static function invalidBytesOnlyEntryPoints(): array
    {
        return [
            'get'                      => ['builder', fn ($b) => $b->get()->all(), []],
            'first'                    => ['builder', fn ($b) => $b->first(), null],
            'paginate'                 => ['builder', fn ($b) => [($p = $b->paginate(5))->total(), $p->items()], [0, []]],
            'simplePaginate'           => ['builder', fn ($b) => $b->simplePaginate(5)->items(), []],
            'count'                    => ['builder', fn ($b) => $b->count(), 0],
            'getFacets'                => ['builder', fn ($b) => $b->facet('email')->getFacets(), ['email' => []]],
            'federated get'            => ['federated', fn ($f) => $f->get()->all(), []],
            'federated paginate'       => ['federated', fn ($f) => [($p = $f->paginate(5))->total(), $p->items()], [0, []]],
            'federated simplePaginate' => ['federated', fn ($f) => $f->simplePaginate(5)->items(), []],
            'federated getCounts'      => ['federated', fn ($f) => $f->getCounts(), ['User' => 0]],
            'in-memory get'            => ['in-memory', fn ($m) => $m->get()->all(), []],
        ];
    }

    #[DataProvider('invalidBytesOnlyEntryPoints')]
    public function test_a_term_made_only_of_invalid_bytes_matches_nothing(string $subject, Closure $run, mixed $expected): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        foreach (["\xFF", "\xC3", " \xFF\xFE "] as $term) {
            $makers = match ($subject) {
                'builder' => [
                    'search'          => fn () => User::search($term),
                    'search+extended' => fn () => User::search($term)->extended(),
                    'extended(term)'  => fn () => User::search('')->extended($term),
                    'searchBoolean'   => fn () => User::search('')->searchBoolean($term),
                    'inverted index'  => fn () => User::search($term)->useInvertedIndex(),
                    'with fallback'   => fn () => User::search($term)->fallback('levenshtein'),
                    'cached'          => fn () => User::search($term)->cache(5),
                ],
                'federated' => ['federated' => fn () => FederatedSearch::across([User::class])->search($term)->searchIn(['name', 'email'])],
                'in-memory' => ['in-memory' => fn () => FuzzySearch::on([['name' => 'John Doe']])->search($term)->searchIn(['name'])],
            };

            foreach ($makers as $path => $make) {
                $this->assertSame($expected, $run($make()), bin2hex($term) . " {$path}");
            }
        }
    }

    public function test_a_term_made_only_of_invalid_bytes_logs_nothing_and_is_not_the_empty_search(): void
    {
        $events = 0;
        Event::listen(FuzzySearchExecuted::class, function () use (&$events) {
            $events++;
        });
        $items = [['name' => 'John Doe'], ['name' => 'Jane Roe']];

        User::search("\xFF")->get();
        User::search("\xFF")->paginate(5);
        FederatedSearch::across([User::class])->search("\xFF")->searchIn(['name'])->get();
        FuzzySearch::on($items)->search("\xFF")->searchIn(['name'])->get();
        $this->assertSame(0, $events, 'no search ran, so nothing reaches the analytics log');

        // A genuine '' is unchanged: it throws by default and, with allow_empty_search, lists
        // every row. The invalid-only term matches nothing either way.
        try {
            User::search('')->get();
            $this->fail("'' should still throw EmptySearchTermException");
        } catch (EmptySearchTermException) {
        }
        config(['fuzzy-search.allow_empty_search' => true]);
        $this->assertSame(User::count(), User::search('')->count());
        $this->assertSame(0, User::search("\xFF")->count());
        $this->assertSame([], User::search("\xFF")->get()->all());
        $this->assertNotEmpty(FederatedSearch::across([User::class])->search('')->searchIn(['name'])->get());
        $this->assertSame([], FederatedSearch::across([User::class])->search("\xFF")->searchIn(['name'])->get()->all());
        $this->assertCount(2, FuzzySearch::on($items)->search('')->searchIn(['name'])->get());

        // A later valid term replaces it on the same builder.
        $this->assertNotEmpty(User::search("\xFF")->search(self::CLEAN)->get());
        $this->assertNotEmpty(User::search("\xFF")->extended("'" . self::CLEAN)->get());
        $this->assertNotEmpty(FederatedSearch::across([User::class])->search("\xFF")->search(self::CLEAN)->searchIn(['name'])->get());
        $this->assertNotEmpty(FuzzySearch::on($items)->search("\xFF")->search(self::CLEAN)->searchIn(['name'])->get());
    }

    public function test_every_executed_event_and_the_analytics_row_carry_the_cleaned_term(): void
    {
        config(['fuzzy-search.analytics.enabled' => true]);

        $terms = [];
        Event::listen(FuzzySearchExecuted::class, function (FuzzySearchExecuted $e) use (&$terms) {
            $terms[] = $e->searchTerm;
        });

        User::search(self::DIRTY)->get();
        User::search(self::DIRTY)->paginate(5);
        User::search('')->extended("'" . self::DIRTY)->get();
        FuzzySearch::on([['name' => 'John']])->search(self::DIRTY)->searchIn(['name'])->get();

        $this->assertSame([self::CLEAN, self::CLEAN, "'" . self::CLEAN, self::CLEAN], $terms);
        $this->assertSame($terms, DB::table('fuzzy_search_logs')->orderBy('id')->pluck('term')->all());

        // A third-party dispatcher's event is cleaned by the recorder too.
        $row = SearchAnalytics::rowFor(new FuzzySearchExecuted(self::DIRTY, [], 'fuzzy', 0, 1.0));
        $this->assertSame(self::CLEAN, $row['term']);
        $this->assertSame(self::CLEAN, $row['normalized_term']);
    }
}
