<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/** didYouMean() corrects a phrase word by word; a word the dictionary holds stays. */
class DidYouMeanPhraseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(IndexManager::class)->indexBatch(User::all());
    }

    public function test_a_misspelled_word_of_a_phrase_is_replaced_in_place(): void
    {
        $this->assertSame('charlie brown', User::search('charlie brwn')->didYouMean(3)[0]['term']);
        $this->assertSame('alice smith', User::search('alise smith')->didYouMean(3)[0]['term']);
        $this->assertContains('snow', array_map(fn ($a) => explode(' ', $a['term'])[1], User::search('jon snw')->didYouMean(3)));
    }

    public function test_every_misspelled_word_is_corrected_in_the_first_phrase(): void
    {
        $first = User::search('charli brwn')->didYouMean(3)[0];

        $this->assertSame('charlie brown', $first['term']);
        $this->assertSame(2, $first['distance']);
    }

    public function test_a_phrase_of_known_words_or_hopeless_words_offers_nothing(): void
    {
        $this->assertSame([], User::search('charlie brown')->didYouMean(3));
        $this->assertSame([], User::search('charlie zzzzzzzz')->didYouMean(3));
    }

    public function test_a_single_word_still_corrects_as_before(): void
    {
        $this->assertContains('jon', array_column(User::search('jonh')->didYouMean(3), 'term'));
    }
}
