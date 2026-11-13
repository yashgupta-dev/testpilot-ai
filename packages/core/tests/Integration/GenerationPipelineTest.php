<?php

declare(strict_types=1);

use TestPilot\Ai\Providers\FakeProvider;
use TestPilot\Generation\ScenarioPlan;
use TestPilot\TestPilot;
use TestPilot\Verification\GenerationOutcome;

/**
 * End-to-end: scan a real project, plan with a fake AI, generate a Pest file, run it with the real
 * Pest binary in a child process, repair, check stability and (optionally) mutation testing.
 */
function sandboxProject(): string
{
    $root = temporaryDirectory();
    $source = fixtures('project');

    foreach (['src/Calculator.php', 'bootstrap.php', 'phpunit.xml', 'tests/.gitkeep'] as $file) {
        is_dir(dirname("{$root}/{$file}")) || mkdir(dirname("{$root}/{$file}"), 0777, true);
        copy("{$source}/{$file}", "{$root}/{$file}");
    }

    // Reuse this package's installed vendor directory (Pest, PHPUnit, TestPilot attributes).
    symlink(realpath(dirname(__DIR__, 2) . '/vendor'), "{$root}/vendor");

    return $root;
}

function calculatorPlan(): ScenarioPlan
{
    return ScenarioPlan::fromArray([
        'target' => 'Demo\Calculator::divide',
        'scenarios' => [
            ['id' => 'S1', 'name' => 'Divides two integers', 'type' => 'positive', 'module' => 'unit', 'expected' => '3.0', 'basis' => 'confirmed', 'basis_source' => 'return $a / $b', 'confidence' => 0.95, 'priority' => 'high'],
            ['id' => 'S2', 'name' => 'Throws on division by zero', 'type' => 'negative', 'module' => 'unit', 'expected' => 'InvalidArgumentException', 'basis' => 'confirmed', 'basis_source' => 'if ($b === 0) throw', 'confidence' => 0.95, 'priority' => 'high'],
        ],
    ]);
}

function calculatorTest(string $s1Expectation = '3.0'): string
{
    return <<<PHP
    ```php
    <?php

    declare(strict_types=1);

    use Demo\Calculator;

    it('[S1] divides two integers', function () {
        expect((new Calculator())->divide(6, 2))->toBe({$s1Expectation});
    });

    it('[S2] throws on division by zero', function () {
        expect(fn () => (new Calculator())->divide(1, 0))->toThrow(InvalidArgumentException::class);
    });
    ```
    PHP;
}

function pilot(string $root, FakeProvider $fake, bool $mutation = false): TestPilot
{
    return new TestPilot($root, [
        'paths' => ['src'],
        'pest' => ['binary' => ['vendor/bin/pest'], 'stability_runs' => 2],
        'mutation' => ['enabled' => $mutation, 'min_score' => 60],
        'limits' => ['max_repair_attempts' => 2, 'process_timeout' => 120],
    ], 'testing', provider: $fake);
}

it('generates, runs and verifies a passing test file', function () {
    $root = sandboxProject();
    $fake = new FakeProvider([calculatorTest()]);
    $pilot = pilot($root, $fake);
    $target = $pilot->scanner->scan($pilot->scanPaths())->find('Demo\Calculator::divide');

    $outcome = $pilot->pipeline()->run($target, $pilot->resolver->resolve($target), calculatorPlan());

    expect($outcome->status)->toBe(GenerationOutcome::VERIFIED)
        ->and($outcome->repairAttempts)->toBe(0)
        ->and($outcome->scenarioResults())->toBe(['S1' => 'passed', 'S2' => 'passed'])
        ->and(array_column(array_map(fn ($s) => $s->toArray(), $outcome->stages), 'status', 'stage'))
        ->toMatchArray(['run' => 'passed', 'stability' => 'passed', 'safety' => 'passed'])
        ->and(file_get_contents("{$root}/{$outcome->pendingPath}"))->toContain('@testpilot-generated')
        ->and(file_exists("{$root}/tests/TestPilot"))->toBeFalse(); // nothing enters the suite before approval
});

