<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TestPilot\Laravel\Models\AuditLog;
use TestPilot\Laravel\Models\Setting;
use TestPilot\Laravel\TestPilotDashboard;

it('shows AI settings with their source and never returns the API key', function () {
    config(['testpilot.ai.api_key' => 'sk-ant-api03-SECRETVALUE1234']);

    $fields = $this->getJson('/testpilot/api/settings')->assertOk()->json('fields');

    expect($fields['ai.api_key'])->toMatchArray(['set' => true, 'value' => null, 'hint' => 'sk-ant-…1234', 'source' => 'default', 'locked' => false])
        ->and($fields['ai.models.repair'])->toMatchArray(['value' => 'claude-haiku-5-5', 'type' => 'model'])
        ->and($fields['ai.driver']['options'])->toContain('anthropic')
        ->and(json_encode($fields))->not->toContain('SECRETVALUE');
});

it('saves settings from the dashboard, encrypts the key, audits the change and applies it', function () {
    $this->putJson('/testpilot/api/settings', ['values' => [
        'ai.models.plan' => 'claude-opus-5-5',
        'ai.monthly_budget' => '120',
        'ai.response_cache.enabled' => false,
        'ai.api_key' => 'sk-ant-api03-NEWKEY99887766',
    ]])->assertOk()->assertJsonPath('changed', ['ai.models.plan', 'ai.monthly_budget', 'ai.response_cache.enabled', 'ai.api_key']);

    expect(config('testpilot.ai.models.plan'))->toBe('claude-opus-5-5')
        ->and(config('testpilot.ai.monthly_budget'))->toBe(120.0)
        ->and(config('testpilot.ai.response_cache.enabled'))->toBeFalse()
        ->and(config('testpilot.ai.api_key'))->toBe('sk-ant-api03-NEWKEY99887766')
        ->and(DB::table('testpilot_settings')->where('key', 'ai.api_key')->value('value'))->not->toContain('NEWKEY')
        ->and(AuditLog::query()->where('action', 'settings.updated')->first()->context['keys'])->toContain('ai.api_key');

    // An empty key keeps the stored one; "__default" removes a dashboard value.
    $this->putJson('/testpilot/api/settings', ['values' => ['ai.api_key' => '', 'ai.models.plan' => '__default']])->assertOk();
    expect(Setting::query()->find('ai.api_key'))->not->toBeNull()
        ->and(Setting::query()->find('ai.models.plan'))->toBeNull();
});

it('keeps .env values locked and validates input', function () {
    config(['testpilot.settings.env_locked' => ['TESTPILOT_AI_MODEL']]);

    $this->putJson('/testpilot/api/settings', ['values' => ['ai.model' => 'claude-haiku-5-5']])
        ->assertStatus(422)->assertJsonPath('message', 'Default model is set in .env (TESTPILOT_AI_MODEL) and can only be changed there.');
    expect($this->getJson('/testpilot/api/settings')->json('fields')['ai.model'])->toMatchArray(['locked' => true, 'source' => 'env']);

    $this->putJson('/testpilot/api/settings', ['values' => ['ai.driver' => 'skynet']])->assertStatus(422);
    $this->putJson('/testpilot/api/settings', ['values' => ['ai.warn_at' => 3]])->assertStatus(422);
    $this->putJson('/testpilot/api/settings', ['values' => ['ai.models.plan' => 'bad model; rm -rf']])->assertStatus(422);
});

it('only lets settings managers change settings, and can be switched off entirely', function () {
    TestPilotDashboard::manageSettingsUsing(fn () => false);
    $this->putJson('/testpilot/api/settings', ['values' => ['ai.prompt_cache' => '1h']])->assertForbidden();
    expect($this->getJson('/testpilot/api/settings')->json('can_manage'))->toBeFalse();

    TestPilotDashboard::manageSettingsUsing(fn () => true);
    config(['testpilot.settings.editable' => false]);
    $this->putJson('/testpilot/api/settings', ['values' => ['ai.prompt_cache' => '1h']])
        ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'TESTPILOT_SETTINGS_EDITABLE=false'));
});

