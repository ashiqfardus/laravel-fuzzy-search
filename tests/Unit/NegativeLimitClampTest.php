<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\LikeUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/** L9: a negative skip()/take()/limit() is 0, not a slice from the end. */
class NegativeLimitClampTest extends TestCase
{
    private const ITEMS = [['name' => 'John A'], ['name' => 'John B'], ['name' => 'John C']];

    private function names(int $skip, int $take, bool $ranked = false): array
    {
        $s = FuzzySearch::on(self::ITEMS)->search('john')->searchIn(['name'])->skip($skip)->take($take);
        if ($ranked) {
            $s->withRelevance();
        }

        return array_column($s->get()->all(), 'name');
    }

    public function test_in_memory_negative_take_is_zero(): void
    {
        $this->assertSame([], $this->names(0, -1));
        $this->assertSame([], $this->names(0, -1, true));
        $this->assertSame([], $this->names(0, 0));
    }

    public function test_in_memory_negative_skip_is_zero(): void
    {
        $this->assertSame($this->names(0, 2), $this->names(-1, 2));
        $this->assertCount(2, $this->names(-1, 2));
        $this->assertCount(2, $this->names(1, 5));
    }

    public function test_federated_negative_limit_is_zero(): void
    {
        $this->assertCount(0, FederatedSearch::across([LikeUser::class])->search('john')->searchIn(['name'])->using('like')->limit(-1)->get());
        $this->assertCount(0, FederatedSearch::across([LikeUser::class])->search('john')->searchIn(['name'])->using('like')->limit(0)->get());
        $this->assertGreaterThan(0, FederatedSearch::across([LikeUser::class])->search('john')->searchIn(['name'])->using('like')->limit(5)->get()->count());
    }
}
