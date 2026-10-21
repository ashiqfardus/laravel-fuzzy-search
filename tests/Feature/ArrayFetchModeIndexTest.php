<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Under an app-wide array fetch mode (a StatementPrepared listener setting PDO::FETCH_ASSOC) the package
 * reads its own rows as objects: index searches, didYouMean(), fuzzy-search:status, the search log's reads
 * and a re-index serve what they serve under the default fetch mode. They threw "Attempt to read property
 * … on array".
 */
class ArrayFetchModeIndexTest extends TestCase
{
    protected function tearDown(): void
    {
        Event::forget(StatementPrepared::class); // the teardown's migrator reads objects

        parent::tearDown();
    }

    private function assoc(): void
    {
        Event::listen(StatementPrepared::class, fn (StatementPrepared $event) => $event->statement->setFetchMode(\PDO::FETCH_ASSOC));
        $this->assertIsArray(DB::table('users')->first(), 'the listener is in effect');
    }

    /** Every read path, as plain values. */
    private function reads(): array
    {
        $names = fn ($rows) => $rows->pluck('name')->all();

        return [
            'get'         => $names(User::search('john')->useInvertedIndex()->get()),
            'paginate'    => [User::search('john')->useInvertedIndex()->paginate(2)->total(), $names(collect(User::search('john')->useInvertedIndex()->paginate(2)->items()))],
            'count'       => User::search('john')->useInvertedIndex()->count(),
            'typo'        => $names(User::search('jonh')->useInvertedIndex()->typoTolerance(2)->get()),
            'constrained' => $names(User::search('john')->useInvertedIndex()->orderBy('name')->get()),
            'asYouType'   => $names(User::search('joh')->useInvertedIndex()->asYouType()->get()),
            'didYouMean'  => User::search('jonh')->useInvertedIndex()->didYouMean(),
            'status'      => [Artisan::call('fuzzy-search:status'), Artisan::output()],
            'popular'     => SearchAnalytics::popular(),
            'zeroResults' => SearchAnalytics::zeroResults(),
            'latency'     => array_keys(SearchAnalytics::averageLatency()),
            'volume'      => SearchAnalytics::volume(),
        ];
    }

    public function test_the_index_paths_and_the_log_read_rows_whatever_the_fetch_mode(): void
    {
        $this->withoutMockingConsoleOutput(); // under Laravel 10's test kernel a package command's output reaches Artisan::output() only this way
        Artisan::call('fuzzy-search:rebuild', ['model' => User::class]);
        config(['fuzzy-search.analytics.enabled' => true]);
        User::search('john')->get();
        User::search('zzznothing')->get();
        config(['fuzzy-search.analytics.enabled' => false]);

        $expected = $this->reads();
        $this->assertContains('John Doe', $expected['get']);
        $this->assertNotSame([], $expected['popular']);
        $this->assertStringContainsString('Total docs', $expected['status'][1]);

        $this->assoc();

        $this->assertSame($expected, $this->reads());
    }

    public function test_a_re_index_and_a_removal_under_an_array_fetch_mode(): void
    {
        Artisan::call('fuzzy-search:rebuild', ['model' => User::class]);
        $user = User::first();
        $this->assoc();

        DB::table('users')->where('id', $user->getKey())->update(['name' => 'Quillon Marsh']);
        IndexModelJob::dispatchSync(User::class, $user->getKey());
        app(IndexManager::class)->indexModel(User::find($user->getKey()));

        $this->assertSame(['Quillon Marsh'], User::search('quillon')->useInvertedIndex()->get()->pluck('name')->all());

        app(IndexManager::class)->removeFromIndex(User::class, $user->getKey());
        $this->assertSame([], User::search('quillon')->useInvertedIndex()->get()->all());
        $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_type', User::class)->where('model_id', (string) $user->getKey())->count());
    }
}
