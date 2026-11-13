<?php

declare(strict_types=1);

use TestPilot\DTO\TargetType;
use TestPilot\Storage\SourceFingerprinter;

it('never executes the files it scans', function () {
    scanFixtures();

    expect(defined('TESTPILOT_FIXTURE_EXECUTED'))->toBeFalse();
});

it('discovers annotated methods, inherited methods, trait methods and functions', function () {
    $keys = array_map(fn ($t) => $t->key, scanFixtures()->targets);

    expect($keys)->toContain(
        'App\Services\InvoiceService::calculateTotal',
        'App\Services\InvoiceService::legacyTotal',      // still a target; the resolver disables it
        'App\Services\InvoiceService::disabledUnit',
        'App\Services\InvoiceService::ping',              // inherited, class is opted in
        'App\Services\InvoiceService::clamp',             // inherited with its own #[Fuzz]
        'App\Services\InvoiceService::formatMoney',       // from trait
        'App\Http\Controllers\AuthController::login',
        'App\Support\money_round()',
    );
});

it('skips private methods, constructors, magic methods, abstract classes, interfaces and anonymous classes', function () {
    $keys = array_map(fn ($t) => $t->key, scanFixtures()->targets);

    expect($keys)
        ->not->toContain('App\Services\InvoiceService::helper')
        ->not->toContain('App\Services\InvoiceService::__construct')
        ->not->toContain('App\Services\InvoiceService::__toString')
        ->not->toContain('App\Services\BaseService::ping')
        ->not->toContain('App\Contracts\TaxRepository::rateFor')
        ->not->toContain('App\Services\PlainService::notAnnotated')
        ->not->toContain('App\Support\not_annotated()');

    foreach ($keys as $key) {
        expect($key)->not->toContain('class@anonymous')->not->toContain('::hidden');
    }
});

it('explains why annotations were ignored', function () {
    $warnings = implode("\n", scanFixtures()->warnings);

    expect($warnings)
        ->toContain('InvoiceService::helper is private')
        ->toContain('TaxRepository is an interface')
        ->toContain('Unknown TestPilot attribute #[Bogus]')
        ->toContain('argument "maxScenarios" has the wrong type')
        ->toContain('has no argument named "foo"');
});

it('captures signature, parameters, return type, location and complexity', function () {
    $target = scanFixtures()->find('App\Services\InvoiceService::calculateTotal');

    expect($target)->not->toBeNull()
        ->and($target->type)->toBe(TargetType::Method)
        ->and($target->signature)->toBe('public function calculateTotal(array $items, float $discount = 0.0, string $region = \'IN\'): float')
        ->and($target->returnType)->toBe('float')
        ->and($target->location->file)->toBe('app/Services/InvoiceService.php')
        ->and($target->location->startLine)->toBeGreaterThan(20)
        ->and($target->complexity)->toBe(8)
        ->and($target->parameters)->toHaveCount(3)
        ->and($target->parameters[1]->name)->toBe('discount')
        ->and($target->parameters[1]->default)->toBe('0.0')
        ->and($target->source)->toContain('Total of all line items')
        ->and($target->classSkeleton)->toContain('final class InvoiceService extends \App\Services\BaseService')
        ->and($target->classSkeleton)->not->toContain('helper');
});

it('reads class and method annotations separately with named arguments', function () {
    $target = scanFixtures()->find('App\Services\InvoiceService::calculateTotal');

    $class = array_map(fn ($a) => [$a->name, $a->arguments], $target->classAnnotations);
    $method = array_map(fn ($a) => [$a->name, $a->arguments], $target->annotations);

    expect($class)->toBe([
        ['TestPilot', ['area' => 'Billing', 'criticality' => 'high']],
        ['UnitTest', ['maxScenarios' => 6]],
    ])->and($method)->toBe([
        ['Fuzz', ['cases' => 500, 'seed' => 48213]],
    ]);
});

it('supports aliased attribute namespaces on functions', function () {
    $target = scanFixtures()->find('App\Support\money_round()');

    expect($target->type)->toBe(TargetType::Function)
        ->and($target->function)->toBe('App\Support\money_round')
        ->and(array_map(fn ($a) => $a->name, $target->annotations))->toBe(['TestPilot', 'Fuzz'])
        ->and($target->parameters[1]->allowsNull())->toBeTrue();
});

it('records where an inherited method is declared', function () {
    $target = scanFixtures()->find('App\Services\InvoiceService::clamp');

    expect($target->declaredIn)->toBe('App\Services\BaseService')
        ->and($target->isInherited())->toBeTrue()
        ->and($target->location->file)->toBe('app/Services/BaseService.php');
});

it('reports files that cannot be parsed without stopping the scan', function () {
    $result = scanFixtures(['app', 'broken']);

    expect($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toContain('broken/Broken.php')
        ->and($result->targets)->not->toBeEmpty();
});

it('produces fingerprints that ignore whitespace-only changes', function () {
    expect(SourceFingerprinter::fingerprint("function a() {\n    return 1;\n}"))
        ->toBe(SourceFingerprinter::fingerprint('function a() { return 1; }'))
        ->not->toBe(SourceFingerprinter::fingerprint('function a() { return 2; }'));
});

it('honours exclude patterns', function () {
    $scanner = new \TestPilot\Discovery\ProjectScanner(new \TestPilot\Security\PathGuard(fixtures()), ['app/Http/*']);
    $keys = array_map(fn ($t) => $t->key, $scanner->scan(['app'])->targets);

    expect($keys)->not->toContain('App\Http\Controllers\AuthController::login');
});
