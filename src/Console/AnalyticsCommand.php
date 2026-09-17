<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Illuminate\Console\Command;

class AnalyticsCommand extends Command
{
    protected $signature   = 'fuzzy-search:analytics {--days=30 : Window in days} {--limit=20 : Rows per table} {--zero-results : Only list searches that returned nothing}';
    protected $description = 'Report popular searches, zero-result searches, latency by path and daily volume from fuzzy_search_logs';

    public function handle(): int
    {
        $days  = max(0, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('zero-results')) {
            $rows = SearchAnalytics::zeroResults($days, $limit);
            $this->info("Zero-result searches (last {$days} days)");
            $rows === []
                ? $this->line('  none')
                : $this->table(['Term', 'Searches'], array_map(fn ($r) => [$r['term'], $r['searches']], $rows));

            return self::SUCCESS;
        }

        $popular = SearchAnalytics::popular($days, $limit);
        if ($popular === []) {
            $this->warn("No searches recorded in the last {$days} days. Enable fuzzy-search.analytics.enabled to start recording.");

            return self::SUCCESS;
        }

        $this->info("Popular searches (last {$days} days)");
        $this->table(['Term', 'Searches', 'Avg results'], array_map(fn ($r) => [$r['term'], $r['searches'], round($r['avg_results'], 1)], $popular));

        $zero = SearchAnalytics::zeroResults($days, $limit);
        if ($zero !== []) {
            $this->info("Zero-result searches (last {$days} days)");
            $this->table(['Term', 'Searches'], array_map(fn ($r) => [$r['term'], $r['searches']], $zero));
        }

        $latency = SearchAnalytics::averageLatency($days);
        $this->info('Average latency by path (ms)');
        $this->table(['Path', 'Avg ms'], array_map(fn ($p, $ms) => [$p, $ms], array_keys($latency), $latency));

        $volume = SearchAnalytics::volume($days);
        $this->info('Searches per day');
        $this->table(['Day', 'Searches'], array_map(fn ($d, $n) => [$d, $n], array_keys($volume), $volume));

        return self::SUCCESS;
    }
}
