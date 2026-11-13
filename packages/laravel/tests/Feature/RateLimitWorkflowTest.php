<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

/**
 * End-to-end: throttled routes in the fixture app. The generated Pest file sends real requests
 * through Laravel's ThrottleRequests middleware in a child process and moves time with travel().
 */
function sessionTarget(string $method): Target
{
    return Target::query()->where('key_hash', hash('sha256', "Shop\\Http\\Controllers\\SessionController::{$method}"))->firstOrFail();
}

function planRateLimit($test, string $method, int $status = 202): array
{
    $target = sessionTarget($method);
    $test->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'ratelimit'])->assertStatus($status);

    return $test->getJson("/testpilot/api/targets/{$target->id}?module=ratelimit")->assertOk()->json();
}

function approveAndGenerate($test, array $detail): GeneratedTest
{
    $plan = $detail['plan'];
    $selected = collect($plan['scenarios'])->where('selected', true)->pluck('id')->all();
    $test->postJson("/testpilot/api/targets/{$detail['id']}/plans/{$plan['id']}/approve", ['selected' => $selected])->assertSuccessful();

    return GeneratedTest::query()->where('target_id', $detail['id'])->where('module', 'ratelimit')->latest()->firstOrFail();
}

it('plans rate-limit cases from the declared limit without calling the AI', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $detail = planRateLimit($this, 'store');
    $plan = $detail['plan'];

    expect($detail['plannable_modules'])->toBe(['ratelimit'])
        ->and($plan['module'])->toBe('ratelimit')
        ->and($plan['status'])->toBe('proposed')
        ->and($plan['prompt_version'])->toBe('ratelimit@v1')
        ->and($plan['context'])->toMatchArray(['method' => 'POST', 'uri' => '/api/login', 'max_attempts' => 3, 'decay_seconds' => 60, 'keyed_by' => 'ip', 'warnings' => []])
        ->and($plan['context']['source'])->toContain('#[RateLimit(maxAttempts: 3, decaySeconds: 60)]')
        ->and($plan['context']['route_throttle']['max_attempts'])->toBe(3)
        ->and(collect($plan['scenarios'])->pluck('meta.kind')->all())->toBe(['allow', 'block', 'retry_after', 'headers', 'reset', 'isolation'])
        ->and($this->ai->requests)->toBe([]);
});

it('reads the limit from the route when #[RateLimit] declares none', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $plan = planRateLimit($this, 'ping')['plan'];

    expect($plan['context'])->toMatchArray(['method' => 'GET', 'uri' => '/api/ping', 'max_attempts' => 2, 'decay_seconds' => 60])
        ->and($plan['context']['source'])->toBe('route middleware throttle:2,1');
});

it('asks for a declared limit when the route uses a named limiter', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $target = sessionTarget('status');
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'ratelimit'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, "named limiter 'status'") && str_contains($m, '#[RateLimit(maxAttempts'));
});

it('generates and runs rate-limit tests against the real throttle middleware', function () {
    $root = validationSandbox();
    app(ScanService::class)->scan();

    $test = approveAndGenerate($this, planRateLimit($this, 'store'));

    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->pending_path)->toBe('storage/testpilot/pending/RateLimit/SessionControllerStoreTest.php')
        ->and($test->verification['run']['counts'])->toMatchArray(['passed' => 6, 'failed' => 0])
        ->and($test->code)->toContain('travel(60 + 1)->seconds()')->not->toContain('sleep(');

    $this->postJson("/testpilot/api/tests/{$test->id}/approve")->assertOk();
    expect(is_file("{$root}/tests/TestPilot/Generated/RateLimit/SessionControllerStoreTest.php"))->toBeTrue();
});

it('reports a suspected bug when the route allows more attempts than declared', function () {
    validationSandbox();
    config(['testpilot.pest.env' => ['TP_WEAK_THROTTLE' => '1']]); // the child app throttles login at 10/min
    app(ScanService::class)->scan();

    $test = approveAndGenerate($this, planRateLimit($this, 'store'));

    // Allowing 3 and isolating clients still pass; the 429, Retry-After, header and reset cases fail.
    expect($test->status)->toBe(GeneratedTest::SUSPECTED_BUG, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and(array_keys($test->verification['suspected_bugs']))->toBe(['S2', 'S3', 'S4', 'S5'])
        ->and($test->verification['suspected_bugs']['S2'])->toContain('Declared in the source but not enforced');
});

it('rejects plain-English additions on rate-limit plans', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $detail = planRateLimit($this, 'ping');
    $this->postJson("/testpilot/api/targets/{$detail['id']}/plans/{$detail['plan']['id']}/approve", ['selected' => ['S1'], 'additions' => ['also check 500 errors']])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Rate-limit cases come from the declared limit'));
});
