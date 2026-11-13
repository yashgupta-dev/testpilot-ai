<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\AiRequest;
use TestPilot\Laravel\Models\AuditLog;
use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

function scanSandbox(): array
{
    return app(ScanService::class)->scan();
}

function divideTarget(): Target
{
    return Target::query()->where('key_hash', hash('sha256', 'Demo\Calculator::divide'))->firstOrFail();
}

it('syncs annotated targets into the database with risk and status', function () {
    $summary = scanSandbox();

    expect($summary['added'])->toBe($summary['targets'])
        ->and($summary['targets'])->toBeGreaterThan(5)
        ->and(Target::query()->where('status', Target::SKIPPED)->pluck('method')->all())->toContain('legacyTotal');

    $invoice = Target::query()->where('key_hash', hash('sha256', 'App\Services\InvoiceService::calculateTotal'))->first();
    expect($invoice->risk_score)->toBeGreaterThan(50)
        ->and($invoice->area)->toBe('Billing')
        ->and($invoice->modules)->toBe(['unit', 'fuzz'])
        ->and(collect($invoice->risk_reasons)->pluck('label')->all())->toContain('Handles money');
});

it('runs the full plan → approve → generate → verify → approve → run workflow over the API', function () {
    scanSandbox();
    $target = divideTarget();
    $this->ai->push(calculatorPlanJson())->push(calculatorTestCode());

    // 1. AI proposes a plan (queue is sync in tests).
    $this->postJson("/testpilot/api/targets/{$target->id}/plan")->assertStatus(202);
    $detail = $this->getJson("/testpilot/api/targets/{$target->id}")->assertOk()->json();

    expect($detail['status'])->toBe(Target::PLAN_READY)
        ->and($detail['plan']['status'])->toBe('proposed')
        ->and(collect($detail['plan']['scenarios'])->firstWhere('id', 'S3')['selected'])->toBeFalse();

    // 2. Human approves S1 + S2; generation and verification run.
    $this->postJson("/testpilot/api/targets/{$target->id}/plans/{$detail['plan']['id']}/approve", ['selected' => ['S1', 'S2']])->assertStatus(202);

    $test = GeneratedTest::query()->firstOrFail();
    expect($test->status)->toBe(GeneratedTest::VERIFIED)
        ->and($test->pending_path)->toBe('storage/testpilot/pending/Unit/CalculatorDivideTest.php')
        ->and(collect($test->verification['stages'])->pluck('status', 'stage')->all())->toMatchArray(['run' => 'passed', 'stability' => 'passed'])
        ->and(divideTarget()->status)->toBe(Target::AWAITING_APPROVAL);

    // 3. It shows up in the review queue and is approved.
    expect(collect($this->getJson('/testpilot/api/review')->json('data'))->pluck('id'))->toContain($test->id);
    $this->postJson("/testpilot/api/tests/{$test->id}/approve")->assertOk()->assertJson(['status' => 'approved']);

    $approvedPath = "{$this->sandbox}/tests/TestPilot/Generated/Unit/CalculatorDivideTest.php";
    expect(file_exists($approvedPath))->toBeTrue()
        ->and(file_get_contents($approvedPath))->toContain('status:        approved')
        ->and(file_exists("{$this->sandbox}/storage/testpilot/pending/Unit/CalculatorDivideTest.php"))->toBeFalse();

    // 4. Approved tests run and results are recorded per scenario.
    $run = $this->postJson('/testpilot/api/runs')->assertStatus(202)->json();
    $run = $this->getJson("/testpilot/api/runs/{$run['id']}")->assertOk()->json();

    expect($run['status'])->toBe(Run::COMPLETED)
        ->and($run['passed'])->toBe(2)
        ->and(collect($run['results'])->pluck('scenario_id')->sort()->values()->all())->toBe(['S1', 'S2'])
        ->and(divideTarget()->last_result)->toBe('passed');

    // 5. Everything is audited and AI usage is recorded.
    expect(AuditLog::query()->pluck('action')->all())->toContain('plan.proposed', 'plan.approved', 'generation.completed', 'test.approved', 'run.finished')
        ->and(AiRequest::query()->count())->toBe(2);
});

