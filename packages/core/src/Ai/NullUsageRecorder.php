<?php

declare(strict_types=1);

namespace TestPilot\Ai;

final class NullUsageRecorder implements UsageRecorder
{
    public function assertWithinBudget(string $purpose, ?string $targetKey = null): void
    {
    }

    public function record(AiRequest $request, ?AiResponse $response, string $provider, string $model, ?string $targetKey, ?string $error): void
    {
    }
}
