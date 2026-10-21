<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * query.max_tokens bounds the distinct terms of a plain index-path query too: the first 32 are
 * used, the rest are ignored, and nothing throws. Each term reads up to max_postings_per_term rows.
 */
class IndexQueryTermCapTest extends TestCase
{
    /** @return list<string> forty distinct two-letter words, 120 characters with their spaces */
    private function words(): array
    {
        return array_merge(
            array_map(fn ($c) => 'k' . $c, range('a', 'z')),
            array_map(fn ($c) => 'l' . $c, range('a', 'n'))
        );
    }

    public function test_a_plain_index_query_uses_its_first_max_tokens_distinct_terms(): void
    {
        $words = $this->words();
        $this->assertCount(40, $words);
        User::create(['name' => $words[0], 'email' => 'first@example.com']);   // inside the first 32
        User::create(['name' => $words[39], 'email' => 'last@example.com']);   // the 40th
        foreach (User::all() as $user) {
            app(IndexManager::class)->indexModel($user);
        }

        $search = User::search(implode(' ', $words))->useInvertedIndex()->typoTolerance(0)->searchIn(['name', 'email']);

        $this->assertSame(['first@example.com'], $search->get()->pluck('email')->all());
    }

    public function test_the_cap_follows_the_config(): void
    {
        config(['fuzzy-search.query.max_tokens' => 2]);
        User::create(['name' => 'ka kb kc', 'email' => 'three@example.com']);
        User::create(['name' => 'kc', 'email' => 'kc@example.com']);
        foreach (User::all() as $user) {
            app(IndexManager::class)->indexModel($user);
        }

        $found = User::search('ka kb kc')->useInvertedIndex()->typoTolerance(0)->searchIn(['name'])->get()->pluck('email')->all();

        $this->assertSame(['three@example.com'], $found);
    }
}
