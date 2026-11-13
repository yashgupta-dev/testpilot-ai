<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

/**
 * End-to-end: a real FormRequest and route (sandbox helper: tests/Pest.php). The generated Pest file runs in a child process that boots
 * Laravel (via Testbench) and sends real JSON requests to the route.
 */
function orderTarget(): Target
{
    return Target::query()->where('key_hash', hash('sha256', 'Shop\Http\Controllers\OrderController::store'))->firstOrFail();
}

function planValidation($test): array
{
    $target = orderTarget();
    $test->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'validation'])->assertStatus(202);

    return $test->getJson("/testpilot/api/targets/{$target->id}?module=validation")->assertOk()->json();
}

it('plans validation cases from FormRequest rules without calling the AI', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $detail = planValidation($this);
    $plan = $detail['plan'];

    expect($detail['plannable_modules'])->toBe(['validation'])
        ->and($plan['module'])->toBe('validation')
        ->and($plan['status'])->toBe('proposed')
        ->and($plan['context']['request_class'])->toBe('Shop\Http\Requests\StoreOrderRequest')
        ->and($plan['context']['method'])->toBe('POST')
        ->and($plan['context']['uri'])->toBe('/api/orders')
        ->and($plan['context']['complete'])->toBeTrue()
        ->and(collect($plan['scenarios'])->pluck('name'))->toContain('email · max:100 — rejects 101 characters (max 100)', 'Accepts a fully valid payload')
        ->and(collect($plan['scenarios'])->every(fn ($s) => $s['basis'] === 'confirmed'))->toBeTrue()
        ->and($this->ai->requests)->toBe([]);
});

it('generates validation tests and runs them against the real route', function () {
    $root = validationSandbox();
    app(ScanService::class)->scan();
    $plan = planValidation($this)['plan'];
    $selected = collect($plan['scenarios'])->where('selected', true)->pluck('id')->all();

    $this->postJson('/testpilot/api/targets/' . orderTarget()->id . "/plans/{$plan['id']}/approve", ['selected' => $selected])->assertStatus(202);

    $test = GeneratedTest::query()->where('module', 'validation')->firstOrFail();
    $verification = $test->verification;

    expect($test->status)->toBe(GeneratedTest::VERIFIED)
        ->and($test->pending_path)->toBe('storage/testpilot/pending/Validation/OrderControllerStoreTest.php')
        ->and($verification['run']['counts']['passed'])->toBe(count($selected))
        ->and(collect($verification['scenario_results'])->unique()->values()->all())->toBe(['passed'])
        ->and($this->ai->requests)->toBe([]);

    // Approval places it next to unit tests, in its own folder, and it runs with the suite.
    $this->postJson("/testpilot/api/tests/{$test->id}/approve")->assertOk();
    expect(is_file("{$root}/tests/TestPilot/Generated/Validation/OrderControllerStoreTest.php"))->toBeTrue();

    $run = $this->postJson('/testpilot/api/runs')->assertStatus(202)->json();
    expect(Run::query()->find($run['id'])->status)->toBe(Run::COMPLETED);
});

it('omits validation cases already covered by an approved test', function () {
    validationSandbox();
    config(['testpilot.validation.additional_cases' => 2, 'testpilot.validation.variation_seed' => 17]);
    app(ScanService::class)->scan();
    $target = orderTarget();
    $firstPlan = planValidation($this)['plan'];
    $firstSelected = collect($firstPlan['scenarios'])->where('selected', true)->values();

    $this->postJson("/testpilot/api/targets/{$target->id}/plans/{$firstPlan['id']}/approve", [
        'selected' => $firstSelected->pluck('id')->all(),
    ])->assertStatus(202);

    $test = GeneratedTest::query()->where('target_id', $target->id)->where('module', 'validation')->firstOrFail();
    $this->postJson("/testpilot/api/tests/{$test->id}/approve")->assertOk();

    config(['testpilot.validation.variation_seed' => 18]);
    $secondPlan = planValidation($this)['plan'];
    $secondSelected = collect($secondPlan['scenarios'])->where('selected', true)->values();
    $identity = static fn (array $scenario): string => json_encode([
        $scenario['meta']['field'] ?? null,
        $scenario['meta']['target_field'] ?? null,
        $scenario['meta']['action'] ?? null,
        $scenario['meta']['value'] ?? null,
        $scenario['meta']['expect'] ?? null,
        $scenario['meta']['also_set'] ?? [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $approvedIdentities = $firstSelected->map($identity)->all();

    expect($secondPlan['context']['approved_duplicates_omitted'])->toBeGreaterThan(0)
        ->and($secondSelected)->not->toBeEmpty()
        ->and($secondSelected->map($identity)->intersect($approvedIdentities))->toBeEmpty()
        ->and($secondPlan['notes'])->toContain('duplicate case(s) omitted');
});

it('reports a suspected bug when the endpoint does not enforce a declared rule', function () {
    validationSandbox();
    config(['testpilot.pest.env' => ['TP_LENIENT_RULES' => '1']]); // the child app ignores email max:255
    app(ScanService::class)->scan();
    $plan = planValidation($this)['plan'];
    $selected = collect($plan['scenarios'])->where('selected', true)->pluck('id')->all();

    $this->postJson('/testpilot/api/targets/' . orderTarget()->id . "/plans/{$plan['id']}/approve", ['selected' => $selected]);

    $test = GeneratedTest::query()->where('module', 'validation')->firstOrFail();
    $maxCase = collect($plan['scenarios'])->firstWhere('name', 'email · max:100 — rejects 101 characters (max 100)')['id'];

    expect($test->status)->toBe(GeneratedTest::SUSPECTED_BUG)
        ->and(array_keys($test->verification['suspected_bugs']))->toBe([$maxCase])
        ->and($test->verification['suspected_bugs'][$maxCase])->toContain('Declared in the source but not enforced')
        ->and($test->verification['scenario_results'][$maxCase])->toBe('failed');
});

it('keeps unit and validation plans separate for the same target', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    planValidation($this);

    expect(Plan::query()->where('module', 'validation')->count())->toBe(1)
        ->and(Plan::query()->where('module', 'unit')->count())->toBe(0);

    $this->postJson('/testpilot/api/targets/' . orderTarget()->id . '/plan', ['module' => 'unit'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'has no unit module'));
});

it('explains why a target without a route cannot get validation tests', function () {
    app(ScanService::class)->scan(); // default sandbox: AuthController::login has #[Validation] but no route
    $login = Target::query()->where('key_hash', hash('sha256', 'App\Http\Controllers\AuthController::login'))->firstOrFail();

    $this->postJson("/testpilot/api/targets/{$login->id}/plan", ['module' => 'validation'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'No route calls AuthController::login'));
});

it('rejects plain-English additions on validation plans', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    $plan = planValidation($this)['plan'];

    $this->postJson('/testpilot/api/targets/' . orderTarget()->id . "/plans/{$plan['id']}/approve", ['selected' => ['S2'], 'additions' => ['email must be unique']])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'only supported for AI-planned unit tests'));
});
