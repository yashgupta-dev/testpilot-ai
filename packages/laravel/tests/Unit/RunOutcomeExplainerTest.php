<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\Run;
use TestPilot\Laravel\Services\RunOutcomeExplainer;
use TestPilot\Exceptions\AiDisabledException;
use TestPilot\Exceptions\AiProviderException;
use TestPilot\Exceptions\AiQuotaExceededException;
use TestPilot\Exceptions\BudgetExceededException;
use TestPilot\Laravel\Services\GenerationFailureExplainer;

function runOutcome(string $status, int $passed, int $failed, int $skipped, int $assertions, ?string $error = null, ?string $output = null): Run
{
    $run = new Run();
    $run->status = $status;
    $run->passed = $passed;
    $run->failed = $failed;
    $run->skipped = $skipped;
    $run->assertions = $assertions;
    $run->error = $error;
    $run->output = $output;

    return $run;
}

it('explains a completed run with passing assertions in plain language', function () {
    $outcome = RunOutcomeExplainer::explain(runOutcome(Run::COMPLETED, 2, 0, 0, 6));

    expect($outcome)->toMatchArray([
        'code' => 'passed',
        'title' => 'All checked tests passed',
        'tone' => 'green',
    ])->and($outcome['summary'])->toContain('2 tests passed', '6 checks');
});

it('does not call an empty or assertion-free run a pass', function () {
    $empty = RunOutcomeExplainer::explain(runOutcome(Run::FAILED, 0, 0, 0, 0));
    $assertionFree = RunOutcomeExplainer::explain(runOutcome(Run::FAILED, 1, 0, 0, 0));

    expect($empty['code'])->toBe('not_verified')
        ->and($empty['title'])->toBe('No behavior was verified')
        ->and($assertionFree['code'])->toBe('not_verified');
});

it('explains a missing test database table as a setup problem', function () {
    $run = runOutcome(Run::FAILED, 0, 2, 0, 0);
    $results = [
        ['status' => 'failed', 'details' => 'SQLSTATE[HY000]: no such table: lead_tasks'],
        ['status' => 'error', 'details' => 'SQLSTATE[HY000]: no such table: lead_tasks'],
    ];

    $outcome = RunOutcomeExplainer::explain($run, $results);

    expect($outcome)->toMatchArray([
        'code' => 'setup_problem',
        'title' => 'The test database is missing a table',
        'tone' => 'amber',
    ])->and($outcome['next_step'])->toContain('developer', 'migrations');
});

it('explains failed expectations as application behavior that needs review', function () {
    $run = runOutcome(Run::FAILED, 1, 1, 0, 3);
    $results = [
        ['status' => 'passed', 'details' => null],
        ['status' => 'failed', 'message' => 'Expected 200 but received 500', 'details' => 'assertStatus(200)'],
    ];

    $outcome = RunOutcomeExplainer::explain($run, $results);

    expect($outcome)->toMatchArray([
        'code' => 'application_failure',
        'title' => 'Some expected behavior did not pass',
        'tone' => 'red',
    ])->and($outcome['summary'])->toContain('1 tests passed', '1 did not match');
});

it('explains AI and budget failures as generation blockers, not test failures', function (Throwable $error, string $code, string $title) {
    expect(GenerationFailureExplainer::explain($error))->toMatchArray([
        'code' => $code,
        'title' => $title,
    ]);
})->with([
    'daily provider quota' => [new AiQuotaExceededException('Groq quota reached'), 'ai_quota_exceeded', 'AI provider quota reached'],
    'provider outage' => [new AiProviderException('Provider unavailable'), 'ai_provider_unavailable', 'AI provider unavailable'],
    'budget limit' => [new BudgetExceededException('Budget reached'), 'ai_budget_exceeded', 'AI budget limit reached'],
    'AI disabled' => [new AiDisabledException('AI disabled'), 'ai_disabled', 'AI generation is disabled'],
]);

it('does not classify ordinary test generation failures as AI blockers', function () {
    expect(GenerationFailureExplainer::explain(new RuntimeException('Pest test failed')))->toBeNull();
});
