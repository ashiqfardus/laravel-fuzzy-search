<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

interface AstNode
{
    /**
     * Tree depth (max distance to a leaf). Used for DoS depth limit.
     */
    public function depth(): int;
}
