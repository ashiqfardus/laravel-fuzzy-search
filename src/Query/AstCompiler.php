<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{
    AstNode, AndNode, OrNode, NotNode,
    FuzzyTerm, ExactTerm, PrefixTerm, SuffixTerm, IncludeMatchTerm,
    TypoTerm, FieldTerm
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
    public function __construct(private readonly string $dbDriver = 'mysql', private readonly int $typoDistance = 0) {}

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

        if ($node instanceof FieldTerm) {
            [$fieldColumns, $fieldRelations] = $this->resolveField($node->field, $columns, $relations);
            $this->visit($node->term, $builder, $fieldColumns, $fieldRelations, $boolean);
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
                // $q is guaranteed to be an Eloquent builder whenever $relations is non-empty:
                // SearchBuilder only ever compiles relation paths against an Eloquent source
                // (it rejects them upfront on a plain Query Builder — see resolveColumnTarget()).
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

    /**
     * A field scope names one direct column (bare or table-qualified) or one relation leaf
     * ("author.name"). Anything else is a syntax error listing the searchable fields.
     *
     * @return array{0: string[], 1: array<string, string[]>}
     */
    private function resolveField(string $field, array $columns, array $relations): array
    {
        $matches = array_values(array_filter(
            $columns,
            fn (string $column) => $column === $field || str_ends_with($column, '.' . $field)
        ));

        if (count($matches) > 1) {
            // "name" with both users.name and profiles.name in scope: picking the first
            // silently searched one table, so say so instead.
            throw QuerySyntaxException::ambiguousSearchField($field, $matches);
        }

        if ($matches !== []) {
            return [[$matches[0]], []];
        }

        $dot = strrpos($field, '.');
        if ($dot !== false) {
            $path = substr($field, 0, $dot);
            $leaf = substr($field, $dot + 1);
            if (isset($relations[$path]) && in_array($leaf, $relations[$path], true)) {
                return [[], [$path => [$leaf]]];
            }
        }

        // Direct columns are listed by the bare name — that is what the user types, and the
        // message is shown to end users, so it should not echo the schema's table names.
        $known = [];
        foreach ($columns as $column) {
            $dotPos  = strrpos($column, '.');
            $known[] = $dotPos === false ? $column : substr($column, $dotPos + 1);
        }
        $known = array_values(array_unique($known));

        foreach ($relations as $path => $leaves) {
            foreach ($leaves as $leaf) {
                $known[] = $path . '.' . $leaf;
            }
        }
        throw QuerySyntaxException::unknownSearchField($field, $known);
    }

    /** One column's condition for a leaf term (the pre-Phase-2 loop body, parameterised on the boolean). */
    private function leafCondition(Builder $q, string $column, AstNode $node, string $pattern, string $term, string $boolean): void
    {
        $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';
        $colMethod = $boolean === 'or' ? 'orWhere'    : 'where';

        if ($node instanceof TypoTerm) {
            // The fuzzy driver builds the omission/substitution/transposition patterns for
            // $typoDistance (0 = plain substring); it wants the underlying query builder.
            $target = $q instanceof \Illuminate\Database\Eloquent\Builder ? $q->getQuery() : $q;
            app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class)->applyFuzzyWhere(
                $target, $column, $term, 'fuzzy', ['max_distance' => $this->typoDistance], $boolean
            );
            return;
        }

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
