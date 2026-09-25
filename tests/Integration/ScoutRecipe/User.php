<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration\ScoutRecipe;

use Illuminate\Database\Eloquent\Model;

// docs/integrations.md "Scout Driver → Usage", character for character from `use Laravel\Scout\Searchable;`
// to the closing brace: ScoutDualTraitTest must test the recipe users copy, not a variant of it.
use Laravel\Scout\Searchable;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;

class User extends Model
{
    use Searchable, FuzzySearchable {
        // FuzzySearchable::search() wins — it returns the fluent SearchBuilder.
        // Scout's search() stays reachable as scoutSearch().
        FuzzySearchable::search insteadof Searchable;
        Searchable::search as scoutSearch;

        // Both traits boot through bootSearchable() and Laravel calls that name only
        // once, so keep the package's and run Scout's from booted().
        FuzzySearchable::bootSearchable insteadof Searchable;
        Searchable::bootSearchable as bootScoutSearchable;
    }

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}
