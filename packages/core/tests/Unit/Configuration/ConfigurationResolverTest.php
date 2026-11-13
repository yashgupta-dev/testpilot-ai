<?php

declare(strict_types=1);

use TestPilot\Configuration\ConfigurationResolver;
use TestPilot\Configuration\Module;
use TestPilot\Configuration\PolicyLimits;

function resolveFixture(string $key, ?ConfigurationResolver $resolver = null): \TestPilot\Configuration\ResolvedConfig
{
    $target = scanFixtures()->find($key) ?? throw new RuntimeException("Fixture target {$key} not found");

    return ($resolver ?? new ConfigurationResolver())->resolve($target);
}

it('combines class and method modules additively', function () {
    $config = resolveFixture('App\Services\InvoiceService::calculateTotal');

    expect($config->isEnabled(Module::Unit))->toBeTrue()
        ->and($config->isEnabled(Module::Fuzz))->toBeTrue()
        ->and($config->option(Module::Unit, 'max_scenarios'))->toBe(6)
        ->and($config->area)->toBe('Billing')
        ->and($config->criticality)->toBe('high');
});

it('clamps values to policy limits and records the clamp in the trace', function () {
    $config = resolveFixture('App\Services\InvoiceService::calculateTotal', new ConfigurationResolver(new PolicyLimits(maxFuzzCases: 200)));

    expect($config->option(Module::Fuzz, 'cases'))->toBe(200)
        ->and($config->option(Module::Fuzz, 'seed'))->toBe(48213)
        ->and(array_slice($config->trace, -1)[0])->toMatchArray(['key' => 'fuzz.cases', 'value' => 200, 'source' => 'policy: max 200']);
});

it('lets an explicit method-level disable win over class enablement', function () {
    $config = resolveFixture('App\Services\InvoiceService::disabledUnit');

    expect($config->enabled)->toBeTrue()
        ->and($config->isEnabled(Module::Unit))->toBeFalse()
        ->and($config->activeModules())->toBe([]);
});

it('disables a target with SkipTest and keeps the reason', function () {
    $config = resolveFixture('App\Services\InvoiceService::legacyTotal');

    expect($config->enabled)->toBeFalse()
        ->and($config->skipReason)->toBe('Deprecated, removed in v3')
        ->and($config->activeModules())->toBe([]);
});

it('enables unit tests by default for a bare TestPilot attribute', function () {
    $config = resolveFixture('App\Services\InvoiceService::ping');

    expect($config->isEnabled(Module::Unit))->toBeTrue()
        ->and($config->isEnabled(Module::Fuzz))->toBeFalse();
});

it('applies Validation and RateLimit options from method annotations', function () {
    $config = resolveFixture('App\Http\Controllers\AuthController::login');

    expect($config->isEnabled(Module::Validation))->toBeTrue()
        ->and($config->isEnabled(Module::RateLimit))->toBeTrue()
        ->and($config->isEnabled(Module::Unit))->toBeFalse()
        ->and($config->option(Module::RateLimit, 'max_attempts'))->toBe(5)
        ->and($config->option(Module::RateLimit, 'lockout'))->toBeTrue();
});

it('applies project defaults below annotations', function () {
    $resolver = ConfigurationResolver::fromArray(
        ['modules' => ['fuzz' => true], 'options' => ['fuzz' => ['cases' => 25]]],
        new PolicyLimits(),
    );

    $ping = resolveFixture('App\Services\InvoiceService::ping', $resolver);
    $total = resolveFixture('App\Services\InvoiceService::calculateTotal', $resolver);

    expect($ping->isEnabled(Module::Fuzz))->toBeTrue()
        ->and($ping->option(Module::Fuzz, 'cases'))->toBe(25)
        ->and($total->option(Module::Fuzz, 'cases'))->toBe(200);
});

it('turns AI off when the policy forbids it, whatever the annotations say', function () {
    $config = resolveFixture('App\Services\InvoiceService::calculateTotal', new ConfigurationResolver(new PolicyLimits(aiAllowed: false)));

    expect($config->isEnabled(Module::Ai))->toBeFalse();
});

it('explains where every value came from', function () {
    $sources = array_column(resolveFixture('App\Services\InvoiceService::calculateTotal')->trace, 'source', 'key');

    expect($sources['unit.max_scenarios'])->toStartWith('class #[UnitTest]')
        ->and($sources['fuzz'])->toStartWith('method #[Fuzz]');
});
