<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
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
    /**
     * The longest code the shadow column holds: the column fuzzy-search:add-shadow-column writes is
     * string(…, 191), and one written by an earlier release is string() — 255, or 191 under
     * Schema::defaultStringLength(191).
     */
    public const CODE_LENGTH = 191;

    /**
     * $value's metaphone code, cut to CODE_LENGTH: the one encoding the shadow column is written
     * with (SearchableObserver) and searched by (apply()). The code is about half the value's
     * length, so a long value (a bio, a description: past about 400 characters for 191, 550 for
     * 255) would not fit the column, and its save failed (22001 on MySQL, MariaDB, PostgreSQL and
     * SQL Server). Cut on both sides, the codes of a long value and of a term for it still compare
     * equal.
     */
    public static function code(string $value): string
    {
        return substr(metaphone($value), 0, self::CODE_LENGTH);
    }

    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $shadowColumn = $column . '_metaphone';

        $this->assertShadowColumnExists($query, $shadowColumn, $column);

        // metaphone() encodes ASCII letters only, and drops H, W and Y before no vowel: "99",
        // "Иван", "東京", "H2O", "Hy" and "W2" all encode as '', the code of every value without
        // an encodable letter and of an empty one, so the shadow column would return all of those
        // rows. A term whose code is empty is searched as a contains LIKE on the column itself, as
        // SoundexDriver sends a term it cannot encode to its pattern fallback (RB-1).
        $code = self::code($value);
        if ($code === '') {
            DbDialect::whereLike($query, $column, '%' . $this->escapeLike($this->normalizeTerm($value)) . '%', $this->driver, $boolean);

            return $query;
        }

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
        return [self::code($value)];
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
