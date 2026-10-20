<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * A multi-word soundex term is encoded as the column is: its first word against the column's first
 * word, its last word against the column's last. MySQL's SOUNDEX() of the whole term ("john doe",
 * J530) equalled neither code, so the native branch found nothing where the pattern fallback of the
 * other databases found the row.
 */
class SoundexMultiWordTermTest extends TestCase
{
    public function test_a_two_word_term_finds_the_full_name_on_every_path(): void
    {
        $search = fn () => User::searchOn(User::query(), 'john doe', ['name'])->using('soundex');

        $this->assertContains('John Doe', $search()->get()->pluck('name')->all());
        $this->assertSame('John Doe', $search()->first()?->name);
        $this->assertContains('John Doe', collect($search()->paginate(50)->items())->pluck('name')->all());
        $this->assertContains('John Doe', collect($search()->simplePaginate(50)->items())->pluck('name')->all());
        $this->assertSame($search()->get()->count(), $search()->count());
    }

    public function test_a_term_with_a_digit_only_word_does_not_match_every_row(): void
    {
        $this->assertSame([], User::searchOn(User::query(), '99 88', ['name'])->using('soundex')->get()->all());
    }
}
