<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
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

        Artisan::call('fuzzy-search:status');
        $output = Artisan::output(); // the buffer empties on read — fetch once

        $this->assertStringContainsString('predate column weighting', $output);
        $this->assertStringContainsString('--fresh', $output);
    }
}
