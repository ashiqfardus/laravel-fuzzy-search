<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Illuminate\Support\Collection;

/**
 * In-memory search over a fixed PHP collection.
 * Mirrors the SearchBuilder fluent API but operates on arrays/objects in memory.
 *
 * Use FuzzySearch::on($iterable) to construct an instance.
 */
class InMemorySearch
{
    private Collection $items;
    private string     $term          = '';
    private array      $columns       = [];
    private int        $limit         = 15;
    private int        $offset        = 0;
    private bool       $withRelevance = true;

    public function __construct(iterable $items)
    {
        $cap   = (int) config('fuzzy-search.in_memory.max_items', 10000);
        $items = $items instanceof Collection ? $items : collect($items);

        if ($items->count() > $cap) {
            throw new \InvalidArgumentException(
                "InMemorySearch: collection size {$items->count()} exceeds max_items={$cap}. " .
                "Increase config('fuzzy-search.in_memory.max_items') or page through your data."
            );
        }

        $this->items = $items;
    }

    public function search(string $term): self
    {
        $this->term = trim($term);
        return $this;
    }

    public function searchIn(array $columns): self
    {
        // Accept either ['name', 'email'] or ['name' => 10, 'email' => 5] — keep just the names
        $this->columns = [];
        foreach ($columns as $key => $value) {
            $this->columns[] = is_string($key) ? $key : $value;
        }
        return $this;
    }

    public function take(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function skip(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function withRelevance(bool $b = true): self
    {
        $this->withRelevance = $b;
        return $this;
    }

    /**
     * Catch-all for unsupported SearchBuilder methods chained on InMemorySearch.
     * Throws immediately so callers get a clear error instead of a silent no-op.
     *
     * @throws \BadMethodCallException
     */
    public function __call(string $name, array $args): never
    {
        throw new \BadMethodCallException(
            "InMemorySearch does not support {$name}(). " .
            "Supported methods: search, searchIn, take, skip, withRelevance, highlight, get."
        );
    }

    public function get(): Collection
    {
        if ($this->term === '' || empty($this->columns)) {
            return $this->items->slice($this->offset, $this->limit)->values();
        }

        $needle = strtolower($this->term);

        $scored = $this->items->map(function ($item) use ($needle) {
            $score = 0;
            foreach ($this->columns as $col) {
                $value = strtolower((string) data_get($item, $col, ''));
                if ($value === $needle) {
                    $score = max($score, 100);
                } elseif (str_starts_with($value, $needle)) {
                    $score = max($score, 60);
                } elseif (str_contains($value, $needle)) {
                    $score = max($score, 30);
                } else {
                    similar_text($needle, $value, $pct);
                    $minPct = (int) config('fuzzy-search.in_memory.min_similarity', 60);
                    if ($pct >= $minPct) {
                        $score = max($score, (int) $pct);
                    }
                }
            }

            if ($this->withRelevance) {
                if (is_object($item)) {
                    $item->_score = $score;
                } elseif (is_array($item)) {
                    $item['_score'] = $score;
                }
            }

            // Carry raw score in a local key for filtering/sorting regardless of withRelevance
            if (is_object($item)) {
                $item->_raw_score_tmp = $score;
            } elseif (is_array($item)) {
                $item['_raw_score_tmp'] = $score;
            }
            return $item;
        })
        ->filter(fn($item) => (is_object($item) ? $item->_raw_score_tmp : $item['_raw_score_tmp']) > 0)
        ->sortByDesc(fn($item) => is_object($item) ? $item->_raw_score_tmp : $item['_raw_score_tmp'])
        ->values();

        // Normalize _score to [0,1] and clean up temp key
        $max = $scored->max(fn($i) => is_object($i) ? $i->_raw_score_tmp : $i['_raw_score_tmp']);
        $scored = $scored->map(function ($item) use ($max) {
            $raw = is_object($item) ? $item->_raw_score_tmp : $item['_raw_score_tmp'];
            if (is_object($item)) {
                unset($item->_raw_score_tmp);
                if ($this->withRelevance && $max > 0) {
                    $item->_raw_score = $raw;
                    $item->_score     = round($raw / $max, 6);
                }
            } else {
                unset($item['_raw_score_tmp']);
                if ($this->withRelevance && $max > 0) {
                    $item['_raw_score'] = $raw;
                    $item['_score']     = round($raw / $max, 6);
                }
            }
            return $item;
        });

        return $scored->slice($this->offset, $this->limit)->values();
    }
}
