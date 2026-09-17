<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecordSearchLogJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds between retries (mirrors the indexing jobs) — a DB blip is not retried three times in the same second. */
    public array $backoff = [10, 60];

    public function __construct(public readonly array $row) {}

    public function handle(): void
    {
        SearchAnalytics::record($this->row);
    }
}
