<?php

namespace Ashiqfardus\LaravelFuzzySearch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array popular(int $days = 30, int $limit = 10)
 * @method static array zeroResults(int $days = 30, int $limit = 10)
 * @method static array averageLatency(int $days = 30)
 * @method static array volume(int $days = 30)
 * @method static int prune(?int $days = null)
 *
 * @see \Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics
 */
class SearchAnalytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics::class;
    }
}
