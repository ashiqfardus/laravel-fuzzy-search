<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Soundex Driver - Phonetic matching
 * Best for matching words that sound alike
 */
class SoundexDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        $col = $this->quoteColumn($column);

        // MySQL and PostgreSQL support native SOUNDEX.
        // IMPORTANT: SOUNDEX() on multi-word strings (e.g. "Jake Jackson") ignores spaces
        // and encodes the entire string as one token, causing false positives — e.g.
        // SOUNDEX('Jake Jackson') = SOUNDEX('john') = J500.
        // Fix: extract only the FIRST word before applying SOUNDEX so the last name
        // does not corrupt the phonetic code. For "Jake Jackson" this gives SOUNDEX('Jake')
        // = J200, which correctly does NOT match SOUNDEX('john') = J500.
        if ($this->driver === 'mysql') {
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
            // SUBSTRING_INDEX(col, ' ', 1) extracts the first word of a full-name column.
            return $query->$method(
                "SOUNDEX(SUBSTRING_INDEX({$col}, ' ', 1)) = SOUNDEX(?)",
                [$value]
            );
        }

        if ($this->driver === 'pgsql') {
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            if (!($this->config['use_native_functions'] ?? false)) {
                return $this->applyFallback($query, $column, $value, $boolean);
            }
            // SPLIT_PART(col, ' ', 1) extracts first word on PostgreSQL
            return $query->$method(
                "SOUNDEX(SPLIT_PART({$col}, ' ', 1)) = SOUNDEX(?)",
                [$value]
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
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        return $query->$method(function ($q) use ($column, $patterns) {
            foreach ($patterns as $index => $pattern) {
                if ($index === 0) {
                    $q->where($column, 'LIKE', $pattern);
                } else {
                    $q->orWhere($column, 'LIKE', $pattern);
                }
            }
        });
    }

    /**
     * Generate phonetic patterns for fallback matching
     */
    protected function generatePhoneticPatterns(string $value): array
    {
        $value = strtolower(trim($value));
        $patterns = [];

        // Exact substring — always include
        $patterns[] = '%' . $value . '%';

        // First 3+ chars prefix — minimum meaningful prefix for phonetic matching.
        // Deliberately NOT adding single-char ($value[0].'%') — that matches every
        // name sharing the first letter and produces nonsensical soundex results
        // (e.g. "john" → j% matches Jake, Jessica, Jackson).
        if (strlen($value) >= 4) {
            $patterns[] = substr($value, 0, 3) . '%';
        }

        // Vowel-stripped consonant skeleton — approximates soundex grouping.
        // soundex('john') = J500: J + strip vowels from 'ohn' → 'jhn'.
        // This matches "Jahn", "Jöhn", etc. without over-matching "Jackson".
        if (strlen($value) > 2) {
            $consonants = $value[0] . preg_replace('/[aeiou]/i', '', substr($value, 1));
            if (strlen($consonants) >= 2 && $consonants !== $value) {
                $patterns[] = '%' . $consonants . '%';
            }
        }

        // Targeted phonetic substitutions — only pairs that share a soundex code.
        // ph/f share code 1; ck/k share code 2; s/z share code 2.
        // Removed j/g swap — they share a soundex code but produce too many false positives.
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
                    $patterns[] = '%' . $replaced . '%';
                }
            }
        }

        return array_unique($patterns);
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

