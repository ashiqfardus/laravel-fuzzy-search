<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\LikeUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Ruling ER-86 (F3): one search binds at most 2,000 values, on every database, so SQL Server's
 * 2,100-parameter limit always holds. Every ~leaf, token, accent variant, synonym and column
 * multiplied up to max_patterns LIKE patterns: 32 ~leaves on 2 columns bound 6,400 and failed on
 * SQL Server with "Tried to bind parameter number 2101". The patterns are shared out instead, each
 * condition keeping at least its plain contains pattern.
 */
class BindingBudgetTest extends TestCase
{
    use FakesDriverConnections;

    private const LIMIT = 2000;

    /** The review's repro: 32 ~leaves (query.max_tokens) on a 2-column model. */
    private function reviewQuery(): string
    {
        return implode(' ', array_map(fn (int $i) => '~wordabcdefgh' . $i, range(1, 32)));
    }

    /** 32 accented words of 12 letters: 32 tokens × 2 accent variants × 2 columns. */
    private function tokenizeTerm(): string
    {
        // The term is longer than the shipped query.max_term_length (128), which would cut it to 10 words.
        config(['fuzzy-search.query.max_term_length' => 512]);

        return implode(' ', array_map(fn (int $i) => 'zoëmüllerxy' . $i, range(1, 32)));
    }

    /** @return int[] the binding count of every statement $run executes */
    private function bindingCounts(\Closure $run): array
    {
        $counts = [];
        DB::listen(function ($query) use (&$counts) {
            $counts[] = count($query->bindings);
        });
        $run();

        return $counts;
    }

    public function test_the_reviews_32_leaf_typo_query_runs_within_the_limit(): void
    {
        // Within one typo of every leaf: ~wordabcdefgh17 finds "wordabcdefgh1" with its last letter left out.
        LikeUser::query()->create(['name' => 'wordabcdefgh1 wordabcdefgh2 wordabcdefgh3', 'email' => 'w@example.com']);
        LikeUser::query()->create(['name' => 'wordabcdefgh1 wordabcdefgh2', 'email' => 'w2@example.com']);

        $make   = fn () => LikeUser::search('x')->extended($this->reviewQuery());
        $counts = $this->bindingCounts(function () use ($make, &$names, &$count, &$total) {
            $names = $make()->get()->pluck('name')->all();
            $count = $make()->count();
            $total = $make()->paginate(10)->total();
        });

        $this->assertSame(['wordabcdefgh1 wordabcdefgh2 wordabcdefgh3'], $names);
        $this->assertSame(1, $count);
        $this->assertSame(1, $total);
        $this->assertLessThanOrEqual(self::LIMIT, max($counts));
    }

    public function test_a_tokenized_accented_search_runs_within_the_limit(): void
    {
        config(['fuzzy-search.unicode.accent_insensitive' => true]);
        LikeUser::query()->create(['name' => 'Zoemullerxy9', 'email' => 'zoe@example.com']);

        $make   = fn () => LikeUser::search($this->tokenizeTerm())->using('fuzzy')->tokenize();
        $counts = $this->bindingCounts(function () use ($make, &$names, &$count) {
            $names = $make()->get()->pluck('name')->all();
            $count = $make()->count();
        });

        $this->assertSame(['Zoemullerxy9'], $names);
        $this->assertSame(1, $count);
        $this->assertLessThanOrEqual(self::LIMIT, max($counts));
    }

    /** The budget is the search's own: a caller's where() or filter() bindings never shrink its patterns. */
    public function test_the_callers_own_bindings_do_not_shrink_the_search(): void
    {
        $ids   = range(1, 1500);
        $plain = count(LikeUser::search('x')->extended($this->reviewQuery())->getBindings());

        $this->assertSame($plain + 1500, count(LikeUser::search('x')->whereIn('id', $ids)->extended($this->reviewQuery())->getBindings()));
        $this->assertSame($plain + 1500, count(LikeUser::search('x')->filterIn('id', $ids)->extended($this->reviewQuery())->getBindings()));
    }

    public function test_every_grammar_binds_the_same_values_within_the_limit(): void
    {
        $shapes = [
            'extended'       => fn (SearchBuilder $b) => $b->searchIn(['name', 'email'])->extended($this->reviewQuery()),
            'tokenize'       => fn (SearchBuilder $b) => $b->search($this->tokenizeTerm())->searchIn(['name', 'email'])->tokenize(),
            'tokenize all'   => fn (SearchBuilder $b) => $b->search($this->tokenizeTerm())->searchIn(['name', 'email'])->tokenize('all'),
            'levenshtein'    => fn (SearchBuilder $b) => $b->search($this->tokenizeTerm())->searchIn(['name', 'email'])->tokenize()->using('levenshtein'),
            'synonyms'       => fn (SearchBuilder $b) => $b->search('alpha')->searchIn(['name', 'email'])
                ->withSynonyms(['alpha' => array_map(fn (int $i) => 'synonymword' . $i, range(1, 60))]),
            'field extended' => fn (SearchBuilder $b) => $b->searchIn(['name', 'email'])
                ->extended(implode(' ', array_map(fn (int $i) => 'name:~wordabcdefgh' . $i . ' email:~wordabcdefgh' . $i, range(1, 16)))),
        ];

        foreach ($shapes as $name => $shape) {
            $bindings = [];
            foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
                if (!$this->fakeDriverAvailable($driver)) {
                    continue;
                }
                $bindings[$driver] = count($shape(new SearchBuilder($this->fakeConnectionTable($driver, 'users'), app(FuzzySearch::class)))->getBindings());
                $this->assertLessThanOrEqual(self::LIMIT, $bindings[$driver], "{$name} on {$driver}");
            }
            $this->assertCount(1, array_unique($bindings), "{$name}: " . json_encode($bindings));
            $this->assertGreaterThan(self::LIMIT / 2, $bindings['sqlite'], "{$name} still uses the budget");
        }
    }

    public function test_a_query_whose_contains_patterns_alone_exceed_the_limit_throws(): void
    {
        // 32 tokens × 71 alternatives (the word and its 70 synonyms) × 2 columns: 4,544 conditions of one pattern each.
        $synonyms = [];
        foreach (range(1, 32) as $i) {
            $synonyms['t' . $i] = array_map(fn (int $j) => "syn{$i}x{$j}", range(1, 70));
        }

        $builder = LikeUser::search(implode(' ', array_keys($synonyms)))->using('like')->tokenize()->searchIn(['name'])->withSynonyms($synonyms);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $builder->get();
            $this->fail('the search ran');
        } catch (QuerySyntaxException $e) {
            $this->assertStringContainsString('too complex', $e->getMessage());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        $this->assertSame([], $queries, 'nothing reached the database');
    }
}
