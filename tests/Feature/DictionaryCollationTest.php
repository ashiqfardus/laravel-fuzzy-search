<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * The dictionary must keep accent- and case-variants apart on every driver. MySQL/MariaDB's
 * default utf8mb4_*_ci collations consider café = cafe, so the unique key on `term` collapsed
 * the two and the posting loop then crashed with "Undefined array key" (B25).
 */
class DictionaryCollationTest extends TestCase
{
    private function assertBothTermsIndexed(User $user, array $terms): void
    {
        $termIds = DB::table('fuzzy_index_terms')->whereIn('term', $terms)->pluck('id');

        $this->assertCount(2, $termIds); // the accented and the plain form are separate rows

        // …and the document carries a posting for each of them (the email column contributes
        // its own terms, so only these two are counted).
        $this->assertSame(2, DB::table('fuzzy_index_postings')
            ->where('model_type', User::class)
            ->where('model_id', $user->getKey())
            ->whereIn('term_id', $termIds)
            ->count());
    }

    public function test_index_model_keeps_an_accent_variant_as_its_own_term(): void
    {
        $user = User::create(['name' => 'Café cafe', 'email' => 'cafe@example.com']);

        app(IndexManager::class)->indexModel($user);

        $this->assertBothTermsIndexed($user, ['café', 'cafe']);
    }

    public function test_index_batch_keeps_an_accent_variant_as_its_own_term(): void
    {
        $user = User::create(['name' => 'Résumé resume', 'email' => 'resume@example.com']);

        app(IndexManager::class)->indexBatch(collect([$user]));

        $this->assertBothTermsIndexed($user, ['résumé', 'resume']);
    }
}
