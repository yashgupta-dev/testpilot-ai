// Sample data used only when the dashboard runs outside Laravel (vite dev / preview / screenshots).
// It mirrors the real API's response shapes exactly.
import { featureCode, featureContext, featureScenarios, featureSource } from './mock-feature.js'
import { rateLimitCode, rateLimitContext, rateLimitScenarios } from './mock-ratelimit.js'
import { bindingContext, bindingScenarios, inlineContext, inlineScenarios, validationCaseCount, validationCode, validationContext, validationResults, validationScenarios } from './mock-validation.js'

const now = Date.now()
const iso = (minutesAgo) => new Date(now - minutesAgo * 60_000).toISOString()

const invoiceSource = `    /**
     * Total of all line items after discount and tax.
     */
    #[Fuzz(cases: 50, seed: 48213)]
    public function calculateTotal(array $items, float $discount = 0.0, string $region = 'IN'): float
    {
        if ($discount < 0 || $discount > 100) {
            throw new InvalidArgumentException('Invalid discount');
        }

        $subtotal = 0.0;
        foreach ($items as $item) {
            if (($item['quantity'] ?? 0) < 0) {
                throw new InvalidArgumentException('Negative quantity');
            }
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 0);
        }

        $discounted = $subtotal * (1 - $discount / 100);

        return round($discounted * (1 + $this->taxes->rateFor($region)), 2);
    }`

const invoiceCode = `<?php

declare(strict_types=1);

/**
 * @testpilot-generated
 *
 * target:        App\\Services\\InvoiceService::calculateTotal
 * source-hash:   sha256:9f2c41d0…
 * model:         claude-sonnet-5-5
 * prompt:        plan@v1, generate@v1
 * fuzz-seed:     48213
 * status:        pending-review
 */

use App\\Contracts\\TaxRepository;
use App\\Services\\InvoiceService;

uses(\\Tests\\TestCase::class);
mutates(InvoiceService::class);

beforeEach(function () {
    $this->taxes = Mockery::mock(TaxRepository::class);
    $this->taxes->allows('rateFor')->andReturn(0.18);
    $this->service = new InvoiceService($this->taxes);
});

it('[S1] calculates total for valid line items', function () {
    $total = $this->service->calculateTotal([['price' => 50, 'quantity' => 2]]);

    expect($total)->toBe(118.0);
});

it('[S2] applies percentage discount before tax', function () {
    expect($this->service->calculateTotal([['price' => 100, 'quantity' => 1]], 10))->toBe(106.2);
});

it('[S3] throws for a negative quantity', function () {
    // assumption: line 49 throws InvalidArgumentException when quantity < 0
    expect(fn () => $this->service->calculateTotal([['price' => 10, 'quantity' => -1]]))
        ->toThrow(InvalidArgumentException::class);
});

it('[S4] rejects a discount above 100', function () {
    expect(fn () => $this->service->calculateTotal([], 101))->toThrow(InvalidArgumentException::class);
});

it('[S5] never returns a negative total', function (array $items, float $discount) {
    expect($this->service->calculateTotal($items, $discount))->toBeGreaterThanOrEqual(0);
})->with('fuzz_seed_48213');
`

const scenarios = [
  { id: 'S1', name: 'Calculates total for valid line items', type: 'positive', module: 'unit', expected: '118.0 with 18% tax', basis: 'confirmed', basis_source: 'line 57: return round(…)', confidence: 0.95, priority: 'high', selected: true },
  { id: 'S2', name: 'Applies percentage discount before tax', type: 'positive', module: 'unit', expected: '106.2', basis: 'confirmed', basis_source: 'line 55: discount applied to subtotal', confidence: 0.9, priority: 'high', selected: true },
  { id: 'S3', name: 'Throws for a negative quantity', type: 'negative', module: 'unit', expected: 'InvalidArgumentException', basis: 'confirmed', basis_source: 'line 49: throw new InvalidArgumentException', confidence: 0.92, priority: 'high', selected: true },
  { id: 'S4', name: 'Rejects a discount above 100', type: 'edge', module: 'unit', expected: 'InvalidArgumentException', basis: 'confirmed', basis_source: 'line 44: $discount > 100', confidence: 0.93, priority: 'medium', selected: true },
  { id: 'S5', name: 'Never returns a negative total (50 fuzz cases)', type: 'fuzz', module: 'fuzz', expected: 'total ≥ 0', basis: 'assumption', basis_source: 'business intent, not in source', confidence: 0.74, priority: 'medium', selected: true },
  { id: 'S6', name: 'Handles currency mismatch between items', type: 'edge', module: 'unit', expected: 'unclear', basis: 'assumption', basis_source: 'no currency handling in source', confidence: 0.41, priority: 'low', selected: false },
]

const verification = {
  status: 'verified',
  repair_attempts: 1,
  fuzz_seed: 48213,
  model: 'claude-sonnet-5-5',
  stages: [
    { stage: 'generate', status: 'passed', summary: '5 approved scenarios sent to claude-sonnet-5-5', data: {} },
    { stage: 'repair', status: 'warning', summary: 'Repair attempt 1', data: { failure: '✗ it [S2] applies percentage discount before tax\nFailed asserting that 106.19999 is identical to 106.2.' } },
    { stage: 'safety', status: 'passed', summary: 'No forbidden constructs found', data: {} },
    { stage: 'run', status: 'passed', summary: '5 passed, 0 failed, 54 assertions in 420 ms', data: { command: 'php vendor/bin/pest storage/testpilot/pending/Unit/InvoiceServiceCalculateTotalTest.php --colors=never --log-junit <junit.xml>' } },
    { stage: 'stability', status: 'passed', summary: '3/3 runs identical', data: {} },
    { stage: 'mutation', status: 'passed', summary: 'Mutation score 78.0% (gate 60%)', data: { score: 78, tested: 18, caught: 14, survived: 4, survivors: [{ file: 'app/Services/InvoiceService.php', line: 62, description: 'Removed round()' }] } },
  ],
  run: { passed: true, counts: { passed: 5, failed: 0, skipped: 0 }, assertions: 54, duration_ms: 420, command: 'php vendor/bin/pest …' },
  mutation: { score: 78, tested: 18, caught: 14, survived: 4, survivors: [{ file: 'app/Services/InvoiceService.php', line: 62, description: 'Removed round()' }], parsed: true },
  suspected_bugs: {},
  scenario_results: { S1: 'passed', S2: 'passed', S3: 'passed', S4: 'passed', S5: 'passed' },
}

