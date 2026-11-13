<?php

declare(strict_types=1);

namespace TestPilot\DTO;

/**
 * A single testable unit discovered in source code: a public method or a function.
 *
 * Built only from static analysis; nothing here required loading application code.
 */
final class TestTarget
{
    /**
     * @param list<ParameterInfo> $parameters
     * @param list<RawAnnotation> $classAnnotations Annotations declared on the owning class (empty for functions).
     * @param list<RawAnnotation> $annotations      Annotations declared on the method/function itself.
     */
    public function __construct(
        public readonly string $key,
        public readonly TargetType $type,
        public readonly ?string $class,
        public readonly ?string $method,
        public readonly ?string $function,
        public readonly ?string $namespace,
        public readonly TargetLocation $location,
        public readonly string $signature,
        public readonly array $parameters,
        public readonly ?string $returnType,
        public readonly bool $isStatic,
        public readonly array $classAnnotations,
        public readonly array $annotations,
        public readonly string $source,
        public readonly string $fingerprint,
        public readonly int $complexity,
        public readonly ?string $classSkeleton = null,
        public readonly ?string $declaredIn = null,
    ) {
    }

    public function shortName(): string
    {
        if ($this->type === TargetType::Function) {
            return ($this->function ?? 'function') . '()';
        }

        $class = $this->class ?? '';
        $short = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;

        return $short . '::' . $this->method;
    }

    public function isInherited(): bool
    {
        return $this->declaredIn !== null && $this->declaredIn !== $this->class;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'class' => $this->class,
            'method' => $this->method,
            'function' => $this->function,
            'namespace' => $this->namespace,
            'file' => $this->location->file,
            'line' => $this->location->startLine,
            'end_line' => $this->location->endLine,
            'signature' => $this->signature,
            'parameters' => array_map(static fn (ParameterInfo $p): array => $p->toArray(), $this->parameters),
            'return_type' => $this->returnType,
            'is_static' => $this->isStatic,
            'class_annotations' => array_map(static fn (RawAnnotation $a): array => $a->toArray(), $this->classAnnotations),
            'annotations' => array_map(static fn (RawAnnotation $a): array => $a->toArray(), $this->annotations),
            'fingerprint' => $this->fingerprint,
            'complexity' => $this->complexity,
            'declared_in' => $this->declaredIn,
        ];
    }
}
