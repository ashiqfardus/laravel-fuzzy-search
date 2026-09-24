<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
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

    private function report(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('fuzzy-search:analytics', $options));

        return Artisan::output();
    }

    public function test_control_characters_in_stored_terms_are_escaped(): void
    {
        $this->log("evil\e[2Jterm", 1);
        $this->log("csi\u{9B}31mterm", 0);
        $this->log("bell\x07", 2, "like\e]0;x\x07");

        foreach ([[], ['--zero-results' => true]] as $options) {
            $output = $this->report($options);

            $this->assertStringNotContainsString("\e", $output);
            $this->assertStringNotContainsString("\u{9B}", $output);
            $this->assertStringNotContainsString("\x07", $output);
        }

        $output = $this->report();
        $this->assertStringContainsString('evil\x1B[2jterm', $output);  // normalize() lower-cases the stored term
        $this->assertStringContainsString('like\x1B]0;x\x07', $output); // the path column is printed too
        $this->assertStringContainsString('csi\x9B31mterm', $this->report(['--zero-results' => true]));
    }

    public function test_console_formatter_tags_in_stored_terms_print_as_text(): void
    {
        $this->log('<href=https://evil.test>click</>', 1);

        $this->assertStringContainsString('<href=https://evil.test>click</>', $this->report());
    }
}
