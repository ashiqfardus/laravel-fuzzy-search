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

/** Rows cached as attributes keep out what the model hides, whether through $hidden or a $visible list. */
class CacheVisibleAttributesTest extends TestCase
{
    public function test_attributes_outside_visible_are_not_written_to_the_cache_store(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $run = fn () => (new SearchBuilder(CacheVisibleUser::query()->from('users as u'), app(FuzzySearch::class)))
            ->search('doe')->searchIn(['name'])->using('like')->cache(60)->get();

        $run();

        $this->assertStringNotContainsString('jane@example.com', serialize((new \ReflectionProperty(Cache::getStore(), 'storage'))->getValue(Cache::getStore())));
        $this->assertCount(2, $run());
    }
}
