<?php

declare(strict_types=1);

namespace TestPilot\Ai;

/**
 * Receives one record per AI call (for cost tracking and the audit trail).
 * Implementations may throw BudgetExceededException from assertWithinBudget().
 */
interface UsageRecorder
{
    /** @throws \TestPilot\Exceptions\BudgetExceededException */
    public function assertWithinBudget(string $purpose, ?string $targetKey = null): void;

    public function record(AiRequest $request, ?AiResponse $response, string $provider, string $model, ?string $targetKey, ?string $error): void;
}
