<?php

declare(strict_types=1);

namespace TestPilot\Discovery\Internal;

use TestPilot\DTO\RawAnnotation;

/** @internal Collected class-like data (class, trait, interface, enum). */
final class ClassInfo
{
    /**
     * @param list<string>              $traits
     * @param list<RawAnnotation>       $annotations
     * @param array<string, MethodInfo> $methods     Keyed by lower-cased method name.
     * @param list<string>              $properties  Property declarations, as written.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly bool $isAbstract,
        public readonly ?string $parent,
        public readonly array $traits,
        public readonly array $annotations,
        public readonly array $methods,
        public readonly array $properties,
        public readonly string $header,
        public readonly string $file,
        public readonly int $line,
    ) {
    }

    public function isConcrete(): bool
    {
        return in_array($this->kind, ['class', 'enum'], true) && ! $this->isAbstract;
    }
}
