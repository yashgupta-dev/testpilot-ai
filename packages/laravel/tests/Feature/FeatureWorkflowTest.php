<?php

declare(strict_types=1);

use TestPilot\Laravel\Models\GeneratedTest;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\ScanService;

/**
 * AI-planned HTTP feature tests for a "fat controller" (validation, pricing, stock, persistence and a
 * queued job inside one action). The AI is faked; the generated file runs against the real route.
 */
function checkoutTarget(string $method = 'store'): Target
{
    return Target::query()->where('key_hash', hash('sha256', "Shop\\Http\\Controllers\\CheckoutController::{$method}"))->firstOrFail();
}

function checkoutPlanJson(): string
{
    $s = fn (string $id, string $name, string $type, string $inputs, string $expected, string $basis, string $source, float $confidence = 0.95) => [
        'id' => $id, 'name' => $name, 'type' => $type, 'module' => 'feature', 'description' => '', 'inputs' => $inputs,
        'expected' => $expected, 'basis' => $basis, 'basis_source' => $source, 'confidence' => $confidence, 'priority' => 'high',
    ];

    return json_encode([
        'schema_version' => '1',
        'target' => 'Shop\Http\Controllers\CheckoutController::store',
        'scenarios' => [
            $s('S1', 'Places an order and charges price × quantity', 'positive', 'product price 100, stock 5; POST {quantity: 2}', '201; total 200; order saved; stock 3; SendInvoice queued', 'confirmed', 'line 37: $total = $product->price * $data[\'quantity\']'),
            $s('S2', 'Applies the SAVE10 coupon', 'positive', 'price 100; POST {quantity: 2, coupon: "SAVE10"}', '201; total 180; order saved with coupon SAVE10', 'confirmed', 'line 38: SAVE10 → $total * 0.9'),
            $s('S3', 'Refuses an order larger than the stock', 'negative', 'stock 1; POST {quantity: 2}', '409 Out of stock; no order; nothing queued', 'confirmed', 'line 33: $product->stock < $data[\'quantity\']'),
            $s('S4', 'Ignores an unknown coupon', 'edge', 'price 100; POST {quantity: 1, coupon: "FREE"}', '201; total 100', 'confirmed', 'line 38: only SAVE10 changes the total'),
            $s('S5', 'Coupon codes are case-insensitive', 'edge', 'POST {coupon: "save10"}', 'total 90?', 'assumption', 'not in source: comparison is case-sensitive', 0.3),
        ],
        'notes' => 'Detailed validation rules are covered by the validation module.',
    ]);
}

function checkoutTestCode(): string
{
    return <<<'PHP'
    ```php
    <?php

    declare(strict_types=1);

    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Notification;
    use Illuminate\Support\Facades\Queue;
    use Shop\Jobs\SendInvoice;
    use Shop\Models\Product;

    uses(\Tests\TestCase::class);
    uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

    beforeEach(function () {
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Http::preventStrayRequests();
    });

    it('[S1] places an order and charges price × quantity', function () {
        $product = Product::factory()->create(['price' => 100, 'stock' => 5]);

        $response = $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 2]);

        $response->assertStatus(201);
        expect((float) $response->json('total'))->toBe(200.0);
        $this->assertDatabaseHas('orders', ['quantity' => 2, 'total' => 200, 'status' => 'placed']);
        expect($product->fresh()->stock)->toEqual(3);
        Queue::assertPushed(SendInvoice::class);
    });

    it('[S2] applies the SAVE10 coupon', function () {
        $product = Product::factory()->create(['price' => 100, 'stock' => 5]);

        $response = $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 2, 'coupon' => 'SAVE10']);

        $response->assertStatus(201);
        expect((float) $response->json('total'))->toBe(180.0);
        $this->assertDatabaseHas('orders', ['total' => 180, 'coupon' => 'SAVE10']);
    });

    it('[S3] refuses an order larger than the stock', function () {
        $product = Product::factory()->create(['price' => 100, 'stock' => 1]);

        $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 2])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Out of stock');

        $this->assertDatabaseCount('orders', 0);
        Queue::assertNothingPushed();
    });

    it('[S4] ignores an unknown coupon', function () {
        $product = Product::factory()->create(['price' => 100, 'stock' => 5]);

        $response = $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 1, 'coupon' => 'FREE']);

        $response->assertStatus(201);
        expect((float) $response->json('total'))->toBe(100.0);
    });
    ```
    PHP;
}

