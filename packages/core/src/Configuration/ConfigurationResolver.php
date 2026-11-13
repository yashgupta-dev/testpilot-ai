<?php

declare(strict_types=1);

namespace TestPilot\Configuration;

use TestPilot\DTO\RawAnnotation;
use TestPilot\DTO\TestTarget;

/**
 * Resolves the effective configuration of a target.
 *
 * Precedence (lowest → highest): project defaults → class annotations → method annotations → policy limits.
 *
 *  - Module enables are additive: class #[UnitTest] + method #[Fuzz] enables both.
 *  - An explicit disable (#[UnitTest(enabled: false)]) or #[SkipTest] at a closer scope wins.
 *  - #[SkipTest] on a class can be overridden by #[TestPilot(enabled: true)] on a method.
 *  - Policy limits clamp the final values and are recorded in the trace; nothing can exceed them.
 */
final class ConfigurationResolver
{
    /**
     * @param array<string, bool>                 $defaultModules
     * @param array<string, array<string, mixed>> $defaultOptions
     */
    public function __construct(
        private readonly PolicyLimits $limits = new PolicyLimits(),
        private readonly array $defaultModules = [],
        private readonly array $defaultOptions = [],
    ) {
    }

    /** @param array<string, mixed> $config Typically config('testpilot.defaults'). */
    public static function fromArray(array $config, PolicyLimits $limits): self
    {
        $modules = [];
        foreach ((array) ($config['modules'] ?? []) as $module => $enabled) {
            $modules[(string) $module] = (bool) $enabled;
        }

        return new self($limits, $modules, (array) ($config['options'] ?? []));
    }

