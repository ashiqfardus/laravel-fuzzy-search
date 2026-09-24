<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Illuminate\Console\Command;

class AnalyticsPruneCommand extends Command
{
    use ValidatesInput;

    protected $signature   = 'fuzzy-search:analytics:prune {--days= : Delete rows older than this many days (default: analytics.retention_days)}';
    protected $description = 'Delete search log rows (analytics.table) older than the retention window';

    public function handle(): int
    {
        // Validated, not cast: (int) 'abc' is 0, and a 0-day window deletes every row.
        $days = $this->option('days') === null ? (int) config('fuzzy-search.analytics.retention_days', 30) : $this->integerOption('days', 0);
        if ($days === null) {
            return self::FAILURE;
        }

        $deleted = SearchAnalytics::prune($days);

        $this->info("Deleted {$deleted} search log row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
