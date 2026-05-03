<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class NotNode implements AstNode
{
    public function __construct(public readonly AstNode $child) {}

    public function depth(): int { return 1 + $this->child->depth(); }
}
