<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchCollection;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * fuzzy_index_terms is one dictionary shared by every indexed model. A lookup that is not
 * scoped to the searched model's postings offers other models' terms — users' names and email
 * tokens on a public product search, published by FuzzySearchCollection on every empty page.
 */
class DictionaryScopeTest extends TestCase
{
    private function indexAll(string $class): void
    {
        foreach ($class::all() as $model) {
            app(IndexManager::class)->indexModel($model);
        }
    }

    /** @return string[] dictionary terms posted under $modelType */
    private function termsOf(string $modelType): array
    {
        return DB::table('fuzzy_index_terms')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('fuzzy_index_postings')
                ->whereColumn('fuzzy_index_postings.term_id', 'fuzzy_index_terms.id')
                ->where('model_type', $modelType))
            ->pluck('term')->map(fn ($term) => (string) $term)->all();
    }

    /** Users (names, email tokens) and products indexed; one product term sits a typo away from 'jonh'. */
    private function indexUsersAndProducts(): void
    {
        Product::create(['title' => 'Jonah headphones', 'price' => 49]);
        $this->indexAll(User::class);
        $this->indexAll(Product::class);
    }

    public function test_json_collection_suggestions_hold_only_the_searched_models_terms(): void
    {
        $this->indexUsersAndProducts();

        $meta = FuzzySearchCollection::fromBuilder(Product::search('jonh')->useInvertedIndex()->typoTolerance(0))
            ->with(request())['meta'];

        $this->assertNotEmpty($meta['suggestions'], 'precondition: suggestions were produced');
        $this->assertSame(
            [],
            array_values(array_diff($meta['suggestions'], $this->termsOf(Product::class))),
            'a Product search API suggested terms that exist only on other models: ' . json_encode($meta['suggestions'])
        );
    }

    public function test_did_you_mean_never_offers_another_models_token(): void
    {
        $this->indexUsersAndProducts();

        $terms = array_column(Product::search('jonh')->didYouMean(10), 'term');

        $this->assertContains('jonah', $terms);
        $this->assertSame([], array_values(array_diff($terms, $this->termsOf(Product::class))), json_encode($terms));
    }

    public function test_did_you_mean_without_an_eloquent_model_returns_nothing(): void
    {
        $this->indexAll(User::class);

        $builder = (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('jonh')->searchIn(['name']);

        $this->assertSame([], $builder->didYouMean(3), 'no model dictionary to consult: the unscoped read is the leak');
    }

    public function test_typo_expansion_is_not_crowded_out_by_another_models_terms(): void
    {
        // 'example' and 'com' sit on all seven users; 'jonas' on three products. A two-term pool
        // drawn from the whole dictionary is filled by the users' terms, leaving no room for the
        // product term one edit from the query.
        config(['fuzzy-search.bm25.fuzzy.candidate_pool' => 2]);
        foreach (['Jonas speaker', 'Jonas lamp', 'Jonas desk'] as $title) {
            Product::create(['title' => $title, 'price' => 10]);
        }
        $this->indexAll(User::class);
        $this->indexAll(Product::class);

        $titles = Product::search('jonaz')->useInvertedIndex()->get()->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Jonas speaker', 'Jonas lamp', 'Jonas desk'], $titles);
    }

    public function test_as_you_type_prefix_expansion_is_not_crowded_out_by_another_models_terms(): void
    {
        // john, johnny and johnson exist only on users. With three prefix slots drawn from the
        // whole dictionary they took every one, and the product's own "jonas" was never searched.
        config(['fuzzy-search.bm25.prefix.max_expansions' => 3]);
        Product::create(['title' => 'Jonas speaker', 'price' => 10]);
        $this->indexAll(User::class);
        $this->indexAll(Product::class);

        $titles = Product::search('jo')->useInvertedIndex()->asYouType()->typoTolerance(0)->get()->pluck('title')->all();

        $this->assertSame(['Jonas speaker'], $titles);
    }
}