function target(id, key, name, extra) {
  return {
    id, key, name, type: 'method', file: 'app/Services/X.php', line: 10, area: null, criticality: null,
    modules: ['unit'], status: 'not_planned', enabled: true, complexity: 3,
    risk: { score: 30, level: 'low' }, route: null, last_result: null, last_run_at: null, mutation_score: null, updated_at: iso(2),
    ...extra,
  }
}

const db = {
  targets: [
    target('t1', 'App\\Services\\InvoiceService::calculateTotal', 'InvoiceService::calculateTotal', { file: 'app/Services/InvoiceService.php', line: 42, area: 'Billing', criticality: 'high', modules: ['unit', 'fuzz'], status: 'awaiting_approval', complexity: 8, risk: { score: 92, level: 'high' }, mutation_score: 78 }),
    target('t2', 'App\\Http\\Controllers\\AuthController::login', 'AuthController::login', { type: 'route', file: 'app/Http/Controllers/AuthController.php', line: 31, area: 'Auth', modules: ['unit', 'validation', 'ratelimit'], status: 'approved', risk: { score: 88, level: 'high' }, route: { methods: ['POST'], uri: '/api/login', throttle: 'throttle:5,1', middleware: ['api', 'throttle:5,1'] }, last_result: 'passed', last_run_at: iso(48), mutation_score: 72 }),
    target('t3', 'App\\Services\\PaymentService::refund', 'PaymentService::refund', { file: 'app/Services/PaymentService.php', line: 77, area: 'Payments', criticality: 'high', modules: ['unit', 'fuzz'], status: 'approved', complexity: 9, risk: { score: 85, level: 'high' }, last_result: 'failed', last_run_at: iso(48), mutation_score: 64 }),
    target('t4', 'App\\Services\\SubscriptionService::renew', 'SubscriptionService::renew', { file: 'app/Services/SubscriptionService.php', line: 58, area: 'Subscriptions', complexity: 11, risk: { score: 81, level: 'high' } }),
    target('t5', 'App\\Http\\Controllers\\OrderController::store', 'OrderController::store', { type: 'route', file: 'app/Http/Controllers/OrderController.php', line: 22, area: 'Checkout', modules: ['unit', 'validation'], status: 'awaiting_approval', risk: { score: 74, level: 'high' }, route: { methods: ['POST'], uri: '/api/orders', throttle: 'throttle:30,1', middleware: ['api', 'auth:sanctum', 'throttle:30,1'] }, last_result: 'passed', last_run_at: iso(48) }),
    target('t6', 'App\\Services\\TaxCalculator::rateFor', 'TaxCalculator::rateFor', { file: 'app/Services/TaxCalculator.php', line: 19, area: 'Billing', status: 'plan_ready', complexity: 5, risk: { score: 63, level: 'medium' } }),
    target('t7', 'App\\Actions\\CreateUser::handle', 'CreateUser::handle', { file: 'app/Actions/CreateUser.php', line: 15, area: 'Users', status: 'approved', risk: { score: 48, level: 'medium' }, last_result: 'passed', last_run_at: iso(48), mutation_score: 38 }),
    target('t8', 'App\\Support\\money_round()', 'money_round()', { type: 'function', file: 'app/Support/helpers.php', line: 8, area: 'Billing', modules: ['unit', 'fuzz'], risk: { score: 42, level: 'medium' } }),
    target('t9', 'App\\Http\\Controllers\\ReportController::index', 'ReportController::index', { type: 'route', file: 'app/Http/Controllers/ReportController.php', line: 14, area: 'Reports', status: 'approved', risk: { score: 22, level: 'low' }, route: { methods: ['GET'], uri: '/api/reports', throttle: 'throttle:reports' }, last_result: 'passed', last_run_at: iso(48), mutation_score: 81 }),
    target('t10', 'App\\Http\\Controllers\\CustomerOrderController::update', 'CustomerOrderController::update', { type: 'route', file: 'app/Http/Controllers/CustomerOrderController.php', line: 27, area: 'Orders', modules: ['validation'], status: 'plan_ready', risk: { score: 58, level: 'medium' }, route: { methods: ['PATCH'], uri: '/api/customers/{customer}/orders/{order}', throttle: null } }),
    target('t11', 'App\\Http\\Controllers\\CouponController::redeem', 'CouponController::redeem', { type: 'route', file: 'app/Http/Controllers/CouponController.php', line: 41, area: 'Checkout', modules: ['validation'], status: 'plan_ready', risk: { score: 66, level: 'medium' }, route: { methods: ['POST'], uri: '/api/coupons/redeem', throttle: null } }),
    target('t12', 'App\\Http\\Controllers\\CheckoutController::store', 'CheckoutController::store', { type: 'route', file: 'app/Http/Controllers/CheckoutController.php', line: 23, area: 'Checkout', criticality: 'high', modules: ['feature'], status: 'awaiting_approval', complexity: 4, risk: { score: 86, level: 'high' }, route: { methods: ['POST'], uri: '/api/checkout', throttle: null } }),
  ],
  runs: [
    { id: 'r482', number: 482, status: 'failed', outcome: { code: 'application_failure', title: 'Some expected behavior did not pass', summary: '4 tests passed, but 2 did not match their expected result.', next_step: 'Ask a developer or QA reviewer to inspect the failed scenario and its technical details below.', tone: 'red' }, trigger: 'dashboard', git_sha: 'a1b2c3d4e5', passed: 4, failed: 2, skipped: 0, assertions: 9, duration_ms: 192_000, started_by: 'yash@example.com', created_at: iso(48), finished_at: iso(45) },
    { id: 'r481', number: 481, status: 'completed', outcome: { code: 'passed', title: 'All checked tests passed', summary: '4 tests passed and 7 checks were made.', next_step: 'No action needed.', tone: 'green' }, trigger: 'ci', git_sha: '9f8e7d6c5b', passed: 4, failed: 0, skipped: 0, assertions: 7, duration_ms: 185_000, started_by: 'ci', created_at: iso(300), finished_at: iso(297) },
    { id: 'r480', number: 480, status: 'completed', outcome: { code: 'passed', title: 'All checked tests passed', summary: '3 tests passed and 5 checks were made.', next_step: 'No action needed.', tone: 'green' }, trigger: 'cli', git_sha: '9f8e7d6c5b', passed: 3, failed: 0, skipped: 0, assertions: 5, duration_ms: 171_000, started_by: 'cli:yash', created_at: iso(1500), finished_at: iso(1497) },
  ],
}

