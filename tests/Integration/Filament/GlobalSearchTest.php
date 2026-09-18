<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration\Filament;

use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Support\HtmlString;

class GlobalSearchTest extends FilamentTestCase
{
    public function test_a_typo_finds_the_record(): void
    {
        // 'jonh' is one edit from "Jon Snow" and two from "John Doe": relevance ordering puts
        // Jon Snow first, so assert presence, not position.
        $results = UserResource::getGlobalSearchResults('jonh');

        $this->assertNotEmpty($results);
        $john = $results->first(fn (GlobalSearchResult $r) => (string) $r->title === 'John Doe');
        $this->assertNotNull($john);
        $this->assertSame('/admin/users/1', $john->url);
        $this->assertSame('john@example.com', $john->details['Email']);
    }

    public function test_matching_attributes_are_added_to_details_highlighted(): void
    {
        // LIKE-path highlighting marks literal occurrences, so an exact term is what gets marked.
        $john = UserResource::getGlobalSearchResults('john')->first(fn (GlobalSearchResult $r) => (string) $r->title === 'John Doe');

        $this->assertNotNull($john);
        $this->assertInstanceOf(HtmlString::class, $john->details['Name']);
        $this->assertSame('<mark>John</mark> Doe', (string) $john->details['Name']);
        $this->assertInstanceOf(HtmlString::class, $john->details['Email']); // john@example.com matches too — highlighted entry replaces the plain one
        $this->assertStringContainsString('<mark>john</mark>', (string) $john->details['Email']);
    }

    public function test_a_non_matching_column_is_never_promoted_to_html(): void
    {
        // _highlighted is uniformly escaped since 2.1, but only columns listed in _matches were
        // actually wrapped in the tag — so a record carrying its own "<mark>" must not be promoted
        // to HtmlString just because a *different* column matched.
        $trap = User::create(['name' => '<mark><img src=x onerror=1></mark>', 'email' => 'trap@example.com']);

        $result = UserResource::getGlobalSearchResults('trap')
            ->first(fn (GlobalSearchResult $r) => $r->url === '/admin/users/' . $trap->getKey());

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('Name', $result->details); // 'name' is not in _matches, so it is never promoted
        $this->assertInstanceOf(HtmlString::class, $result->details['Email']);
        $this->assertStringContainsString('<mark>trap</mark>', (string) $result->details['Email']);
    }

    public function test_the_resource_eloquent_query_is_honoured(): void
    {
        User::create(['name' => 'Jonh Outsider', 'email' => 'jonh@elsewhere.org']);

        $titles = UserResource::getGlobalSearchResults('jonh')->map(fn (GlobalSearchResult $r) => (string) $r->title)->all();

        $this->assertContains('John Doe', $titles);
        $this->assertNotContains('Jonh Outsider', $titles); // filtered out by getGlobalSearchEloquentQuery()
    }

    public function test_results_are_capped_by_the_resource_limit_and_empty_search_returns_nothing(): void
    {
        $this->assertLessThanOrEqual(UserResource::getGlobalSearchResultsLimit(), UserResource::getGlobalSearchResults('o')->count());
        $this->assertCount(0, UserResource::getGlobalSearchResults(''));
    }

    public function test_an_invalid_utf8_search_finds_what_the_cleaned_search_finds(): void
    {
        $titles = fn (string $search) => UserResource::getGlobalSearchResults($search)->map(fn (GlobalSearchResult $r) => (string) $r->title)->all();

        $this->assertNotEmpty($titles('john'));
        $this->assertSame($titles('john'), $titles("jo\xC3hn")); // e.g. ?q=jo%C3hn
        $this->assertCount(0, UserResource::getGlobalSearchResults("\xFF")); // nothing valid left: an empty search
    }

    public function test_records_without_a_url_are_skipped(): void
    {
        $resource = new class extends UserResource {
            public static function getGlobalSearchResultUrl(\Illuminate\Database\Eloquent\Model $record): ?string { return null; }
        };

        $this->assertCount(0, $resource::getGlobalSearchResults('john'));
    }

    public function test_the_models_searchable_algorithm_is_applied_and_the_resource_knob_overrides_it(): void
    {
        // LikeUser's $searchable['algorithm'] is 'like' (exact substring): the typo must not match.
        $titles = fn ($resource) => $resource::getGlobalSearchResults('jonh')->map(fn (GlobalSearchResult $r) => (string) $r->title)->all();

        $this->assertNotContains('John Doe', $titles(LikeUserResource::class));

        $override = new class extends LikeUserResource {
            protected static ?string $fuzzySearchAlgorithm = 'fuzzy';
        };

        $this->assertContains('John Doe', $titles($override));
    }

    public function test_the_resource_attributes_replace_the_models_configured_columns(): void
    {
        // LikeUser configures name + email; the resource lists only name. searchIn() accumulates,
        // so the configured list must not be applied on top: a term that only occurs in an email
        // finds nothing.
        $this->assertCount(0, LikeUserResource::getGlobalSearchResults('bob@'));
    }

    public function test_nested_globally_searchable_attribute_groups_are_flattened(): void
    {
        // Filament allows groups: ['name', ['email']]. Unflattened, searchIn() preg_match()es an array.
        $resource = new class extends UserResource {
            public static function getGloballySearchableAttributes(): array { return ['name', ['email']]; }
        };

        $titles = $resource::getGlobalSearchResults('bob@')->map(fn (GlobalSearchResult $r) => (string) $r->title)->all();

        $this->assertContains('Bob Johnson', $titles);
    }
}
