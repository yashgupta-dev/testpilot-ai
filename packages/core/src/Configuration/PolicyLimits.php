<?php

declare(strict_types=1);

namespace TestPilot\Configuration;

/**
 * Global limits that annotations and AI output can never exceed.
 */
final class PolicyLimits
{
    /**
     * @param list<string> $blockedEnvironments Environments where TestPilot refuses to execute tests.
     */
    public function __construct(
        public readonly int $maxFuzzCases = 200,
        public readonly int $maxScenarios = 20,
        public readonly int $maxRateLimitAttempts = 500,
        public readonly int $maxRepairAttempts = 3,
        public readonly int $processTimeoutSeconds = 300,
        public readonly bool $aiAllowed = true,
        public readonly array $blockedEnvironments = ['production', 'prod'],
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        return new self(
            maxFuzzCases: (int) ($config['max_fuzz_cases'] ?? 200),
            maxScenarios: (int) ($config['max_scenarios'] ?? 20),
            maxRateLimitAttempts: (int) ($config['max_rate_limit_attempts'] ?? 500),
            maxRepairAttempts: (int) ($config['max_repair_attempts'] ?? 3),
            processTimeoutSeconds: (int) ($config['process_timeout'] ?? 300),
            aiAllowed: (bool) ($config['ai_allowed'] ?? true),
            blockedEnvironments: array_values((array) ($config['blocked_environments'] ?? ['production', 'prod'])),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'max_fuzz_cases' => $this->maxFuzzCases,
            'max_scenarios' => $this->maxScenarios,
            'max_rate_limit_attempts' => $this->maxRateLimitAttempts,
            'max_repair_attempts' => $this->maxRepairAttempts,
            'process_timeout' => $this->processTimeoutSeconds,
            'ai_allowed' => $this->aiAllowed,
            'blocked_environments' => $this->blockedEnvironments,
        ];
    }
}
