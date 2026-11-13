<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TestPilot\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function calculatorPlanJson(): string
{
    return json_encode([
        'schema_version' => '1',
        'target' => 'Demo\Calculator::divide',
        'scenarios' => [
            ['id' => 'S1', 'name' => 'Divides two integers', 'type' => 'positive', 'module' => 'unit', 'description' => '', 'inputs' => '6, 2', 'expected' => '3.0', 'basis' => 'confirmed', 'basis_source' => 'return $a / $b', 'confidence' => 0.95, 'priority' => 'high'],
            ['id' => 'S2', 'name' => 'Throws on division by zero', 'type' => 'negative', 'module' => 'unit', 'description' => '', 'inputs' => '1, 0', 'expected' => 'InvalidArgumentException', 'basis' => 'confirmed', 'basis_source' => 'if ($b === 0) throw', 'confidence' => 0.95, 'priority' => 'high'],
            ['id' => 'S3', 'name' => 'Rounds repeating decimals', 'type' => 'edge', 'module' => 'unit', 'description' => '', 'inputs' => '1, 3', 'expected' => '0.333…', 'basis' => 'assumption', 'basis_source' => 'not specified', 'confidence' => 0.4, 'priority' => 'low'],
        ],
        'notes' => '',
    ]);
}

function calculatorTestCode(): string
{
    return <<<'PHP'
    ```php
    <?php

    declare(strict_types=1);

    use Demo\Calculator;

    it('[S1] divides two integers', function () {
        expect((new Calculator())->divide(6, 2))->toBe(3.0);
    });

    it('[S2] throws on division by zero', function () {
        expect(fn () => (new Calculator())->divide(1, 0))->toThrow(InvalidArgumentException::class);
    });
    ```
    PHP;
}

/**
 * A copy of tests/Fixtures/validation-app (FormRequests, throttled routes) used by the validation and
 * rate-limit workflow tests. Generated Pest files run inside it, in a child process booted by Testbench.
 */
function validationSandbox(): string
{
    // One sandbox per process: PHP cannot unload the Shop\ classes once loaded, so every test must use the same files.
    static $root = null;

    if ($root === null) {
        $root = sys_get_temp_dir() . '/testpilot-validation-' . bin2hex(random_bytes(5));
        exec('cp -R ' . escapeshellarg(__DIR__ . '/Fixtures/validation-app') . ' ' . escapeshellarg($root));
        symlink(realpath(dirname(__DIR__) . '/vendor'), "{$root}/vendor");
        $cleanup = $root;
        register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($cleanup)));

        // The dashboard process needs the classes too (to locate the FormRequest file). Rules are read from source, not run.
        spl_autoload_register(static function (string $class) use ($root): void {
            if (str_starts_with($class, 'Shop\\')) {
                $file = "{$root}/app/" . str_replace('\\', '/', substr($class, 5)) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }

    exec('rm -rf ' . escapeshellarg("{$root}/storage") . ' ' . escapeshellarg("{$root}/tests/TestPilot"));

    config([
        'testpilot.root' => $root,
        'testpilot.paths' => ['app'],
        'testpilot.pest.test_case' => 'Tests\\TestCase',
    ]);
    // The dashboard process inspects these routes; the child test process registers the same ones (tests/TestCase.php).
    Route::post('/api/orders', ['Shop\\Http\\Controllers\\OrderController', 'store']);
    Route::post('/api/login', ['Shop\\Http\\Controllers\\SessionController', 'store'])->middleware('throttle:3,1');
    Route::get('/api/ping', ['Shop\\Http\\Controllers\\SessionController', 'ping'])->middleware('throttle:2,1');
    Route::get('/api/status', ['Shop\\Http\\Controllers\\SessionController', 'status'])->middleware('throttle:status');
    Route::post('/api/checkout', ['Shop\\Http\\Controllers\\CheckoutController', 'store']);
    $i = 'Shop\\Http\\Controllers\\InlineController';
    foreach (['orders' => 'store', 'posts' => 'post', 'legacy' => 'legacy', 'unclear' => 'unclear', 'declared' => 'declared'] as $uri => $action) {
        Route::post("/api/inline/{$uri}", [$i, $action]);
    }
    Route::middleware(\Illuminate\Session\Middleware\StartSession::class)->post('/web/form', [$i, 'form']);
    Route::middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)->group(function (): void {
        $c = 'Shop\\Http\\Controllers\\ManageOrderController';
        Route::put('/api/orders/{order}', [$c, 'update'])->middleware('throttle:3,1');
        Route::patch('/api/customers/{customer}/orders/{order:reference}', [$c, 'updateForCustomer']);
        Route::put('/api/legacy-orders/{id}', [$c, 'updateLegacy']);
        Route::delete('/api/coupons/{coupon}', [$c, 'destroyCoupon'])->middleware('throttle:2,1');
    });

    return $root;
}
