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
        DB::table('fuzzy_index_postings')->insert(['term_id' => $termId, 'model_type' => User::class, 'model_id' => '1', 'frequency' => 1]);
        DB::table('fuzzy_index_meta')->insert(['model_type' => User::class, 'total_docs' => 1, 'total_tokens' => 1, 'avg_doc_length' => 1]);

        // PendingCommand, like the other command tests: buffered Artisan output is empty on
        // Laravel 10 in this harness, but expectsOutputToContain() works on every version.
        // One expectation: PendingCommand consumes a matched output line, so two substrings of
        // the same warning line cannot both be asserted separately.
        $this->artisan('fuzzy-search:status')
            ->assertSuccessful()
            ->expectsOutputToContain('predate column weighting and rank at weight 1 — run: php artisan fuzzy-search:rebuild "' . User::class . '" --fresh')
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
}
