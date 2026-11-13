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
 * Reads FormRequest::rules() statically from source. Nothing is executed.
 *
 * Supported: literal arrays whose values are pipe strings, arrays of strings, Rule::in/notIn/unique/exists/enum,
 * Password::min()/defaults(), plus rule objects and closures (reported as custom). Anything computed at runtime
 * (variables, conditionals, array_merge, method calls) is reported as dynamic so coverage is never overstated.
 */
final class FormRequestRulesExtractor
{
    private const RULE_FACADE = ['Illuminate\\Validation\\Rule', 'Rule'];
    private const PASSWORD = ['Illuminate\\Validation\\Rules\\Password', 'Password'];

    public function extract(string $code, string $class, string $file = ''): RulesExtraction
    {
        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse($code) ?? [];
        } catch (Error $e) {
            return new RulesExtraction($class, $file, [], ["Could not parse {$file}: {$e->getRawMessage()}"], false, 'missing');
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $finder = new NodeFinder();
        $classNode = $finder->findFirst($ast, static fn (Node $n): bool => $n instanceof Stmt\Class_
            && $n->namespacedName !== null && strcasecmp($n->namespacedName->toString(), ltrim($class, '\\')) === 0);

        if (! $classNode instanceof Stmt\Class_) {
            return new RulesExtraction($class, $file, [], ["Class {$class} was not found in {$file}"], false, 'missing');
        }

        $authorize = $this->authorize($classNode->getMethod('authorize'));
        $rules = $classNode->getMethod('rules');

        if ($rules === null) {
            return new RulesExtraction($class, $file, [], ["{$class} has no rules() method of its own (inherited or dynamic rules are not read)."], false, $authorize);
        }

        $returns = $this->returns($rules);
        if (count($returns) !== 1 || ! $returns[0]->expr instanceof Expr\Array_) {
            return new RulesExtraction($class, $file, [], [
                "{$class}::rules() is built at runtime (" . (count($returns) === 1 ? $this->describe($returns[0]->expr) : count($returns) . ' return statements') . '). TestPilot reads literal rule arrays only.',
            ], false, $authorize, $rules->getStartLine());
        }

        [$fields, $warnings, $complete] = $this->fieldsFromArray($returns[0]->expr);

        return new RulesExtraction($class, $file, $fields, $warnings, $complete, $authorize, $rules->getStartLine());
    }

    /**
     * Reads one literal rules array (['field' => 'required|…', …]). Shared with inline $request->validate([...]).
     *
     * @return array{0: list<FieldRules>, 1: list<string>, 2: bool}
     */
    public function fieldsFromArray(Expr\Array_ $array): array
    {
        $fields = [];
        $warnings = [];
        $complete = true;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            if (! $item->key instanceof Scalar\String_) {
                $warnings[] = 'A rule on line ' . $item->getStartLine() . ' has a computed field name and was skipped.';
                $complete = false;
                continue;
            }

            $parsed = $this->ruleList($item->value);
            foreach ($parsed as $rule) {
                if ($rule->kind !== ValidationRule::KNOWN) {
                    $complete = $complete && $rule->kind === ValidationRule::CUSTOM;
                }
            }

            $fields[] = new FieldRules($item->key->value, $parsed, $item->getStartLine());
        }

