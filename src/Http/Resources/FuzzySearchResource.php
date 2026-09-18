<?php

namespace Ashiqfardus\LaravelFuzzySearch\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit: the model's own attributes, then the package's result fields promoted to
 * stable keys. Works for any row get()/paginate() returns (Eloquent model or array).
 */
class FuzzySearchResource extends JsonResource
{
    public function toArray($request): array
    {
        $row  = $this->resource;
        $attr = is_object($row) && method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
        $get  = fn (string $key) => is_object($row) ? ($row->{$key} ?? null) : ($row[$key] ?? null);

        $plain = array_filter($attr, fn ($key) => !str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);

        return $plain + [
            '_score'       => $get('_score') !== null ? (float) $get('_score') : null,
            '_raw_score'   => $get('_raw_score') !== null ? (float) $get('_raw_score') : null,
            '_highlighted' => (array) ($get('_highlighted') ?? []),
            '_matches'     => (array) ($get('_matches') ?? []),
            '_model_type'  => $get('_model_type') ?? (is_object($row) ? class_basename($row) : null),
        ];
    }
}