    public function resolve(TestTarget $target): ResolvedConfig
    {
        $trace = [];
        $enabled = true;
        $skipReason = null;
        $area = null;
        $criticality = null;

        $modules = [
            Module::Unit->value => false,
            Module::Fuzz->value => false,
            Module::Validation->value => false,
            Module::RateLimit->value => false,
            Module::Feature->value => false,
            Module::Ai->value => true,
        ];
        $options = [
            Module::Unit->value => ['max_scenarios' => 8, 'focus' => []],
            Module::Fuzz->value => ['cases' => 50, 'seed' => null],
            Module::Validation->value => ['error_status' => null, 'ai' => null],
            Module::RateLimit->value => ['max_attempts' => null, 'decay_seconds' => null, 'lockout' => false],
            Module::Feature->value => ['max_scenarios' => 8, 'fakes' => [], 'focus' => []],
            Module::Ai->value => [],
        ];

        foreach ($this->defaultModules as $module => $value) {
            if (array_key_exists($module, $modules)) {
                $modules[$module] = $value;
                $trace[] = ['key' => $module, 'value' => $value, 'source' => 'project config'];
            }
        }
        foreach ($this->defaultOptions as $module => $values) {
            foreach ((array) $values as $name => $value) {
                $options[$module][$name] = $value;
                $trace[] = ['key' => "{$module}.{$name}", 'value' => $value, 'source' => 'project config'];
            }
        }

        $hasExplicitModule = false;

        foreach ([$target->classAnnotations, $target->annotations] as $scopeAnnotations) {
            /** @var list<RawAnnotation> $scopeAnnotations */
            foreach ($scopeAnnotations as $annotation) {
                $source = $annotation->describe();

                if ($annotation->name === 'SkipTest') {
                    $enabled = false;
                    $skipReason = (string) ($annotation->argument('reason') ?? 'Skipped by #[SkipTest]');
                    $trace[] = ['key' => 'enabled', 'value' => false, 'source' => $source];
                    continue;
                }

                if ($annotation->name === 'TestPilot') {
                    $flag = (bool) $annotation->argument('enabled', true);
                    $enabled = $flag;
                    if ($flag) {
                        $skipReason = null;
                    }
                    $trace[] = ['key' => 'enabled', 'value' => $flag, 'source' => $source];
                    $area = $annotation->argument('area', $area);
                    $criticality = $annotation->argument('criticality', $criticality);
                    continue;
                }

                $module = Module::fromAttribute($annotation->name);
                if ($module === null) {
                    continue;
                }

                $flag = (bool) $annotation->argument('enabled', true);
                $modules[$module->value] = $flag;
                if ($module !== Module::Ai) {
                    // Any module attribute (even a disabled one) means the developer chose modules explicitly.
                    $hasExplicitModule = true;
                }
                $trace[] = ['key' => $module->value, 'value' => $flag, 'source' => $source];

                foreach ($this->optionMap($module) as $argument => $option) {
                    $value = $annotation->argument($argument);
                    if ($value !== null) {
                        $options[$module->value][$option] = $value;
                        $trace[] = ['key' => "{$module->value}.{$option}", 'value' => $value, 'source' => $source];
                    }
                }
            }
        }

        // A bare #[TestPilot] with no module attributes means "unit tests with project defaults".
        $anyTestModule = $modules[Module::Unit->value] || $modules[Module::Fuzz->value]
            || $modules[Module::Validation->value] || $modules[Module::RateLimit->value] || $modules[Module::Feature->value];
        if (! $hasExplicitModule && ! $anyTestModule) {
            $modules[Module::Unit->value] = true;
            $trace[] = ['key' => Module::Unit->value, 'value' => true, 'source' => 'default for #[TestPilot]'];
        }

        // Policy limits always win.
        $this->clamp($options, Module::Fuzz, 'cases', $this->limits->maxFuzzCases, $trace);
        $this->clamp($options, Module::Unit, 'max_scenarios', $this->limits->maxScenarios, $trace);
        $this->clamp($options, Module::Feature, 'max_scenarios', $this->limits->maxScenarios, $trace);
        $this->clamp($options, Module::RateLimit, 'max_attempts', $this->limits->maxRateLimitAttempts, $trace);

        if (! $this->limits->aiAllowed && $modules[Module::Ai->value]) {
            $modules[Module::Ai->value] = false;
            $trace[] = ['key' => Module::Ai->value, 'value' => false, 'source' => 'policy: AI disabled'];
        }

        return new ResolvedConfig(
            enabled: $enabled,
            modules: $modules,
            options: $options,
            trace: $trace,
            area: is_string($area) ? $area : null,
            criticality: is_string($criticality) ? $criticality : null,
            skipReason: $enabled ? null : $skipReason,
        );
    }

    /** @return array<string, string> attribute argument => option name */
    private function optionMap(Module $module): array
    {
        return match ($module) {
            Module::Unit => ['maxScenarios' => 'max_scenarios', 'focus' => 'focus'],
            Module::Fuzz => ['cases' => 'cases', 'seed' => 'seed'],
            Module::RateLimit => ['maxAttempts' => 'max_attempts', 'decaySeconds' => 'decay_seconds', 'lockout' => 'lockout'],
            Module::Validation => ['errorStatus' => 'error_status', 'ai' => 'ai'],
            Module::Feature => ['maxScenarios' => 'max_scenarios', 'fakes' => 'fakes', 'focus' => 'focus'],
            default => [],
        };
    }

    /**
     * @param array<string, array<string, mixed>>                     $options
     * @param list<array{key: string, value: mixed, source: string}>  $trace
     */
    private function clamp(array &$options, Module $module, string $name, int $max, array &$trace): void
    {
        $value = $options[$module->value][$name] ?? null;

        if (is_int($value) && $value > $max) {
            $options[$module->value][$name] = $max;
            $trace[] = ['key' => "{$module->value}.{$name}", 'value' => $max, 'source' => "policy: max {$max}"];
        }

        if (is_int($value) && $value < 1) {
            $options[$module->value][$name] = 1;
            $trace[] = ['key' => "{$module->value}.{$name}", 'value' => 1, 'source' => 'policy: min 1'];
        }
    }
}
