<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** String keys whose database order differs from PHP's: numeric strings of different lengths, mixed case. */
class FkoCode extends Model
{
    use Searchable;

    protected $table      = 'fko_codes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

class FkoBook extends Model
{
    use Searchable;

    protected $table   = 'fko_books';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

/**
 * Without relevance, a federated search serves each model's rows in the order its own search
 * returns them, by key as its database orders keys, page after page (P7R-1); and paginate()'s total
 * counts every match, also where the BM25 ranking is cut at bm25.max_postings_per_term (P7R-2).
 */
class FederatedKeyOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('fko_codes');
        Schema::dropIfExists('fko_books');
        Schema::create('fko_codes', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name');
        });
        Schema::create('fko_books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        foreach (['a1', 'B2', 'c3', 'D4', '9', '10', '100', 'e5', 'F6', '11'] as $n => $code) {
            DB::table('fko_codes')->insert(['code' => $code, 'name' => trim('laravel ' . str_repeat('laravel ', $n % 3))]);
        }
        for ($i = 1; $i <= 23; $i++) {
            // The higher the key, the better the BM25 match, so a cut ranking keeps the high keys.
            DB::table('fko_books')->insert(['title' => trim(str_repeat('laravel ', 1 + $i % 7)) . " book {$i}"]);
        }

        app(IndexManager::class)->indexBatch(FkoCode::all());
        app(IndexManager::class)->indexBatch(FkoBook::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('fko_codes');
        Schema::dropIfExists('fko_books');

        parent::tearDown();
    }

    /** Every page of $make, by paginate() and by simplePaginate(), as "Class:key", and each total paginate() gave. */
    private function walk(\Closure $make, int $per): array
    {
        $tag    = fn ($row) => class_basename($row) . ':' . $row->getKey();
        $paged  = [];
        $simple = [];
        $totals = [];
        for ($page = 1; $page <= 20; $page++) {
            $paginator = $make()->paginate($per, 'page', $page);
            $totals[]  = $paginator->total();
            $rows      = $paginator->getCollection()->map($tag)->all();
            $paged     = [...$paged, ...$rows];
            $simple    = [...$simple, ...collect($make()->simplePaginate($per, 'page', $page)->items())->map($tag)->all()];
            if ($rows === []) {
                break;
            }
        }

        return [$make()->limit(100)->get()->map($tag)->all(), $paged, $simple, array_values(array_unique($totals))];
    }

    public function test_string_keys_are_served_page_after_page_in_the_databases_order(): void
    {
        foreach (['index' => true, 'like' => false] as $path => $index) {
            $make = fn () => FederatedSearch::across([FkoCode::class])->search('laravel')->withRelevance(false)->useInvertedIndex($index);
            [$all, $paged, $simple, $totals] = $this->walk($make, 3);

            $this->assertCount(10, $all, $path);
            $this->assertSame(count($all), count(array_unique($all)), "{$path}: no row twice");
            $this->assertSame($all, $paged, "{$path}: paginate()'s pages are get()'s rows, in order");
            $this->assertSame($all, $simple, "{$path}: simplePaginate()'s too");
            $this->assertSame([10], $totals, "{$path}: total()");
            $this->assertSame(array_slice($all, 0, 4), $make()->limit(4)->get()->map(fn ($row) => class_basename($row) . ':' . $row->getKey())->all(), "{$path}: limit(4) is get()'s first four");
        }
    }

    public function test_the_total_counts_every_match_where_the_ranking_is_cut(): void
    {
        config(['fuzzy-search.bm25.max_postings_per_term' => 6]);
        $make = fn () => FederatedSearch::across([FkoBook::class])->search('laravel')->withRelevance(false)->useInvertedIndex();

        [$all, $paged, , $totals] = $this->walk($make, 4);

        $this->assertSame([23], $totals);
        $this->assertCount(23, $all);
        $this->assertSame($all, $paged);
    }
}