        return [$fields, $warnings, $complete];
    }

    /** @return list<ValidationRule> */
    private function ruleList(Expr $value): array
    {
        if ($value instanceof Scalar\String_) {
            return $this->fromString($value->value);
        }

        if ($value instanceof Expr\Array_) {
            $rules = [];
            foreach ($value->items as $item) {
                if ($item !== null) {
                    array_push($rules, ...$this->ruleNode($item->value));
                }
            }

            return $rules;
        }

        return [new ValidationRule('dynamic', [], $this->describe($value), ValidationRule::DYNAMIC, 'Computed at runtime: ' . $this->describe($value))];
    }

    /** @return list<ValidationRule> */
    private function ruleNode(Expr $node): array
    {
        if ($node instanceof Scalar\String_) {
            return $this->fromString($node->value);
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            return [new ValidationRule('closure', [], 'closure', ValidationRule::CUSTOM, 'Closure rule')];
        }

        if ($node instanceof Expr\New_) {
            $name = $node->class instanceof Node\Name ? $node->class->toString() : 'anonymous rule';

            return [new ValidationRule('custom', [$name], 'new ' . $name, ValidationRule::CUSTOM, 'Custom rule ' . $this->short($name))];
        }

        // Rule::unique(...)->ignore(...): walk down the method chain to the static call.
        $chain = [];
        $root = $node;
        while ($root instanceof Expr\MethodCall) {
            $chain[] = $root->name instanceof Node\Identifier ? $root->name->toString() : '?';
            $root = $root->var;
        }

        if ($root instanceof Expr\StaticCall && $root->class instanceof Node\Name && $root->name instanceof Node\Identifier) {
            $class = $root->class->toString();
            $method = strtolower($root->name->toString());
            $args = array_map(fn ($arg) => $arg instanceof Node\Arg ? $this->literal($arg->value) : null, $root->args);
            $note = $chain === [] ? null : 'with ->' . implode('->', array_reverse($chain)) . '()';

            if (in_array($class, self::RULE_FACADE, true)) {
                return [$this->ruleFacade($method, $args, $node, $note)];
            }

            if (in_array($class, self::PASSWORD, true)) {
                $min = $method === 'min' && is_int($args[0] ?? null) ? $args[0] : 8;

                return [new ValidationRule('password', [(string) $min], "Password::{$root->name->toString()}()", ValidationRule::KNOWN, "Password (min {$min})" . ($chain === [] ? '' : ' + extra checks'))];
            }
        }

        return [new ValidationRule('dynamic', [], $this->describe($node), ValidationRule::DYNAMIC, 'Computed at runtime: ' . $this->describe($node))];
    }

    /** @param list<mixed> $args */
    private function ruleFacade(string $method, array $args, Expr $node, ?string $note): ValidationRule
    {
        $strings = array_values(array_filter(array_map(static fn ($a) => is_scalar($a) ? (string) $a : null, $args), static fn ($a) => $a !== null));

        return match ($method) {
            'in', 'notin' => is_array($args[0] ?? null)
                ? new ValidationRule($method === 'in' ? 'in' : 'not_in', array_map('strval', $args[0]), 'Rule::' . $method . '(…)', ValidationRule::KNOWN, ($method === 'in' ? 'in:' : 'not_in:') . implode(',', array_map('strval', $args[0])))
                : new ValidationRule($method === 'in' ? 'in' : 'not_in', [], 'Rule::' . $method . '(…)', ValidationRule::DYNAMIC, 'Rule::' . $method . ' with computed values'),
            'unique', 'exists' => new ValidationRule($method, $strings, 'Rule::' . $method . '(…)', ValidationRule::KNOWN, $method . ':' . implode(',', $strings) . ($note === null ? '' : " ({$note})")),
            'enum' => new ValidationRule('enum', $strings, 'Rule::enum(…)', ValidationRule::KNOWN, 'enum:' . $this->short($strings[0] ?? '?')),
            default => new ValidationRule(strtolower($method), $strings, 'Rule::' . $method . '(…)', ValidationRule::DYNAMIC, 'Rule::' . $method . '() — conditional, not analysed'),
        };
    }

    /** @return list<ValidationRule> */
    private function fromString(string $rules): array
    {
        $parts = preg_match('/(^|\|)\s*(not_)?regex:/', $rules) === 1 ? $this->splitKeepingRegex($rules) : explode('|', $rules);

        return array_values(array_map(
            static fn (string $r): ValidationRule => ValidationRule::parse($r),
            array_filter($parts, static fn (string $r): bool => trim($r) !== ''),
        ));
    }

    /** @return list<string> */
    private function splitKeepingRegex(string $rules): array
    {
        $parts = [];
        foreach (explode('|', $rules) as $piece) {
            $last = count($parts) - 1;
            if ($last >= 0 && preg_match('/^\s*(not_)?regex:/', $parts[$last]) === 1 && ! preg_match('#^/.*/[a-z]*$#', trim(explode(':', $parts[$last], 2)[1]))) {
                $parts[$last] .= '|' . $piece;
                continue;
            }
            $parts[] = $piece;
        }

        return $parts;
    }

    private function authorize(?Stmt\ClassMethod $method): string
    {
        if ($method === null) {
            return 'missing';
        }

        $returns = $this->returns($method);
        if (count($returns) === 1 && $returns[0]->expr instanceof Expr\ConstFetch) {
            $value = strtolower($returns[0]->expr->name->toString());
            if (in_array($value, ['true', 'false'], true)) {
                return $value;
            }
        }

        return 'dynamic';
    }

    /** @return list<Stmt\Return_> Returns of this method only (not of nested closures). */
    private function returns(Stmt\ClassMethod $method): array
    {
        $found = [];
        $walk = function (array $nodes) use (&$walk, &$found): void {
            foreach ($nodes as $node) {
                if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\Class_) {
                    continue;
                }
                if ($node instanceof Stmt\Return_ && $node->expr !== null) {
                    $found[] = $node;
                }
                if ($node instanceof Node) {
                    foreach ($node->getSubNodeNames() as $name) {
                        $child = $node->{$name};
                        $walk(is_array($child) ? $child : [$child]);
                    }
                }
            }
        };
        $walk($method->stmts ?? []);

        return $found;
    }

    private function literal(Expr $expr): mixed
    {
        return match (true) {
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\Int_ => $expr->value,
            $expr instanceof Scalar\Float_ => $expr->value,
            $expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'class' => $expr->class->toString(),
            $expr instanceof Expr\Array_ => array_map(fn ($i) => $i === null ? null : $this->literal($i->value), $expr->items),
            default => null,
        };
    }

    public function describe(Expr $expr): string
    {
        $short = (new \ReflectionClass($expr))->getShortName();

        return match (true) {
            $expr instanceof Expr\Variable => '$' . (is_string($expr->name) ? $expr->name : '…'),
            $expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name => $expr->name->toString() . '(…)',
            $expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier => '->' . $expr->name->toString() . '(…)',
            $expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier => $this->short($expr->class->toString()) . '::' . $expr->name->toString() . '(…)',
            $expr instanceof Expr\Ternary => 'a conditional',
            default => rtrim($short, '_'),
        };
    }

    private function short(string $class): string
    {
        return str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;
    }
}
