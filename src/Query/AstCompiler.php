<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{
    AstNode, AndNode, OrNode, NotNode,
    FuzzyTerm, ExactTerm, PrefixTerm, SuffixTerm, IncludeMatchTerm,
    TypoTerm, FieldTerm
};
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * @internal This class is not part of the public API and may change without notice.
 *
 * Walk the AST and apply WHERE clauses to a query builder.
 * All term values pass through PDO bindings — no string interpolation.
 */
class AstCompiler
{
    /**
     * @param array<string, mixed>  $fuzzyOptions the builder's driver options (max_patterns, ...),
     *                                            forwarded to applyFuzzyWhere() for ~ terms
     * @param array<string, string> $qualified    direct column => "table." prefix it is written
     *                                            with in SQL (SearchBuilder::qualifiedColumnMap());
     *                                            field scopes still resolve against the bare names
     * @param string[]|null         $listed       the fields the unknown-field message may name, as it
     *                                            names them ("email", "author.name"): SearchBuilder
     *                                            leaves out those the model hides (ruling ER-95). It
     *                                            narrows the message only; null names every field
     */
    public function __construct(
        private readonly string $dbDriver = 'mysql',
        private readonly int $typoDistance = 0,
        private readonly array $fuzzyOptions = [],
        private readonly array $qualified = [],
        private readonly ?array $listed = null,
    ) {}

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

    /**
     * $negated: the node is inside a NOT, whose predicate must be true or false, never NULL — see
     * leafCondition().
     */
    private function visit(AstNode $node, Builder $builder, array $columns, array $relations, string $boolean = 'and', bool $negated = false): void
    {
        if ($node instanceof AndNode) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $builder->$method(function (Builder $q) use ($node, $columns, $relations, $negated) {
                foreach ($node->children as $child) {
                    $this->visit($child, $q, $columns, $relations, 'and', $negated);
                }
            });
            return;
        }

        if ($node instanceof OrNode) {
            $method = $boolean === 'or' ? 'orWhere' : 'where';
            $builder->$method(function (Builder $q) use ($node, $columns, $relations, $negated) {
                foreach ($node->children as $i => $child) {
                    $this->visit($child, $q, $columns, $relations, $i === 0 ? 'and' : 'or', $negated);
                }
            });
            return;
        }

        if ($node instanceof NotNode) {
            $method = $boolean === 'or' ? 'orWhereNot' : 'whereNot';
            $builder->$method(function (Builder $q) use ($node, $columns, $relations) {
                $this->visit($node->child, $q, $columns, $relations, 'and', true);
            });
            return;
        }

        if ($node instanceof FieldTerm) {
            [$fieldColumns, $fieldRelations] = $this->resolveField($node->field, $columns, $relations);
            $this->visit($node->term, $builder, $fieldColumns, $fieldRelations, $boolean, $negated);
            return;
        }

        // Leaf term — match against any direct column OR any relation column (all OR'd)
        $term    = $this->extractTerm($node);
        $pattern = $this->patternFor($node, $term);

        $method = $boolean === 'or' ? 'orWhere' : 'where';
        $builder->$method(function (Builder $q) use ($columns, $relations, $node, $pattern, $term, $negated) {
            $first = true;
            foreach ($columns as $column) {
                $this->leafCondition($q, ($this->qualified[$column] ?? '') . $column, $node, $pattern, $term, $first ? 'and' : 'or', $negated);
                $first = false;
            }
            foreach ($relations as $relation => $leafColumns) {
                // $q is guaranteed to be an Eloquent builder whenever $relations is non-empty:
                // SearchBuilder only ever compiles relation paths against an Eloquent source
                // (it rejects them upfront on a plain Query Builder — see resolveColumnTarget()).
                $q->{$first ? 'whereHas' : 'orWhereHas'}($relation, function (Builder $related) use ($leafColumns, $node, $pattern, $term, $negated) {
                    $inner = true;
                    foreach ($leafColumns as $column) {
                        $this->leafCondition($related, $column, $node, $pattern, $term, $inner ? 'and' : 'or', $negated);
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

        if (in_array($field, $matches, true)) {
            return [[$field], []]; // an exact bare-name declaration wins outright (P5-R15a)
        }

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
        if ($this->listed !== null) {
            $known = array_values(array_intersect($known, $this->listed));
        }
        throw QuerySyntaxException::unknownSearchField($field, $known);
    }

    /**
     * One column's condition for a leaf term (the pre-Phase-2 loop body, parameterised on the
     * boolean). Under a NOT ($negated) a NULL column is read as '': NOT (a LIKE x OR b LIKE x) is
     * NULL, not true, when b is NULL, so `pro !banned` dropped every row with a NULL searchable
     * column. The fuzzy driver takes a column name, not an expression, so a ~term gets
     * `col IS NOT NULL AND (...)` instead, which is false for NULL in the same way. SQL without a
     * NOT is unchanged.
     */
    private function leafCondition(Builder $q, string $column, AstNode $node, string $pattern, string $term, string $boolean, bool $negated = false): void
    {
        $rawMethod = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        if ($node instanceof TypoTerm) {
            // The fuzzy driver builds the omission/substitution/transposition patterns for
            // $typoDistance (0 = plain substring); it wants the underlying query builder.
            $target = $q instanceof \Illuminate\Database\Eloquent\Builder ? $q->getQuery() : $q;
            $fuzzy  = fn ($query, string $bool) => app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class)->applyFuzzyWhere(
                $query, $column, $term, 'fuzzy', ['max_distance' => $this->typoDistance] + $this->fuzzyOptions, $bool
            );

            $negated
                ? $target->where(fn ($g) => $fuzzy($g->whereNotNull($column), 'and'), null, null, $boolean)
                : $fuzzy($target, $boolean);
            return;
        }

        if ($node instanceof ExactTerm) {
            // Case-insensitive exact: LOWER(quoted_col) = LOWER(?) on all drivers
            $q->$rawMethod('LOWER(' . $this->nullSafe($this->quoteColumn($column, $q), $negated) . ') = LOWER(?)', [$term]);
        } elseif ($negated) {
            // whereLike()'s predicate, the column wrapped as where() writes it
            $q->$rawMethod(DbDialect::like($this->nullSafe($q->getGrammar()->wrap($column), true), $this->dbDriver, DbDialect::likeOperator($this->dbDriver)), [$pattern]);
        } else {
            DbDialect::whereLike($q, $column, $pattern, $this->dbDriver, $boolean);
        }
    }

    /** COALESCE(col, '') under a NOT (see leafCondition()), the column as written otherwise. */
    private function nullSafe(string $sql, bool $negated): string
    {
        return $negated ? "COALESCE({$sql}, '')" : $sql;
    }

    private function quoteColumn(string $column, Builder $q): string
    {
        return DbDialect::quoteIdentifier($column, $this->dbDriver, $q->getGrammar()->getTablePrefix());
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
        $safe = DbDialect::escapeLike($term, $this->dbDriver);
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