const validationVerification = {
  status: 'suspected_bug',
  repair_attempts: 0,
  fuzz_seed: null,
  model: 'deterministic',
  stages: [
    { stage: 'generate', status: 'passed', summary: `${validationCaseCount} validation cases built from StoreOrderRequest::rules() (no AI)`, data: { model: 'deterministic' } },
    { stage: 'safety', status: 'passed', summary: 'No forbidden constructs found', data: {} },
    { stage: 'run', status: 'failed', summary: `${validationCaseCount - 1} passed, 1 failed, ${validationCaseCount * 2 - 1} assertions in 1.1 s`, data: { command: 'php vendor/bin/pest storage/testpilot/pending/Validation/OrderControllerStoreTest.php --colors=never --log-junit <junit.xml>', failure: '✗ it [S4] email · max:100 — rejects 101 characters (max 100)\nExpected response status code [422] but received 201.' } },
    { stage: 'mutation', status: 'skipped', summary: 'Not applicable to validation tests', data: {} },
  ],
  run: { passed: false, counts: { passed: validationCaseCount - 1, failed: 1, skipped: 0 }, assertions: validationCaseCount * 2 - 1, duration_ms: 1100, command: 'php vendor/bin/pest …' },
  mutation: null,
  suspected_bugs: { S4: 'Declared in the source but not enforced: Expected response status code [422] but received 201.' },
  scenario_results: validationResults,
}

const validationPlan = {
  id: 'p5v', module: 'validation', status: 'approved', scenarios: validationScenarios, context: validationContext, model: 'deterministic', prompt_version: 'validation@v1',
  notes: 'Built from StoreOrderRequest::rules() without AI. 3 cases need a human: a unique rule, a regex and a closure rule.', approved_by: 'yash@example.com', approved_at: iso(12), error: null, created_at: iso(14),
}

const rateLimitResults = Object.fromEntries(rateLimitScenarios.filter((s) => s.selected).map((s) => [s.id, 'passed']))
const rateLimitTest = {
  id: 'g2r', target_id: 't2', module: 'ratelimit', status: 'approved', code: rateLimitCode,
  pending_path: null, approved_path: 'tests/TestPilot/Generated/RateLimit/AuthControllerLoginTest.php', mutation_score: null, fuzz_seed: null,
  model: 'deterministic', repair_attempts: 0, scenarios: rateLimitScenarios, created_at: iso(70),
  verification: {
    status: 'verified', repair_attempts: 0, fuzz_seed: null, model: 'deterministic', mutation: null, suspected_bugs: {}, scenario_results: rateLimitResults,
    stages: [
      { stage: 'generate', status: 'passed', summary: '6 rate-limit cases for 5 requests per 60s (no AI)', data: { model: 'deterministic' } },
      { stage: 'safety', status: 'passed', summary: 'No forbidden constructs found', data: {} },
      { stage: 'run', status: 'passed', summary: '6 passed, 0 failed, 18 assertions in 310 ms', data: { command: 'php vendor/bin/pest storage/testpilot/pending/RateLimit/AuthControllerLoginTest.php --colors=never --log-junit <junit.xml>' } },
      { stage: 'stability', status: 'passed', summary: '2/2 runs identical', data: {} },
      { stage: 'mutation', status: 'skipped', summary: 'Not applicable to ratelimit tests', data: {} },
    ],
    run: { passed: true, counts: { passed: 6, failed: 0, skipped: 0 }, assertions: 18, duration_ms: 310, command: 'php vendor/bin/pest …' },
  },
}

