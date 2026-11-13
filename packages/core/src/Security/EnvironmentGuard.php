<?php

declare(strict_types=1);

namespace TestPilot\Security;

use TestPilot\Exceptions\PolicyViolationException;

/**
 * Refuses to run tests in production-like environments.
 */
final class EnvironmentGuard
{
    /**
     * @param list<string> $blockedEnvironments
     * @param list<string> $blockedDatabaseHosts Hosts that must never be used by generated tests.
     */
    public function __construct(
        private readonly string $environment,
        private readonly array $blockedEnvironments = ['production', 'prod'],
        private readonly ?string $databaseHost = null,
        private readonly array $blockedDatabaseHosts = [],
    ) {
    }

    public function assertTestingAllowed(): void
    {
        if (in_array(strtolower($this->environment), array_map('strtolower', $this->blockedEnvironments), true)) {
            throw new PolicyViolationException(
                "TestPilot will not execute tests when the application environment is \"{$this->environment}\".",
            );
        }

        if ($this->databaseHost !== null && in_array(strtolower($this->databaseHost), array_map('strtolower', $this->blockedDatabaseHosts), true)) {
            throw new PolicyViolationException("The database host \"{$this->databaseHost}\" is on the production block list.");
        }
    }

    public function isAllowed(): bool
    {
        try {
            $this->assertTestingAllowed();

            return true;
        } catch (PolicyViolationException) {
            return false;
        }
    }

    public function environment(): string
    {
        return $this->environment;
    }
}
