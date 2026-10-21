<?php

namespace Ashiqfardus\LaravelFuzzySearch\Scout;

use Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates;
use Illuminate\Database\Eloquent\Builder;

/**
 * A Scout query() callback as the engine reads it: the callback, then its wheres grouped when one is
 * an or and a union read as one table (RankedCandidates::rows()), as constrainedQuery() and
 * modelsById() read it, and Scout's restriction to ids named as the FROM names the key
 * (restrictByKeyColumn()). Scout 10.0 counts paginate()'s total itself, through the Builder's callback,
 * so FuzzySearchEngine::paginate() puts this in the callback's place there. A second reading
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

        self::restrictByKeyColumn($query);
    }

    /**
     * Scout restricts the read to a page's ids by the model's qualified key, after the callback
     * (queryScoutModelsByIds()). A FROM that is not named as the model's table has no such column: the
     * derived table a union on a schema-qualified table (billing.invoices) is read as, named by the
     * table alone, or the callback's alias. A scope, which applies after Scout's whereIn, names
     * the key there as the FROM does (RankedCandidates::keyColumn()); under the model's own table the
     * two names are one, and nothing changes.
     */
    public static function restrictByKeyColumn(Builder $query): void
    {
        $model = $query->getModel();

        if (!method_exists($model, 'getScoutKeyName')) {
            return;
        }

        $scoutKey = $model->qualifyColumn($model->getScoutKeyName());

        $query->withGlobalScope(self::class, function (Builder $query) use ($scoutKey) {
            $base = $query->getQuery();
            $key  = RankedCandidates::keyColumn($query);

            foreach ($base->wheres as $i => $where) {
                if (($where['column'] ?? null) === $scoutKey) {
                    $base->wheres[$i]['column'] = $key;
                }
            }
        });
    }
}
