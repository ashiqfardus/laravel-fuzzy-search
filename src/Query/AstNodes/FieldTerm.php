<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

/** `field:term` — the wrapped leaf applies to exactly one direct column or relation column. */
final class FieldTerm implements AstNode
{
    public function __construct(
        public readonly string $field,
        public readonly AstNode $term,
    ) {}

    public function depth(): int
    {
        return $this->term->depth();
    }
}
