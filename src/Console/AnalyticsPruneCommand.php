<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Illuminate\Console\Command;

class AnalyticsPruneCommand extends Command
{
    protected $signature   = 'fuzzy-search:analytics:prune {--days= : Delete rows older than this many days (default: analytics.retention_days)}';
    protected $description = 'Delete search log rows (analytics.table) older than the retention window';

    public function handle(): int
    {
        $days    = $this->option('days') === null ? (int) config('fuzzy-search.analytics.retention_days', 30) : max(0, (int) $this->option('days'));
        $deleted = SearchAnalytics::prune($days);

        $this->info("Deleted {$deleted} search log row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
