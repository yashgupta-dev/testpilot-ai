<?php

declare(strict_types=1);

namespace TestPilot\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TestPilot\Laravel\Console\GenerateCommand;
use TestPilot\Laravel\Console\InstallCommand;
use TestPilot\Laravel\Console\ReportCommand;
use TestPilot\Laravel\Console\ReviewCommand;
use TestPilot\Laravel\Console\RunCommand;
use TestPilot\Laravel\Console\ScanCommand;
use TestPilot\Laravel\Http\Middleware\Authorize;
use TestPilot\Laravel\Services\EloquentUsageRecorder;
use TestPilot\Laravel\Services\GitHistory;
use TestPilot\Laravel\Services\TestPilotManager;

final class TestPilotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/testpilot.php', 'testpilot');

        $this->app->singleton(\TestPilot\Laravel\Services\SettingsService::class);

        $this->app->singleton(EloquentUsageRecorder::class, function ($app): EloquentUsageRecorder {
            // Budgets may have been changed in Settings: layer stored values over config first.
            $app->make(\TestPilot\Laravel\Services\SettingsService::class)->apply();

            return new EloquentUsageRecorder(
                (float) $app['config']->get('testpilot.ai.monthly_budget', 0),
                (array) $app['config']->get('testpilot.ai.pricing', []),
                (float) $app['config']->get('testpilot.ai.target_monthly_budget', 0),
                (float) $app['config']->get('testpilot.ai.warn_at', 0.8),
                (bool) $app['config']->get('testpilot.ai.free_tier', false),
            );
        });

        $this->app->singleton(TestPilotManager::class, fn ($app): TestPilotManager => new TestPilotManager(
            $app['config'],
            $app->make(EloquentUsageRecorder::class),
            null,
            new \TestPilot\Laravel\Support\LaravelResponseCache(
                $app['cache']->store($app['config']->get('testpilot.ai.response_cache.store')),
                (int) $app['config']->get('testpilot.ai.response_cache.ttl_days', 14),
            ),
        ));

        $this->app->singleton(GitHistory::class, fn ($app): GitHistory => new GitHistory(
            (string) ($app['config']->get('testpilot.root') ?: base_path()),
            (bool) $app['config']->get('testpilot.git.enabled', true),
            (int) $app['config']->get('testpilot.git.window_days', 30),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'testpilot');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/testpilot.php' => config_path('testpilot.php')], 'testpilot-config');
            $this->publishes([__DIR__ . '/../database/migrations' => database_path('migrations')], 'testpilot-migrations');

            $this->commands([
                InstallCommand::class,
                ScanCommand::class,
                GenerateCommand::class,
                ReviewCommand::class,
                RunCommand::class,
                ReportCommand::class,
                \TestPilot\Laravel\Console\PlanCommand::class,
                \TestPilot\Laravel\Console\BatchesCommand::class,
                \TestPilot\Laravel\Console\ExportCommand::class,
                \TestPilot\Laravel\Console\AiCheckCommand::class,
            ]);
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        if (! config('testpilot.dashboard.enabled', true)) {
            return;
        }

        Route::group([
            'domain' => config('testpilot.dashboard.domain'),
            'prefix' => trim((string) config('testpilot.dashboard.path', 'testpilot'), '/'),
            'middleware' => [...(array) config('testpilot.dashboard.middleware', ['web']), Authorize::class],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/../routes/testpilot.php');
        });
    }
}