it('marks approved tests stale when the source code changes', function () {
    scanSandbox();
    $target = divideTarget();
    GeneratedTest::query()->create(['target_id' => $target->id, 'status' => GeneratedTest::APPROVED, 'source_fingerprint' => $target->fingerprint, 'approved_path' => 'x']);
    $target->update(['status' => Target::APPROVED]);

    $file = "{$this->sandbox}/src/Calculator.php";
    file_put_contents($file, str_replace('return $a / $b;', 'return round($a / $b, 2);', (string) file_get_contents($file)));

    $summary = scanSandbox();

    expect($summary['changed'])->toBe(1)
        ->and($summary['stale'])->toBe(1)
        ->and(divideTarget()->status)->toBe(Target::STALE)
        ->and(GeneratedTest::query()->first()->status)->toBe(GeneratedTest::STALE);
});

it('refuses to approve a test generated for older source code', function () {
    scanSandbox();
    $target = divideTarget();
    $test = GeneratedTest::query()->create(['target_id' => $target->id, 'status' => GeneratedTest::VERIFIED, 'source_fingerprint' => 'sha256:old', 'pending_path' => 'storage/testpilot/pending/Unit/X.php']);

    $this->postJson("/testpilot/api/tests/{$test->id}/approve")
        ->assertStatus(422)
        ->assertJson(['message' => 'The source code changed after this test was generated. Regenerate it before approving.']);
});

it('rejects a test, discards the pending file and records the reason', function () {
    scanSandbox();
    $target = divideTarget();
    $this->ai->push(calculatorPlanJson())->push(calculatorTestCode());
    $this->postJson("/testpilot/api/targets/{$target->id}/plan");
    $plan = $this->getJson("/testpilot/api/targets/{$target->id}")->json('plan');
    $this->postJson("/testpilot/api/targets/{$target->id}/plans/{$plan['id']}/approve", ['selected' => ['S1']]);
    $test = GeneratedTest::query()->firstOrFail();

    $this->postJson("/testpilot/api/tests/{$test->id}/reject", ['reason' => 'Asserts the wrong rounding rule'])->assertOk();

    expect($test->refresh()->status)->toBe(GeneratedTest::REJECTED)
        ->and($test->rejection_reason)->toBe('Asserts the wrong rounding rule')
        ->and(file_exists("{$this->sandbox}/{$test->pending_path}"))->toBeFalse();
});

it('explains that rate-limit-only targets cannot get unit tests yet', function () {
    scanSandbox();
    $login = Target::query()->where('key_hash', hash('sha256', 'App\Http\Controllers\AuthController::login'))->firstOrFail();

    $this->postJson("/testpilot/api/targets/{$login->id}/plan", ['module' => 'unit'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'has no unit module'));
});

it('stops AI calls when the monthly budget is used up', function () {
    config(['testpilot.ai.monthly_budget' => 1]);
    app()->forgetInstance(\TestPilot\Laravel\Services\EloquentUsageRecorder::class);
    scanSandbox();
    AiRequest::query()->create(['purpose' => 'plan', 'provider' => 'fake', 'model' => 'm', 'status' => 'ok', 'cost_micros' => 2_000_000]);
    $this->ai->push(calculatorPlanJson());

    $this->postJson('/testpilot/api/targets/' . divideTarget()->id . '/plan')
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Monthly AI budget reached'));

    expect(divideTarget()->status)->toBe(Target::NEEDS_ATTENTION)
        ->and($this->ai->requests)->toBe([]);
});

it('does not start a second run for the same tests while one is active', function () {
    scanSandbox();
    $target = divideTarget();
    GeneratedTest::query()->create(['target_id' => $target->id, 'status' => GeneratedTest::APPROVED, 'source_fingerprint' => $target->fingerprint, 'approved_path' => 'tests/TestPilot/Generated/Unit/A.php']);

    $service = app(\TestPilot\Laravel\Services\RunService::class);
    $first = $service->queue('cli');
    $second = $service->queue('dashboard');

    expect($second->id)->toBe($first->id)->and(Run::query()->count())->toBe(1);
});
