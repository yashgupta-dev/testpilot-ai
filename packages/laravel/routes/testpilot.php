<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TestPilot\Laravel\Http\Controllers\Api\OverviewController;
use TestPilot\Laravel\Http\Controllers\Api\ReviewController;
use TestPilot\Laravel\Http\Controllers\Api\RunController;
use TestPilot\Laravel\Http\Controllers\Api\TargetController;
use TestPilot\Laravel\Http\Controllers\DashboardController;

Route::get('assets/{file}', [DashboardController::class, 'asset'])->name('testpilot.asset')->where('file', '[a-z0-9.]+');

Route::prefix('api')->name('testpilot.api.')->group(function (): void {
    // Passcode lock screen: always reachable.
    Route::get('session', [\TestPilot\Laravel\Http\Controllers\Api\SessionController::class, 'show'])->name('session');
    Route::post('unlock', [\TestPilot\Laravel\Http\Controllers\Api\SessionController::class, 'unlock'])->name('unlock');
    Route::post('lock', [\TestPilot\Laravel\Http\Controllers\Api\SessionController::class, 'lock'])->name('lock');

    Route::middleware(\TestPilot\Laravel\Http\Middleware\RequirePasscode::class)->group(function (): void {
        Route::get('settings', [\TestPilot\Laravel\Http\Controllers\Api\SettingsController::class, 'show'])->name('settings.show');
        Route::put('settings', [\TestPilot\Laravel\Http\Controllers\Api\SettingsController::class, 'update'])->name('settings.update');
        Route::put('settings/passcode', [\TestPilot\Laravel\Http\Controllers\Api\SettingsController::class, 'passcode'])->name('settings.passcode');
        Route::post('settings/ai/test', [\TestPilot\Laravel\Http\Controllers\Api\SettingsController::class, 'testAi'])->name('settings.ai.test');

        Route::get('overview', [OverviewController::class, 'show'])->name('overview');
        Route::get('environment', [OverviewController::class, 'environment'])->name('environment');
        Route::post('scan', [OverviewController::class, 'scan'])->name('scan');

        Route::get('targets', [TargetController::class, 'index'])->name('targets.index');
        Route::get('targets/{target}', [TargetController::class, 'show'])->name('targets.show');
        Route::post('targets/{target}/plan', [TargetController::class, 'plan'])->name('targets.plan');
        Route::post('targets/{target}/plans/{plan}/approve', [TargetController::class, 'approvePlan'])->name('targets.plan.approve');

        Route::get('review', [ReviewController::class, 'index'])->name('review.index');
        Route::post('tests/{test}/approve', [ReviewController::class, 'approve'])->name('tests.approve');
        Route::post('tests/{test}/reject', [ReviewController::class, 'reject'])->name('tests.reject');

        Route::get('ai/usage', [\TestPilot\Laravel\Http\Controllers\Api\AiController::class, 'usage'])->name('ai.usage');
        Route::post('ai/batches', [\TestPilot\Laravel\Http\Controllers\Api\AiController::class, 'submitBatch'])->name('ai.batches.store');
        Route::post('ai/batches/{batch}/poll', [\TestPilot\Laravel\Http\Controllers\Api\AiController::class, 'pollBatch'])->name('ai.batches.poll');

        Route::get('export', [\TestPilot\Laravel\Http\Controllers\Api\ExportController::class, 'download'])->name('export');

        Route::get('runs', [RunController::class, 'index'])->name('runs.index');
        Route::post('runs', [RunController::class, 'store'])->name('runs.store');
        Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
        Route::post('runs/{run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
    });
});

// The single-page dashboard handles every other path under the prefix.
Route::get('{view?}', [DashboardController::class, 'index'])->where('view', '.*')->name('testpilot.dashboard');
