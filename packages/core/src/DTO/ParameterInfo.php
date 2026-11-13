<?php

declare(strict_types=1);

namespace TestPilot\DTO;

final class ParameterInfo
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $type,
        public readonly bool $hasDefault,
        public readonly ?string $default,
        public readonly bool $variadic,
        public readonly bool $byRef,
        /** Fully-qualified class name when the type is a single class (e.g. a FormRequest). */
        public readonly ?string $class = null,
    ) {
    }

    public function allowsNull(): bool
    {
        if ($this->type === null) {
            return true;
        }

        $type = strtolower($this->type);

        return str_starts_with($type, '?') || str_contains($type, 'null') || $type === 'mixed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'has_default' => $this->hasDefault,
            'default' => $this->default,
            'variadic' => $this->variadic,
            'by_ref' => $this->byRef,
            'class' => $this->class,
        ];
    }
}
