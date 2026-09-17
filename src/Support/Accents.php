<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

/** Accent folding shared by the index pipeline and the LIKE path. Task 4 adds the intl path. */
final class Accents
{
    public static function usesIntl(): bool
    {
        return class_exists(\Normalizer::class);
    }

    /**
     * Strip diacritics: decompose (NFD) and drop non-spacing marks that belong to Latin/Greek/
     * Cyrillic letters, then apply the legacy map for characters without a decomposition
     * (ø, ß, đ…). Without ext-intl only the map runs — the v2.0 behaviour.
     *
     * Marks that carry meaning in Indic/Thai/Arabic scripts (\p{Mc} and the \p{Mn} used by
     * those scripts) are not diacritics: only marks in the Combining Diacritical Marks blocks
     * (U+0300–U+036F, U+1AB0–U+1AFF, U+1DC0–U+1DFF, U+20D0–U+20FF, U+FE20–U+FE2F) are removed.
     */
    public static function fold(string $string): string
    {
        if (self::usesIntl()) {
            $decomposed = \Normalizer::normalize($string, \Normalizer::FORM_D);
            if ($decomposed !== false) {
                $string = preg_replace('/[\x{0300}-\x{036F}\x{1AB0}-\x{1AFF}\x{1DC0}-\x{1DFF}\x{20D0}-\x{20FF}\x{FE20}-\x{FE2F}]/u', '', $decomposed) ?? $string;
                $string = \Normalizer::normalize($string, \Normalizer::FORM_C) ?: $string;
            }
        }

        return strtr($string, self::MAP);
    }

    /** The v2.0 map, verbatim (SearchBuilder::removeAccents()). */
    public const MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
    ];
}