it('locks the dashboard behind a passcode, throttles guesses and unlocks with the right one', function () {
    $this->putJson('/testpilot/api/settings/passcode', ['passcode' => 'blue-falcon-42'])->assertOk()->assertJsonPath('passcode.enabled', true);
    expect(Setting::query()->find('dashboard.passcode')->value)->not->toContain('blue-falcon');
    $this->getJson('/testpilot/api/overview')->assertOk(); // the person who set it stays unlocked

    $this->flushSession(); // another browser
    $this->getJson('/testpilot/api/overview')->assertStatus(423)->assertJsonPath('locked', true);
    $this->getJson('/testpilot/api/session')->assertOk()->assertJson(['locked' => true, 'passcode_enabled' => true]);

    foreach (range(1, 5) as $i) {
        $this->postJson('/testpilot/api/unlock', ['passcode' => "wrong-{$i}"])->assertStatus(422);
    }
    $this->postJson('/testpilot/api/unlock', ['passcode' => 'blue-falcon-42'])->assertStatus(429);
    expect(AuditLog::query()->where('action', 'dashboard.unlock_failed')->count())->toBe(5);

    \Illuminate\Support\Facades\RateLimiter::clear('testpilot-unlock:' . sha1('127.0.0.1|' . \TestPilot\Laravel\Services\AuditLogger::currentActor()));
    $this->postJson('/testpilot/api/unlock', ['passcode' => 'blue-falcon-42'])->assertOk()->assertJson(['locked' => false]);
    $this->getJson('/testpilot/api/overview')->assertOk();

    $this->postJson('/testpilot/api/lock')->assertOk();
    $this->getJson('/testpilot/api/overview')->assertStatus(423);
});

it('requires the current passcode to change it and relocks other sessions when it changes', function () {
    $this->putJson('/testpilot/api/settings/passcode', ['passcode' => 'first-code-1'])->assertOk();

    $this->putJson('/testpilot/api/settings/passcode', ['current' => 'nope', 'passcode' => 'second-code-2'])
        ->assertStatus(422)->assertJsonPath('message', 'The current passcode is not correct.');
    $this->putJson('/testpilot/api/settings/passcode', ['current' => 'first-code-1', 'passcode' => '123'])
        ->assertStatus(422)->assertJsonPath('message', 'Use a passcode of 6 to 64 characters.');

    $other = session()->get('testpilot.unlocked'); // a session unlocked with the old passcode
    $this->putJson('/testpilot/api/settings/passcode', ['current' => 'first-code-1', 'passcode' => 'second-code-2'])->assertOk();
    $this->flushSession();
    $this->withSession(['testpilot.unlocked' => $other])->getJson('/testpilot/api/overview')->assertStatus(423);

    $this->postJson('/testpilot/api/unlock', ['passcode' => 'second-code-2'])->assertOk();
    $this->putJson('/testpilot/api/settings/passcode', ['current' => 'second-code-2', 'remove' => true])->assertOk()->assertJsonPath('passcode.enabled', false);
    $this->flushSession();
    $this->getJson('/testpilot/api/overview')->assertOk();
});

it('treats a passcode from .env as locked', function () {
    config(['testpilot.dashboard.passcode' => 'from-env-123']);

    $this->getJson('/testpilot/api/overview')->assertStatus(423);
    $this->postJson('/testpilot/api/unlock', ['passcode' => 'from-env-123'])->assertOk();
    $this->getJson('/testpilot/api/settings')->assertOk()->assertJsonPath('passcode.locked_by_env', true);
    $this->putJson('/testpilot/api/settings/passcode', ['current' => 'from-env-123', 'passcode' => 'another-1'])
        ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'TESTPILOT_DASHBOARD_PASSCODE'));
});

it('tests the AI connection with the saved settings', function () {
    $this->ai->push('OK');

    $this->postJson('/testpilot/api/settings/ai/test')->assertOk()->assertJson(['ok' => true, 'reply' => 'OK']);
    expect($this->ai->requests[0]->purpose)->toBe('test');
});
