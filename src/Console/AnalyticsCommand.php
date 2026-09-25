<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

class AnalyticsCommand extends Command
{
    use ValidatesInput;

    protected $signature   = 'fuzzy-search:analytics {--days=30 : Window in days} {--limit=20 : Rows per table} {--zero-results : Only list searches that returned nothing}';
    protected $description = 'Report popular searches, zero-result searches, latency by path and daily volume from the search log (analytics.table)';

    public function handle(): int
    {
        $days  = $this->integerOption('days', 0);
        $limit = $this->integerOption('limit', 1);
        if ($days === null || $limit === null) {
            return self::FAILURE;
        }

        if ($this->option('zero-results')) {
            $rows = SearchAnalytics::zeroResults($days, $limit);
            $this->info("Zero-result searches (last {$days} days)");
            $rows === []
                ? $this->line('  none')
                : $this->table(['Term', 'Searches'], array_map(fn ($r) => [$this->printable($r['term']), $r['searches']], $rows));

            return self::SUCCESS;
        }

        $popular = SearchAnalytics::popular($days, $limit);
        if ($popular === []) {
            $this->warn("No searches recorded in the last {$days} days. Enable fuzzy-search.analytics.enabled to start recording.");

            return self::SUCCESS;
        }

        $this->info("Popular searches (last {$days} days)");
        $this->table(['Term', 'Searches', 'Avg results'], array_map(fn ($r) => [$this->printable($r['term']), $r['searches'], round($r['avg_results'], 1)], $popular));

        $zero = SearchAnalytics::zeroResults($days, $limit);
        if ($zero !== []) {
            $this->info("Zero-result searches (last {$days} days)");
            $this->table(['Term', 'Searches'], array_map(fn ($r) => [$this->printable($r['term']), $r['searches']], $zero));
        }

        $latency = SearchAnalytics::averageLatency($days);
        $this->info('Average latency by path (ms)');
        $this->table(['Path', 'Avg ms'], array_map(fn ($p, $ms) => [$this->printable((string) $p), $ms], array_keys($latency), $latency));

        $volume = SearchAnalytics::volume($days);
        $this->info('Searches per day');
        $this->table(['Day', 'Searches'], array_map(fn ($d, $n) => [$d, $n], array_keys($volume), $volume));

        return self::SUCCESS;
    }

    /**
     * A logged value (user input) made safe to print: C0, DEL and C1 control characters are
     * shown as \xNN, so no escape sequence (\e[2J clears the screen) reaches the terminal, and
     * console formatter tags are escaped, so <href=…> prints as text instead of a hyperlink.
     */
    private function printable(string $value): string
    {
        $value = preg_replace_callback(
            '/[\x00-\x1F\x7F\x{80}-\x{9F}]/u',
            fn (array $m) => sprintf('\\x%02X', mb_ord($m[0], 'UTF-8')),
            Utf8::clean($value), // the /u pattern needs valid UTF-8
        ) ?? '';

        return OutputFormatter::escape($value);
    }
}
