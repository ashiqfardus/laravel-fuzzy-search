<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TypoPoolTieWord extends Model
{
    use Searchable;

    protected $table   = 'typo_pool_tie_words';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['word' => 1]];
}

/**
 * R11-M5 / TE-1 (round 12). The typo expansion, didYouMean() and the prefix expansion read the most
 * common dictionary words of the model: `order by doc_count desc limit pool`, one read per length,
 * merged in PHP. Neither the reads nor the merge broke a tie, and most words share a small doc_count,
 * so the cut usually fell inside a group of equal counts: which words entered was the plan's order,
 * the length order of the merge, or the order the dictionary was written in. A statistics refresh
 * (InnoDB's background recalculation, the ANALYZE after a rebuild) changed what the same typo search
 * found. Equal counts are now cut by id, the newest word first, in the reads and in the merge. Every
 * word here is in one row, so every count is 1, and the word the search wants was indexed last.
 */
class TypoPoolTieTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('typo_pool_tie_words');
        Schema::create('typo_pool_tie_words', function (Blueprint $table) {
            $table->id();
            $table->string('word');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('typo_pool_tie_words');

        parent::tearDown();
    }

    /** One row a word, each indexed on its own, so the dictionary ids follow $words. */
    private function index(array $words): void
    {
        foreach ($words as $word) {
            app(IndexManager::class)->indexModel(TypoPoolTieWord::create(['word' => $word]));
        }
    }

    /**
     * $read under three plans: as built, after fresh statistics, and with no index on term_length
     * (a scan and a sort, where the database's tie order is not the index's).
     *
     * @return array<string, mixed>
     */
    private function underEveryPlan(\Closure $read): array
    {
        $out = ['as built' => $read()];

        $tables = array_map(fn (string $table) => DB::getQueryGrammar()->wrapTable($table), ['fuzzy_index_terms', 'fuzzy_index_postings']);
        match ($this->dbDriver) {
            'sqlite'           => DB::statement('analyze'),
            'pgsql'            => DB::statement('analyze ' . implode(', ', $tables)),
            'mysql', 'mariadb' => DB::statement('analyze table ' . implode(', ', $tables)),
            default            => array_map(fn (string $table) => DB::statement("update statistics {$table}"), $tables),
        };
        $out['after analyze'] = $read();

        $prefix = DB::connection()->getTablePrefix();
        $names  = array_map(fn ($row) => strtolower((string) ((object) $row)->name), match ($this->dbDriver) {
            'sqlite' => DB::select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$prefix . 'fuzzy_index_terms']),
            'pgsql'  => DB::select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$prefix . 'fuzzy_index_terms']),
            'sqlsrv' => DB::select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$prefix . 'fuzzy_index_terms']),
            default  => DB::select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$prefix . 'fuzzy_index_terms']),
        });
        foreach ($names as $name) {
            if (str_starts_with($name, 'fuzzy_index_terms_term_length')) {
                Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
        $out['without the length index'] = $read();

        return $out;
    }

    /** @return array<string, list<string>> each plan's value, expected on every plan */
    private function expectEverywhere(mixed $expected, array $byPlan): array
    {
        return array_fill_keys(array_keys($byPlan), $expected);
    }

    /**
     * Five-, six- and seven-letter words, all at doc_count 1, then "kitten". With a pool of 3, a
     * typo of kitten (one edit, so lengths 5 to 7) takes the three newest words of the window:
     * kitten among them. The length-ordered merge took three five-letter words, and each length's
     * read three words of the plan's choosing.
     */
    public function test_the_typo_pool_is_cut_at_a_tie_by_the_newest_word_on_every_path_and_plan(): void
    {
        $this->index(['apple', 'baker', 'cider', 'delta', 'eagle', 'banana', 'cherry', 'dragon', 'eleven', 'forest', 'gardens', 'harbors', 'islands', 'kitten']);
        config(['fuzzy-search.bm25.fuzzy.candidate_pool' => 3]);

        // The pool itself, before the distance filter: within 7 edits, every word of the window.
        $newest = ['kitten', 'islands', 'harbors'];
        $pool   = array_values(array_filter($newest, fn (string $word) => TermExpander::distance('kittex', $word) <= 7));

        $search = fn () => TypoPoolTieWord::search('kittex')->typoTolerance(1)->useInvertedIndex();
        $byPlan = $this->underEveryPlan(fn () => [
            'pool'           => array_column((new TermExpander)->candidates('kittex', 7, 3, TypoPoolTieWord::class, visibleOnly: false), 'term'),
            'candidates'     => array_column((new TermExpander)->candidates('kittex', 1, 3, TypoPoolTieWord::class, visibleOnly: false), 'term'),
            'get'            => $search()->get()->pluck('word')->all(),
            'first'          => $search()->first()?->word,
            'paginate'       => [$search()->paginate(5)->total(), collect($search()->paginate(5)->items())->pluck('word')->all()],
            'simplePaginate' => collect($search()->simplePaginate(5)->items())->pluck('word')->all(),
            'count'          => $search()->count(),
        ]);

        $this->assertSame($this->expectEverywhere([
            'pool'           => $pool,
            'candidates'     => ['kitten'],
            'get'            => ['kitten'],
            'first'          => 'kitten',
            'paginate'       => [1, ['kitten']],
            'simplePaginate' => ['kitten'],
            'count'          => 1,
        ], $byPlan), $byPlan);
    }

    /**
     * didYouMean() reads a pool of 300 within three edits of a six-letter term (lengths 3 to 9).
     * 320 four-letter words that share no letter with kittex (6 edits away, never offered), then
     * kitten: it is the newest, so it is in the pool. The length-ordered merge filled the pool with
     * the four-letter words.
     */
    public function test_did_you_mean_keeps_the_newest_word_at_its_pool_cut_on_every_plan(): void
    {
        $letters = str_split('bqvwyz');
        $fillers = [];
        for ($i = 0; count($fillers) < 320; $i++) {
            $fillers[] = $letters[intdiv($i, 216) % 6] . $letters[intdiv($i, 36) % 6] . $letters[intdiv($i, 6) % 6] . $letters[$i % 6];
        }
        foreach (array_chunk($fillers, 100) as $chunk) {
            TypoPoolTieWord::insert(array_map(fn (string $word) => ['word' => $word], $chunk));
        }
        app(IndexManager::class)->indexBatch(TypoPoolTieWord::all());
        $this->index(['kitten']);

        $byPlan = $this->underEveryPlan(fn () => array_column(TypoPoolTieWord::search('kittex')->didYouMean(3), 'term'));

        $this->assertSame($this->expectEverywhere(['kitten'], $byPlan), $byPlan);
    }

    /**
     * suggest() and asYouType() take the most common words that start with the prefix: here five
     * at doc_count 1, indexed so that the newest two are not the first two in term order.
     */
    public function test_the_prefix_expansion_is_cut_at_a_tie_by_the_newest_word_on_every_plan(): void
    {
        $this->index(['kitbag', 'kitchen', 'kites', 'kitten', 'kitsch']);
        config(['fuzzy-search.bm25.prefix.max_expansions' => 2]);

        $byPlan = $this->underEveryPlan(fn () => [
            'prefix'    => array_keys((new TermExpander)->prefix('kit', 2, TypoPoolTieWord::class, visibleOnly: false)),
            'suggest'   => TypoPoolTieWord::search('kit')->suggestFrom('index')->suggest(2),
            'asYouType' => TypoPoolTieWord::search('kit')->asYouType()->useInvertedIndex()->get()->pluck('word')->sort()->values()->all(),
        ]);

        $this->assertSame($this->expectEverywhere([
            'prefix'    => ['kitsch', 'kitten'],
            'suggest'   => ['kitsch', 'kitten'],
            'asYouType' => ['kitsch', 'kitten'],
        ], $byPlan), $byPlan);
    }
}
