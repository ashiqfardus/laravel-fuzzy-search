<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\LikeUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

class CapitalSynonymUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'like',
        'synonyms'  => ['Zzqq' => ['alice']],
    ];
}

/**
 * A synonym key with a capital ('Laptop') never matched: the lookup lower-cases the term's ASCII
 * letters (Utf8::lowerAscii()) and looked for that exact key. withSynonyms(), which the config,
 * $searchable['synonyms'] and the query all go through, now folds each key the same way.
 */
class SynonymKeyCaseTest extends TestCase
{
    private function names($builder): array
    {
        return $builder->get()->pluck('name')->sort()->values()->all();
    }

    public function test_a_capitalised_key_matches_from_every_source_on_every_path(): void
    {
        app(IndexManager::class)->indexBatch(LikeUser::all());
        app(IndexManager::class)->indexBatch(CapitalSynonymUser::all());

        $sources = [
            'config'     => function (string $term) {
                config(['fuzzy-search.synonyms' => ['Zzqq' => ['alice']]]);

                return LikeUser::search($term);
            },
            '$searchable' => fn (string $term) => CapitalSynonymUser::search($term),
            'withSynonyms' => fn (string $term) => LikeUser::search($term)->withSynonyms(['ZZQQ' => ['alice']]),
        ];

        $seen = [];
        foreach ($sources as $source => $make) {
            foreach (['zzqq', 'Zzqq'] as $term) {
                $seen["{$source} {$term} like"]  = $this->names($make($term));
                $seen["{$source} {$term} index"] = $this->names($make($term)->useInvertedIndex()->typoTolerance(0));
                config(['fuzzy-search.synonyms' => []]);
            }
        }

        $this->assertSame(array_fill_keys(array_keys($seen), ['Alice Smith']), $seen);
    }

    public function test_keys_that_differ_only_in_case_are_one_word_and_the_later_wins(): void
    {
        $this->assertSame(
            ['zzqq' => ['bob']],
            LikeUser::search('zzqq')->withSynonyms(['zzqq' => ['alice'], 'ZzQq' => ['bob']])->getDebugInfo()['synonyms']
        );
        $this->assertSame(['Bob Johnson'], $this->names(LikeUser::search('zzqq')->withSynonyms(['zzqq' => ['alice']])->withSynonyms(['Zzqq' => ['bob']])));
    }
}
