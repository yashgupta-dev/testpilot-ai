<?php

declare(strict_types=1);

namespace TestPilot\Laravel;

use Closure;
use Illuminate\Http\Request;

/**
 * Dashboard access control, in the style of Horizon/Telescope.
 *
 *   TestPilotDashboard::auth(fn (Request $request) => $request->user()?->isAdmin());
 *   TestPilotDashboard::approveUsing(fn (Request $request) => $request->user()?->can('approve-tests'));
 *
 * Without a callback the dashboard is only available in the "local" environment.
 */
final class TestPilotDashboard
{
    /** @var (Closure(Request): bool)|null */
    private static ?Closure $auth = null;

    /** @var (Closure(Request): bool)|null */
    private static ?Closure $approve = null;

    /** @var (Closure(Request): bool)|null */
    private static ?Closure $settings = null;

    /** @param Closure(Request): bool $callback */
    public static function auth(Closure $callback): void
    {
        self::$auth = $callback;
    }

    /** @param Closure(Request): bool $callback */
    public static function approveUsing(Closure $callback): void
    {
        self::$approve = $callback;
    }

    /**
     * Who may change AI settings and the dashboard passcode from the browser. Defaults to whoever can approve tests.
     *
     * @param Closure(Request): bool $callback
     */
    public static function manageSettingsUsing(Closure $callback): void
    {
        self::$settings = $callback;
    }

    public static function canManageSettings(Request $request): bool
    {
        return self::$settings !== null ? (bool) (self::$settings)($request) : self::canApprove($request);
    }

    public static function check(Request $request): bool
    {
        return self::$auth !== null ? (bool) (self::$auth)($request) : app()->environment('local');
    }

    public static function canApprove(Request $request): bool
    {
        return self::$approve !== null ? (bool) (self::$approve)($request) : self::check($request);
    }

    public static function flush(): void
    {
        self::$auth = null;
        self::$approve = null;
        self::$settings = null;
    }
}
