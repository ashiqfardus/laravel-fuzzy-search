<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Metaphone Driver
 *
 * Requires a precomputed shadow column `{column}_metaphone` populated
 * by SearchableObserver on model save. Without the shadow column this
 * driver throws with instructions to run the artisan command.
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
        $table = $query->from;
        $schema = $query->getConnection()->getSchemaBuilder();

        // A qualified column (the search path qualifies the FROM table's own under a join) names
        // the FROM's alias, its table, or the last segment of a schema-qualified one, or else
        // a table the caller joined: check the column itself on that table. Eloquent hands a
        // where(Closure) group the model's table without the FROM alias ("users", not
        // "users as u"), so a qualifier that is no table is taken as the FROM's alias.
        $parts     = explode('.', $shadowColumn);
        $column    = array_pop($parts);
        $qualifier = implode('.', $parts);
        if ($qualifier !== '' && is_string($table)) {
            $from  = preg_split('/\s+as\s+/i', $table);
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
                "Then trigger model saves to populate the shadow column, or run:\n" .
                "  php artisan fuzzy-search:rebuild <ModelClass> --fresh"
            );
        }
    }
}
