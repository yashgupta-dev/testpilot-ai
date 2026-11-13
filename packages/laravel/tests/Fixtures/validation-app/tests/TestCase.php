<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Shop\Http\Controllers\CheckoutController;
use Shop\Http\Controllers\InlineController;
use Shop\Http\Controllers\ManageOrderController;
use Shop\Http\Controllers\OrderController;
use Shop\Http\Controllers\SessionController;
use Shop\Http\Requests\LenientOrderRequest;
use Shop\Http\Requests\StoreOrderRequest;

/** The "host application" test case that generated validation tests bind with uses(). */
abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array'); // the rate limiter's counters
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);

        // Simulates a business regression: coupons silently stop working.
        if (getenv('TP_NO_COUPONS') === '1') {
            $app['config']->set('shop.coupons', false);
        }

        // Simulates a regression: the endpoint silently uses weaker rules than the ones declared.
        if (getenv('TP_LENIENT_RULES') === '1') {
            $app->bind(StoreOrderRequest::class, LenientOrderRequest::class);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/database/migrations');
    }

    protected function defineRoutes($router): void
    {
        // Real apps get SubstituteBindings from the "api"/"web" group; implicit model binding needs it.
        $router->middleware(SubstituteBindings::class)->group(function ($router): void {
            $router->put('/api/orders/{order}', [ManageOrderController::class, 'update'])->middleware('throttle:3,1');
            $router->patch('/api/customers/{customer}/orders/{order:reference}', [ManageOrderController::class, 'updateForCustomer']);
            $router->put('/api/legacy-orders/{id}', [ManageOrderController::class, 'updateLegacy']);
            $router->delete('/api/coupons/{coupon}', [ManageOrderController::class, 'destroyCoupon'])->middleware('throttle:2,1');
        });

        $router->post('/api/orders', [OrderController::class, 'store']);

        $router->post('/api/checkout', [CheckoutController::class, 'store']);
        $router->post('/api/inline/orders', [InlineController::class, 'store']);
        $router->post('/api/inline/posts', [InlineController::class, 'post']);
        $router->post('/api/inline/legacy', [InlineController::class, 'legacy']);
        $router->post('/api/inline/unclear', [InlineController::class, 'unclear']);
        $router->post('/api/inline/declared', [InlineController::class, 'declared']);
        $router->middleware(StartSession::class)->post('/web/form', [InlineController::class, 'form']);

        // TP_WEAK_THROTTLE simulates a regression: the login route allows 10 attempts instead of 3.
        $router->post('/api/login', [SessionController::class, 'store'])
            ->middleware(getenv('TP_WEAK_THROTTLE') === '1' ? 'throttle:10,1' : 'throttle:3,1');
        $router->get('/api/ping', [SessionController::class, 'ping'])->middleware('throttle:2,1');
    }
}
