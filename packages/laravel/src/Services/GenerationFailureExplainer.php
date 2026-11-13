<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use TestPilot\Exceptions\AiProviderException;
use TestPilot\Exceptions\AiQuotaExceededException;
use TestPilot\Exceptions\AiDisabledException;
use TestPilot\Exceptions\BudgetExceededException;
use Throwable;

final class GenerationFailureExplainer
{
    /** @return array{code: string, title: string, summary: string, next_step: string}|null */
    public static function explain(Throwable $error): ?array
    {
        if ($error instanceof AiQuotaExceededException) {
            return [
                'code' => 'ai_quota_exceeded',
                'title' => 'AI provider quota reached',
                'summary' => 'TestPilot could not generate a test. No test was executed, so this is not an application test failure.',
                'next_step' => 'Wait until the provider quota resets or switch provider/model. Then create a fresh plan, approve it, and retry generation.',
            ];
        }

        if ($error instanceof AiProviderException) {
            return [
                'code' => 'ai_provider_unavailable',
                'title' => 'AI provider unavailable',
                'summary' => 'TestPilot could not generate a test. No test was executed, so application behavior was not checked.',
                'next_step' => 'Check the provider status and API credentials, then retry generation.',
            ];
        }

        if ($error instanceof BudgetExceededException) {
            return [
                'code' => 'ai_budget_exceeded',
                'title' => 'AI budget limit reached',
                'summary' => 'TestPilot stopped before generating a test because the configured AI budget was reached.',
                'next_step' => 'Review the project AI budget settings before retrying.',
            ];
        }

        if ($error instanceof AiDisabledException) {
            return [
                'code' => 'ai_disabled',
                'title' => 'AI generation is disabled',
                'summary' => 'TestPilot did not generate or execute a test.',
                'next_step' => 'Enable an allowed AI provider or use a deterministic module such as validation or rate-limit testing.',
            ];
        }

        return null;
    }
}
