<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class ExactTerm implements AstNode
{
    public function __construct(public readonly string $term) {}
    public function depth(): int { return 1; }
}
