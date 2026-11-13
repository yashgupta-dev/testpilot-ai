<?php

declare(strict_types=1);

namespace TestPilot\Generation;

final class Scenario
{
    public const TYPES = ['positive', 'negative', 'edge', 'fuzz'];
    public const MODULES = ['unit', 'fuzz', 'validation', 'ratelimit', 'feature'];
    public const BASES = ['confirmed', 'assumption'];
    public const PRIORITIES = ['high', 'medium', 'low'];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $module,
        public readonly string $description,
        public readonly string $inputs,
        public readonly string $expected,
        public readonly string $basis,
        public readonly string $basisSource,
        public readonly float $confidence,
        public readonly string $priority,
        public readonly bool $selected = true,
        /** @var array<string, mixed> Engine-specific data (e.g. validation field, rule and input). */
        public readonly array $meta = [],
    ) {
    }

    public function isAssumption(): bool
    {
        return $this->basis === 'assumption';
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $basis = (string) ($data['basis'] ?? 'assumption');
        $confidence = max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.5)));

        return new self(
            id: (string) $data['id'],
            name: trim((string) $data['name']),
            type: (string) $data['type'],
            module: (string) ($data['module'] ?? 'unit'),
            description: (string) ($data['description'] ?? ''),
            inputs: is_string($data['inputs'] ?? null) ? $data['inputs'] : (string) json_encode($data['inputs'] ?? ''),
            expected: is_string($data['expected'] ?? null) ? $data['expected'] : (string) json_encode($data['expected'] ?? ''),
            basis: $basis,
            basisSource: (string) ($data['basis_source'] ?? ''),
            confidence: $confidence,
            priority: (string) ($data['priority'] ?? 'medium'),
            // Weak assumptions start unselected so a human opts in to them.
            selected: (bool) ($data['selected'] ?? ! ($basis === 'assumption' && $confidence < 0.5)),
            meta: (array) ($data['meta'] ?? []),
        );
    }

    public function withSelected(bool $selected): self
    {
        return new self($this->id, $this->name, $this->type, $this->module, $this->description, $this->inputs, $this->expected, $this->basis, $this->basisSource, $this->confidence, $this->priority, $selected, $this->meta);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'module' => $this->module,
            'description' => $this->description,
            'inputs' => $this->inputs,
            'expected' => $this->expected,
            'basis' => $this->basis,
            'basis_source' => $this->basisSource,
            'confidence' => $this->confidence,
            'priority' => $this->priority,
            'selected' => $this->selected,
        ] + ($this->meta === [] ? [] : ['meta' => $this->meta]);
    }
}
