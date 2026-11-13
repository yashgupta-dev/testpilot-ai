<?php

declare(strict_types=1);

namespace TestPilot\Ai;

/**
 * Picks the model per call so each step uses the cheapest model that does it well:
 *
 *   plan              → models.plan              (reasoning about intent; default model)
 *   generate          → models.generate          (writing the test file; default model)
 *   repair, attempt 1 → models.repair            (fixing imports/mocks/typos; a fast, cheap model)
 *   repair, attempt 2+→ models.repair_escalation (when the cheap fix did not work)
 *   validation        → models.validation, else models.plan (AI help for regex/custom rules and gap review)
 *   high-criticality targets → models.critical for plan, generate and validation, when configured
 *
 * Unset entries fall back to the provider's default model.
 */
final class ModelRouter
{
    /** @param array{plan?: ?string, generate?: ?string, validation?: ?string, repair?: ?string, repair_escalation?: ?string, critical?: ?string} $models */
    public function __construct(private readonly array $models = [])
    {
    }

    public function route(AiRequest $request): ?string
    {
        if ($request->model !== null) {
            return $request->model;
        }

        $critical = ($request->meta['criticality'] ?? null) === 'high' ? ($this->models['critical'] ?? null) : null;

        return match ($request->purpose) {
            'plan' => $critical ?? $this->models['plan'] ?? null,
            'generate' => $critical ?? $this->models['generate'] ?? null,
            'validation' => $critical ?? $this->models['validation'] ?? $this->models['plan'] ?? null,
            'repair' => (int) ($request->meta['attempt'] ?? 1) <= 1
                ? ($this->models['repair'] ?? null)
                : ($this->models['repair_escalation'] ?? $this->models['generate'] ?? null),
            default => null,
        };
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return $this->models;
    }
}
