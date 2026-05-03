<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

class PrefixTerm implements AstNode
{
    public function __construct(public readonly string $term) {}
    public function depth(): int { return 1; }
}
