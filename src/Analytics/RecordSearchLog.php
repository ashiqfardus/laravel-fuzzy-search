<?php

namespace Ashiqfardus\LaravelFuzzySearch\Analytics;

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RecordSearchLogJob;

/** Registered by the service provider only when fuzzy-search.analytics.enabled is true. */
class RecordSearchLog
{
    public function handle(FuzzySearchExecuted $event): void
    {
        if (!config('fuzzy-search.analytics.enabled', false)) {
            return; // the flag may be flipped after boot (tests, runtime toggles)
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
        try {
            $row = SearchAnalytics::rowFor($event);

            if (is_string($queue) && $queue !== '') {
                RecordSearchLogJob::dispatch($row)->onQueue($queue);
                return;
            }

            SearchAnalytics::record($row);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
