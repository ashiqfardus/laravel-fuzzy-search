<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;
use Illuminate\Database\Query\Builder;

/**
 * SimilarText Driver
 *
 * SQL level: contains LIKE, because similar_text() has no SQL equivalent on any supported
 * database. similar_text.min_percentage (per call: the min_percentage option) is still enforced
 * in SQL: once the term is contained in the value, similar_text(term, value) is exactly
 * 200·t / (t + v), so "at least p%" is a bound on the value's length — see maxValueLength().
 * The PHP-side scorer in SearchBuilder::calculateRelevanceScores() scores the rows fetched.
 */
class SimilarTextDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $pattern = '%' . $this->escapeLike(Utf8::lowerAscii($value)) . '%';
        $bound   = $this->matchBound($query, $column, $value);

        if ($bound === null) {
            DbDialect::whereLike($query, $column, $pattern, $this->driver, $boolean);

            return $query;
        }

        // One group, so an outer OR takes the LIKE and its bound together.
        return $query->{$boolean === 'or' ? 'orWhere' : 'where'}(function (Builder $q) use ($column, $pattern, $bound) {
            DbDialect::whereLike($q, $column, $pattern, $this->driver);
            $q->whereRaw(...$bound);
        });
    }

    /**
     * The min_percentage length bound (see maxValueLength()), or null when it is off. t is the
     * term's length, or the term_length option: under tokenize() SearchBuilder passes the whole
     * search term's, so each token is bounded by the whole term (ruling ER-59).
     */
    public function matchBound(Builder $query, string $column, string $value): ?array
    {
        $max = $this->maxValueLength((int) ($this->config['similar_text']['term_length'] ?? mb_strlen($value, 'UTF-8')));

        return $max === null ? null : [$this->characterLength($query, $column) . ' <= ?', [$max]];
    }

    /**
     * The longest value, in characters, a t-character term still reaches min_percentage p in:
     * 200·t / (t + v) >= p ⇔ v <= t·(200 − p) / p. null when min_percentage is 0 or null (off,
     * the 2.0 behaviour). Characters, not bytes: PHP's similar_text() counts bytes, so on text
     * with multibyte characters the bound is the character form of the same percentage.
     */
    private function maxValueLength(int $termLength): ?int
    {
        $percentage = (float) ($this->config['similar_text']['min_percentage'] ?? 0);

        return $percentage > 0 ? (int) floor($termLength * (200 - $percentage) / $percentage) : null;
    }

    /**
     * The column's length in characters, the column written as DbDialect::whereLike() writes it.
     * SQL Server's LEN() ignores trailing spaces, so it measures the column with one character added.
     */
    private function characterLength(Builder $query, string $column): string
    {
        if ($this->driver === DbDialect::PGSQL) {
            return 'CHAR_LENGTH(' . $this->quoteColumn($column, $query) . ')';
        }

        $col = $query->getGrammar()->wrap($column);

        return match (true) {
            $this->driver === DbDialect::SQLSRV => "(LEN({$col} + 'x') - 1)",
            $this->driver === DbDialect::SQLITE => "LENGTH({$col})",
            default                             => "CHAR_LENGTH({$col})", // MySQL, MariaDB
        };
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $col = $this->quoteColumn($column);

        return match ($this->driver) {
            'mysql'  => "IF(LOWER({$col}) LIKE ?, 50, 0)",
            'pgsql'  => "CASE WHEN {$col} ILIKE ? THEN 50 ELSE 0 END",
            default  => "CASE WHEN {$col} LIKE ? THEN 50 ELSE 0 END",
        };
    }

    public function getRelevanceBindings(string $value): array
    {
        return ['%' . Utf8::lowerAscii($value) . '%'];
    }
}
