<?php

namespace Ashiqfardus\LaravelFuzzySearch\Analytics;

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RecordSearchLogJob;
use Illuminate\Support\Facades\DB;

/** Registered by the service provider unconditionally; analytics.enabled is checked per event. */
class RecordSearchLog
{
    public function handle(FuzzySearchExecuted $event): void
    {
        if (!config('fuzzy-search.analytics.enabled', false)) {
            return; // read per event, so the flag can be flipped after boot
        }

        $rate = (float) config('fuzzy-search.analytics.sample_rate', 1.0);
        if ($rate < 1.0 && (mt_rand() / mt_getrandmax()) >= $rate) {
            return;
        }

        $queue = config('fuzzy-search.analytics.queue');

        // Analytics is observability, never a failure mode for the search itself: a missing
        // table (enabled before `php artisan migrate`), an oversized value or a transient DB
        // error is reported and swallowed here so the caller still gets their results.
        // RecordSearchLogJob::handle() deliberately keeps throwing, so the queue can retry.
        $level = DB::transactionLevel();

        try {
            $row = SearchAnalytics::rowFor($event);

            if (is_string($queue) && $queue !== '') {
                RecordSearchLogJob::dispatch($row)->onQueue($queue);
                return;
            }

            SearchAnalytics::record($row);
        } catch (\Throwable $e) {
            // A connection lost inside the caller's transaction took that transaction with it, and
            // Laravel reset the level to 0: thrown, as the caller's own next statement would have
            // been; swallowed, the caller carried on at level 0, autocommitting what followed.
            if (DB::transactionLevel() < $level) {
                throw $e;
            }

            report($e);
        }
    }
}
