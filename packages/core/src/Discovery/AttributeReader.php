<?php

declare(strict_types=1);

namespace TestPilot\Discovery;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use TestPilot\DTO\RawAnnotation;

/**
 * Turns TestPilot attribute nodes from the AST into RawAnnotation objects.
 *
 * Positional arguments are mapped to constructor parameter names using reflection on
 * TestPilot's own attribute classes (never on application classes). Values are evaluated
 * as constant expressions only; anything else is reported as a warning and ignored.
 */
final class AttributeReader
{
    public const NAMESPACE = 'TestPilot\\Attributes\\';

    /** @var array<string, list<ReflectionParameter>|null> */
    private array $parameterCache = [];

    private readonly ConstExprEvaluator $evaluator;

    public function __construct()
    {
        $this->evaluator = new ConstExprEvaluator(static function (Expr $expr): never {
            throw new ConstExprEvaluationException('Expression is not a constant: ' . $expr::class);
        });
    }

    /**
     * @param array<AttributeGroup> $groups
     *
     * @return list<RawAnnotation>
     */
    public function read(array $groups, string $scope): array
    {
        $annotations = [];

        foreach ($groups as $group) {
            foreach ($group->attrs as $attribute) {
                $fqcn = ltrim($attribute->name->toString(), '\\');

                if (! str_starts_with($fqcn, self::NAMESPACE)) {
                    continue;
                }

                $short = substr($fqcn, strlen(self::NAMESPACE));
                $warnings = [];
                $parameters = $this->parameters($fqcn);

                if ($parameters === null) {
                    $annotations[] = new RawAnnotation($short, [], $attribute->getStartLine(), $scope, [
                        "Unknown TestPilot attribute #[{$short}] — it will be ignored.",
                    ]);
                    continue;
                }

                $arguments = [];
                foreach ($attribute->args as $index => $arg) {
                    $name = $arg->name?->toString() ?? ($parameters[$index] ?? null)?->getName();

                    if ($name === null) {
                        $warnings[] = "#[{$short}] has more arguments than it accepts.";
                        continue;
                    }

                    $parameter = $this->findParameter($parameters, $name);
                    if ($parameter === null) {
                        $warnings[] = "#[{$short}] has no argument named \"{$name}\".";
                        continue;
                    }

                    try {
                        $value = $this->evaluator->evaluateDirectly($arg->value);
                    } catch (ConstExprEvaluationException) {
                        $warnings[] = "#[{$short}] argument \"{$name}\" must be a literal value; it was ignored.";
                        continue;
                    }

                    if (! $this->typeMatches($parameter, $value)) {
                        $warnings[] = sprintf(
                            '#[%s] argument "%s" has the wrong type (%s given); it was ignored.',
                            $short,
                            $name,
                            get_debug_type($value),
                        );
                        continue;
                    }

                    $arguments[$name] = $value;
                }

                $annotations[] = new RawAnnotation($short, $arguments, $attribute->getStartLine(), $scope, $warnings);
            }
        }

        return $annotations;
    }

    /** @return list<ReflectionParameter>|null */
    private function parameters(string $fqcn): ?array
    {
        if (array_key_exists($fqcn, $this->parameterCache)) {
            return $this->parameterCache[$fqcn];
        }

        // Only TestPilot's own attribute classes are ever reflected (they ship with this package).
        if (! class_exists($fqcn)) {
            return $this->parameterCache[$fqcn] = null;
        }

        $constructor = (new ReflectionClass($fqcn))->getConstructor();

        return $this->parameterCache[$fqcn] = $constructor === null ? [] : $constructor->getParameters();
    }

    /** @param list<ReflectionParameter> $parameters */
    private function findParameter(array $parameters, string $name): ?ReflectionParameter
    {
        foreach ($parameters as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter;
            }
        }

        return null;
    }

    private function typeMatches(ReflectionParameter $parameter, mixed $value): bool
    {
        $type = $parameter->getType();

        if ($type === null) {
            return true;
        }

        if ($value === null) {
            return $type->allowsNull();
        }

        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(
                static fn ($t): string => $t instanceof ReflectionNamedType ? $t->getName() : 'mixed',
                $type->getTypes(),
            ),
            default => ['mixed'],
        };

        foreach ($names as $name) {
            $ok = match ($name) {
                'mixed' => true,
                'bool' => is_bool($value),
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'string' => is_string($value),
                'array' => is_array($value),
                default => false,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
