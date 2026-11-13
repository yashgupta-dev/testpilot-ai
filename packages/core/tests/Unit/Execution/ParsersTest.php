<?php

declare(strict_types=1);

use TestPilot\Execution\JunitReportParser;
use TestPilot\Execution\MutationReport;
use TestPilot\Risk\RiskScorer;
use TestPilot\Risk\RiskSignals;

it('parses Pest JUnit output into results mapped to scenario ids', function () {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <testsuites>
      <testsuite name="P" tests="3">
        <testcase name="it [S1] divides two numbers" class="P\CalculatorTest" file="a.php" assertions="1" time="0.0123"/>
        <testcase name="it [S2] throws on zero" class="P\CalculatorTest" file="a.php" assertions="1" time="0.002">
          <failure type="PHPUnit\Framework\ExpectationFailedException" message="Failed asserting that 2 is identical to 3.">Failed asserting that 2 is identical to 3.
    at a.php:12</failure>
        </testcase>
        <testcase name="it [S3] is skipped" class="P\CalculatorTest" assertions="0" time="0"><skipped/></testcase>
      </testsuite>
    </testsuites>
    XML;

    $cases = (new JunitReportParser())->parse($xml);

    expect($cases)->toHaveCount(3)
        ->and($cases[0]->scenarioId())->toBe('S1')
        ->and($cases[0]->timeMs)->toBe(12.3)
        ->and($cases[1]->status)->toBe('failed')
        ->and($cases[1]->message)->toBe('Failed asserting that 2 is identical to 3.')
        ->and($cases[2]->status)->toBe('skipped');
});

it('returns no cases for empty or invalid XML instead of crashing', function () {
    expect((new JunitReportParser())->parse(''))->toBe([])
        ->and((new JunitReportParser())->parse('<not-closed'))->toBe([]);
});

it('parses mutation scores best-effort and keeps the raw output when unknown', function () {
    $report = MutationReport::fromOutput("\e[32mMutations:\e[0m 4 untested, 14 tested\n  Score: 77.78%\n UNTESTED  src/Calculator.php > Line 18: ReturnValue - ID: abc\n");
    $unknown = MutationReport::fromOutput('something else entirely');

    expect($report->score)->toBe(77.78)
        ->and($report->tested)->toBe(14)
        ->and($report->survivors[0])->toBe(['file' => 'src/Calculator.php', 'line' => 18, 'description' => 'ReturnValue - ID: abc'])
        ->and($unknown->parsed)->toBeFalse()
        ->and($unknown->score)->toBeNull();
});

it('scores risk with an explanation for every point', function () {
    $target = scanFixtures()->find(INVOICE);
    $risk = (new RiskScorer())->score($target, new RiskSignals(hasApprovedTests: false, recentChanges: 3));

    expect($risk->score)->toBe(21 + 25 + 15 + 20)
        ->and($risk->level)->toBe('high')
        ->and(array_column($risk->reasons, 'label'))->toBe(['Complexity 8', 'No approved tests', 'Changed 3× recently', 'Handles money'])
        ->and(array_sum(array_column($risk->reasons, 'points')))->toBe($risk->score);
});

it('explains a missing coverage driver for mutation testing', function () {
    $report = MutationReport::fromOutput("Pest\\Exceptions\\InvalidOption\n  Mutation testing requires code coverage to be enabled.");

    expect($report->error)->toContain('PCOV')->and($report->parsed)->toBeFalse();
});
