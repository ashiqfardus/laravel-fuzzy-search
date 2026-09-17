<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

/** Stop-word lists come from config as arrays or as a path to a one-word-per-line file. */
final class StopWords
{
    /** @return string[] lower-cased, de-duplicated, in source order */
    public static function resolve(array|string|null $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            if (!is_file($value)) {
                throw new \InvalidArgumentException("Stop-word file not found: {$value}");
            }
            $value = array_filter(
                array_map('trim', file($value, FILE_IGNORE_NEW_LINES) ?: []),
                fn (string $line) => $line !== '' && !str_starts_with($line, '#')
            );
        }

        return array_values(array_unique(array_map(fn ($w) => mb_strtolower((string) $w, 'UTF-8'), $value)));
    }

    public static function forLocale(string $locale): array
    {
        return self::resolve(config("fuzzy-search.stop_words.{$locale}"));
    }
}
