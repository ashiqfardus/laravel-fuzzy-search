<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CappedTieItem extends Model
{
    use Searchable;

    protected $table   = 'capped_tie_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

class CappedTieCode extends Model
{
    use Searchable;

    protected $table      = 'capped_tie_codes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

/**
 * SE-3 (round 11). rank() cuts the (document, term) rows at bm25.max_postings_per_term, ordered by
 * weighted frequency only. When the cap fell inside a group of equal weighted frequency (the usual
 * case: it takes few values), which of those rows were kept was the database's choice, and changed
 * with its plan (a statistics refresh, the ANALYZE the package runs after a rebuild), so a user
 * paging a capped search was served pages cut from different rankings. Equal rows are now cut by
 * model_id, then term_id: the capped ranking is a function of the index. The rows here all weigh
 * the same, and were indexed in shuffled order, so the order the database stores them in is not the
 * order the cut must follow.
 */
class CappedRankingTieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('capped_tie_items');
        Schema::create('capped_tie_items', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::dropIfExists('capped_tie_codes');
        Schema::create('capped_tie_codes', function ($table) {
            $table->string('code', 20)->primary();
            $table->string('name');
        });

        DB::table('capped_tie_items')->insert(array_fill(0, 60, ['name' => 'common alpha']));
        // Keys of one length, lower-case letters and digits: every database's collation orders them byte-wise.
        DB::table('capped_tie_codes')->insert(array_map(fn ($i) => ['code' => sprintf('k%03d', ($i * 37) % 60), 'name' => 'common alpha'], range(0, 59)));

        mt_srand(3);
        foreach ([CappedTieItem::class, CappedTieCode::class] as $class) {
            app(IndexManager::class)->indexBatch($class::all()->shuffle());
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('capped_tie_items');
        Schema::dropIfExists('capped_tie_codes');

        parent::tearDown();
    }

    /**
     * The documents of the first $cap (document, term) rows of $class's postings for $terms, every
     * row weighing the same: by model_id (byte-wise here), then term_id.
     *
     * @return string[]
     */
    private function expected(string $class, array $terms, int $cap): array
    {
        $rows = DB::table('fuzzy_index_postings as p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', $class)->whereIn('t.term', $terms)
            ->get(['p.model_id', 'p.term_id'])
            ->map(fn ($row) => [(string) $row->model_id, (int) $row->term_id])->all();

        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]) ?: $a[1] <=> $b[1]);
        $documents = array_values(array_unique(array_column(array_slice($rows, 0, $cap), 0)));
        sort($documents);

        return $documents;
    }

    /** @return string[] the documents rank() kept, as strings, sorted */
    private function ranked(string $class, array $terms): array
    {
        $documents = array_map('strval', array_keys(app(Bm25Scorer::class)->rank($terms, $class, ['name' => 10])));
        sort($documents);

        return $documents;
    }

    private function analyze(): void
    {
        match ($this->dbDriver) {
            'mysql', 'mariadb' => DB::select('ANALYZE TABLE fuzzy_index_postings, fuzzy_index_documents, fuzzy_index_terms'),
            'pgsql'            => DB::statement('ANALYZE fuzzy_index_postings, fuzzy_index_documents, fuzzy_index_terms'),
            'sqlsrv'           => DB::statement('UPDATE STATISTICS fuzzy_index_postings WITH FULLSCAN'),
            default            => DB::statement('ANALYZE'),
        };
    }

    public function test_a_capped_ranking_cuts_equal_rows_by_document_then_term(): void
    {
        foreach ([CappedTieItem::class, CappedTieCode::class] as $class) {
            foreach ([['common'], ['common', 'alpha']] as $terms) {
                foreach ([25, 7] as $cap) {
                    config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);
                    $at       = class_basename($class) . ' ' . json_encode($terms) . " cap {$cap}";
                    $expected = $this->expected($class, $terms, $cap);

                    $this->assertCount(intdiv($cap + count($terms) - 1, count($terms)), $expected, $at);
                    $this->assertSame($expected, $this->ranked($class, $terms), "{$at}: the cut");
                    $this->analyze();
                    $this->assertSame($expected, $this->ranked($class, $terms), "{$at}: the cut after the statistics change");
                }
            }
        }
    }

    /** Relevance-order pages of a capped search, one request each, with the statistics refreshed between them: each match once. */
    public function test_capped_relevance_pages_partition_the_ranking_across_a_statistics_refresh(): void
    {
        config(['fuzzy-search.bm25.max_postings_per_term' => 25]);

        foreach ([CappedTieItem::class, CappedTieCode::class] as $class) {
            $make   = fn () => $class::search('common alpha')->useInvertedIndex()->typoTolerance(0);
            $served = [];

            for ($page = 1; $page <= 4; $page++) {
                $paginator = $make()->paginate(4, 'page', $page);
                $this->assertSame(13, $paginator->total(), class_basename($class) . " page {$page}");
                $served = [...$served, ...array_map(fn ($model) => (string) $model->getKey(), $paginator->items())];
                $this->analyze();
            }

            sort($served);
            $this->assertSame($this->expected($class, ['common', 'alpha'], 25), $served, class_basename($class) . ': every match once');
        }
    }
}
