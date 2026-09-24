<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Query\Builder;

/**
 * Soundex Driver - Phonetic matching
 * Best for matching words that sound alike
 */
class SoundexDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $col = $this->quoteColumn($column, $query);

        // MySQL and PostgreSQL support native SOUNDEX.
        // IMPORTANT: SOUNDEX() on multi-word strings (e.g. "Jake Jackson") ignores spaces
        // and encodes the entire string as one token, causing false positives — e.g.
        // SOUNDEX('Jake Jackson') = SOUNDEX('john') = J500.
        // Fix: extract only the FIRST word before applying SOUNDEX so the last name
        // does not corrupt the phonetic code. For "Jake Jackson" this gives SOUNDEX('Jake')
        // = J200, which correctly does NOT match SOUNDEX('john') = J500.
        if ($this->isMySqlFamily()) {
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            // Match first word OR last word of a full-name column.
            // Rationale: SOUNDEX() on the full string (e.g. "Jake Jackson") ignores spaces
            // and encodes everything as one token — SOUNDEX('Jake Jackson') = J500 = SOUNDEX('john'),
            // producing false positives. By splitting to first/last word we get accurate per-token
            // phonetic matching while supporting both first-name and last-name queries.
            return $query->$method(
                "(SOUNDEX(SUBSTRING_INDEX({$col}, ' ', 1)) = SOUNDEX(?) OR SOUNDEX(SUBSTRING_INDEX({$col}, ' ', -1)) = SOUNDEX(?))",
                [$value, $value]
            );
        }

        if ($this->driver === 'pgsql') {
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            if (!($this->config['use_native_functions'] ?? false)) {
                return $this->applyFallback($query, $column, $value, $boolean);
            }
            // Match first word OR last word on PostgreSQL.
            // SPLIT_PART(col, ' ', -1) requires PostgreSQL 14+. Use SUBSTRING with a
            // POSIX regex to extract the last space-delimited token — works on all versions.
            return $query->$method(
                "(SOUNDEX(SPLIT_PART({$col}, ' ', 1)) = SOUNDEX(?) OR SOUNDEX(TRIM(SUBSTRING({$col} FROM '[^ ]+$'))) = SOUNDEX(?))",
                [$value, $value]
            );
        }

        // SQLite and SQL Server: fallback pattern matching
        return $this->applyFallback($query, $column, $value, $boolean);
    }

    /**
     * Fallback for databases without SOUNDEX
     */
    protected function applyFallback(Builder $query, string $column, string $value, string $boolean): Builder
    {
        $patterns = $this->generatePhoneticPatterns($value);
        $method   = $boolean === 'or' ? 'orWhere' : 'where';

        // Patterns are lower-cased; whereLike() uses ILIKE on PostgreSQL, where LIKE is case-sensitive.
        return $query->$method(function ($q) use ($column, $patterns) {
            foreach ($patterns as $index => $pattern) {
                DbDialect::whereLike($q, $column, $pattern, $this->driver, $index === 0 ? 'and' : 'or');
            }
        });
    }

    /**
     * Generate phonetic patterns for fallback matching
     */
    protected function generatePhoneticPatterns(string $value): array
    {
        $value    = $this->normalizeTerm($value);
        $len      = mb_strlen($value, 'UTF-8');
        $patterns = [];

        // Exact substring — always include
        $patterns[] = '%' . $this->escapeLike($value) . '%';

        // First 3+ chars prefix
        if ($len >= 4) {
            $patterns[] = $this->escapeLike(mb_substr($value, 0, 3, 'UTF-8')) . '%';
        }

        // Vowel-stripped consonant skeleton
        if ($len > 2) {
            $consonants = mb_substr($value, 0, 1, 'UTF-8') . preg_replace('/[aeiou]/i', '', mb_substr($value, 1, null, 'UTF-8'));
            if (mb_strlen($consonants, 'UTF-8') >= 2 && $consonants !== $value) {
                $patterns[] = '%' . $this->escapeLike($consonants) . '%';
            }
        }

        // Targeted phonetic substitutions
        $substitutions = [
            'ph' => 'f',  'f'  => 'ph',
            'ck' => 'k',  'k'  => 'ck',
            'z'  => 's',  's'  => 'z',
            'wr' => 'r',  'kn' => 'n',
            'gh' => '',
        ];

        foreach ($substitutions as $from => $to) {
            if (str_contains($value, $from)) {
                $replaced = str_replace($from, $to, $value);
                if ($replaced !== $value) {
                    $patterns[] = '%' . $this->escapeLike($replaced) . '%';
                }
            }
        }

        return $this->capPatterns($patterns);
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        $col = $this->quoteColumn($column);

        if (in_array($this->driver, ['mysql'])) {
            return "IF(SOUNDEX({$col}) = SOUNDEX(?), 100, 0)";
        }

        if ($this->driver === 'pgsql' && ($this->config['use_native_functions'] ?? false)) {
            return "CASE WHEN SOUNDEX({$col}) = SOUNDEX(?) THEN 100 ELSE 0 END";
        }

        // Fallback
        return "CASE WHEN {$col} LIKE ? THEN 50 ELSE 0 END";
    }

    public function getRelevanceBindings(string $value): array
    {
        if (in_array($this->driver, ['mysql', 'pgsql'])) {
            return [$value];
        }

        return ['%' . $value . '%'];
    }
}

