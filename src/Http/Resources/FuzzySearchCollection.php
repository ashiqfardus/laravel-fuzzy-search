<?php

namespace Ashiqfardus\LaravelFuzzySearch\Http\Resources;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A page of search hits plus request metadata: the query, the algorithm/path that answered,
 * its latency, and "did you mean" suggestions when nothing matched. Build it from a builder:
 *
 *   return FuzzySearchCollection::fromBuilder(User::search($q)->highlight('mark'), perPage: 20);
 */
class FuzzySearchCollection extends ResourceCollection
{
    public $collects = FuzzySearchResource::class;

    protected ?SearchBuilder $builder = null;

    public static function fromBuilder(SearchBuilder $builder, ?int $perPage = null): static
    {
        $collection = new static($perPage === null ? $builder->get() : $builder->paginate($perPage));
        $collection->builder = $builder;

        return $collection;
    }

    public function with($request): array
    {
        $exec = $this->builder?->lastExecution();
        $meta = [
            'query'       => $exec?->searchTerm ?? $this->builder?->getDebugInfo()['search_term'] ?? null,
            'algorithm'   => $exec?->algorithm,
            'latency_ms'  => $exec?->latencyMs,
            'suggestions' => [],
        ];

        // Only when nothing matched: a page past the last one is empty too, beside a total that is not.
        $nothingMatched = $this->resource instanceof \Illuminate\Pagination\AbstractPaginator
            ? ($this->resource instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
                ? $this->resource->total() === 0
                : $this->resource->currentPage() === 1) // simplePaginate() knows no total
            : true;

        if ($this->builder !== null && $this->collection->isEmpty() && $nothingMatched) {
            $meta['suggestions'] = array_map(fn (array $s) => $s['term'], $this->builder->didYouMean());
        }

        return ['meta' => $meta];
    }
}
