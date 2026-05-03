<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

class NotNode implements AstNode
{
    public function __construct(public readonly AstNode $child) {}

    public function depth(): int { return 1 + $this->child->depth(); }
}
