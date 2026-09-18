<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../TestModels.php';

class TermLengthTest extends TestCase
{
    public function test_dictionary_has_an_indexed_term_length_column(): void
    {
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'term_length'));
    }

    public function test_index_model_writes_the_character_length_of_every_term(): void
    {
        // Email intentionally avoids the "cafe" local-part: MySQL's default accent-insensitive
        // collation treats "café" and "cafe" as the same unique term, which would collapse the
        // two rows this test needs to stay separate (a pre-existing dictionary characteristic,
        // unrelated to term_length).
        $user = User::create(['name' => 'Café Latte', 'email' => 'guest@example.com']);

        app(IndexManager::class)->indexModel($user);

        $lengths = DB::table('fuzzy_index_terms')->whereIn('term', ['café', 'latte'])->pluck('term_length', 'term')->all();
        $this->assertSame(['café' => 4, 'latte' => 5], array_map('intval', $lengths));
    }

    public function test_index_batch_writes_term_length_too(): void
    {
        $a = User::create(['name' => 'Zebra Crossing', 'email' => 'z1@example.com']);
        $b = User::create(['name' => 'Zebra Stripes', 'email' => 'z2@example.com']);

        app(IndexManager::class)->indexBatch(collect([$a, $b]));

        $this->assertSame(5, (int) DB::table('fuzzy_index_terms')->where('term', 'zebra')->value('term_length'));
        $this->assertSame(7, (int) DB::table('fuzzy_index_terms')->where('term', 'stripes')->value('term_length'));
    }

    public function test_the_255_limit_on_a_token_counts_characters_not_bytes(): void
    {
        // Unsaved models: the users.name column could not hold the 256-character case, and the
        // indexer only reads attributes and the key.
        $index = fn (int $id, string $name) => app(IndexManager::class)->indexModel(
            (new User(['name' => $name, 'email' => "t{$id}@example.com"]))->forceFill(['id' => $id])
        );

        $bengali  = str_repeat('ক', 100); // 300 bytes
        $cyrillic = str_repeat('я', 200); // 400 bytes
        $tooLong  = str_repeat('ক', 256);

        $index(9001, $bengali);
        $index(9002, $cyrillic);
        $index(9003, $tooLong . ' widget');

        $lengths = array_map('intval', DB::table('fuzzy_index_terms')->whereIn('term', [$bengali, $cyrillic, $tooLong])->pluck('term_length', 'term')->all());
        ksort($lengths);
        $expected = [$bengali => 100, $cyrillic => 200];
        ksort($expected);
        $this->assertSame($expected, $lengths);
        $this->assertTrue(DB::table('fuzzy_index_terms')->where('term', 'widget')->exists());
    }

    public function test_new_config_keys_are_published_with_defaults(): void
    {
        $config = require __DIR__ . '/../../config/fuzzy-search.php';

        $this->assertSame(500, $config['bm25']['fuzzy']['candidate_pool']);
        $this->assertSame(5, $config['bm25']['fuzzy']['max_expansions']);
        $this->assertTrue($config['bm25']['fuzzy']['damping']);
        $this->assertSame(10, $config['bm25']['prefix']['max_expansions']);
    }
}
