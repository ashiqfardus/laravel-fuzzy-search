<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

class RankReadScoutUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * UD-1 (ER-174 b). rank() told RankedCandidates::matches() what its read found (the words' share of
 * the documents, and whether it reached bm25.max_postings_per_term) through a memo on the scorer,
 * read back from the container. Under Octane, which clones the booted application for every request,
 * a Scout engine made in the first request keeps that request's scorer, while matches() read a
 * fresh one in every later request: the memo always missed there, so an ordered Scout search of a
 * ranking that holds every match went through the postings subquery instead of the ranked ids. The
 * read is now handed from rank() to matches() by the caller.
 */
class ScoutRankReadTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), \Laravel\Scout\ScoutServiceProvider::class];
    }

    /** Octane's CurrentApplication::set(). */
    private static function become(Container $app): void
    {
        $app->instance('app', $app);
        $app->instance(Container::class, $app);
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    /** @return array{list<string>, bool} the names an ordered Scout search serves, and whether its ordered read went through the postings */
    private function search(): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql) {
            $sql[] = $query->sql;
        });
        try {
            $names = (new \Laravel\Scout\Builder(new RankReadScoutUser(), 'zebra lion'))->orderBy('name')->get()->pluck('name')->all();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return [$names, array_filter($sql, fn (string $query) => str_contains($query, 'fuzzy_walk_key') && str_contains($query, 'fuzzy_index_postings')) !== []];
    }

    public function test_an_ordered_scout_search_reads_the_ranked_ids_in_every_octane_request(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        // 12 matches of two words: 24 by the bound matches() falls back on, past a cap of 20, while
        // rank()'s read of 12 postings did not reach it, so the ranking holds every match.
        config(['scout.driver' => 'fuzzy-search', 'fuzzy-search.bm25.max_postings_per_term' => 20]);
        $rows = [];
        for ($i = 0; $i < 6; $i++) {
            $rows[] = ['name' => "Zebra z{$i}", 'email' => "z{$i}@rank-read.test"];
            $rows[] = ['name' => "Lion l{$i}", 'email' => "l{$i}@rank-read.test"];
        }
        DB::table('users')->insert($rows);
        app(IndexManager::class)->indexBatch(RankReadScoutUser::query()->where('email', 'like', '%@rank-read.test')->get());

        [$reference, $postings] = $this->search();
        $this->assertCount(12, $reference);
        $this->assertFalse($postings, 'one container: the ranked ids are listed');

        // EngineManager resolved at boot (Scout's documented extend() in a provider, or an Octane
        // warm entry), the scorer not: the engine made in request 1 keeps request 1's scorer.
        $base = $this->app;
        $base->forgetInstance(Bm25Scorer::class);
        $base->forgetInstance(\Laravel\Scout\EngineManager::class);
        $base->make(\Laravel\Scout\EngineManager::class);

        foreach ([1, 2, 3] as $request) {
            self::become(clone $base);
            try {
                [$names, $postings] = $this->search();
            } finally {
                self::become($base);
            }

            $this->assertSame($reference, $names, "request {$request}");
            $this->assertFalse($postings, "request {$request}: the ranked ids are listed, not read through the postings");
        }
    }
}
