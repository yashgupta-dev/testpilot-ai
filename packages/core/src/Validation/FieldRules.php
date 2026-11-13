<?php

declare(strict_types=1);

namespace TestPilot\Validation;

final class FieldRules
{
    /** @param list<ValidationRule> $rules */
    public function __construct(
        public readonly string $field,
        public readonly array $rules,
        public readonly int $line,
    ) {
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function get(string $name): ?ValidationRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->name === $name) {
                return $rule;
            }
        }

        return null;
    }

    /** The data type Laravel uses for size rules (min/max/size/between). */
    public function type(): string
    {
        return match (true) {
            $this->has('integer') => 'integer',
            $this->has('numeric') || $this->has('decimal') => 'numeric',
            $this->has('boolean') || $this->has('accepted') || $this->has('declined') => 'boolean',
            $this->has('array') || $this->has('list') => 'array',
            $this->has('file') || $this->has('image') || $this->has('mimes') || $this->has('mimetypes') => 'file',
            $this->has('date') || $this->has('date_format') || $this->has('after') || $this->has('before') => 'date',
            default => 'string',
        };
    }

    /** Field path used in request bodies and error bags: "items.*.price" → "items.0.price". */
    public function path(): string
    {
        return str_replace('*', '0', $this->field);
    }

    public function rulesText(): string
    {
        return implode('|', array_map(static fn (ValidationRule $r): string => $r->label(), $this->rules));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'line' => $this->line,
            'type' => $this->type(),
            'rules' => array_map(static fn (ValidationRule $r): array => $r->toArray(), $this->rules),
        ];
    }
}
