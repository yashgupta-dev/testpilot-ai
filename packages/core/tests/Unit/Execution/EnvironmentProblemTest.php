<?php

declare(strict_types=1);

use TestPilot\Execution\RunResult;
use TestPilot\Execution\TestCaseResult;
use TestPilot\Verification\GenerationPipeline;

function runOf(array $cases): RunResult
{
    return new RunResult(1, array_map(fn (array $c) => new TestCaseResult($c[0], 'T', null, $c[1], 1.0, $c[4] ?? ($c[1] === 'passed' ? 1 : 0), $c[2] ?? null, $c[3] ?? null), $cases), '', 10.0, 'pest');
}

it('explains a missing PDO driver instead of reporting failures', function () {
    $error = 'Illuminate\Database\QueryException: could not find driver (Connection: sqlite, SQL: PRAGMA foreign_keys = ON;)';
    $problem = GenerationPipeline::environmentProblem(runOf([
        ['[S1] accepts', 'failed', 'Illuminate\Database\QueryException', $error],
        ['[S2] rejects', 'failed', 'Illuminate\Database\QueryException', $error],
    ]));

    expect($problem)->toContain('no PDO driver for the "sqlite" test database', 'php8.4-sqlite3', 'Not reported as a bug');
});

it('recognises an unreachable or missing test database', function (string $details, string $expected) {
    expect(GenerationPipeline::environmentProblem(runOf([['[S1] x', 'failed', 'QueryException', $details]])))->toContain($expected);
})->with([
    ['SQLSTATE[HY000] [2002] Connection refused', 'unreachable'],
    ['SQLSTATE[HY000] [1045] Access denied for user', 'rejected the credentials'],
    ['SQLSTATE[HY000] [1049] Unknown database', 'does not exist'],
]);

it('treats mixed or real failures as results, not setup problems', function () {
    $driver = 'could not find driver (Connection: sqlite';

    expect(GenerationPipeline::environmentProblem(runOf([['[S1] a', 'passed'], ['[S2] b', 'failed', 'x', $driver]])))->toBeNull()
        ->and(GenerationPipeline::environmentProblem(runOf([['[S1] a', 'failed', 'x', $driver], ['[S2] b', 'failed', 'Expected 422, got 302']])))->toBeNull()
        ->and(GenerationPipeline::environmentProblem(runOf([['[S1] a', 'passed']])))->toBeNull();
});

it('recognises a missing module table as an incomplete test schema', function () {
    $error = 'Illuminate\Database\QueryException: SQLSTATE[HY000]: General error: 1 no such table: lead_tasks';
    $problem = GenerationPipeline::environmentProblem(runOf([
        ['[S1] returns settings', 'failed', 'Expected 200, got 500', $error],
        ['[S2] searches settings', 'failed', 'Expected 200, got 500', $error],
    ]));

    expect($problem)->toContain('schema is missing table "lead_tasks"', 'module migrations', 'cannot confirm');
});

it('does not treat an unexecuted or assertion-free run as proven', function () {
    $noCases = new RunResult(0, [], '', 1.0, 'pest');
    $noAssertions = new RunResult(0, [
        new TestCaseResult('[S1] returns settings', 'Tests', null, 'passed', 1.0, 0),
    ], '', 1.0, 'pest');
    $withAssertions = new RunResult(0, [
        new TestCaseResult('[S1] returns settings', 'Tests', null, 'passed', 1.0, 1),
    ], '', 1.0, 'pest');
    $onlySkipped = new RunResult(0, [
        new TestCaseResult('[S1] returns settings', 'Tests', null, 'skipped', 1.0, 0),
    ], '', 1.0, 'pest');

    expect($noCases->passed())->toBeFalse()
        ->and(GenerationPipeline::environmentProblem($noCases))->toContain('no test cases', 'not evidence')
        ->and($noAssertions->passed())->toBeFalse()
        ->and(GenerationPipeline::environmentProblem($noAssertions))->toContain('zero assertions', 'No proof')
        ->and($withAssertions->passed())->toBeTrue()
        ->and($onlySkipped->passed())->toBeFalse();
});
