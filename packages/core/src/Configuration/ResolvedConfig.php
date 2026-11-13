<?php

declare(strict_types=1);

namespace TestPilot\Configuration;

/**
 * The final, policy-clamped configuration for one target, plus a trace of where each value came from.
 */
final class ResolvedConfig
{
    /**
     * @param array<string, bool>                  $modules Keyed by Module value.
     * @param array<string, array<string, mixed>>  $options Keyed by Module value, then option name.
     * @param list<array{key: string, value: mixed, source: string}> $trace
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly array $modules,
        public readonly array $options,
        public readonly array $trace,
        public readonly ?string $area = null,
        public readonly ?string $criticality = null,
        public readonly ?string $skipReason = null,
    ) {
    }

    public function isEnabled(Module $module): bool
    {
        return $this->enabled && ($this->modules[$module->value] ?? false);
    }

    public function option(Module $module, string $name, mixed $default = null): mixed
    {
        return $this->options[$module->value][$name] ?? $default;
    }

    /** @return list<Module> Testing modules that will run (AI is a capability, not a test module). */
    public function activeModules(): array
    {
        return array_values(array_filter(
            [Module::Unit, Module::Fuzz, Module::Validation, Module::RateLimit, Module::Feature],
            fn (Module $m): bool => $this->isEnabled($m),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'modules' => $this->modules,
            'options' => $this->options,
            'trace' => $this->trace,
            'area' => $this->area,
            'criticality' => $this->criticality,
            'skip_reason' => $this->skipReason,
        ];
    }
}
