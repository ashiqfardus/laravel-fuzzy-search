<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{
    AstNode, AndNode, OrNode, NotNode,
    FuzzyTerm, ExactTerm, PrefixTerm, SuffixTerm, IncludeMatchTerm
};
use Illuminate\Contracts\Database\Query\Builder;

/**
 * @internal This class is not part of the public API and may change without notice.
 *
 * Walk the AST and apply WHERE clauses to a query builder.
 * All term values pass through PDO bindings — no string interpolation.
 */
class AstCompiler
{
    public function __construct(private readonly string $dbDriver = 'mysql') {}

    /**
     * @param string[]                $columns   direct columns (may be table-qualified)
     * @param array<string, string[]> $relations relation path => leaf columns; requires an Eloquent builder
     */
    public function compile(AstNode $node, Builder $builder, array $columns, array $relations = []): void
    {
        $builder->where(function (Builder $q) use ($node, $columns, $relations) {
            $this->visit($node, $q, $columns, $relations);
        });
    }

    private function visit(AstNode $node, Builder $builder, array $columns, array $relations, string $boolean = 'and'): void
    {
        if ($node instanceof AndNode) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $builder->$method(function (Builder $q) use ($node, $columns, $relations) {
                foreach ($node->children as $child) {
                    $this->visit($child, $q, $columns, $relations, 'and');
                }
            });
            return;
        }

        if ($node instanceof OrNode) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $builder->$method(function (Builder $q) use ($node, $columns, $relations) {
                foreach ($node->children as $i => $child) {
                    $this->visit($child, $q, $columns, $relations, $i === 0 ? 'and' : 'or');
                }
            });
            return;
        }

        if ($node instanceof NotNode) {
            $method = $boolean === 'or' ? 'orWhereNot' : 'whereNot';
            $builder->$method(function (Builder $q) use ($node, $columns, $relations) {
                $this->visit($node->child, $q, $columns, $relations, 'and');
            });
            return;
        }

        // Leaf term — match against any direct column OR any relation column (all OR'd)
        $term    = $this->extractTerm($node);
        $pattern = $this->patternFor($node, $term);

        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $builder->$method(function (Builder $q) use ($columns, $relations, $node, $pattern, $term) {
            $first = true;
            foreach ($columns as $column) {
                $this->leafCondition($q, $column, $node, $pattern, $term, $first ? 'and' : 'or');
                $first = false;
            }
            foreach ($relations as $relation => $leafColumns) {
                $q->{$first ? 'whereHas' : 'orWhereHas'}($relation, function (Builder $related) use ($leafColumns, $node, $pattern, $term) {
                    $inner = true;
                    foreach ($leafColumns as $column) {
                        $this->leafCondition($related, $column, $node, $pattern, $term, $inner ? 'and' : 'or');
                        $inner = false;
                    }
                });
                $first = false;
            }
        });
    }

    /** One column's condition for a leaf term (the pre-Phase-2 loop body, parameterised on the boolean). */
    private function leafCondition(Builder $q, string $column, AstNode $node, string $pattern, string $term, string $boolean): void
    {
        $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $colMethod = $boolean === 'or' ? 'orWhere'    : 'where';

        if ($node instanceof ExactTerm) {
            // Case-insensitive exact: LOWER(quoted_col) = LOWER(?) on all drivers
            $q->$rawMethod('LOWER(' . $this->quoteColumn($column) . ') = LOWER(?)', [$term]);
        } elseif ($this->dbDriver === 'pgsql') {
            $q->$rawMethod($this->quoteColumn($column) . ' ILIKE ?', [$pattern]);
        } else {
            $q->$colMethod($column, 'LIKE', $pattern);
        }
    }

    private function quoteColumn(string $column): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::quoteIdentifier($column, $this->dbDriver);
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
        $safe = addcslashes($term, '%_');
        return match (true) {
            $node instanceof FuzzyTerm        => '%' . $safe . '%',
            $node instanceof IncludeMatchTerm => '%' . $safe . '%',
            $node instanceof PrefixTerm       => $safe . '%',
            $node instanceof SuffixTerm       => '%' . $safe,
            $node instanceof ExactTerm        => $term, // uses = operator, not LIKE
            default                            => '%' . $safe . '%',
        };
    }
}
