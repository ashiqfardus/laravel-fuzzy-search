<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * A non-ASCII capitalised term typed exactly as stored finds its row. The drivers once
 * lower-cased the term with mb_strtolower(), so 'Лев' became the pattern '%лев%', and SQLite's
 * LIKE, which folds ASCII only, no longer matched "Лев Толстой" (v2.0.1 kept '%Лев%'). The
 * other databases fold case in LIKE themselves, so the test holds on all five.
 */
class CapitalisedMultibyteTermTest extends TestCase
{
    private const ROWS = ['Лев' => 'Лев Толстой', 'Éva' => 'Éva Green', 'МОСКВА' => 'МОСКВА СИТИ'];

    protected function setUp(): void
    {
        parent::setUp();

        $i = 0;
        foreach (self::ROWS as $name) {
            $this->app['db']->table('users')->insert([
                'name' => $name, 'email' => 'mb' . ++$i . '@example.com', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_a_capitalised_multibyte_term_typed_as_stored_finds_its_row_on_every_path(): void
    {
        $found = [];
        foreach (['fuzzy', 'levenshtein', 'trigram'] as $algorithm) {
            foreach (self::ROWS as $term => $name) {
                $search = fn () => User::search($term)->searchIn(['name'])->using($algorithm);

                $found["{$algorithm} {$term}"] = [
                    'get'            => $search()->get()->contains('name', $name),
                    'first'          => $search()->first()?->name === $name,
                    'paginate'       => collect($search()->paginate(10)->items())->contains('name', $name),
                    'simplePaginate' => collect($search()->simplePaginate(10)->items())->contains('name', $name),
                    'count'          => $search()->count() >= 1,
                ];
            }
        }

        $this->assertSame(
            array_fill_keys(array_keys($found), ['get' => true, 'first' => true, 'paginate' => true, 'simplePaginate' => true, 'count' => true]),
            $found
        );
    }
}
