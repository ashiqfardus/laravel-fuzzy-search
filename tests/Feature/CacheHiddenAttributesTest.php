<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CachedSecretDoc extends Model
{
    protected $table   = 'cached_secret_docs';
    protected $guarded = [];
    public $timestamps = false;
    protected $hidden  = ['secret'];
}

/**
 * Rows the cache holds as their attributes (a join, a union, an aliased FROM) are stored without
 * the model's $hidden attributes: the cache store is shared, and the hit never serialises them.
 */
class CacheHiddenAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
        Schema::dropIfExists('cached_secret_docs');
        Schema::create('cached_secret_docs', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('secret');
            $table->string('note')->nullable();
        });
        DB::table('cached_secret_docs')->insert(['name' => 'Ada Doe', 'secret' => 'hunter2-plaintext', 'note' => 'kept']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cached_secret_docs');
        parent::tearDown();
    }

    private function search(): SearchBuilder
    {
        return (new SearchBuilder(CachedSecretDoc::query()->from('cached_secret_docs as c'), app(FuzzySearch::class)))
            ->search('ada')->searchIn(['name'])->using('like')->cache(60);
    }

    public function test_a_cached_aliased_from_row_holds_no_hidden_attribute(): void
    {
        $this->search()->get();

        $stored = (new \ReflectionProperty(Cache::store('array')->getStore(), 'storage'))->getValue(Cache::store('array')->getStore());
        $this->assertStringContainsString('Ada Doe', serialize($stored), 'precondition: the entry is cached');
        $this->assertStringNotContainsString('hunter2-plaintext', serialize($stored));
    }

    public function test_a_hit_still_serves_the_other_attributes(): void
    {
        $this->search()->get();
        $row = $this->search()->get()->first();

        $this->assertSame('Ada Doe', $row->name);
        $this->assertSame('kept', $row->note);
        $this->assertArrayNotHasKey('secret', $row->toArray());
    }
}
