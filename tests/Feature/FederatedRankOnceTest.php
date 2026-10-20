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

class RankOnceBook extends Model
{
    use Searchable;

    protected $table   = 'rank_once_books';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10, 'summary' => 5]];
}

class RankOnceAuthor extends Model
{
    use Searchable;

    protected $table   = 'rank_once_authors';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'bio' => 5]];
}

/** No Searchable trait: searched with LIKE through whereFuzzyMultiple(). */
class RankOnceNote extends Model
{
    protected $table   = 'rank_once_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['body']];
}

/**
 * WB-1: FederatedSearch::paginate() counted every model (a full BM25 ranking each), then ranked it
 * again for the page: two rankings per model per request, 99.7% of a federated page at 1M rows. It
 * now reads each model's total and first rows from one SearchBuilder::paginate(), one ranking, and
 * serves the same total and rows as before: the counts getCounts() reports, and the rows get()
 * merges for the same depth.
 */
class FederatedRankOnceTest extends TestCase
{
    private const TABLES = ['rank_once_books', 'rank_once_authors', 'rank_once_notes'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('rank_once_books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('summary');
        });
        Schema::create('rank_once_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bio');
        });
        Schema::create('rank_once_notes', function (Blueprint $table) {
            $table->id();
            $table->string('body');
        });

        $books = [];
        for ($i = 1; $i <= 30; $i++) {
            $books[] = ['title' => "Laravel book $i", 'summary' => $i % 3 === 0 ? 'more laravel inside' : 'plain'];
        }
        DB::table('rank_once_books')->insert($books);

        $authors = [];
        for ($i = 1; $i <= 20; $i++) {
            $authors[] = ['name' => "Author $i", 'bio' => $i % 2 === 0 ? 'writes about laravel' : 'writes laravel laravel'];
        }
        DB::table('rank_once_authors')->insert($authors);

        DB::table('rank_once_notes')->insert(array_map(fn ($i) => ['body' => "laravel note $i"], range(1, 12)));

        app(IndexManager::class)->indexBatch(RankOnceBook::all());
        app(IndexManager::class)->indexBatch(RankOnceAuthor::all());
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /** How many BM25 rankings $work runs: Bm25Scorer::rank()'s grouped postings read, by its doc_len alias. */
    private function rankings(\Closure $work): int
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        $work();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return count(array_filter($sql, fn (string $s) => str_contains($s, 'doc_len')));
    }

    private function indexed(array $models = [RankOnceBook::class, RankOnceAuthor::class]): FederatedSearch
    {
        return FederatedSearch::across($models)->search('laravel')->useInvertedIndex();
    }

    public function test_paginate_ranks_each_model_once(): void
    {
        $this->assertSame(2, $this->rankings(fn () => $this->indexed()->paginate(10)), 'paginate()');
        $this->assertSame(2, $this->rankings(fn () => $this->indexed()->paginate(10, 'page', 3)), 'a deeper page');
        $this->assertSame(2, $this->rankings(fn () => $this->indexed()->get()), 'get()');
        $this->assertSame(2, $this->rankings(fn () => $this->indexed()->simplePaginate(10)), 'simplePaginate()');
        $this->assertSame(2, $this->rankings(fn () => $this->indexed()->getCounts()), 'getCounts()');
        $this->assertSame(2, $this->rankings(fn () => $this->indexed()->withRelevance(false)->paginate(10)), 'without relevance');
    }

    /** @return array<string, array{\Closure}> */
    public static function searches(): array
    {
        return [
            'the index'                 => [fn (self $test) => $test->indexed()],
            'the index, no relevance'   => [fn (self $test) => $test->indexed()->withRelevance(false)],
            'the index, per-model cap'  => [fn (self $test) => $test->indexed()->limitPerModel(7)],
            'the index, a word apiece'  => [fn (self $test) => FederatedSearch::across([RankOnceBook::class, RankOnceAuthor::class])->search('book')->useInvertedIndex()],
            'like, with a plain model'  => [fn (self $test) => FederatedSearch::across([RankOnceBook::class, RankOnceAuthor::class, RankOnceNote::class])->search('laravel')],
            'the index, a plain model'  => [fn (self $test) => $test->indexed([RankOnceBook::class, RankOnceNote::class, RankOnceAuthor::class])],
            'no match'                  => [fn (self $test) => FederatedSearch::across([RankOnceBook::class, RankOnceAuthor::class])->search('zzzqqq')->useInvertedIndex()],
        ];
    }

    /**
     * The total is the sum of getCounts(), and each page holds the rows get() merges for its depth,
     * with the same scores; simplePaginate() serves the same rows. max_candidates at 25 caps a model's
     * count and rows (books match 30).
     *
     * @dataProvider searches
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('searches')]
    public function test_the_total_and_rows_are_those_of_get_and_get_counts(\Closure $search): void
    {
        config(['fuzzy-search.max_candidates' => 25]);

        $total = array_sum($search($this)->getCounts());
        $rows  = fn ($items) => collect($items)->map(fn ($item) => [$item->_model_type, $item->getKey(), $item->_score ?? null])->all();

        foreach ([[10, 1], [10, 2], [10, 4], [7, 3], [25, 2]] as [$perPage, $page]) {
            $paginator = $search($this)->paginate($perPage, 'page', $page);
            $expected  = $search($this)->limit($perPage * $page)->get()->slice(($page - 1) * $perPage, $perPage)->values();

            $this->assertSame($total, $paginator->total(), "total, {$perPage} per page, page {$page}");
            $this->assertSame($rows($expected), $rows($paginator->items()), "rows, {$perPage} per page, page {$page}");
            $this->assertSame($rows($expected), $rows(array_slice($search($this)->simplePaginate($perPage, 'page', $page)->items(), 0, $perPage)), "simplePaginate(), {$perPage} per page, page {$page}");
        }
    }

    public function test_an_empty_term_still_throws_and_invalid_bytes_match_nothing(): void
    {
        $this->assertSame(0, FederatedSearch::across([RankOnceBook::class])->search("\xFF")->useInvertedIndex()->paginate(10)->total());

        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException::class);
        FederatedSearch::across([RankOnceBook::class])->search('')->useInvertedIndex()->paginate(10);
    }
}
