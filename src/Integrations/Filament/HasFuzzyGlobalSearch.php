<?php

namespace Ashiqfardus\LaravelFuzzySearch\Integrations\Filament;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Fuzzy global search for a Filament Resource (v3, v4, v5). Replaces the LIKE constraint
 * Filament applies to getGloballySearchableAttributes() with the package's search builder:
 * typo tolerance, relevance ordering and highlighted details. Everything else — the
 * Eloquent query (tenant scopes), modifyGlobalSearchQuery(), title, URL, actions, limit —
 * still comes from the resource's own static methods.
 *
 *   class UserResource extends Resource
 *   {
 *       use HasFuzzyGlobalSearch;
 *       protected static ?int $fuzzyTypoTolerance = 2;      // optional
 *       protected static ?string $fuzzySearchAlgorithm = null; // optional, e.g. 'levenshtein'
 *       protected static string $fuzzyHighlightTag = 'mark';   // optional
 *   }
 *
 * Filament is not a dependency of this package; the trait is only loaded by a class that
 * already extends Filament's Resource.
 */
trait HasFuzzyGlobalSearch
{
    public static function getGlobalSearchResults(string $search): Collection
    {
        if (trim($search) === '' || !static::canGloballySearch()) {
            return collect();
        }

        $query   = static::getGlobalSearchEloquentQuery();
        $columns = array_values(static::getGloballySearchableAttributes());

        if ($columns === []) {
            return collect();
        }

        static::modifyGlobalSearchQuery($query, $search);

        $builder = (new SearchBuilder($query, app(FuzzySearch::class)))
            ->search($search)
            ->searchIn($columns)
            ->highlight(static::fuzzyHighlightTag())
            ->limit(static::getGlobalSearchResultsLimit());

        if (($algorithm = static::fuzzySearchAlgorithm()) !== null) {
            $builder->using($algorithm);
        }
        if (($distance = static::fuzzyTypoTolerance()) !== null) {
            $builder->typoTolerance($distance);
        }

        return $builder->get()
            ->map(function (Model $record) use ($columns): ?GlobalSearchResult {
                $url = static::getGlobalSearchResultUrl($record);
                if (blank($url)) {
                    return null;
                }

                return new GlobalSearchResult(
                    title: static::getGlobalSearchResultTitle($record),
                    url: $url,
                    details: static::fuzzyDetails($record, $columns),
                    actions: static::fuzzyActions($record),
                );
            })
            ->filter()
            ->values();
    }

    /**
     * The resource's details plus one highlighted entry per searchable attribute that matched.
     *
     * Only columns listed in `_matches` are promoted to HtmlString. SearchBuilder stores the RAW
     * model value in `_highlighted` for columns that did not match and only escapes the ones it
     * wrapped, so sniffing the tag string would render a record's own `<mark>…</mark>` payload
     * unescaped whenever a *different* column was the one that matched.
     */
    protected static function fuzzyDetails(Model $record, array $columns): array
    {
        $details     = static::getGlobalSearchResultDetails($record);
        $highlighted = (array) ($record->_highlighted ?? []);
        $matched     = array_column((array) ($record->_matches ?? []), 'column');

        foreach ($columns as $column) {
            $html = $highlighted[$column] ?? null;
            if (is_string($html) && in_array($column, $matched, true)) {
                $details[Str::headline(str_replace('.', ' ', $column))] = new HtmlString($html);
            }
        }

        return $details;
    }

    /** v4/v5 bind the record to each action; v3 actions have no hasRecord() — pass them through. */
    protected static function fuzzyActions(Model $record): array
    {
        return array_map(
            fn ($action) => (is_object($action) && method_exists($action, 'hasRecord') && method_exists($action, 'record') && !$action->hasRecord())
                ? $action->record($record)
                : $action,
            static::getGlobalSearchResultActions($record),
        );
    }

    protected static function fuzzySearchAlgorithm(): ?string
    {
        return property_exists(static::class, 'fuzzySearchAlgorithm') ? static::$fuzzySearchAlgorithm : null;
    }

    protected static function fuzzyTypoTolerance(): ?int
    {
        return property_exists(static::class, 'fuzzyTypoTolerance') ? static::$fuzzyTypoTolerance : null;
    }

    protected static function fuzzyHighlightTag(): string
    {
        return property_exists(static::class, 'fuzzyHighlightTag') ? static::$fuzzyHighlightTag : 'mark';
    }
}
