<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Cache;

class CacheVisibleUser extends User
{
    protected $visible = ['id', 'name'];
}

/** Rows cached as attributes drop what $hidden hides, and keep the rest, so a hit reads what a miss reads. */
class CacheVisibleAttributesTest extends TestCase
{
    public function test_an_aliased_column_outside_visible_reads_the_same_on_a_hit_and_a_miss(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $run = fn () => (new SearchBuilder(CacheVisibleUser::query()->from('users as u')->select('u.*', 'u.email as mail'), app(FuzzySearch::class)))
            ->search('doe')->searchIn(['name'])->using('like')->cache(60)->get();

        $miss = $run()->map->mail->sort()->values()->all();
        $hit  = $run()->map->mail->sort()->values()->all();

        $this->assertNotEmpty(array_filter($miss));
        $this->assertSame($miss, $hit);
    }
}
