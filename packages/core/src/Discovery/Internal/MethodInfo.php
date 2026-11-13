<?php

declare(strict_types=1);

namespace TestPilot\Discovery\Internal;

use TestPilot\DTO\ParameterInfo;
use TestPilot\DTO\RawAnnotation;

/** @internal Collected method/function data before targets are built. */
final class MethodInfo
{
    /**
     * @param list<ParameterInfo> $parameters
     * @param list<RawAnnotation> $annotations
     */
    public function __construct(
        public readonly string $name,
        public readonly string $visibility,
        public readonly bool $isStatic,
        public readonly bool $isAbstract,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly string $signature,
        public readonly array $parameters,
        public readonly ?string $returnType,
        public readonly string $source,
        public readonly int $complexity,
        public readonly array $annotations,
        public readonly string $file,
        public readonly string $declaringClass,
    ) {
    }
}
