<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Artisan;

/**
 * N2-6: fuzzy-search:explain printed a "Pattern Analysis" it built by hand (20 patterns for
 * "johnathan" where the search bound 72; LIKE patterns for soundex, which runs SOUNDEX()). It now
 * prints only what the search runs: its SQL and every one of its bindings.
 */
class ExplainCommandOutputTest extends TestCase
{
    public function test_explain_prints_the_searchs_own_sql_and_bindings_and_nothing_made_up(): void
    {
        foreach (['fuzzy', 'levenshtein', 'soundex', 'simple'] as $algorithm) {
            $this->assertSame(0, Artisan::call('fuzzy-search:explain', ['model' => User::class, '--term' => 'johnathan', '--algorithm' => $algorithm]));
            $output = Artisan::output();

            $search = User::search('johnathan')->using($algorithm)->debugScore()->limit(5);

            $this->assertStringNotContainsString('Pattern Analysis', $output, $algorithm);
            $this->assertStringNotContainsString('Generated patterns', $output, $algorithm);
            $this->assertStringContainsString($search->toSql(), $output, $algorithm);
            $this->assertSame(count($search->getBindings()), preg_match_all('/^  \[\d+\] => /m', $output), $algorithm);
        }
    }
}
