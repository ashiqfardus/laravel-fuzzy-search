<?php

namespace Ashiqfardus\LaravelFuzzySearch\Http\Resources;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit: the model's own attributes, then the package's result fields promoted to
 * stable keys. Works for any row get()/paginate() returns (Eloquent model or array).
 * `_highlighted` and `_matches` keep only columns the row's toArray() shows now, so a
 * makeHidden() after the search holds here too (the search already left out what the row, and
 * any related row, hid while it ran).
 */
class FuzzySearchResource extends JsonResource
{
    public function toArray($request): array
    {
        $row = $this->resource;

        if ($row === null) {
            return []; // same as JsonResource::toArray() — nothing to shape
        }

        $attr = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
        $get  = fn (string $key) => is_object($row) ? ($row->{$key} ?? null) : ($row[$key] ?? null);

        $plain = array_filter($attr, fn ($key) => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);

        // A dotted column is the row's relation when it has that relation loaded ("author.name"),
        // else a qualified column ("teams.name"), whose attribute is the last part.
        $shown = function (string $column) use ($row): bool {
            $head = strstr($column, '.', true);

            return SearchBuilder::shows($row, $head !== false && $row instanceof Model && $row->relationLoaded($head)
                ? $head
                : substr((string) strrchr('.' . $column, '.'), 1));
        };

        return $plain + [
            '_score'       => $get('_score') !== null ? (float) $get('_score') : null,
            '_raw_score'   => $get('_raw_score') !== null ? (float) $get('_raw_score') : null,
            '_highlighted' => array_filter((array) ($get('_highlighted') ?? []), fn ($column) => $shown((string) $column), ARRAY_FILTER_USE_KEY),
            '_matches'     => array_values(array_filter((array) ($get('_matches') ?? []), fn ($match) => $shown((string) ($match['column'] ?? '')))),
            '_model_type'  => $get('_model_type') ?? (is_object($row) ? class_basename($row) : null),
        ];
    }
}
