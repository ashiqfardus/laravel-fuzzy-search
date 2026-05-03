<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

class SuffixTerm implements AstNode
{
    public function __construct(public readonly string $term) {}
    public function depth(): int { return 1; }
}
