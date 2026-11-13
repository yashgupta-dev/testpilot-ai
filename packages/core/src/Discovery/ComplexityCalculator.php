<?php

declare(strict_types=1);

namespace TestPilot\Discovery;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Cyclomatic complexity: 1 + one point per decision point.
 * Nested closures and arrow functions are counted too, since they are part of the method's logic.
 */
final class ComplexityCalculator
{
    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder();
    }

    public function calculate(Stmt\ClassMethod|Stmt\Function_ $node): int
    {
        $statements = $node->getStmts();
        if ($statements === null) {
            return 1;
        }

        $decisions = $this->finder->find($statements, static function (Node $n): bool {
            return match (true) {
                $n instanceof Stmt\If_,
                $n instanceof Stmt\ElseIf_,
                $n instanceof Stmt\For_,
                $n instanceof Stmt\Foreach_,
                $n instanceof Stmt\While_,
                $n instanceof Stmt\Do_,
                $n instanceof Stmt\Catch_,
                $n instanceof Expr\Ternary,
                $n instanceof Expr\BinaryOp\BooleanAnd,
                $n instanceof Expr\BinaryOp\BooleanOr,
                $n instanceof Expr\BinaryOp\LogicalAnd,
                $n instanceof Expr\BinaryOp\LogicalOr,
                $n instanceof Expr\BinaryOp\Coalesce,
                $n instanceof Expr\AssignOp\Coalesce => true,
                $n instanceof Stmt\Case_ => $n->cond !== null,
                $n instanceof Node\MatchArm => $n->conds !== null,
                default => false,
            };
        });

        return 1 + count($decisions);
    }
}
