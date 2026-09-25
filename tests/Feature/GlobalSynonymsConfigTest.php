<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\LikeUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

class ModelSynonymsUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'like',
        'synonyms'  => ['zzqq' => ['jane']],
    ];
}

/**
 * Ruling ER-80: config('fuzzy-search.synonyms') is every builder's default synonyms, exactly as
 * if withSynonyms() had been called first; the model's $searchable['synonyms'] and a query's
 * withSynonyms() merge on top, so a key they also set is theirs.
 */
class GlobalSynonymsConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['fuzzy-search.synonyms' => ['zzqq' => ['alice'], 'yyww' => ['bob']]]);
    }

    private function names($builder): array
    {
        return $builder->get()->pluck('name')->sort()->values()->all();
    }

    public function test_config_synonyms_apply_on_every_path(): void
    {
        app(IndexManager::class)->indexBatch(LikeUser::all());

        $this->assertSame(['Alice Smith'], $this->names(LikeUser::search('zzqq')));
        $this->assertSame(['Alice Smith'], $this->names(LikeUser::search('zzqq')->useInvertedIndex()->typoTolerance(0)));
        $this->assertSame(['Alice Smith'], $this->names(LikeUser::search('zzqq')->tokenize()));
        $this->assertSame(1, LikeUser::search('zzqq')->count());
        $this->assertSame(1, LikeUser::search('zzqq')->paginate(10)->total());
        $this->assertSame(['zzqq' => ['alice'], 'yyww' => ['bob']], LikeUser::search('zzqq')->getDebugInfo()['synonyms']);
        $this->assertSame(
            ['Alice Smith'],
            FederatedSearch::across([LikeUser::class])->search('zzqq')->searchIn(['name' => 1])->using('like')->get()->pluck('name')->all()
        );
    }

    public function test_they_behave_exactly_as_with_synonyms(): void
    {
        $fromConfig = LikeUser::search('zzqq')->withRelevance()->get();

        config(['fuzzy-search.synonyms' => []]);
        $fromQuery = LikeUser::search('zzqq')->withSynonyms(['zzqq' => ['alice'], 'yyww' => ['bob']])->withRelevance()->get();

        $this->assertSame($fromQuery->toArray(), $fromConfig->toArray());
    }

    public function test_a_query_override_wins_and_other_keys_stay(): void
    {
        $this->assertSame(['Jane Doe'], $this->names(LikeUser::search('zzqq')->withSynonyms(['zzqq' => ['jane']])));
        $this->assertSame(['Bob Johnson'], $this->names(LikeUser::search('yyww')->withSynonyms(['zzqq' => ['jane']])));
    }

    public function test_the_models_synonyms_win_over_the_config_and_a_query_wins_over_both(): void
    {
        $this->assertSame(['Jane Doe'], $this->names(ModelSynonymsUser::search('zzqq')));
        $this->assertSame(['Bob Johnson'], $this->names(ModelSynonymsUser::search('yyww')));
        $this->assertSame(['Jon Snow'], $this->names(ModelSynonymsUser::search('zzqq')->withSynonyms(['zzqq' => ['snow']])));
    }

    public function test_the_cache_key_changes_with_the_config_synonyms(): void
    {
        $this->assertSame(['Alice Smith'], $this->names(LikeUser::search('zzqq')->cache()));

        config(['fuzzy-search.synonyms' => ['zzqq' => ['bob']]]);

        $this->assertSame(['Bob Johnson'], $this->names(LikeUser::search('zzqq')->cache()));
    }
}
