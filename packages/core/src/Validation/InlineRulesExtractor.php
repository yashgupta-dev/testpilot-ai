<?php

declare(strict_types=1);

namespace TestPilot\Validation;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Reads validation written inside a controller action — no FormRequest needed. Nothing is executed.
 *
 * Laravel's ValidationException (422 + {"errors": …}):
 *   $request->validate([...])              request()->validate([...])
 *   $request->validateWithBag('b', [...])  $this->validate($request, [...])
 *   Validator::make($data, [...])->validate()   $v = Validator::make(...); $v->validate() / ->validated()
 *
 * Custom handling: $v = Validator::make(...); if ($v->fails()) { return response()->json(…, 400); }
 *   → rules are tested, "rejected" means that status code; the error body is not assumed.
 */
final class InlineRulesExtractor
{
    private const SIDE_EFFECTS = ['create', 'save', 'update', 'delete', 'insert', 'forcecreate', 'updateorcreate', 'firstorcreate', 'increment', 'decrement', 'dispatch', 'send', 'queue', 'post', 'put', 'charge'];

    public function __construct(
        private readonly FormRequestRulesExtractor $arrays = new FormRequestRulesExtractor(),
    ) {
    }

    public function extract(string $code, string $class, string $method, string $file = '', ?int $declaredStatus = null): RulesExtraction
    {
        $short = (str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class) . '::' . $method;
        $none = fn (string $why, int $line = 0): RulesExtraction => new RulesExtraction($short, $file, [], [$why], false, 'true', $line, 'inline');

        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error $e) {
            return $none("Could not parse {$file}: {$e->getRawMessage()}");
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);
        $finder = new NodeFinder();

        $classNode = $finder->findFirst($ast, static fn (Node $n): bool => $n instanceof Stmt\ClassLike
            && $n->namespacedName !== null && strcasecmp($n->namespacedName->toString(), ltrim($class, '\\')) === 0);
        $methodNode = $classNode instanceof Stmt\ClassLike ? $classNode->getMethod($method) : null;
        if (! $methodNode instanceof Stmt\ClassMethod || $methodNode->stmts === null) {
            return $none("{$short}() was not found in {$file}.");
        }

        $found = $this->findCall($finder, $methodNode);
        if ($found === null) {
            return $none("{$short}() has no FormRequest parameter and no \$request->validate(), \$this->validate() or Validator::make() call.");
        }

        [$call, $rulesExpr, $label, $standard] = $found;
        $line = $call->getStartLine();
        $warnings = [];
        $status = $standard ? 422 : null;
        $mode = $standard ? 'laravel' : 'unknown';

        if (! $standard) {
            [$redirect, $withErrors] = $this->redirectAfter($finder, $methodNode, $line);
            if ($declaredStatus !== null) {
                [$mode, $status] = ['status', $declaredStatus];
            } elseif ($withErrors) {
                [$mode, $status] = ['redirect_errors', 302];
            } elseif (($found = $this->customStatus($finder, $methodNode, $line)) !== null) {
                [$mode, $status] = ['status', $found];
            }

            $warnings[] = match ($mode) {
                'status' => "Validation failures return your own response (HTTP {$status}); tests check the status code, not the error body.",
                'redirect_errors' => 'Validation failures redirect back with ->withErrors(); tests check the 302 and the field errors in the session.',
                default => $redirect
                    ? 'Validation failures redirect without ->withErrors(), which looks the same as a successful submit. Add ->withErrors($validator), or declare #[Validation(errorStatus: 302)] to check the status only.'
                    : 'Validation failures are handled by your own code and no status code could be read. Declare it: #[Validation(errorStatus: 400)].',
            };
        }

        $array = $this->resolveArray($finder, $methodNode, $rulesExpr, $line);
        $inline = ['call' => $label, 'line' => $line, 'error_status' => $status, 'error_mode' => $mode, 'standard_errors' => $standard];

        if ($array === null) {
            return new RulesExtraction($short, $file, [], [
                "The rules passed to {$label} on line {$line} are built at runtime (" . $this->arrays->describe($rulesExpr) . '). TestPilot reads literal rule arrays only.',
            ], false, 'true', $line, 'inline', $inline);
        }

