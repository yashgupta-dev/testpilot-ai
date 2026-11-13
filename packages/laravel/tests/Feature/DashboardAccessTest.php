<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;
use TestPilot\Laravel\TestPilotDashboard;

it('serves the dashboard shell with its configuration', function () {
    $this->get('/testpilot')->assertOk()
        ->assertSee('window.TestPilot', false)
        ->assertSee('"apiPath":"\/testpilot\/api"', false)
        ->assertSee('<div id="app"></div>', false);

    $this->get('/testpilot/targets/some-id')->assertOk(); // client-side routes
});

it('returns overview and environment data', function () {
    app(ScanService::class)->scan();

    $this->getJson('/testpilot/api/overview')->assertOk()
        ->assertJsonStructure([
            'stats' => ['targets', 'with_approved_tests', 'awaiting_approval'],
            'recent_runs',
            'ai' => ['spent', 'budget'],
            'environment' => [
                'app_env',
                'tests_allowed',
                'readiness' => ['can_run_tests', 'can_plan_with_ai', 'checks'],
            ],
        ])
        ->assertJsonPath('environment.readiness.can_run_tests', true);

    $targets = $this->getJson('/testpilot/api/targets')->assertOk()->json('data');
    expect($targets[0]['risk']['score'])->toBeGreaterThanOrEqual($targets[1]['risk']['score']);

    $this->getJson('/testpilot/api/targets?search=Calculator')->assertOk()->assertJsonCount(1, 'data');
});

it('reports the PHPUnit test database without connecting or exposing credentials', function () {
    file_put_contents($this->sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit>
    <php>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="DB_PASSWORD" value="do-not-expose-this"/>
    </php>
</phpunit>
XML);

    $this->getJson('/testpilot/api/environment')
        ->assertOk()
        ->assertJsonPath('test_database.config_file', 'phpunit.xml')
        ->assertJsonPath('test_database.driver', 'sqlite')
        ->assertJsonPath('test_database.database', 'SQLite in-memory')
        ->assertJsonPath('test_database.isolation', 'isolated')
        ->assertJsonPath('test_database.schema_status', 'not_verified')
        ->assertJsonPath('test_database.action', fn (string $action) => str_contains($action, 'never migrates automatically'))
        ->assertDontSee('do-not-expose-this');

    file_put_contents($this->sandbox . '/phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit>
    <php>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_DATABASE" value="user:secret@production"/>
        <env name="DB_PASSWORD" value="do-not-expose-this"/>
    </php>
</phpunit>
XML);

    $this->getJson('/testpilot/api/environment')
        ->assertOk()
        ->assertJsonPath('test_database.database', 'Configured (value omitted)')
        ->assertJsonPath('test_database.isolation', 'review')
        ->assertDontSee('secret')
        ->assertDontSee('do-not-expose-this');
});

it('denies access when the auth callback says no', function () {
    TestPilotDashboard::auth(fn () => false);

    $this->get('/testpilot')->assertForbidden();
    $this->getJson('/testpilot/api/overview')->assertForbidden();
});

it('is only open in the local environment by default', function () {
    TestPilotDashboard::flush();

    $this->get('/testpilot')->assertForbidden(); // the test environment is "testing", not "local"
});

it('hides the dashboard entirely in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->get('/testpilot')->assertNotFound();
});

it('requires approval permission to approve tests', function () {
    TestPilotDashboard::approveUsing(fn () => false);
    app(ScanService::class)->scan();
    $target = Target::query()->firstOrFail();
    $test = GeneratedTest::query()->create(['target_id' => $target->id, 'status' => GeneratedTest::VERIFIED, 'source_fingerprint' => $target->fingerprint]);

    $this->postJson("/testpilot/api/tests/{$test->id}/approve")->assertForbidden();
    $this->postJson("/testpilot/api/tests/{$test->id}/reject", ['reason' => 'nope'])->assertForbidden();
});

it('only serves known asset files', function () {
    // Traversal attempts never reach the file system: they fall through to the SPA shell.
    $this->get('/testpilot/assets/..%2F..%2Fcomposer.json')->assertDontSee('"require"', false)->assertSee('<div id="app"></div>', false);
    $this->get('/testpilot/assets/secret.php')->assertNotFound();
});

it('validates plan approval input', function () {
    app(ScanService::class)->scan();
    $target = Target::query()->firstOrFail();
    $plan = $target->plans()->create(['status' => 'proposed', 'scenarios' => [], 'target_fingerprint' => $target->fingerprint]);

    $this->postJson("/testpilot/api/targets/{$target->id}/plans/{$plan->id}/approve", ['selected' => ['DROP TABLE']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['selected.0']);
});
