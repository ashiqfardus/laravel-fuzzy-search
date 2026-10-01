<?php

namespace Ashiqfardus\LaravelFuzzySearch\Scout;

use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Illuminate\Database\Eloquent\Builder;

/**
 * A Scout query() callback as the engine reads it: the callback, then its wheres grouped when one is
 * an or (RE-1) and a union read as one table (RankedCandidates::rows()), as constrainedQuery() and
 * modelsById() read it. Scout 10.0 counts paginate()'s total itself, through the Builder's callback,
 * so FuzzySearchEngine::paginate() puts this in the callback's place there (SD-1). A second reading
 * changes nothing.
 *
 * @internal This class is not part of the public API and may change without notice.
 */
final class GroupedQueryCallback
{
    /** @param callable $callback the Builder's query() callback */
    public function __construct(private $callback)
    {
    }

    public function __invoke(Builder $query): void
    {
        $from = count($query->getQuery()->wheres);
        call_user_func($this->callback, $query);
        RankedCandidates::groupOrWheres($query, $from);

        if (($rows = RankedCandidates::rows($query)) !== $query) {
            $query->setQuery($rows->getQuery())->withoutGlobalScopes();
        }
    }
}
