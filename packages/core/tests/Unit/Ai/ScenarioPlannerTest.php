<?php

declare(strict_types=1);

use TestPilot\Ai\AiDataPolicy;
use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiProviderInterface;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\ContextBuilder;
use TestPilot\Ai\Providers\FakeProvider;
use TestPilot\Ai\UsageRecorder;
use TestPilot\Configuration\ConfigurationResolver;
use TestPilot\Configuration\PolicyLimits;
use TestPilot\Exceptions\AiDisabledException;
use TestPilot\Exceptions\BudgetExceededException;
use TestPilot\Exceptions\InvalidAiResponseException;
use TestPilot\Generation\ScenarioPlanner;

function planJson(string $target, array $overrides = []): string
{
    return json_encode(array_replace([
        'schema_version' => '1',
        'target' => $target,
        'scenarios' => [
            ['id' => 'S1', 'name' => 'Calculates total for valid items', 'type' => 'positive', 'module' => 'unit', 'description' => 'happy path', 'inputs' => '2 × 50', 'expected' => '118.0 with 18% tax', 'basis' => 'confirmed', 'basis_source' => 'return statement', 'confidence' => 0.95, 'priority' => 'high'],
            ['id' => 'S2', 'name' => 'Throws for negative quantity', 'type' => 'negative', 'module' => 'unit', 'description' => '', 'inputs' => 'quantity -1', 'expected' => 'InvalidArgumentException', 'basis' => 'confirmed', 'basis_source' => 'line: throw new InvalidArgumentException', 'confidence' => 0.9, 'priority' => 'high'],
            ['id' => 'S3', 'name' => 'Handles currency mismatch', 'type' => 'edge', 'module' => 'unit', 'description' => '', 'inputs' => 'USD item', 'expected' => 'unclear', 'basis' => 'assumption', 'basis_source' => 'not in source', 'confidence' => 0.3, 'priority' => 'low'],
            ['id' => 'S4', 'name' => 'Never returns a negative total', 'type' => 'fuzz', 'module' => 'fuzz', 'description' => '', 'inputs' => 'dataset', 'expected' => '>= 0', 'basis' => 'assumption', 'basis_source' => 'business intent', 'confidence' => 0.7, 'priority' => 'medium'],
        ],
        'notes' => '',
    ], $overrides));
}

function plannerFor(AiProviderInterface $provider, AiDataPolicy $policy = AiDataPolicy::Cloud, ?UsageRecorder $usage = null): ScenarioPlanner
{
    return new ScenarioPlanner(
        new AiGateway($provider, $policy, $usage ?? new \TestPilot\Ai\NullUsageRecorder()),
        new ContextBuilder(['Pest 3, plain PHP']),
    );
}


it('returns a validated scenario plan with assumptions flagged', function () {
    $target = scanFixtures()->find(INVOICE);
    $config = (new ConfigurationResolver())->resolve($target);
    $fake = new FakeProvider([planJson(INVOICE)]);

    $plan = plannerFor($fake)->plan($target, $config);

    expect($plan->scenarios)->toHaveCount(4)
        ->and($plan->scenarios[0]->basis)->toBe('confirmed')
        ->and($plan->scenarios[2]->isAssumption())->toBeTrue()
        ->and($plan->scenarios[2]->selected)->toBeFalse() // weak assumption starts unselected
        ->and(array_map(fn ($s) => $s->id, $plan->selected()))->toBe(['S1', 'S2', 'S4'])
        ->and($plan->model)->toBe('fake-model');
});

it('sends the source, outline and configuration but no other method bodies', function () {
    $target = scanFixtures()->find(INVOICE);
    $fake = new FakeProvider([planJson(INVOICE)]);

    plannerFor($fake)->plan($target, (new ConfigurationResolver())->resolve($target));
    $prompt = $fake->requests[0]->messages[0]['content'];

    expect($prompt)
        ->toContain('Key: App\Services\InvoiceService::calculateTotal')
        ->toContain('Enabled modules: unit, fuzz')
        ->toContain("throw new InvalidArgumentException('Negative quantity')")
        ->toContain('public function legacyTotal(array $items): float;') // signature only
        ->not->toContain('return 0.0;');                                  // legacyTotal body is not sent
});

it('retries once with the validation errors when the plan is invalid', function () {
    $target = scanFixtures()->find(INVOICE);
    $fake = new FakeProvider([
        'Sure! Here is the plan: {"schema_version":"1","target":"wrong","scenarios":[]}',
        "```json\n" . planJson(INVOICE) . "\n```",
    ]);

    $plan = plannerFor($fake)->plan($target, (new ConfigurationResolver())->resolve($target));

    expect($plan->scenarios)->toHaveCount(4)
        ->and($fake->requests)->toHaveCount(2)
        ->and($fake->requests[1]->messages[2]['content'])->toContain('target must be exactly');
});

