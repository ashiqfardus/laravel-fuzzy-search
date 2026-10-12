<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RestrictedColumnsUser extends Model
{
    use Searchable;

    protected $table   = 'restricted_columns_users';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5]];
}

/**
 * TE-12. A search restricted to some of the model's columns (searchOn($query, $term, ['name']), a
 * plain builder's searchIn(['name'])) matched those columns only on the LIKE paths, and every indexed
 * column on useInvertedIndex(): a name search found a row by its email. The index read now keeps the
 * postings of the builder's columns only, as the LIKE paths search them.
 */
class IndexColumnRestrictionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('restricted_columns_users');
        Schema::create('restricted_columns_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
        });
        DB::table('restricted_columns_users')->insert([
            ['name' => 'john smith', 'email' => 'js@example.com'],
            ['name' => 'mary peters', 'email' => 'john@example.com'],
            ['name' => 'peter pan', 'email' => 'pp@example.com'],
        ]);
        app(IndexManager::class)->indexBatch(RestrictedColumnsUser::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('restricted_columns_users');

        parent::tearDown();
    }

    public function test_a_search_restricted_to_a_column_matches_it_alone_on_the_index_path(): void
    {
        $name = fn ($row) => is_array($row) ? $row['name'] : $row->name;

        foreach ([
            "searchOn(['name'])"         => fn () => RestrictedColumnsUser::searchOn(RestrictedColumnsUser::query(), 'john', ['name']),
            "searchOn(['name' => 3])"    => fn () => RestrictedColumnsUser::searchOn(RestrictedColumnsUser::query(), 'john', ['name' => 3]),
            "builder searchIn(['name'])" => fn () => (new SearchBuilder(DB::table('restricted_columns_users'), app(FuzzySearch::class)))->search('john')->searchIn(['name']),
        ] as $label => $make) {
            foreach (['like' => fn ($b) => $b->using('simple'), 'index' => fn ($b) => $b->useInvertedIndex(RestrictedColumnsUser::class)->typoTolerance(0)] as $path => $on) {
                $this->assertSame(['john smith'], collect($on($make())->get())->map($name)->all(), "{$label} {$path}: get");
                $this->assertSame(1, $on($make())->count(), "{$label} {$path}: count()");
                $this->assertSame(1, $on($make())->paginate(10)->total(), "{$label} {$path}: total");
            }
        }

        // The model's own search keeps every column it searches: the email match stays.
        $this->assertSame(['john smith', 'mary peters'], RestrictedColumnsUser::search('john')->useInvertedIndex()->typoTolerance(0)->get()->pluck('name')->sort()->values()->all());
    }
}