const featureResults = { S1: 'passed', S2: 'passed', S3: 'passed', S4: 'passed' }
const details = {
  t12: {
    signature: 'public function store(Request $request): JsonResponse',
    source: featureSource,
    annotations: { class: [{ name: 'TestPilot', arguments: { area: 'Checkout', criticality: 'high' }, line: 18, scope: 'class' }], method: [{ name: 'FeatureTest', arguments: { focus: ['coupons', 'stock'] }, line: 22, scope: 'method' }] },
    modulePlans: {
      feature: {
        plan: { id: 'p12f', module: 'feature', status: 'approved', scenarios: featureScenarios, context: featureContext, model: 'claude-sonnet-5-5', prompt_version: 'feature@v1', notes: 'Detailed validation rules are covered by the validation module.', approved_by: 'yash@example.com', approved_at: iso(20), error: null, created_at: iso(24) },
        test: {
          id: 'g12f', target_id: 't12', module: 'feature', status: 'verified', code: featureCode, pending_path: 'storage/testpilot/pending/Feature/CheckoutControllerStoreTest.php', approved_path: null,
          mutation_score: null, fuzz_seed: null, model: 'claude-sonnet-5-5', repair_attempts: 0, scenarios: featureScenarios, created_at: iso(18),
          verification: {
            status: 'verified', repair_attempts: 0, fuzz_seed: null, model: 'claude-sonnet-5-5', mutation: null, suspected_bugs: {}, scenario_results: featureResults,
            stages: [
              { stage: 'generate', status: 'passed', summary: '4 approved HTTP scenarios sent to claude-sonnet-5-5', data: { model: 'claude-sonnet-5-5' } },
              { stage: 'safety', status: 'passed', summary: 'No forbidden constructs found', data: {} },
              { stage: 'run', status: 'passed', summary: '4 passed, 0 failed, 14 assertions in 610 ms', data: { command: 'php vendor/bin/pest storage/testpilot/pending/Feature/CheckoutControllerStoreTest.php --colors=never --log-junit <junit.xml>' } },
              { stage: 'stability', status: 'passed', summary: '2/2 runs identical', data: {} },
              { stage: 'mutation', status: 'skipped', summary: 'Not applicable to feature tests', data: {} },
            ],
            run: { passed: true, counts: { passed: 4, failed: 0, skipped: 0 }, assertions: 14, duration_ms: 610, command: 'php vendor/bin/pest …' },
          },
        },
      },
    },
  },
  t11: {
    signature: 'public function redeem(Request $request): JsonResponse',
    annotations: { class: [{ name: 'TestPilot', arguments: { area: 'Checkout' }, line: 15, scope: 'class' }], method: [{ name: 'Validation', arguments: {}, line: 40, scope: 'method' }] },
    modulePlans: {
      validation: {
        plan: { id: 'p11v', module: 'validation', status: 'proposed', scenarios: inlineScenarios, context: inlineContext, model: null, prompt_version: 'validation@v1', notes: `${inlineScenarios.filter((s) => s.selected).length} cases from 2 fields in ${inlineContext.rules_origin}.`, approved_by: null, approved_at: null, error: null, created_at: iso(2) },
        test: null,
      },
    },
  },
  t10: {
    signature: 'public function update(UpdateOrderRequest $request, Customer $customer, Order $order): JsonResponse',
    annotations: { class: [{ name: 'TestPilot', arguments: { area: 'Orders' }, line: 14, scope: 'class' }], method: [{ name: 'Validation', arguments: {}, line: 26, scope: 'method' }] },
    modulePlans: {
      validation: {
        plan: { id: 'p10v', module: 'validation', status: 'proposed', scenarios: bindingScenarios, context: bindingContext, model: null, prompt_version: 'validation@v1', notes: `${bindingScenarios.filter((s) => s.selected).length} cases from 2 fields in UpdateOrderRequest::rules().`, approved_by: null, approved_at: null, error: null, created_at: iso(3) },
        test: null,
      },
    },
  },
  t2: {
    signature: 'public function login(LoginRequest $request): JsonResponse',
    annotations: { class: [{ name: 'TestPilot', arguments: { area: 'Auth' }, line: 12, scope: 'class' }], method: [{ name: 'Validation', arguments: {}, line: 29, scope: 'method' }, { name: 'RateLimit', arguments: { maxAttempts: 5, decaySeconds: 60, lockout: true }, line: 30, scope: 'method' }] },
    modulePlans: {
      ratelimit: {
        plan: { id: 'p2r', module: 'ratelimit', status: 'approved', scenarios: rateLimitScenarios, context: rateLimitContext, model: null, prompt_version: 'ratelimit@v1', notes: '6 cases for 5 requests per 1 minute (from #[RateLimit(maxAttempts: 5, decaySeconds: 60)] on AuthController::login). Requests go through the real middleware; time is moved with travel(), never slept.', approved_by: 'yash@example.com', approved_at: iso(75), error: null, created_at: iso(80) },
        test: rateLimitTest,
      },
    },
  },
  t5: {
    signature: 'public function store(StoreOrderRequest $request): JsonResponse',
    annotations: { class: [{ name: 'TestPilot', arguments: { area: 'Checkout' }, line: 14, scope: 'class' }], method: [{ name: 'Validation', arguments: {}, line: 21, scope: 'method' }] },
    modulePlans: {
      validation: {
        plan: validationPlan,
        test: { id: 'g5v', target_id: 't5', module: 'validation', status: 'suspected_bug', code: validationCode, pending_path: 'storage/testpilot/pending/Validation/OrderControllerStoreTest.php', approved_path: null, verification: validationVerification, mutation_score: null, fuzz_seed: null, model: 'deterministic', repair_attempts: 0, scenarios: validationScenarios, created_at: iso(10) },
      },
    },
  },
  t1: {
    signature: "public function calculateTotal(array $items, float $discount = 0.0, string $region = 'IN'): float",
    source: invoiceSource,
    end_line: 64,
    risk_reasons: [
      { label: 'Complexity 8', detail: 'Cyclomatic complexity is medium (8 paths through the code).', points: 21 },
      { label: 'No approved tests', detail: 'No TestPilot test covers this target yet.', points: 25 },
      { label: 'Changed 3× recently', detail: 'Modified 3 time(s) in the last 30 days (git history).', points: 15 },
      { label: 'Handles money', detail: 'Name or signature mentions "total".', points: 20 },
    ],
    annotations: {
      class: [{ name: 'TestPilot', arguments: { area: 'Billing', criticality: 'high' }, line: 18, scope: 'class' }, { name: 'UnitTest', arguments: { maxScenarios: 6 }, line: 19, scope: 'class' }],
      method: [{ name: 'Fuzz', arguments: { cases: 50, seed: 48213 }, line: 41, scope: 'method' }],
    },
    config: {
      enabled: true, area: 'Billing', criticality: 'high',
      modules: { unit: true, fuzz: true, validation: false, ratelimit: false, ai: true },
      trace: [
        { key: 'unit.max_scenarios', value: 8, source: 'project config' },
        { key: 'enabled', value: true, source: 'class #[TestPilot] · line 18' },
        { key: 'unit', value: true, source: 'class #[UnitTest] · line 19' },
        { key: 'unit.max_scenarios', value: 6, source: 'class #[UnitTest] · line 19' },
        { key: 'fuzz', value: true, source: 'method #[Fuzz] · line 41' },
        { key: 'fuzz.cases', value: 50, source: 'method #[Fuzz] · line 41' },
        { key: 'fuzz.seed', value: 48213, source: 'method #[Fuzz] · line 41' },
      ],
    },
    plan: { id: 'p1', status: 'approved', scenarios, notes: '', model: 'claude-sonnet-5-5', prompt_version: 'v1', approved_by: 'yash@example.com', approved_at: iso(30), error: null, created_at: iso(35) },
    test: { id: 'g1', target_id: 't1', status: 'verified', code: invoiceCode, pending_path: 'storage/testpilot/pending/Unit/InvoiceServiceCalculateTotalTest.php', approved_path: null, verification, mutation_score: 78, fuzz_seed: 48213, model: 'claude-sonnet-5-5', repair_attempts: 1, scenarios, created_at: iso(25) },
    history: [],
  },
  t6: {
    plan: {
      id: 'p6', status: 'proposed', model: 'claude-sonnet-5-5', prompt_version: 'v1', created_at: iso(5), notes: 'Rates are read from config; regions outside the table are an assumption.',
      scenarios: [
        { id: 'S1', name: 'Returns the configured rate for a known region', type: 'positive', module: 'unit', expected: '0.18 for IN', basis: 'confirmed', basis_source: 'line 22: $this->rates[$region]', confidence: 0.94, priority: 'high', selected: true },
        { id: 'S2', name: 'Region codes are case-insensitive', type: 'edge', module: 'unit', expected: "'in' → 0.18", basis: 'confirmed', basis_source: 'line 21: strtoupper($region)', confidence: 0.88, priority: 'medium', selected: true },
        { id: 'S3', name: 'Unknown region falls back to the default rate', type: 'negative', module: 'unit', expected: 'default 0.0', basis: 'assumption', basis_source: 'fallback is not visible in source', confidence: 0.55, priority: 'medium', selected: true },
        { id: 'S4', name: 'Empty region string', type: 'edge', module: 'unit', expected: 'unclear', basis: 'assumption', basis_source: 'not specified', confidence: 0.35, priority: 'low', selected: false },
      ],
    },
  },
}

