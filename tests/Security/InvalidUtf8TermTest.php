<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
