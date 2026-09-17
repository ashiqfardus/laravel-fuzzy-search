<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Support\StopWords;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class StopWordsTest extends TestCase
{
    public function test_arrays_are_lower_cased_and_de_duplicated(): void
    {
        $this->assertSame(['the', 'a'], StopWords::resolve(['The', 'a', 'THE']));
        $this->assertSame([], StopWords::resolve(null));
    }

    public function test_a_string_is_a_file_with_one_word_per_line(): void
    {
        $path = sys_get_temp_dir() . '/fuzzy-stop-' . uniqid() . '.txt';
        file_put_contents($path, "# comment\nDer\n\n die \ndas\n");

        $this->assertSame(['der', 'die', 'das'], StopWords::resolve($path));
        unlink($path);
    }

    public function test_a_missing_file_names_the_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('/no/such/stop-words.txt');
        StopWords::resolve('/no/such/stop-words.txt');
    }

    public function test_the_four_new_locales_ship_with_lists(): void
    {
        foreach (['it', 'pt', 'nl', 'ru'] as $locale) {
            $list = StopWords::forLocale($locale);
            $this->assertGreaterThanOrEqual(10, count($list), $locale);
        }
        $this->assertContains('и', StopWords::forLocale('ru'));
    }

    public function test_ignore_stop_words_by_locale_reads_config_then_the_built_in_lists(): void
    {
        config(['fuzzy-search.stop_words.xx' => ['zz']]);

        $this->assertSame(['zz'], User::search('a')->ignoreStopWords('xx')->getDebugInfo()['stop_words']);
        $this->assertContains('the', User::search('a')->ignoreStopWords('en')->getDebugInfo()['stop_words']);
        $this->assertContains('mais', User::search('a')->ignoreStopWords('fr')->getDebugInfo()['stop_words']);
    }

    public function test_a_locale_configured_as_a_file_feeds_the_index_pipeline(): void
    {
        $path = sys_get_temp_dir() . '/fuzzy-stop-' . uniqid() . '.txt';
        file_put_contents($path, "pro\n");
        config(['fuzzy-search.stop_words.en' => $path, 'fuzzy-search.locale' => 'en']);
        $this->app->forgetInstance(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);

        $this->assertSame(['the'], app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)->processTerms('the pro'));
        unlink($path);
    }
}
