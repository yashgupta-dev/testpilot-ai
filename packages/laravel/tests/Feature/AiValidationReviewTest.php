<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

/**
 * The optional AI step of validation plans (testpilot.validation.ai): it may add checked values and rule-gap
 * findings, but the deterministic cases never depend on it and a failed call never blocks the plan.
 */
function aiReviewTarget(): Target
{
    return Target::query()->where('key_hash', hash('sha256', 'Shop\Http\Controllers\OrderController::store'))->firstOrFail();
}

function planValidationWithAi($test): array
{
    $target = aiReviewTarget();
    $test->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'validation'])->assertStatus(202);

    return $test->getJson("/testpilot/api/targets/{$target->id}?module=validation")->assertOk()->json('plan');
}

it('adds the AI rule review to a validation plan without changing the rule-based cases', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    $deterministic = count(planValidationWithAi($this)['scenarios']);

    config(['testpilot.validation.ai' => true]);
    $this->ai->push(json_encode([
        'schema_version' => '1',
        'values' => [],
        'gaps' => [['field' => 'email', 'severity' => 'medium', 'issue' => 'Stored without a uniqueness check', 'suggestion' => 'unique:orders,email', 'basis_source' => 'store()']],
        'notes' => '',
    ]));

    $plan = planValidationWithAi($this);

    expect($this->ai->requests)->toHaveCount(1)
        ->and($this->ai->requests[0]->purpose)->toBe('validation')
        ->and($plan['context']['ai']['status'])->toBe('used')
        ->and($plan['context']['ai']['gaps'][0])->toMatchArray(['field' => 'email', 'severity' => 'medium'])
        ->and($plan['notes'])->toContain('AI review flagged 1 possible rule gap(s)')
        ->and($plan['model'])->toBe('fake-model')
        ->and($plan['scenarios'])->toHaveCount($deterministic)
        ->and(collect($plan['scenarios'])->every(fn ($s) => $s['basis'] === 'confirmed' || ($s['meta']['todo'] ?? null) !== null))->toBeTrue();
});

it('adds diverse numeric AI cases only after TestPilot independently verifies them', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    config(['testpilot.validation.ai' => false, 'testpilot.validation.ai_additional_cases' => 3]);
    $deterministic = planValidationWithAi($this)['scenarios'];

    config(['testpilot.validation.ai' => true]);
    $this->ai->push(json_encode([
        'schema_version' => '1',
        'values' => [['question' => 'Q1', 'valid' => 'ABC-123', 'invalid' => 'abc-123', 'reason' => 'case-sensitive pattern']],
        'cases' => [
            ['field' => 'quantity', 'action' => 'set', 'value' => '5', 'reason' => 'numeric string'],
            ['field' => 'quantity', 'action' => 'set', 'value' => null, 'reason' => 'null input'],
            ['field' => 'items.0.price', 'action' => 'set', 'value' => 500.5, 'reason' => 'valid decimal'],
        ],
        'gaps' => [],
        'notes' => '',
    ]));

    $plan = planValidationWithAi($this);
    $aiCases = collect($plan['scenarios'])->filter(fn (array $scenario): bool => ($scenario['meta']['rule'] ?? null) === 'AI-proposed numeric input');

    expect($plan['context']['ai']['status'])->toBe('used')
        ->and($plan['context']['ai']['cases'])->toHaveCount(3)
        ->and($aiCases)->toHaveCount(3)
        ->and($aiCases->every(fn (array $scenario): bool => $scenario['selected'] && $scenario['basis'] === 'confirmed'))->toBeTrue()
        ->and($aiCases->pluck('meta.expect')->all())->toBe(['no_error', 'error', 'no_error'])
        ->and($plan['notes'])->toContain('additional AI-proposed case(s) independently checked')
        ->and($deterministic)->not->toBeEmpty();
});

it('falls back to the rule-based plan with a warning when the AI call fails', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    config(['testpilot.validation.ai' => true]);
    // No queued response: the fake provider throws, like a provider outage or an exhausted budget.

    $plan = planValidationWithAi($this);

    expect($plan['status'])->toBe('proposed')
        ->and($plan['context']['ai']['status'])->toBe('failed')
        ->and(collect($plan['context']['warnings'])->implode(' '))->toContain('AI review skipped')
        ->and($plan['scenarios'])->not->toBeEmpty();
});

it('does not call the AI when the policy is off', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    config(['testpilot.validation.ai' => true, 'testpilot.ai.policy' => 'off']);

    $plan = planValidationWithAi($this);

    expect($this->ai->requests)->toBe([])
        ->and($plan['context']['ai'])->toMatchArray(['status' => 'off', 'reason' => 'AI policy is off']);
});
