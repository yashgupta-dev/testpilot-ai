<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

/**
 * "Fat controller" validation: no FormRequest, rules written inside the action. Each case runs the
 * generated Pest file against the real route in a child process.
 */
function inlineTarget(string $method): Target
{
    return Target::query()->where('key_hash', hash('sha256', "Shop\\Http\\Controllers\\InlineController::{$method}"))->firstOrFail();
}

function inlinePlan($test, string $method): array
{
    $target = inlineTarget($method);
    $test->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'validation'])->assertStatus(202);

    return $test->getJson("/testpilot/api/targets/{$target->id}?module=validation")->assertOk()->json('plan');
}

function inlineGenerate($test, string $method): GeneratedTest
{
    $plan = inlinePlan($test, $method);
    $target = inlineTarget($method);
    $selected = collect($plan['scenarios'])->where('selected', true)->pluck('id')->all();
    $test->postJson("/testpilot/api/targets/{$target->id}/plans/{$plan['id']}/approve", ['selected' => $selected])->assertSuccessful();

    return GeneratedTest::query()->where('target_id', $target->id)->where('module', 'validation')->latest()->firstOrFail();
}

it('reads $request->validate() rules from the controller action', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $plan = inlinePlan($this, 'store');

    expect($plan['context'])->toMatchArray([
        'origin' => 'inline',
        'request_class' => null,
        'rules_origin' => 'InlineController::store() → $request->validate()',
        'error_mode' => 'laravel',
        'error_status' => 422,
    ])
        ->and(collect($plan['context']['fields'])->pluck('field')->all())->toBe(['email', 'quantity', 'note'])
        ->and(collect($plan['scenarios'])->pluck('name'))->toContain('quantity · max:20 — rejects 21 (max 20)')
        ->and($plan['scenarios'][1]['basis_source'])->toStartWith('InlineController::store() → $request->validate() line');
});

it('verifies inline $request->validate() against the real route', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = inlineGenerate($this, 'store');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->code)->toContain('->assertJsonValidationErrors([\'quantity\'])')
        ->and($test->verification['run']['counts']['failed'])->toBe(0);
});

it('follows a rules variable passed to $this->validate()', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = inlineGenerate($this, 'post');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->plan->context['rules_origin'])->toBe('InlineController::post() → $this->validate()');
});

it('checks the status code of a custom JSON error response', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = inlineGenerate($this, 'legacy');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->plan->context)->toMatchArray(['error_mode' => 'status', 'error_status' => 400])
        ->and($test->code)->toContain('->assertStatus(400);')
        ->and($test->code)->not->toContain('assertJsonValidationErrors');
});

it('checks session errors when failures redirect back with ->withErrors()', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = inlineGenerate($this, 'form');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->plan->context)->toMatchArray(['error_mode' => 'redirect_errors', 'error_status' => 302])
        ->and($test->code)->toContain("->assertSessionHasErrors(['age'])")
        ->and($test->code)->toContain("->assertSessionDoesntHaveErrors(['age'])");
});

it('asks for #[Validation(errorStatus: …)] when the failure response cannot be read', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $target = inlineTarget('unclear');
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'validation'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'not how failures are answered') && str_contains($m, '#[Validation(errorStatus: 400)]'));
});

it('uses the declared error status when the attribute provides it', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = inlineGenerate($this, 'declared');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->plan->context)->toMatchArray(['error_mode' => 'status', 'error_status' => 400]);
});
