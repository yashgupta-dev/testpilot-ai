<?php

declare(strict_types=1);

namespace TestPilot\Discovery;

use TestPilot\Discovery\Internal\ClassInfo;
use TestPilot\Discovery\Internal\MethodInfo;
use TestPilot\DTO\RawAnnotation;
use TestPilot\DTO\TargetLocation;
use TestPilot\DTO\TargetType;
use TestPilot\DTO\TestTarget;
use TestPilot\Storage\SourceFingerprinter;

/**
 * Turns collected declarations into test targets.
 *
 * Rules:
 *  - Only concrete classes and enums produce method targets. Methods from parent classes and traits
 *    that are part of the scanned code are included as inherited targets.
 *  - A public method is a target when it carries a TestPilot attribute, or when its class
 *    (or a parent class) does.
 *  - Private and protected methods are never executed directly; an annotation on one produces a
 *    warning recommending a public entry point.
 *  - Constructors and magic methods are skipped.
 */
final class TargetBuilder
{
    /** @var list<string> */
    private array $warnings = [];

    /** @param array<string, ClassInfo> $classes Keyed by lower-cased FQCN. */
    public function __construct(private readonly array $classes)
    {
    }

    /**
     * @param list<MethodInfo> $functions
     *
     * @return list<\TestPilot\DTO\TestTarget>
     */
    public function build(array $functions): array
    {
        $targets = [];

        foreach ($this->classes as $class) {
            if (! $class->isConcrete()) {
                $this->warnAboutAnnotatedNonConcrete($class);
                continue;
            }

            $classAnnotations = $this->inheritedClassAnnotations($class);
            $classOptedIn = $classAnnotations !== [];
            $skeleton = $this->skeleton($class);

            foreach ($this->allMethods($class) as $method) {
                $lower = strtolower($method->name);
                $annotated = $method->annotations !== [];

                if ($lower === '__construct' || str_starts_with($lower, '__')) {
                    if ($annotated) {
                        $this->warnings[] = "{$method->file}:{$method->startLine} {$class->name}::{$method->name} is a constructor or magic method and cannot be a target.";
                    }
                    continue;
                }

                if ($method->visibility !== 'public') {
                    if ($annotated) {
                        $this->warnings[] = "{$method->file}:{$method->startLine} {$class->name}::{$method->name} is {$method->visibility}. TestPilot tests it through the public methods that call it.";
                    }
                    continue;
                }

                if ($method->isAbstract || (! $annotated && ! $classOptedIn)) {
                    continue;
                }

                $targets[] = $this->target(
                    key: $class->name . '::' . $method->name,
                    type: TargetType::Method,
                    method: $method,
                    class: $class->name,
                    classAnnotations: $classAnnotations,
                    skeleton: $skeleton,
                );
            }
        }

        foreach ($functions as $function) {
            if ($function->annotations === []) {
                continue;
            }

            $targets[] = $this->target(
                key: $function->name . '()',
                type: TargetType::Function,
                method: $function,
                class: null,
                classAnnotations: [],
                skeleton: null,
            );
        }

        usort($targets, static fn (TestTarget $a, TestTarget $b): int => strcmp($a->key, $b->key));

        return $targets;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param list<RawAnnotation> $classAnnotations
     */
    private function target(
        string $key,
        TargetType $type,
        MethodInfo $method,
        ?string $class,
        array $classAnnotations,
        ?string $skeleton,
    ): TestTarget {
        $fqn = $class ?? $method->name;
        $namespace = str_contains($fqn, '\\') ? substr($fqn, 0, (int) strrpos($fqn, '\\')) : null;

        return new TestTarget(
            key: $key,
            type: $type,
            class: $class,
            method: $type === TargetType::Method ? $method->name : null,
            function: $type === TargetType::Function ? $method->name : null,
            namespace: $namespace,
            location: new TargetLocation($method->file, $method->startLine, $method->endLine),
            signature: $method->signature,
            parameters: $method->parameters,
            returnType: $method->returnType,
            isStatic: $method->isStatic,
            classAnnotations: $classAnnotations,
            annotations: $method->annotations,
            source: $method->source,
            fingerprint: SourceFingerprinter::fingerprint($method->source),
            complexity: $method->complexity,
            classSkeleton: $skeleton,
            declaredIn: $type === TargetType::Method ? $method->declaringClass : null,
        );
    }

    /**
     * Own methods first, then trait methods, then parent methods that were not overridden.
     *
     * @return list<MethodInfo>
     */
    private function allMethods(ClassInfo $class): array
    {
        $methods = $class->methods;
        $visited = [strtolower($class->name) => true];

        $merge = function (ClassInfo $source) use (&$methods): void {
            foreach ($source->methods as $lower => $method) {
                $methods[$lower] ??= $method;
            }
        };

        foreach ($this->traitsOf($class, $visited) as $trait) {
            $merge($trait);
        }

        $parent = $this->lookup($class->parent);
        while ($parent !== null && ! isset($visited[strtolower($parent->name)])) {
            $visited[strtolower($parent->name)] = true;
            $merge($parent);
            foreach ($this->traitsOf($parent, $visited) as $trait) {
                $merge($trait);
            }
            $parent = $this->lookup($parent->parent);
        }

        return array_values($methods);
    }

    /**
     * @param array<string, bool> $visited
     *
     * @return list<ClassInfo>
     */
    private function traitsOf(ClassInfo $class, array &$visited): array
    {
        $found = [];
        foreach ($class->traits as $name) {
            $trait = $this->lookup($name);
            if ($trait === null || isset($visited[strtolower($trait->name)])) {
                continue;
            }
            $visited[strtolower($trait->name)] = true;
            $found[] = $trait;
            array_push($found, ...$this->traitsOf($trait, $visited));
        }

        return $found;
    }

    /**
     * Parent class annotations come first so the child's own annotations override them.
     *
     * @return list<RawAnnotation>
     */
    private function inheritedClassAnnotations(ClassInfo $class): array
    {
        $chain = [];
        $seen = [];
        $current = $class;
        while ($current !== null && ! isset($seen[strtolower($current->name)])) {
            $seen[strtolower($current->name)] = true;
            array_unshift($chain, $current);
            $current = $this->lookup($current->parent);
        }

        $annotations = [];
        foreach ($chain as $item) {
            array_push($annotations, ...$item->annotations);
        }

        return $annotations;
    }

    private function skeleton(ClassInfo $class): string
    {
        $lines = [$class->header, '{'];
        foreach ($class->properties as $property) {
            $lines[] = '    ' . $property;
        }
        foreach ($this->allMethods($class) as $method) {
            if ($method->visibility === 'private') {
                continue;
            }
            $lines[] = '    ' . $method->signature . ';';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    private function warnAboutAnnotatedNonConcrete(ClassInfo $class): void
    {
        if ($class->kind === 'trait' || $class->isAbstract) {
            return; // Their methods are picked up through the concrete classes that use or extend them.
        }

        if ($class->annotations !== []) {
            $this->warnings[] = "{$class->file}:{$class->line} {$class->name} is an {$class->kind}; annotate the implementing classes instead.";
        }
    }

    private function lookup(?string $name): ?ClassInfo
    {
        return $name === null ? null : ($this->classes[strtolower(ltrim($name, '\\'))] ?? null);
    }
}