it('repairs a failing generated test using the failure output', function () {
    $root = sandboxProject();
    $fake = new FakeProvider([calculatorTest('4.0'), calculatorTest('3.0')]);
    $pilot = pilot($root, $fake);
    $target = $pilot->scanner->scan($pilot->scanPaths())->find('Demo\Calculator::divide');

    $outcome = $pilot->pipeline()->run($target, $pilot->resolver->resolve($target), calculatorPlan());

    expect($outcome->status)->toBe(GenerationOutcome::VERIFIED)
        ->and($outcome->repairAttempts)->toBe(1)
        ->and($fake->requests[1]->purpose)->toBe('repair')
        ->and($fake->requests[1]->messages[0]['content'])->toContain('[S1] divides two integers');
});

it('reports a suspected bug instead of weakening a confirmed assertion', function () {
    $root = sandboxProject();
    $marked = str_replace("it('[S1]", "// @testpilot-suspected-bug S1: divide(6, 2) should be 4 per spec\nit('[S1]", calculatorTest('4.0'));
    $fake = new FakeProvider([calculatorTest('4.0'), $marked]);
    $pilot = pilot($root, $fake);
    $target = $pilot->scanner->scan($pilot->scanPaths())->find('Demo\Calculator::divide');

    $outcome = $pilot->pipeline()->run($target, $pilot->resolver->resolve($target), calculatorPlan());

    expect($outcome->status)->toBe(GenerationOutcome::SUSPECTED_BUG)
        ->and($outcome->suspectedBugs)->toBe(['S1' => 'divide(6, 2) should be 4 per spec'])
        ->and($outcome->needsReview())->toBeTrue();
});

it('never executes unsafe generated code', function () {
    $root = sandboxProject();
    $evil = "```php\n<?php\nit('[S1] x', function () { file_put_contents(__DIR__ . '/../../../pwned.txt', 'x'); expect(true)->toBeTrue(); });\n```";
    $pilot = pilot($root, new FakeProvider([$evil]));
    $target = $pilot->scanner->scan($pilot->scanPaths())->find('Demo\Calculator::divide');

    $outcome = $pilot->pipeline()->run($target, $pilot->resolver->resolve($target), calculatorPlan());

    expect($outcome->status)->toBe(GenerationOutcome::UNSAFE)
        ->and($outcome->pendingPath)->toBeNull()
        ->and(file_exists("{$root}/pwned.txt"))->toBeFalse()
        ->and(glob("{$root}/storage/testpilot/pending/Unit/*") ?: [])->toBe([]);
});

it('gives up after the repair budget and reports the failure', function () {
    $root = sandboxProject();
    $pilot = pilot($root, new FakeProvider([calculatorTest('9.0'), calculatorTest('8.0'), calculatorTest('7.0')]));
    $target = $pilot->scanner->scan($pilot->scanPaths())->find('Demo\Calculator::divide');

    $outcome = $pilot->pipeline()->run($target, $pilot->resolver->resolve($target), calculatorPlan());

    expect($outcome->status)->toBe(GenerationOutcome::FAILED)
        ->and($outcome->repairAttempts)->toBe(2)
        ->and($outcome->run?->failed())->toHaveCount(1);
});

it('runs Pest mutation testing on verified tests', function () {
    $root = sandboxProject();
    $code = str_replace('use Demo\Calculator;', "use Demo\\Calculator;\n\nmutates(Calculator::class);", calculatorTest());
    $pilot = pilot($root, new FakeProvider([$code]), mutation: true);
    $target = $pilot->scanner->scan($pilot->scanPaths())->find('Demo\Calculator::divide');

    $outcome = $pilot->pipeline()->run($target, $pilot->resolver->resolve($target), calculatorPlan());
    $stage = array_values(array_filter($outcome->stages, fn ($s) => $s->stage === 'mutation'))[0];

    expect($outcome->status)->toBe(GenerationOutcome::VERIFIED)
        ->and($stage->status)->toBeIn(['passed', 'warning']);

    if ($outcome->mutation?->parsed) {
        expect($outcome->mutation->score)->toBeGreaterThan(0.0);
    }
});