function plannable(t) {
  return [t.modules.some((m) => ['unit', 'fuzz'].includes(m)) && 'unit', t.modules.includes('validation') && 'validation', t.modules.includes('ratelimit') && 'ratelimit', t.modules.includes('feature') && 'feature'].filter(Boolean)
}

function detailFor(t, wanted = null) {
  const available = plannable(t)
  const base = details[t.id] ?? {}
  const worked = available.find((m) => base.modulePlans?.[m]?.test) ?? available.find((m) => base.modulePlans?.[m]?.plan)
  const module = available.includes(wanted) ? wanted : (worked ?? available[0] ?? 'unit')
  const forModule = (m) => base.modulePlans?.[m] ?? (m === 'unit' ? { plan: base.plan ?? null, test: base.test ?? null } : { plan: null, test: null })
  const extra = { ...base, ...forModule(module) }
  return {
    module,
    plannable_modules: available,
    modules_summary: Object.fromEntries(available.map((m) => [m, { plan: forModule(m).plan?.status ?? null, test: forModule(m).test?.status ?? null }])),
    ...t,
    signature: extra.signature ?? `public function ${t.name.split('::')[1] ?? t.name}(): mixed`,
    source: extra.source ?? '    // source shown here',
    end_line: extra.end_line ?? t.line + 12,
    config: extra.config ?? { enabled: true, modules: {}, trace: [] },
    risk_reasons: extra.risk_reasons ?? [{ label: `Complexity ${t.complexity}`, detail: 'Cyclomatic complexity.', points: 12 }],
    route: t.route,
    annotations: extra.annotations ?? { class: [], method: [] },
    plan: extra.plan ?? null,
    test: extra.test ?? null,
    history: extra.history ?? [],
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

// Settings / passcode demo state. Add ?locked=1 to the URL to see the lock screen (passcode: testpilot).
const mockState = { passcode: true, locked: typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('locked') }
const field = (key, label, type, value, extra = {}) => ({ key, label, type, value, default: value, options: null, min: null, max: null, env: null, locked: false, source: 'default', updated_by: null, updated_at: null, set: null, hint: null, ...extra })
const settingsDb = {
  'ai.driver': field('ai.driver', 'Provider', 'enum', 'anthropic', { options: ['anthropic', 'openai', 'gemini', 'ollama', 'custom'], env: 'TESTPILOT_AI_DRIVER', locked: true, source: 'env' }),
  'ai.api_key': field('ai.api_key', 'API key', 'secret', null, { env: 'TESTPILOT_AI_API_KEY', set: true, hint: 'sk-ant-…9f3a', source: 'dashboard', updated_by: 'yash@example.com' }),
  'ai.model': field('ai.model', 'Default model', 'model', 'claude-sonnet-5-5', { env: 'TESTPILOT_AI_MODEL' }),
  'ai.base_url': field('ai.base_url', 'Base URL', 'url', null, { env: 'TESTPILOT_AI_BASE_URL' }),
  'ai.policy': field('ai.policy', 'Data policy', 'enum', 'cloud', { options: ['cloud', 'local', 'off'], env: 'TESTPILOT_AI_POLICY' }),
  'ai.models.plan': field('ai.models.plan', 'Plan', 'model', 'claude-sonnet-5-5', { env: 'TESTPILOT_AI_MODEL_PLAN' }),
  'ai.models.generate': field('ai.models.generate', 'Generate', 'model', 'claude-sonnet-5-5', { env: 'TESTPILOT_AI_MODEL_GENERATE' }),
  'ai.models.repair': field('ai.models.repair', 'Repair (first try)', 'model', 'claude-haiku-5-5', { env: 'TESTPILOT_AI_MODEL_REPAIR' }),
  'ai.models.repair_escalation': field('ai.models.repair_escalation', 'Repair (later tries)', 'model', 'claude-sonnet-5-5', { env: 'TESTPILOT_AI_MODEL_REPAIR_ESCALATION' }),
  'ai.models.critical': field('ai.models.critical', 'Critical targets', 'model', 'claude-opus-5-5', { env: 'TESTPILOT_AI_MODEL_CRITICAL', source: 'dashboard', updated_by: 'yash@example.com' }),
  'ai.prompt_cache': field('ai.prompt_cache', 'Prompt cache', 'enum', '1h', { options: ['5m', '1h', 'off'], env: 'TESTPILOT_AI_PROMPT_CACHE', source: 'dashboard', updated_by: 'yash@example.com' }),
  'ai.response_cache.enabled': field('ai.response_cache.enabled', 'Response cache', 'bool', true, { env: 'TESTPILOT_AI_RESPONSE_CACHE' }),
  'ai.response_cache.ttl_days': field('ai.response_cache.ttl_days', 'Keep cached answers (days)', 'int', 14, { env: 'TESTPILOT_AI_CACHE_DAYS', min: 1, max: 90 }),
  'ai.monthly_budget': field('ai.monthly_budget', 'Monthly budget (USD)', 'number', 50, { env: 'TESTPILOT_AI_MONTHLY_BUDGET', min: 0, max: 100000 }),
  'ai.target_monthly_budget': field('ai.target_monthly_budget', 'Per-target monthly budget (USD)', 'number', 5, { env: 'TESTPILOT_AI_TARGET_BUDGET', min: 0, max: 10000, source: 'dashboard', updated_by: 'yash@example.com' }),
  'ai.warn_at': field('ai.warn_at', 'Warn at (share of budget)', 'number', 0.8, { min: 0.1, max: 1 }),
  'dashboard.unlock_minutes': field('dashboard.unlock_minutes', 'Stay unlocked for (minutes)', 'int', 720, { env: 'TESTPILOT_DASHBOARD_UNLOCK_MINUTES', min: 5, max: 10080 }),
}
function mockSettings() {
  return {
    fields: JSON.parse(JSON.stringify(settingsDb)),
    passcode: { enabled: mockState.passcode, source: mockState.passcode ? 'dashboard' : null, updated_by: 'yash@example.com', updated_at: iso(60 * 26), unlock_minutes: 720, locked_by_env: false },
    editable: true,
    can_manage: true,
    access: { approver: true, environment: 'local' },
  }
}

export async function mockApi(path, { method, body }) {
  await sleep(120)
  const url = new URL(path, 'http://mock')
  const parts = url.pathname.split('/').filter(Boolean)

  if (path === '/overview') {
    const approved = db.targets.filter((t) => t.status === 'approved').length
    return {
      last_scan: { at: iso(2), targets: db.targets.length, files: 214, duration_ms: 812 },
      stats: { targets: 148, with_approved_tests: 112, coverage_of_intent: 76, pass_rate: 94.2, mutation_score: 68, awaiting_approval: 7, failing_targets: 2, stale: 3, _approved_sample: approved },
      trend: [86, 88, 87, 90, 91, 92, 93, 94].map((v, i) => ({ week: `W${i + 1}`, pass_rate: v, mutation: [52, 55, 57, 58, 61, 63, 66, 68][i], runs: 4 + i })),
      recent_runs: db.runs,
      ai: { spent: 18.4, budget: 50 },
      environment: await mockApi('/environment', { method: 'GET' }),
    }
  }

  if (path === '/environment') {
    return {
      app_env: 'local', tests_allowed: true, database: 'sqlite', queue: 'redis', queue_is_sync: false, ai_driver: 'anthropic', ai_model: 'claude-sonnet-5-5',
      ai_policy: 'cloud', ai_key_set: true, php: '8.4.6', laravel: '12.40.0', pest_installed: true, mutation_enabled: true, coverage_driver: 'pcov',
      readiness: {
        can_run_tests: true,
        can_plan_with_ai: true,
        checks: [
          { id: 'runtime', status: 'ready', title: 'Supported runtime', detail: 'PHP 8.4 · Laravel 12.40', action: 'This version can run TestPilot.', area: 'run' },
          { id: 'safe_environment', status: 'ready', title: 'Safe environment', detail: 'Current environment: local', action: 'Tests are allowed here.', area: 'run' },
          { id: 'pest', status: 'ready', title: 'Pest test runner', detail: 'The configured Pest executable was found.', action: 'Approved tests can be executed.', area: 'run' },
          { id: 'ai', status: 'ready', title: 'AI-assisted test planning', detail: 'Provider: anthropic', action: 'New scenarios can be planned with AI.', area: 'planning' },
          { id: 'queue', status: 'ready', title: 'Background processing', detail: 'Queue connection: redis', action: 'Make sure a worker is running for the configured queue.', area: 'recommended' },
          { id: 'database', status: 'check_when_running', title: 'Test database and migrations', detail: 'The test database is opened by the project test suite when tests run.', action: 'If a run reports a missing table or connection, fix the test database configuration or migrations and try again.', area: 'run' },
          { id: 'mutation', status: 'ready', title: 'Mutation testing (optional)', detail: 'A coverage extension is available.', action: 'TestPilot can measure whether tests catch injected defects.', area: 'optional' },
        ],
      },
      limits: { max_fuzz_cases: 200, max_scenarios: 20, max_rate_limit_attempts: 500, max_repair_attempts: 3, process_timeout: 300, ai_allowed: true, blocked_environments: ['production', 'prod'] },
      paths: ['app'], generated_path: 'tests/TestPilot/Generated',
    }
  }

  if (path === '/scan' && method === 'POST') {
    return { targets: db.targets.length, added: 0, changed: 1, removed: 0, stale: 1, warnings: [], errors: [], files: 214, duration_ms: 812 }
  }

  if (parts[0] === 'targets' && parts.length === 1) {
    const status = url.searchParams.get('status')
    const search = (url.searchParams.get('search') ?? '').toLowerCase()
    let data = [...db.targets].sort((a, b) => b.risk.score - a.risk.score)
    if (status) data = data.filter((t) => (status === 'failing' ? t.last_result === 'failed' : t.status === status))
    if (search) data = data.filter((t) => t.key.toLowerCase().includes(search) || t.file.includes(search))
    const counts = { all: db.targets.length, failing: db.targets.filter((t) => t.last_result === 'failed').length }
    for (const t of db.targets) counts[t.status] = (counts[t.status] ?? 0) + 1
    return { data, counts, areas: [...new Set(db.targets.map((t) => t.area).filter(Boolean))].sort() }
  }

  if (parts[0] === 'targets' && parts.length === 2) {
    return detailFor(db.targets.find((t) => t.id === parts[1]), url.searchParams.get('module'))
  }

  if (parts[0] === 'targets' && parts[2] === 'plan' && ['validation', 'ratelimit'].includes(body?.module)) {
    const t = db.targets.find((x) => x.id === parts[1])
    const d = (details[t.id] ??= {})
    const source = body.module === 'validation' ? validationPlan : details.t2.modulePlans.ratelimit.plan
    d.modulePlans = { ...(d.modulePlans ?? {}), [body.module]: { plan: { ...source, id: `p${t.id}${body.module[0]}`, status: 'proposed', approved_by: null, approved_at: null }, test: null } }
    t.status = 'plan_ready'
    return detailFor(t, body.module)
  }

  if (parts[0] === 'targets' && parts[2] === 'plan') {
    const t = db.targets.find((x) => x.id === parts[1])
    t.status = 'planning'
    setTimeout(() => { t.status = 'plan_ready'; details[t.id] = { ...(details[t.id] ?? {}), plan: details.t6.plan } }, 2500)
    return detailFor(t)
  }

  if (parts[0] === 'targets' && parts[4] === 'approve') {
    const t = db.targets.find((x) => x.id === parts[1])
    for (const [m, sample, done] of [['validation', details.t5.modulePlans.validation.test, 'suspected_bug'], ['ratelimit', rateLimitTest, 'verified']]) {
      const v = details[t.id]?.modulePlans?.[m]
      if (v && v.plan.id === parts[3]) {
        v.plan = { ...v.plan, status: 'approved', approved_by: 'yash@example.com', approved_at: new Date().toISOString() }
        v.test = { ...sample, target_id: t.id, status: 'generating', approved_path: null }
        t.status = 'generating'
        setTimeout(() => { t.status = 'awaiting_approval'; v.test = { ...v.test, status: done } }, 2500)
        return detailFor(t, m)
      }
    }
    t.status = 'generating'
    setTimeout(() => { t.status = 'awaiting_approval' }, 3000)
    return detailFor(t)
  }

  if (path === '/review') {
    return { data: [{ ...details.t1.test, target: db.targets[0] }] }
  }

  if (parts[0] === 'tests') {
    const approve = parts[2] === 'approve'
    db.targets[0].status = approve ? 'approved' : 'not_planned'
    details.t1.test.status = approve ? 'approved' : 'rejected'
    return { status: details.t1.test.status }
  }

  if (path === '/runs' && method === 'GET') return { data: db.runs }

  if (path === '/runs' && method === 'POST') {
    const run = { ...db.runs[0], id: 'r483', number: 483, status: 'running', outcome: { code: 'in_progress', title: 'Tests are running', summary: 'TestPilot is checking the approved scenarios now.', next_step: 'Keep this page open; it updates when the run finishes.', tone: 'blue' }, created_at: new Date().toISOString(), finished_at: null, passed: 0, failed: 0, assertions: 0, duration_ms: 0 }
    db.runs.unshift(run)
    setTimeout(() => Object.assign(run, { status: 'completed', outcome: { code: 'passed', title: 'All checked tests passed', summary: '4 tests passed and 7 checks were made.', next_step: 'No action needed.', tone: 'green' }, passed: 4, failed: 0, assertions: 7, duration_ms: 3000, finished_at: new Date().toISOString() }), 3000)
    return { ...run, results: [] }
  }

  if (parts[0] === 'runs' && parts.length === 2) {
    const run = db.runs.find((r) => r.id === parts[1]) ?? db.runs[0]
    const sampleFailures = [
      { id: 'x1', target_id: 't3', target: 'PaymentService::refund', scenario_id: 'S3', name: 'it [S3] refunds a discounted order in full', status: 'failed', time_ms: 18, assertions: 1, message: 'Failed asserting that -10.0 is greater than or equal to 0.', details: 'Failed asserting that -10.0 is greater than or equal to 0.\nat tests/TestPilot/Generated/Unit/PaymentServiceRefundTest.php:58' },
      { id: 'x2', target_id: 't5', target: 'OrderController::store', scenario_id: 'S5', name: 'it [S5] rejects an unknown coupon', status: 'failed', time_ms: 44, assertions: 1, message: 'Expected response status code [422] but received 201.', details: null },
      { id: 'x3', target_id: 't1', target: 'InvoiceService::calculateTotal', scenario_id: 'S1', name: 'it [S1] calculates total for valid line items', status: 'passed', time_ms: 9, assertions: 1, message: null, details: null },
      { id: 'x4', target_id: 't1', target: 'InvoiceService::calculateTotal', scenario_id: 'S2', name: 'it [S2] applies percentage discount before tax', status: 'passed', time_ms: 6, assertions: 1, message: null, details: null },
      { id: 'x5', target_id: 't2', target: 'AuthController::login', scenario_id: 'S1', name: 'it [S1] logs in with valid credentials', status: 'passed', time_ms: 61, assertions: 3, message: null, details: null },
      { id: 'x6', target_id: 't7', target: 'CreateUser::handle', scenario_id: 'S2', name: 'it [S2] rejects a duplicate email', status: 'passed', time_ms: 33, assertions: 2, message: null, details: null },
    ]
    const samplePasses = run.passed === 3 ? sampleFailures.slice(2, 5) : sampleFailures.slice(2, 6)
    const results = ['queued', 'running'].includes(run.status) ? [] : run.failed ? sampleFailures : samplePasses
    const output = run.status === 'running'
      ? 'TestPilot is waiting for the approved tests to finish.'
      : run.failed
        ? '  FAIL  Tests\\TestPilot\\Generated\\Unit\\PaymentServiceRefundTest\n  FAIL  Tests\\TestPilot\\Generated\\Feature\\OrderControllerStoreTest\n\n  Tests:    2 failed, 4 passed (9 assertions)\n  Duration: 3.12s'
        : `  PASS  Tests\\TestPilot\\Generated\\Unit\\InvoiceServiceCalculateTotalTest\n  PASS  Tests\\TestPilot\\Generated\\Feature\\AuthControllerLoginTest\n\n  Tests:    ${run.passed} passed (${run.assertions} assertions)\n  Duration: ${(run.duration_ms / 1000).toFixed(2)}s`
    return {
      ...run,
      command: 'php vendor/bin/pest tests/TestPilot/Generated --colors=never --log-junit <junit.xml>',
      output,
      error: null,
      results,
    }
  }

  if (path === '/session') {
    return { locked: mockState.locked, passcode_enabled: mockState.passcode, can_manage_settings: true, user: 'yash@example.com' }
  }
  if (path === '/unlock' && method === 'POST') {
    if (body?.passcode !== 'testpilot') throw new Error('That passcode is not correct.')
    mockState.locked = false
    return { locked: false }
  }
  if (path === '/lock' && method === 'POST') { mockState.locked = mockState.passcode; return { locked: mockState.locked } }

  if (path === '/settings' && method === 'GET') return mockSettings()
  if (path === '/settings' && method === 'PUT') {
    const changed = []
    for (const [key, v] of Object.entries(body.values ?? {})) {
      const fld = settingsDb[key]
      if (!fld || fld.locked) throw new Error(`${fld?.label ?? key} is set in .env (${fld?.env}) and can only be changed there.`)
      if (fld.type === 'secret') { if (v) { fld.set = true; fld.hint = `${String(v).slice(0, 7)}…${String(v).slice(-4)}`; fld.source = 'dashboard'; fld.updated_by = 'yash@example.com'; changed.push(key) } continue }
      if (v === '__default') { fld.value = fld.default; fld.source = 'default'; fld.updated_by = null } else { fld.value = v; fld.source = 'dashboard'; fld.updated_by = 'yash@example.com' }
      changed.push(key)
    }
    return { changed, fields: mockSettings().fields }
  }
  if (path === '/settings/passcode' && method === 'PUT') {
    if (mockState.passcode && body.current !== 'testpilot') throw new Error('The current passcode is not correct.')
    mockState.passcode = !body.remove
    return { passcode: mockSettings().passcode }
  }
  if (path === '/settings/ai/test' && method === 'POST') {
    await sleep(600)
    return { ok: true, model: settingsDb['ai.model'].value || 'claude-sonnet-5-5', reply: 'OK', latency_ms: 642 }
  }

  if (url.pathname === '/ai/usage') {
    const days = Number(url.searchParams.get('days') ?? 30)
    const daily = Array.from({ length: days }, (_, i) => {
      const d = new Date(now - (days - 1 - i) * 86_400_000)
      const busy = [1, 2, 3, 4, 5].includes(d.getDay())
      const cost = busy ? 0.35 + ((i * 37) % 23) / 40 : 0.05
      return { day: d.toISOString().slice(0, 10), cost: Number(cost.toFixed(4)), saved: Number((cost * (1.6 + ((i * 13) % 7) / 10)).toFixed(4)) }
    })
    const cost = daily.reduce((a, d) => a + d.cost, 0)
    const saved = daily.reduce((a, d) => a + d.saved, 0)
    const r2 = (v) => Number(v.toFixed(2))
    return {
      days,
      month: { spent: 18.4, budget: 50, used_ratio: 0.368, warn_at: 0.8, month_to_date_calls: 412 },
      totals: {
        calls: Math.round(days * 14.2), errors: 2, cost: r2(cost),
        saved: { prompt_cache: r2(saved * 0.62), response_cache: r2(saved * 0.14), batch: r2(saved * 0.24), total: r2(saved) },
        tokens: { input: 1_840_000, cache_write: 920_000, cache_read: 6_310_000, output: 1_020_000 }, cache_read_share: 69.6, response_cache_hits: 38,
      },
      by_purpose: [
        { name: 'generate', calls: 132, cost: r2(cost * 0.48), saved: r2(saved * 0.41), cache_read_share: 61.2 },
        { name: 'plan', calls: 158, cost: r2(cost * 0.37), saved: r2(saved * 0.38), cache_read_share: 58.4 },
        { name: 'repair', calls: 136, cost: r2(cost * 0.15), saved: r2(saved * 0.21), cache_read_share: 88.9 },
      ],
      by_model: [
        { name: 'claude-sonnet-5-5', calls: 312, cost: r2(cost * 0.91), saved: r2(saved * 0.83), cache_read_share: 66.1 },
        { name: 'claude-haiku-5-5', calls: 102, cost: r2(cost * 0.03), saved: r2(saved * 0.04), cache_read_share: 90.3 },
        { name: 'claude-opus-5-5', calls: 12, cost: r2(cost * 0.06), saved: r2(saved * 0.13), cache_read_share: 52.0 },
      ],
      daily,
      top_targets: [
        { target: 'App\\Http\\Controllers\\CheckoutController::store', calls: 19, cost: 1.42 },
        { target: 'App\\Services\\PaymentService::refund', calls: 14, cost: 1.08 },
        { target: 'App\\Services\\InvoiceService::calculateTotal', calls: 11, cost: 0.74 },
      ],
      routing: { plan: 'claude-sonnet-5-5', generate: 'claude-sonnet-5-5', repair: 'claude-haiku-5-5', repair_escalation: 'claude-sonnet-5-5', critical: 'claude-opus-5-5' },
      settings: { driver: 'anthropic', default_model: 'claude-sonnet-5-5', prompt_cache: '1h', response_cache: true, response_cache_days: 14, target_budget: 5, batch_available: true },
      batches: [
        { id: '01JD3Q9ZK8V6M2T4R7X1Y5N0B3', module: 'feature', status: 'in_progress', requests: 18, counts: { processing: 18 }, created_by: 'yash@example.com', created_at: iso(22), ended_at: null, error: null },
        { id: '01JCZ7W4H2Q8F5N1M9K3T6R0P8', module: 'unit', status: 'ended', requests: 64, counts: { succeeded: 63, errored: 1 }, created_by: 'yash@example.com', created_at: iso(1440 * 3), ended_at: iso(1440 * 3 - 41), error: null },
      ],
    }
  }

  if (path === '/ai/batches' && method === 'POST') {
    return { batch: { id: '01JD3RNEWBATCH0000000000000', status: 'in_progress', requests: 23 }, skipped: { 'App\\Support\\money_round()': 'no #[FeatureTest]' } }
  }

  if (parts[0] === 'ai' && parts[1] === 'batches' && parts[3] === 'poll') {
    return { ended: false, status: 'in_progress', counts: { processing: 18 } }
  }

  throw new Error(`Mock API has no handler for ${method} ${path}`)
}
