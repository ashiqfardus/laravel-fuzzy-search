<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

class AndNode implements AstNode
{
    /** @param AstNode[] $children */
    public function __construct(public readonly array $children) {}

    public function depth(): int
    {
        return 1 + max(array_map(fn(AstNode $c) => $c->depth(), $this->children));
    }
}
