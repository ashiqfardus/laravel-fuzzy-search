<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

require_once __DIR__ . '/../TestModels.php';

class StatusCommandTest extends TestCase
{
    public function test_status_warns_about_postings_written_before_column_weighting(): void
    {
        $termId = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'legacy', 'doc_count' => 1, 'term_length' => 6]);
        $this->insertNamedPostings([['term_id' => $termId, 'model_type' => User::class, 'model_id' => '1', 'frequency' => 1]]);
        DB::table('fuzzy_index_meta')->insert(['model_type' => User::class, 'total_docs' => 1, 'total_tokens' => 1, 'avg_doc_length' => 1]);

        // PendingCommand, like the other command tests: buffered Artisan output is empty on
        // Laravel 10 in this harness, but expectsOutputToContain() works on every version.
        // One expectation: PendingCommand consumes a matched output line, so two substrings of
        // the same warning line cannot both be asserted separately.
        $this->artisan('fuzzy-search:status')
            ->assertSuccessful()
            ->expectsOutputToContain('predate column weighting and rank at weight 1 — run: php artisan fuzzy-search:rebuild "' . User::class . '"')
            ->run();
    }

    /** A legacy posting under an index of $tokens tokens (fuzzy_index_meta's total_tokens). */
    private function legacyIndexOf(int $tokens): void
    {
        $termId = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'legacy', 'doc_count' => 1, 'term_length' => 6]);
        $this->insertNamedPostings([['term_id' => $termId, 'model_type' => User::class, 'model_id' => '1', 'frequency' => 1]]);
        DB::table('fuzzy_index_meta')->insert(['model_type' => User::class, 'total_docs' => 1, 'total_tokens' => $tokens, 'avg_doc_length' => 1]);
    }

    /** @return list<string> the statements that read fuzzy_index_postings while $run ran */
    private function postingsReads(\Closure $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        DB::disableQueryLog();

        return array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, 'fuzzy_index_postings')));
    }

    /**
     * S4: the legacy check scans every posting (100 s at 6M rows on MySQL), so past 1,000,000
     * indexed tokens it is skipped by default, with one line saying how to run it.
     */
    public function test_status_skips_the_legacy_scan_on_a_large_index(): void
    {
        $this->legacyIndexOf(1_000_001);

        $reads = $this->postingsReads(fn () => $this->artisan('fuzzy-search:status')
            ->assertSuccessful()
            ->expectsOutputToContain('php artisan fuzzy-search:status --legacy')
            ->doesntExpectOutputToContain('predate column weighting and rank at weight 1')
            ->run());

        $this->assertSame([], $reads);
    }

    public function test_status_legacy_runs_the_scan_on_a_large_index(): void
    {
        $this->legacyIndexOf(1_000_001);

        $reads = $this->postingsReads(fn () => $this->artisan('fuzzy-search:status', ['--legacy' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('predate column weighting and rank at weight 1 — run: php artisan fuzzy-search:rebuild "' . User::class . '"')
            ->run());

        $this->assertCount(1, $reads);
    }

    public function test_status_still_scans_an_index_of_up_to_a_million_tokens(): void
    {
        $this->legacyIndexOf(1_000_000);

        $this->artisan('fuzzy-search:status')
            ->assertSuccessful()
            ->expectsOutputToContain('predate column weighting and rank at weight 1')
            ->doesntExpectOutputToContain('--legacy')
            ->run();
    }

    public function test_status_prints_no_warning_when_every_posting_has_a_column(): void
    {
        // indexModel() writes the meta row too, so the command reaches the legacy check.
        app(IndexManager::class)->indexModel(User::first());

        $this->artisan('fuzzy-search:status')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('predate column weighting')
            ->run();
    }

    /**
     * The warning names a plain rebuild, not --fresh: re-indexing a row replaces every posting it
     * held, a legacy one included, and a plain rebuild runs while the app writes the index.
     */
    public function test_the_plain_rebuild_the_warning_names_clears_it(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $termId = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'legacy', 'doc_count' => 1, 'term_length' => 6]);
        $this->insertNamedPostings([['term_id' => $termId, 'model_type' => User::class, 'model_id' => (string) User::query()->min('id'), 'frequency' => 1]]);

        // Two runs: PendingCommand consumes the line the first expectation matches.
        $this->artisan('fuzzy-search:status')
            ->expectsOutputToContain('predate column weighting and rank at weight 1 — run: php artisan fuzzy-search:rebuild "' . User::class . '"')
            ->run();
        $this->artisan('fuzzy-search:status')->doesntExpectOutputToContain('--fresh')->run();

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertSuccessful()->run();

        $this->artisan('fuzzy-search:status')
            ->assertSuccessful()
            ->doesntExpectOutputToContain('predate column weighting')
            ->run();
    }
}