it('fails loudly when the plan is still invalid after the retry', function () {
    $target = scanFixtures()->find(INVOICE);
    $bad = planJson(INVOICE, ['scenarios' => [['id' => 'X', 'name' => 'n', 'type' => 'weird', 'module' => 'unit', 'expected' => 'e', 'basis' => 'maybe', 'basis_source' => 's', 'confidence' => 2, 'priority' => 'high']]]);

    plannerFor(new FakeProvider([$bad, $bad]))->plan($target, (new ConfigurationResolver())->resolve($target));
})->throws(InvalidAiResponseException::class, 'invalid after one retry');

it('rejects scenarios for modules that are not enabled', function () {
    $target = scanFixtures()->find('App\Services\InvoiceService::ping'); // unit only
    $plan = planJson('App\Services\InvoiceService::ping');

    plannerFor(new FakeProvider([$plan, $plan]))->plan($target, (new ConfigurationResolver())->resolve($target));
})->throws(InvalidAiResponseException::class, 'module "fuzz" is not enabled');

it('redacts secrets before calling the provider', function () {
    $target = scanFixtures()->find(INVOICE);
    $fake = new FakeProvider([planJson(INVOICE)]);
    $planner = new ScenarioPlanner(new AiGateway($fake), new ContextBuilder(['Deploy key: sk-ant-api03-SECRETSECRETSECRETSECRET1']));

    $planner->plan($target, (new ConfigurationResolver())->resolve($target));

    expect($fake->requests[0]->messages[0]['content'])->not->toContain('SECRETSECRET')->toContain('[REDACTED]');
});

it('refuses cloud providers when the data policy is local-only', function () {
    $target = scanFixtures()->find(INVOICE);
    $cloud = new class implements AiProviderInterface {
        public function complete(AiRequest $request): AiResponse { throw new LogicException('must not be called'); }
        public function name(): string { return 'cloudy'; }
        public function model(): string { return 'm'; }
        public function isLocal(): bool { return false; }
    };

    plannerFor($cloud, AiDataPolicy::Local)->plan($target, (new ConfigurationResolver())->resolve($target));
})->throws(AiDisabledException::class, 'only allows local AI providers');

it('does not call the AI when the policy disables it', function () {
    $target = scanFixtures()->find(INVOICE);
    $config = (new ConfigurationResolver(new PolicyLimits(aiAllowed: false)))->resolve($target);

    plannerFor(new FakeProvider())->plan($target, $config);
})->throws(AiDisabledException::class);

it('stops before calling the provider when the budget is exhausted', function () {
    $target = scanFixtures()->find(INVOICE);
    $fake = new FakeProvider([planJson(INVOICE)]);
    $usage = new class implements UsageRecorder {
        public function assertWithinBudget(string $purpose, ?string $targetKey = null): void { throw new BudgetExceededException('Monthly AI budget of $50 reached'); }
        public function record(AiRequest $r, ?AiResponse $s, string $p, string $m, ?string $t, ?string $e): void {}
    };

    expect(fn () => plannerFor($fake, AiDataPolicy::Cloud, $usage)->plan($target, (new ConfigurationResolver())->resolve($target)))
        ->toThrow(BudgetExceededException::class);
    expect($fake->requests)->toBe([]);
});

it('plans HTTP feature scenarios with the feature prompts and only the feature module', function () {
    $target = scanFixtures()->find(INVOICE);
    $config = (new ConfigurationResolver(new PolicyLimits(), ['feature' => true]))->resolve($target);
    $feature = fn (array $s) => array_map(static fn (array $x): array => [...$x, 'module' => 'feature', 'type' => $x['type'] === 'fuzz' ? 'edge' : $x['type']], $s);
    $json = json_decode(planJson($target->key), true);
    $provider = new FakeProvider([json_encode([...$json, 'scenarios' => $feature($json['scenarios'])])]);

    $plan = plannerFor($provider)->planFeature($target, $config, "## HTTP context\nRoute: POST /api/checkout");

    expect(array_map(fn ($s) => $s->module, $plan->scenarios))->toBe(['feature', 'feature', 'feature', 'feature'])
        ->and($provider->requests[0]->system)->toContain('HTTP feature-test scenarios')
        ->and($provider->requests[0]->messages[0]['content'])->toContain('Route: POST /api/checkout')->toContain('Enabled modules: feature');
});

it('rejects unit scenarios in a feature plan', function () {
    $target = scanFixtures()->find(INVOICE);
    $config = (new ConfigurationResolver(new PolicyLimits(), ['feature' => true]))->resolve($target);
    $provider = new FakeProvider([planJson($target->key), planJson($target->key)]);

    expect(fn () => plannerFor($provider)->planFeature($target, $config, ''))->toThrow(InvalidAiResponseException::class);
});
