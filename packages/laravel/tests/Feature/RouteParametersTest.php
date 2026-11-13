<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

/**
 * Routes with {parameters}: the generated tests create the bound records with factories and build
 * the URL from them. Missing records would make Laravel answer 404 before validation or throttling,
 * so a passing run proves the bindings were filled correctly.
 */
function manageTarget(string $method): Target
{
    return Target::query()->where('key_hash', hash('sha256', "Shop\\Http\\Controllers\\ManageOrderController::{$method}"))->firstOrFail();
}

function planAndGenerate($test, string $method, string $module): GeneratedTest
{
    $target = manageTarget($method);
    $test->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => $module])->assertStatus(202);
    $plan = $test->getJson("/testpilot/api/targets/{$target->id}?module={$module}")->assertOk()->json('plan');
    $selected = collect($plan['scenarios'])->where('selected', true)->pluck('id')->all();
    $test->postJson("/testpilot/api/targets/{$target->id}/plans/{$plan['id']}/approve", ['selected' => $selected])->assertSuccessful();

    return GeneratedTest::query()->where('target_id', $target->id)->where('module', $module)->latest()->firstOrFail();
}

it('plans a model-bound route with a factory-created record', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $target = manageTarget('update');
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'validation'])->assertStatus(202);
    $context = $this->getJson("/testpilot/api/targets/{$target->id}?module=validation")->json('plan.context');

    expect($context['uri'])->toBe('/api/orders/{order}')
        ->and($context['bindings']['parameters'])->toBe([['name' => 'order', 'kind' => 'model', 'class' => 'Shop\Models\Order', 'field' => null, 'parent' => null]])
        ->and($context['bindings']['description'])->toBe(['{order} → Order::factory()->create()']);
});

it('runs validation tests against a model-bound PUT route', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = planAndGenerate($this, 'update', 'validation');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->code)->toContain('$order = \Shop\Models\Order::factory()->create();')
        ->and($test->code)->toContain("\$this->testpilotUri = '/api/orders/' . \$order->getRouteKey();")
        ->and($test->code)->toContain('uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);')
        ->and($test->code)->toContain('$this->putJson($this->testpilotUri, $payload)')
        ->and($test->verification['run']['counts']['failed'])->toBe(0);
});

it('creates scoped children with ->for() and uses the custom route key', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = planAndGenerate($this, 'updateForCustomer', 'validation');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->code)->toContain('$order = \Shop\Models\Order::factory()->for($customer)->create();')
        ->and($test->code)->toContain("'/api/customers/' . \$customer->getRouteKey() . '/orders/' . \$order->reference");
});

it('fills a plain {id} with a placeholder', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = planAndGenerate($this, 'updateLegacy', 'validation');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->code)->toContain("\$this->testpilotUri = '/api/legacy-orders/' . '1';")
        ->and($test->code)->not->toContain('RefreshDatabase');
});

it('runs rate-limit tests against a model-bound route', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $test = planAndGenerate($this, 'update', 'ratelimit');

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->code)->toContain('->putJson($test->testpilotUri)')
        ->and($test->verification['run']['counts'])->toMatchArray(['passed' => 6, 'failed' => 0]);
});

it('explains what to add when a bound model has no factory', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $target = manageTarget('destroyCoupon');
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'ratelimit'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, '{coupon} is bound to Coupon, which has no factory') && str_contains($m, 'HasFactory'));
});

it('reports a 404 as a setup problem, not as a bug in the application', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    // Simulate a wrong binding: the order is no longer created for the customer in the URL.
    $target = manageTarget('updateForCustomer');
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'validation'])->assertStatus(202);
    $plan = $target->plans()->where('module', 'validation')->latest()->firstOrFail();
    $context = $plan->context;
    $context['bindings']['parameters'][1]['parent'] = null;
    $plan->update(['context' => $context]);

    $this->postJson("/testpilot/api/targets/{$target->id}/plans/{$plan->id}/approve", ['selected' => collect($plan->scenarios)->where('selected', true)->pluck('id')->all()])->assertSuccessful();
    $test = GeneratedTest::query()->where('target_id', $target->id)->latest()->firstOrFail();

    expect($test->status)->toBe(GeneratedTest::FAILED)
        ->and($test->verification['suspected_bugs'] ?? [])->toBe([])
        ->and(collect($test->verification['stages'])->firstWhere('stage', 'setup')['summary'])->toContain('404 Not Found');
});
