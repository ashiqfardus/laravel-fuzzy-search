<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query\AstNodes;

/**
 * `~word`: typo-tolerant match through the fuzzy driver, gated by the builder's `typoTolerance()`.
 */
class TypoTerm implements AstNode
{
    public function __construct(public readonly string $term) {}
    public function depth(): int { return 1; }
}
