<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

class Token
{
    public const TYPE_FUZZY            = 'FUZZY';
    public const TYPE_EXACT            = 'EXACT';
    public const TYPE_PREFIX           = 'PREFIX';
    public const TYPE_SUFFIX           = 'SUFFIX';
    public const TYPE_INCLUDE_MATCH    = 'INCLUDE_MATCH';
    public const TYPE_NOT_FUZZY        = 'NOT_FUZZY';
    public const TYPE_NOT_EXACT        = 'NOT_EXACT';
    public const TYPE_NOT_PREFIX       = 'NOT_PREFIX';
    public const TYPE_NOT_SUFFIX       = 'NOT_SUFFIX';
    public const TYPE_NOT_INCLUDE_MATCH = 'NOT_INCLUDE_MATCH';
    public const TYPE_OR               = 'OR';
    public const TYPE_LPAREN           = 'LPAREN';
    public const TYPE_RPAREN           = 'RPAREN';

    public function __construct(
        public readonly string $type,
        public readonly string $value = '',
    ) {}
}
