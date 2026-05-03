<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class AndNode implements AstNode
{
    /** @param AstNode[] $children */
    public function __construct(public readonly array $children) {}

    public function depth(): int
    {
        return 1 + max(array_map(fn(AstNode $c) => $c->depth(), $this->children));
    }
}
