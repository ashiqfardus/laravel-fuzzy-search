<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Metaphone Driver
 *
 * Requires a precomputed shadow column `{column}_metaphone`, filled by
 * SearchableObserver on model save and, for rows saved before the column
 * existed, by fuzzy-search:rebuild. Without the shadow column this driver
 * throws with instructions to run the artisan commands.
 *
 * Run: php artisan fuzzy-search:add-shadow-column {Model} {column} --type=metaphone
 */
class MetaphoneDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $shadowColumn = $column . '_metaphone';

        $this->assertShadowColumnExists($query, $shadowColumn, $column);

        $code = metaphone($value);
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        return $query->$method($shadowColumn, $code);
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $shadowColumn = $column . '_metaphone';
        $col = $this->quoteColumn($shadowColumn);

        return match ($this->driver) {
            'mysql'  => "IF({$col} = ?, 100, 0)",
            'pgsql'  => "CASE WHEN {$col} = ? THEN 100 ELSE 0 END",
            default  => "CASE WHEN {$col} = ? THEN 100 ELSE 0 END",
        };
    }

    public function getRelevanceBindings(string $value): array
    {
        return [metaphone($value)];
    }

    private function assertShadowColumnExists(Builder $query, string $shadowColumn, string $originalColumn): void
    {
        $from   = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::fromTable($query->from);
        $table  = $from[0] ?? $query->from; // the FROM's table, never "users as u"
        $schema = $query->getConnection()->getSchemaBuilder();

        // A qualified column (the search path qualifies the FROM table's own under a join) names
        // the FROM: its alias, its table, or the last segment of a schema-qualified one. That is
        // resolved first, as SQL does, even when a table has the alias's name. Anything else is a
        // table the caller joined: check the column itself on that table. A qualifier that is no
        // table is taken as the FROM's alias: an Eloquent where(Closure) group with a relation
        // column carries the model's table without the FROM alias ("users", not "users as u").
        $parts     = explode('.', $shadowColumn);
        $column    = array_pop($parts);
        $qualifier = implode('.', $parts);
        if ($qualifier !== '' && $from !== null) {
            $own   = in_array($qualifier, [...$from, substr(strrchr('.' . $from[0], '.'), 1)], true)
                || !$schema->hasTable($qualifier);
            $table = $own ? $from[0] : $qualifier;
        }

        if (!$schema->hasColumn($table, $column)) {
            throw new \RuntimeException(
                "MetaphoneDriver: column [{$originalColumn}] on table [{$table}] requires a shadow column " .
                "[{$shadowColumn}] to be populated at write time. " .
                "Generate and run the migration with:\n" .
                "  php artisan fuzzy-search:add-shadow-column <ModelClass> {$originalColumn} --type=metaphone\n" .
                "Then fill it for the existing rows (saves fill it from then on) with:\n" .
                "  php artisan fuzzy-search:rebuild <ModelClass>"
            );
        }
    }
}