it('plans HTTP feature scenarios with route, factories, rules and fakes in the AI context', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    $this->ai->push(checkoutPlanJson());

    $target = checkoutTarget();
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'feature'])->assertStatus(202);
    $detail = $this->getJson("/testpilot/api/targets/{$target->id}?module=feature")->assertOk()->json();

    $prompt = $this->ai->requests[0]->messages[0]['content'] ?? '';
    expect($detail['plannable_modules'])->toBe(['feature'])
        ->and($detail['plan'])->toMatchArray(['module' => 'feature', 'status' => 'proposed', 'prompt_version' => 'feature@v1'])
        ->and($detail['plan']['context'])->toMatchArray(['method' => 'POST', 'uri' => '/api/checkout', 'authenticate' => false, 'fakes' => ['mail', 'notification', 'queue', 'http']])
        ->and(collect($detail['plan']['context']['models'])->pluck('class')->all())->toContain('Shop\Models\Product', 'Shop\Models\Order', 'Shop\Models\Customer')
        ->and(collect($detail['plan']['scenarios'])->pluck('basis')->all())->toBe(['confirmed', 'confirmed', 'confirmed', 'confirmed', 'assumption'])
        ->and($prompt)->toContain('Route: POST /api/checkout')
        ->and($prompt)->toContain('\Shop\Models\Product (table products, fillable: name, price, stock) — factory available')
        ->and($prompt)->toContain('Validation (CheckoutController::store() → $request->validate(); failures answer 422 with JSON errors)')
        ->and($prompt)->toContain('Queue::fake()')
        ->and($prompt)->toContain('Focus especially on: coupons, stock')
        ->and($this->ai->requests[0]->messages[0]['content'] ?? '')->toContain('Enabled modules: feature');
});

it('generates feature tests and verifies them against the real route', function () {
    $root = validationSandbox();
    app(ScanService::class)->scan();
    $this->ai->push(checkoutPlanJson());
    $this->ai->push(checkoutTestCode());

    $target = checkoutTarget();
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'feature'])->assertStatus(202);
    $plan = $this->getJson("/testpilot/api/targets/{$target->id}?module=feature")->json('plan');
    $this->postJson("/testpilot/api/targets/{$target->id}/plans/{$plan['id']}/approve", ['selected' => ['S1', 'S2', 'S3', 'S4']])->assertSuccessful();

    $test = GeneratedTest::query()->where('target_id', $target->id)->where('module', 'feature')->firstOrFail();
    expect($test->status)->toBe(GeneratedTest::VERIFIED, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->pending_path)->toBe('storage/testpilot/pending/Feature/CheckoutControllerStoreTest.php')
        ->and($test->verification['run']['counts'])->toMatchArray(['passed' => 4, 'failed' => 0])
        ->and($test->verification['scenario_results'])->toBe(['S1' => 'passed', 'S2' => 'passed', 'S3' => 'passed', 'S4' => 'passed'])
        ->and(collect($test->verification['stages'])->firstWhere('stage', 'mutation')['summary'])->toBe('Not applicable to feature tests')
        ->and($this->ai->requests[1]->messages[0]['content'] ?? '')->toContain('Route: POST /api/checkout');

    $this->postJson("/testpilot/api/tests/{$test->id}/approve")->assertOk();
    expect(is_file("{$root}/tests/TestPilot/Generated/Feature/CheckoutControllerStoreTest.php"))->toBeTrue();
});

it('accepts plain-English additions on feature plans', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    $this->ai->push(checkoutPlanJson());

    $target = checkoutTarget();
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'feature'])->assertStatus(202);
    $plan = $target->plans()->where('module', 'feature')->latest()->firstOrFail();

    app(\TestPilot\Laravel\Services\PlanService::class)->approve($plan, ['S1'], [], ['a second SAVE10 order still gets the discount']);

    expect(collect($plan->refresh()->scenarios)->last())->toMatchArray(['id' => 'S6', 'module' => 'feature', 'basis' => 'confirmed']);
});

it('explains that feature tests need a route', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $target = checkoutTarget('preview');
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'feature'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'No route calls CheckoutController::preview') && str_contains($m, '#[UnitTest]'));
});

it('flags a broken business rule as a suspected bug instead of weakening the test', function () {
    validationSandbox();
    config(['testpilot.pest.env' => ['TP_NO_COUPONS' => '1']]); // the child app silently stops applying SAVE10
    app(ScanService::class)->scan();

    $this->ai->push(checkoutPlanJson());
    $this->ai->push(checkoutTestCode());
    // The repair step keeps the confirmed expectation and marks the scenario, exactly as the repair prompt requires.
    $this->ai->push(str_replace(
        "it('[S2] applies the SAVE10 coupon'",
        "// @testpilot-suspected-bug S2: SAVE10 no longer reduces the total (line 36)\nit('[S2] applies the SAVE10 coupon'",
        checkoutTestCode(),
    ));

    $target = checkoutTarget();
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'feature'])->assertStatus(202);
    $plan = $this->getJson("/testpilot/api/targets/{$target->id}?module=feature")->json('plan');
    $this->postJson("/testpilot/api/targets/{$target->id}/plans/{$plan['id']}/approve", ['selected' => ['S1', 'S2', 'S3', 'S4']])->assertSuccessful();

    $test = GeneratedTest::query()->where('target_id', $target->id)->where('module', 'feature')->firstOrFail();
    expect($test->status)->toBe(GeneratedTest::SUSPECTED_BUG, (string) json_encode($test->verification['stages'] ?? $test->error))
        ->and($test->verification['suspected_bugs'])->toBe(['S2' => 'SAVE10 no longer reduces the total (line 36)'])
        ->and($test->code)->toContain('->toBe(180.0)') // the confirmed expectation was not weakened
        ->and($this->ai->requests[2]->purpose)->toBe('repair')
        ->and($this->ai->requests[2]->system)->toContain('Pest feature tests');
});
