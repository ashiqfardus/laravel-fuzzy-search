<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * fuzzy-search:analytics prints logged search terms, which are user input. A control character
 * (ESC starts a terminal escape sequence: \e[2J clears the screen) must not reach the terminal
 * raw, and neither may a console formatter tag (<href=…> writes an OSC 8 hyperlink).
 */
class AnalyticsOutputEscapingTest extends TestCase
{
    private function log(string $term, int $results, string $path = 'like'): void
    {
        DB::table('fuzzy_search_logs')->insert([
            'term' => $term, 'normalized_term' => SearchAnalytics::normalize($term), 'model_type' => null,
            'algorithm' => 'fuzzy', 'path' => $path, 'result_count' => $results, 'latency_ms' => 1.5,
            'day' => now()->toDateString(), 'created_at' => now(),
        ]);
    }

    public function test_control_characters_in_stored_terms_are_escaped(): void
    {
        $this->log("evil\e[2Jterm", 1);
        $this->log("csi\u{9B}31mterm", 0);
        $this->log("bell\x07", 2, "like\e]0;x\x07");

        // $this->artisan() rather than Artisan::call() + output(): under Laravel 10's test kernel a
        // package command's output never reaches Artisan::output() or a passed-in buffer.
        foreach ([[], ['--zero-results' => true]] as $options) {
            $this->artisan('fuzzy-search:analytics', $options)
                ->doesntExpectOutputToContain("\e")
                ->doesntExpectOutputToContain("\u{9B}")
                ->doesntExpectOutputToContain("\x07")
                ->assertExitCode(0);
        }

        $this->artisan('fuzzy-search:analytics')
            ->expectsOutputToContain('evil\x1B[2jterm')  // normalize() lower-cases the stored term
            ->expectsOutputToContain('like\x1B]0;x\x07') // the path column is printed too
            ->assertExitCode(0);
        $this->artisan('fuzzy-search:analytics', ['--zero-results' => true])
            ->expectsOutputToContain('csi\x9B31mterm')
            ->assertExitCode(0);
    }

    public function test_console_formatter_tags_in_stored_terms_print_as_text(): void
    {
        $this->log('<href=https://evil.test>click</>', 1);

        $this->artisan('fuzzy-search:analytics')
            ->expectsOutputToContain('<href=https://evil.test>click</>')
            ->assertExitCode(0);
    }
}
