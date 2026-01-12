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

        // MySQL and PostgreSQL support native SOUNDEX
        if (in_array($this->driver, ['mysql', 'pgsql'])) {
            $method = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

            if ($this->driver === 'pgsql' && !($this->config['use_native_functions'] ?? false)) {
                // PostgreSQL without fuzzystrmatch extension
                return $this->applyFallback($query, $column, $value, $boolean);
            }

            return $query->$method("SOUNDEX({$col}) = SOUNDEX(?)", [$value]);
        }

        // Fallback for SQLite and others
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

        // Original
        $patterns[] = '%' . $value . '%';

        // First letter + wildcards (soundex focuses on first letter)
        if (strlen($value) > 0) {
            $patterns[] = $value[0] . '%';
        }

        // First 3 letters (soundex uses these heavily)
        if (strlen($value) >= 3) {
            $patterns[] = substr($value, 0, 3) . '%';
        }

        // Phonetic substitutions
        $substitutions = [
            'ph' => 'f', 'f' => 'ph',
            'ck' => 'k', 'k' => 'ck',
            'c' => 'k', 'k' => 'c',
            'q' => 'k',
            'x' => 'ks',
            'z' => 's', 's' => 'z',
            'j' => 'g', 'g' => 'j',
            'v' => 'f',
            'w' => 'v',
            'tion' => 'shun',
            'sion' => 'shun',
            'ough' => 'off',
            'ight' => 'ite',
            'gh' => '',
            'wr' => 'r',
            'kn' => 'n',
            'gn' => 'n',
            'pn' => 'n',
            'ae' => 'e',
            'oe' => 'e',
            'ie' => 'y',
            'ei' => 'i',
            'ai' => 'ay',
            'ey' => 'i',
        ];

        foreach ($substitutions as $from => $to) {
            if (str_contains($value, $from)) {
                $replaced = str_replace($from, $to, $value);
                $patterns[] = '%' . $replaced . '%';
            }
        }

        // Remove vowels pattern (soundex ignores vowels after first letter)
        if (strlen($value) > 1) {
            $consonants = $value[0] . preg_replace('/[aeiou]/i', '', substr($value, 1));
            if ($consonants !== $value) {
                $patterns[] = '%' . $consonants . '%';
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

