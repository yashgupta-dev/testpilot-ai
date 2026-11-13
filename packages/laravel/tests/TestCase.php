<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use TestPilot\Ai\Providers\FakeProvider;
use TestPilot\Laravel\Services\EloquentUsageRecorder;
use TestPilot\Laravel\Services\TestPilotManager;
use TestPilot\Laravel\TestPilotDashboard;
use TestPilot\Laravel\TestPilotServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected string $sandbox;

    protected FakeProvider $ai;

    protected function getPackageProviders($app): array
    {
        return [TestPilotServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->sandbox = $this->makeSandbox();

        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('t', 32)));
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('testpilot.root', $this->sandbox);
        $app['config']->set('testpilot.paths', ['app', 'src']);
        $app['config']->set('testpilot.ai.driver', 'fake');
        $app['config']->set('testpilot.ai.monthly_budget', 50);
        $app['config']->set('testpilot.pest.test_case', '');
        $app['config']->set('testpilot.pest.stability_runs', 2);
        $app['config']->set('testpilot.mutation.enabled', false);
        $app['config']->set('testpilot.git.enabled', false);
        // Validation plans stay deterministic in tests unless a test turns the AI review on.
        $app['config']->set('testpilot.validation.ai', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        TestPilotDashboard::flush();
        TestPilotDashboard::auth(fn (): bool => true);

        $this->ai = new FakeProvider();
        $this->app->singleton(TestPilotManager::class, fn ($app): TestPilotManager => new TestPilotManager(
            $app['config'],
            $app->make(EloquentUsageRecorder::class),
            $this->ai,
        ));
    }

    /**
     * A throw-away project: the core fixtures app (annotated services) plus a tiny runnable
     * project (Demo\Calculator) whose vendor/ points at this package's dependencies.
     */
    private function makeSandbox(): string
    {
        $root = sys_get_temp_dir() . '/testpilot-laravel-' . bin2hex(random_bytes(5));
        $fixtures = dirname(__DIR__, 2) . '/core/tests/Fixtures';

        $this->copyDirectory("{$fixtures}/app", "{$root}/app");
        $this->copyDirectory("{$fixtures}/project/src", "{$root}/src");
        copy("{$fixtures}/project/bootstrap.php", "{$root}/bootstrap.php");
        copy("{$fixtures}/project/phpunit.xml", "{$root}/phpunit.xml");
        mkdir("{$root}/tests", 0777, true);
        symlink(realpath(dirname(__DIR__) . '/vendor'), "{$root}/vendor");

        register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

        return $root;
    }

    private function copyDirectory(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            is_dir("{$from}/{$item}") ? $this->copyDirectory("{$from}/{$item}", "{$to}/{$item}") : copy("{$from}/{$item}", "{$to}/{$item}");
        }
    }
}