        [$fields, $more, $complete] = $this->arrays->fieldsFromArray($array);
        array_push($warnings, ...$more);

        $effects = $this->sideEffectsBefore($finder, $methodNode, $line);
        if ($effects !== []) {
            $warnings[] = 'Code runs before validation (' . implode(', ', $effects) . '). Invalid requests still reach it; tests use a fresh database, but check for external calls.';
        }
        if ($this->insideCondition($finder, $methodNode, $call)) {
            $warnings[] = "{$label} on line {$line} only runs on some paths (inside an if/switch). Cases assume it always runs.";
        }

        return new RulesExtraction($short, $file, $fields, $warnings, $complete, 'true', $line, 'inline', $inline);
    }

    /** @return array{0: Expr, 1: Expr, 2: string, 3: bool}|null [call node, rules expression, label, throws ValidationException] */
    private function findCall(NodeFinder $finder, Stmt\ClassMethod $method): ?array
    {
        $candidates = [];

        foreach ($finder->find($method->stmts, static fn (Node $n): bool => $n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall || $n instanceof Expr\FuncCall) as $node) {
            $name = $node instanceof Expr\FuncCall
                ? ($node->name instanceof Node\Name ? strtolower($node->name->getLast()) : '')
                : ($node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '');

            // $request->validate([...]) / request()->validate([...]) / $this->validate($request, [...]) / ->validateWithBag('bag', [...])
            if ($node instanceof Expr\MethodCall && in_array($name, ['validate', 'validatewithbag'], true) && $node->args !== []) {
                $isThis = $node->var instanceof Expr\Variable && $node->var->name === 'this';
                $index = $isThis ? ($name === 'validate' ? 1 : 2) : ($name === 'validate' ? 0 : 1);
                $arg = $node->args[$index] ?? null;
                if ($arg instanceof Node\Arg) {
                    $receiver = $isThis ? '$this' : ($node->var instanceof Expr\Variable && is_string($node->var->name) ? '$' . $node->var->name : 'request()');
                    $candidates[] = [$node, $arg->value, "{$receiver}->" . ($name === 'validate' ? 'validate' : 'validateWithBag') . '()', true];
                }
                continue;
            }

            // Validator::make($data, [...]) or validator($data, [...])
            $isMake = ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && strcasecmp($node->class->getLast(), 'Validator') === 0 && $name === 'make')
                || ($node instanceof Expr\FuncCall && $name === 'validator');
            if ($isMake && ($node->args[1] ?? null) instanceof Node\Arg) {
                $label = $node instanceof Expr\StaticCall ? 'Validator::make()' : 'validator()';
                $candidates[] = [$node, $node->args[1]->value, $label, $this->makeThrows($finder, $method, $node)];
            }
        }

        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => $a[0]->getStartFilePos() <=> $b[0]->getStartFilePos());

        return $candidates[0];
    }

    /** True when the Validator is used with ->validate()/->validated() (throws), false when ->fails()/->passes() is handled by hand. */
    private function makeThrows(NodeFinder $finder, Stmt\ClassMethod $method, Expr $make): bool
    {
        foreach ($finder->findInstanceOf($method->stmts, Expr\MethodCall::class) as $call) {
            if ($call->var === $make && $call->name instanceof Node\Identifier) {
                return in_array(strtolower($call->name->toString()), ['validate', 'validated'], true);
            }
        }

        $variable = null;
        foreach ($finder->findInstanceOf($method->stmts, Expr\Assign::class) as $assign) {
            if ($assign->expr === $make && $assign->var instanceof Expr\Variable && is_string($assign->var->name)) {
                $variable = $assign->var->name;
            }
        }
        if ($variable === null) {
            return false;
        }

        foreach ($finder->findInstanceOf($method->stmts, Expr\MethodCall::class) as $call) {
            if ($call->var instanceof Expr\Variable && $call->var->name === $variable && $call->name instanceof Node\Identifier) {
                $name = strtolower($call->name->toString());
                if (in_array($name, ['validate', 'validated'], true)) {
                    return true;
                }
                if (in_array($name, ['fails', 'passes'], true)) {
                    return false;
                }
            }
        }

        return false;
    }

    /** @return array{0: bool, 1: bool} [redirects after the call, uses ->withErrors()] */
    private function redirectAfter(NodeFinder $finder, Stmt\ClassMethod $method, int $afterLine): array
    {
        $redirect = false;
        $withErrors = false;
        foreach ($finder->find($method->stmts, static fn (Node $n): bool => ($n instanceof Expr\MethodCall || $n instanceof Expr\FuncCall) && $n->getStartLine() >= $afterLine) as $call) {
            $name = $call instanceof Expr\FuncCall
                ? ($call->name instanceof Node\Name ? strtolower($call->name->getLast()) : '')
                : ($call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '');
            $redirect = $redirect || in_array($name, ['redirect', 'back', 'route', 'to'], true);
            $withErrors = $withErrors || $name === 'witherrors';
        }

        return [$redirect || $withErrors, $withErrors];
    }

    /** First 4xx status written as a literal after the validation call: response()->json(…, 400), response(…, 400), abort(400). */
    private function customStatus(NodeFinder $finder, Stmt\ClassMethod $method, int $afterLine): ?int
    {
        $calls = $finder->find($method->stmts, static fn (Node $n): bool => ($n instanceof Expr\MethodCall || $n instanceof Expr\FuncCall) && $n->getStartLine() >= $afterLine);
        usort($calls, static fn (Node $a, Node $b): int => $a->getStartFilePos() <=> $b->getStartFilePos());

        foreach ($calls as $call) {
            $name = $call instanceof Expr\FuncCall
                ? ($call->name instanceof Node\Name ? strtolower($call->name->getLast()) : '')
                : ($call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : '');
            $index = match ($name) {
                'abort' => 0,
                'json', 'response', 'make' => 1,
                default => null,
            };
            $arg = $index === null ? null : ($call->args[$index] ?? null);
            if ($arg instanceof Node\Arg && $arg->value instanceof Scalar\Int_ && $arg->value->value >= 400 && $arg->value->value < 500) {
                return $arg->value->value;
            }
        }

        return null;
    }

    /** The rules array itself, or the literal array last assigned to the variable passed as rules. */
    private function resolveArray(NodeFinder $finder, Stmt\ClassMethod $method, Expr $rules, int $beforeLine): ?Expr\Array_
    {
        if ($rules instanceof Expr\Array_) {
            return $rules;
        }
        if (! $rules instanceof Expr\Variable || ! is_string($rules->name)) {
            return null;
        }

        $assigned = null;
        foreach ($finder->findInstanceOf($method->stmts, Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Expr\Variable && $assign->var->name === $rules->name && $assign->getStartLine() <= $beforeLine) {
                $assigned = $assign->expr;
            }
        }

        return $assigned instanceof Expr\Array_ ? $assigned : null;
    }

    /** @return list<string> Calls that change state before validation runs, e.g. "Order::create() line 21". */
    private function sideEffectsBefore(NodeFinder $finder, Stmt\ClassMethod $method, int $line): array
    {
        $found = [];
        foreach ($finder->find($method->stmts, static fn (Node $n): bool => ($n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall) && $n->getStartLine() < $line) as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if (in_array(strtolower($name), self::SIDE_EFFECTS, true)) {
                $owner = $call instanceof Expr\StaticCall && $call->class instanceof Node\Name ? $call->class->getLast() . '::' : '->';
                $found[] = "{$owner}{$name}() line {$call->getStartLine()}";
            }
        }

        return array_values(array_unique($found));
    }

    private function insideCondition(NodeFinder $finder, Stmt\ClassMethod $method, Expr $call): bool
    {
        $start = $call->getStartFilePos();
        foreach ($finder->find($method->stmts, static fn (Node $n): bool => $n instanceof Stmt\If_ || $n instanceof Stmt\Switch_ || $n instanceof Expr\Ternary || $n instanceof Expr\Match_) as $branch) {
            $condEnd = $branch instanceof Stmt\If_ ? $branch->cond->getEndFilePos() : ($branch instanceof Stmt\Switch_ ? $branch->cond->getEndFilePos() : -1);
            // The call itself being the condition (if ($v->fails())) is fine; only calls inside a branch body count.
            if ($start > $condEnd && $start >= $branch->getStartFilePos() && $start <= $branch->getEndFilePos()) {
                return true;
            }
        }

        return false;
    }
}
