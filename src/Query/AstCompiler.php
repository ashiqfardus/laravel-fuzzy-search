<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{
    AstNode, AndNode, OrNode, NotNode,
    FuzzyTerm, ExactTerm, PrefixTerm, SuffixTerm, IncludeMatchTerm
};
use Illuminate\Database\Query\Builder;

/**
 * Walk the AST and apply WHERE clauses to a query builder.
 * All term values pass through PDO bindings — no string interpolation.
 */
class AstCompiler
{
    /**
     * @param string[] $columns
     */
    public function compile(AstNode $node, Builder $builder, array $columns): void
    {
        $builder->where(function (Builder $q) use ($node, $columns) {
            $this->visit($node, $q, $columns);
        });
    }

    private function visit(AstNode $node, Builder $builder, array $columns, string $boolean = 'and'): void
    {
        if ($node instanceof AndNode) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $builder->$method(function (Builder $q) use ($node, $columns) {
                foreach ($node->children as $child) {
                    $this->visit($child, $q, $columns, 'and');
                }
            });
            return;
        }

        if ($node instanceof OrNode) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $builder->$method(function (Builder $q) use ($node, $columns) {
                foreach ($node->children as $i => $child) {
                    $this->visit($child, $q, $columns, $i === 0 ? 'and' : 'or');
                }
            });
            return;
        }

        if ($node instanceof NotNode) {
            $method = $boolean === 'or' ? 'orWhereNot' : 'whereNot';
            $builder->$method(function (Builder $q) use ($node, $columns) {
                $this->visit($node->child, $q, $columns, 'and');
            });
            return;
        }

        // Leaf term — match against any of the given columns (OR'd)
        $term    = $this->extractTerm($node);
        $pattern = $this->patternFor($node, $term);

        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $builder->$method(function (Builder $q) use ($columns, $node, $pattern, $term) {
            foreach ($columns as $idx => $column) {
                $colMethod = $idx === 0 ? 'where' : 'orWhere';
                if ($node instanceof ExactTerm) {
                    $q->$colMethod($column, '=', $term);
                } else {
                    $q->$colMethod($column, 'LIKE', $pattern);
                }
            }
        });
    }

    private function extractTerm(AstNode $node): string
    {
        if (property_exists($node, 'term')) {
            return $node->term;
        }
        throw new \InvalidArgumentException('Non-term node in extractTerm()');
    }

    private function patternFor(AstNode $node, string $term): string
    {
        return match (true) {
            $node instanceof FuzzyTerm        => '%' . $term . '%',
            $node instanceof IncludeMatchTerm => '%' . $term . '%',
            $node instanceof PrefixTerm       => $term . '%',
            $node instanceof SuffixTerm       => '%' . $term,
            $node instanceof ExactTerm        => $term, // not used as LIKE
            default                            => '%' . $term . '%',
        };
    }
}
