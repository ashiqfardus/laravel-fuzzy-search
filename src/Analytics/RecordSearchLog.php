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

        $row   = SearchAnalytics::rowFor($event);
        $queue = config('fuzzy-search.analytics.queue');

        if (is_string($queue) && $queue !== '') {
            RecordSearchLogJob::dispatch($row)->onQueue($queue);
            return;
        }

        SearchAnalytics::record($row);
    }
}
