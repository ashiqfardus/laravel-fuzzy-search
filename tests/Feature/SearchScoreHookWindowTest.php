<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** getSearchScore() puts "Saga 04" and "Saga 12" far ahead of every other row. */
class WindowBoostedUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];

    public function getSearchScore($baseScore): float
    {
        return in_array($this->name, ['Saga 04', 'Saga 12'], true) ? $baseScore * 1000 : $baseScore;
    }
}

/**
 * F5 (ruling ER-88). With a getSearchScore() override the index path re-ranked max(page end,
 * max_candidates) rows, a window that grew with each deep page, so each page was cut from a
 * different ordering and rows repeated or never showed. The hook now re-ranks a fixed window of
 * max_candidates matches; deeper pages follow the BM25 order, as the LIKE path falls back to
 * database order past its window.
 */
class SearchScoreHookWindowTest extends TestCase
{
    public function test_pages_past_max_candidates_serve_every_row_exactly_once(): void
    {
        foreach (range(1, 12) as $i) {
            DB::table('users')->insert(['name' => sprintf('Saga %02d', $i), 'email' => "saga{$i}@example.com"]);
        }
        app(IndexManager::class)->indexBatch(WindowBoostedUser::query()->where('name', 'like', 'Saga%')->get());
        config(['fuzzy-search.max_candidates' => 5, 'fuzzy-search.bm25.candidate_chunk' => 5]);

        // Equal BM25 scores rank by key. The hook re-ranks the first five: Saga 04 leads; Saga 12
        // is past the window and keeps its BM25 place.
        $expected = ['Saga 04', 'Saga 01', 'Saga 02', 'Saga 03', 'Saga 05', 'Saga 06', 'Saga 07', 'Saga 08', 'Saga 09', 'Saga 10', 'Saga 11', 'Saga 12'];
        $make     = fn () => WindowBoostedUser::search('saga')->useInvertedIndex();

        $pages = [
            'paginate'       => fn (int $page) => $make()->paginate(5, 'page', $page)->items(),
            'simplePaginate' => fn (int $page) => $make()->simplePaginate(5, 'page', $page)->items(),
            'skip'           => fn (int $page) => $make()->skip(($page - 1) * 5)->take(5)->get()->all(),
        ];

        foreach ($pages as $terminal => $page) {
            $names = collect([...$page(1), ...$page(2), ...$page(3)])->pluck('name')->all();
            $this->assertSame($expected, $names, $terminal);
        }
    }
}
