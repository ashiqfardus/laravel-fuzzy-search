<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * SF-4. The LIKE path compared each word with the stop-word list after lowering ASCII letters
 * only, so a stop word whose capital is not ASCII ("Не", "À", the whole ru list) stayed in the
 * term: "Не работает" searched "%Не работает%" and found nothing, while "не работает" and the index
 * path found the rows. Words and list are now both folded with mb_strtolower(), as the synonym
 * lookup (ruling ER-100) and the index pipeline do.
 */
class StopWordCaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->insert([
            ['name' => 'Принтер работает', 'email' => 'p1@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Сканер работает отлично', 'email' => 'p2@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Propos de nous', 'email' => 'p3@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(IndexManager::class)->indexBatch(User::all());
    }

    public function test_a_capitalised_stop_word_is_dropped_on_every_path(): void
    {
        $cases = [
            // [typed term, the same in lower case, the list]
            ['Не работает', 'не работает', 'ru'],
            // (the rest of the term keeps its case: SQLite's LIKE folds ASCII only)
            ['НЕ работает', 'не работает', 'ru'],
            ['À propos', 'à propos', 'fr'],
            // A list written with capitals is folded too.
            ['ÊTRE propos', 'propos', ['Être']],
        ];
        $paths = [
            'like'              => fn (string $term, $list) => User::search($term)->ignoreStopWords($list)->using('simple'),
            'like tokenize all' => fn (string $term, $list) => User::search($term)->ignoreStopWords($list)->using('simple')->tokenize()->matchAll(),
            'index'             => fn (string $term, $list) => User::search($term)->ignoreStopWords($list)->useInvertedIndex(),
        ];

        foreach ($cases as [$typed, $lower, $list]) {
            foreach ($paths as $path => $make) {
                $label    = json_encode($typed, JSON_UNESCAPED_UNICODE) . " {$path}";
                $expected = $make($lower, $list)->get()->pluck('name')->sort()->values()->all();

                $this->assertNotEmpty($expected, $label);
                $this->assertSame($expected, $make($typed, $list)->get()->pluck('name')->sort()->values()->all(), "{$label} get");
                $this->assertSame(count($expected), $make($typed, $list)->count(), "{$label} count");
                $this->assertSame(count($expected), $make($typed, $list)->paginate(10)->total(), "{$label} paginate");
            }
        }
    }

    public function test_a_term_of_capitalised_stop_words_only_matches_nothing(): void
    {
        DB::table('users')->insert(['name' => 'НЕ В СЕТИ', 'email' => 'p4@example.com', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, User::search('НЕ')->ignoreStopWords('ru')->count());
        $this->assertSame([], User::search('НЕ В')->ignoreStopWords('ru')->using('simple')->get()->all());
    }

    public function test_the_builder_s_own_french_list_drops_capitalised_words(): void
    {
        // A locale the config does not list falls back to the builder's built-in lists.
        config(['fuzzy-search.stop_words' => []]);

        $this->assertSame(['Propos de nous'], User::search('Être propos')->ignoreStopWords('fr')->using('simple')->get()->pluck('name')->all());
    }
}
