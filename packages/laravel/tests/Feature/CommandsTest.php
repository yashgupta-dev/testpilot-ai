<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\GeneratedTest;

it('scans from the command line', function () {
    $this->artisan('testpilot:scan')
        ->expectsOutputToContain('targets (')
        ->expectsOutputToContain('InvoiceService::calculateTotal')
        ->assertExitCode(0);
});

it('generates, reviews and runs from the command line', function () {
    $this->artisan('testpilot:scan');
    $this->ai->push(calculatorPlanJson())->push(calculatorTestCode());

    $this->artisan('testpilot:generate', ['target' => 'Calculator::divide', '--accept-plan' => true])
        ->expectsOutputToContain('Verified')
        ->assertExitCode(0);

    $test = GeneratedTest::query()->firstOrFail();
    $this->artisan('testpilot:review')->expectsOutputToContain($test->id)->assertExitCode(0);
    $this->artisan('testpilot:review', ['--approve' => $test->id])->expectsOutputToContain('Approved')->assertExitCode(0);

    $this->artisan('testpilot:run', ['--ci' => true])
        ->expectsOutputToContain('2 passed, 0 failed')
        ->assertExitCode(0);

    $this->artisan('testpilot:report', ['--format' => 'json'])->expectsOutputToContain('"with_approved_tests": 1')->assertExitCode(0);
});

it('exits with code 2 in CI when there is nothing to run', function () {
    $this->artisan('testpilot:scan');

    $this->artisan('testpilot:run', ['--ci' => true])->assertExitCode(2);
});
