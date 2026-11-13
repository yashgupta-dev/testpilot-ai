<?php

declare(strict_types=1);

namespace TestPilot\Validation;

final class ValidationRule
{
    /** A plain Laravel rule string or a Rule:: helper TestPilot understands. */
    public const KNOWN = 'known';
    /** A rule object or closure TestPilot cannot reason about. */
    public const CUSTOM = 'custom';
    /** A value only known at runtime (variables, method calls, conditionals). */
    public const DYNAMIC = 'dynamic';

    /** @param list<string> $params */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public readonly string $source,
        public readonly string $kind = self::KNOWN,
        public readonly ?string $note = null,
    ) {
    }

    public static function parse(string $rule): self
    {
        $rule = trim($rule);
        [$name, $rest] = str_contains($rule, ':') ? explode(':', $rule, 2) : [$rule, ''];
        $name = strtolower(trim($name));

        $params = match (true) {
            $rest === '' => [],
            in_array($name, ['regex', 'not_regex', 'date_format'], true) => [$rest],
            default => array_map('trim', explode(',', $rest)),
        };

        return new self($name, $params, $rule);
    }

    public function param(int $index, ?string $default = null): ?string
    {
        return $this->params[$index] ?? $default;
    }

    public function label(): string
    {
        return $this->note ?? $this->source;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'params' => $this->params, 'source' => $this->source, 'kind' => $this->kind, 'note' => $this->note];
    }
}
