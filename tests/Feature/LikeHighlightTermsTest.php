<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/** On the LIKE path highlight() marks every term the search used: tokens and synonyms, not only the whole typed string. */
class LikeHighlightTermsTest extends TestCase
{
    public function test_tokens_are_highlighted(): void
    {
        $rows = User::searchOn(User::query(), 'johnny bob', ['name'])->using('simple')->tokenize()->highlight()->get()->keyBy('name');

        $this->assertSame('<em>Johnny</em> Bravo', $rows['Johnny Bravo']->_highlighted['name']);
        $this->assertSame('<em>Bob</em> Johnson', $rows['Bob Johnson']->_highlighted['name']);
        $this->assertNotEmpty($rows['Bob Johnson']->_matches);
    }

    public function test_synonyms_are_highlighted(): void
    {
        $rows = User::searchOn(User::query(), 'jon', ['name'])->using('simple')->withSynonyms(['jon' => ['johnny']])->highlight()->get()->keyBy('name');

        $this->assertSame('<em>Jon</em> Snow', $rows['Jon Snow']->_highlighted['name']);
        $this->assertSame('<em>Johnny</em> Bravo', $rows['Johnny Bravo']->_highlighted['name']);
    }

    public function test_a_single_term_is_highlighted_as_before(): void
    {
        $row = User::searchOn(User::query(), 'oh', ['name'])->using('simple')->highlight()->get()->firstWhere('name', 'Bob Johnson');

        $this->assertSame('Bob J<em>oh</em>nson', $row->_highlighted['name']);
    }
}
