<?php

declare(strict_types=1);

namespace TestPilot\DTO;

/**
 * A TestPilot attribute as written in source, with arguments normalised to named keys.
 */
final class RawAnnotation
{
    /**
     * @param string               $name      Short attribute name, e.g. "UnitTest".
     * @param array<string, mixed> $arguments Named arguments (positional ones are mapped to parameter names).
     * @param list<string>         $warnings  Problems found while reading the attribute.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
        public readonly int $line,
        public readonly string $scope,
        public readonly array $warnings = [],
    ) {
    }

    public function argument(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->arguments) ? $this->arguments[$name] : $default;
    }

    public function describe(): string
    {
        return sprintf('%s #[%s] · line %d', $this->scope, $this->name, $this->line);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'arguments' => $this->arguments,
            'line' => $this->line,
            'scope' => $this->scope,
            'warnings' => $this->warnings,
        ];
    }
}
