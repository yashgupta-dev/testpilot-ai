<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\Setting;

/**
 * Settings that may be changed from the dashboard, layered over config/testpilot.php:
 *
 *   .env  (locked — shown read-only in the UI)  >  dashboard value (testpilot_settings)  >  config default
 *
 * The AI API key is stored encrypted with the app key and is never sent back to the browser.
 * Every change is written to the audit log. Policy limits, paths and the Pest setup stay in code.
 */
final class SettingsService
{
    public const PASSCODE_KEY = 'dashboard.passcode';
    private const SESSION_KEY = 'testpilot.unlocked';

    /** key (= config path under "testpilot.") => definition */
    public const FIELDS = [
        'ai.driver' => ['env' => 'TESTPILOT_AI_DRIVER', 'type' => 'enum', 'options' => ['anthropic', 'openai', 'groq', 'openrouter', 'gemini', 'ollama', 'custom'], 'label' => 'Provider'],
        'ai.free_tier' => ['env' => 'TESTPILOT_AI_FREE_TIER', 'type' => 'bool', 'label' => 'Free-tier key (record calls at $0)'],
        'ai.api_key' => ['env' => 'TESTPILOT_AI_API_KEY', 'type' => 'secret', 'label' => 'API key'],
        'ai.model' => ['env' => 'TESTPILOT_AI_MODEL', 'type' => 'model', 'label' => 'Default model'],
        'ai.base_url' => ['env' => 'TESTPILOT_AI_BASE_URL', 'type' => 'url', 'label' => 'Base URL'],
        'ai.policy' => ['env' => 'TESTPILOT_AI_POLICY', 'type' => 'enum', 'options' => ['cloud', 'local', 'off'], 'label' => 'Data policy'],
        'ai.models.plan' => ['env' => 'TESTPILOT_AI_MODEL_PLAN', 'type' => 'model', 'label' => 'Plan'],
        'ai.models.generate' => ['env' => 'TESTPILOT_AI_MODEL_GENERATE', 'type' => 'model', 'label' => 'Generate'],
        'ai.models.repair' => ['env' => 'TESTPILOT_AI_MODEL_REPAIR', 'type' => 'model', 'label' => 'Repair (first try)'],
        'ai.models.repair_escalation' => ['env' => 'TESTPILOT_AI_MODEL_REPAIR_ESCALATION', 'type' => 'model', 'label' => 'Repair (later tries)'],
        'ai.models.validation' => ['env' => 'TESTPILOT_AI_MODEL_VALIDATION', 'type' => 'model', 'label' => 'Validation review'],
        'ai.models.critical' => ['env' => 'TESTPILOT_AI_MODEL_CRITICAL', 'type' => 'model', 'label' => 'Critical targets'],
        'ai.prompt_cache' => ['env' => 'TESTPILOT_AI_PROMPT_CACHE', 'type' => 'enum', 'options' => ['5m', '1h', 'off'], 'label' => 'Prompt cache'],
        'ai.response_cache.enabled' => ['env' => 'TESTPILOT_AI_RESPONSE_CACHE', 'type' => 'bool', 'label' => 'Response cache'],
        'ai.response_cache.ttl_days' => ['env' => 'TESTPILOT_AI_CACHE_DAYS', 'type' => 'int', 'min' => 1, 'max' => 90, 'label' => 'Keep cached answers (days)'],
        'ai.monthly_budget' => ['env' => 'TESTPILOT_AI_MONTHLY_BUDGET', 'type' => 'number', 'min' => 0, 'max' => 100000, 'label' => 'Monthly budget (USD)'],
        'ai.target_monthly_budget' => ['env' => 'TESTPILOT_AI_TARGET_BUDGET', 'type' => 'number', 'min' => 0, 'max' => 10000, 'label' => 'Per-target monthly budget (USD)'],
        'ai.warn_at' => ['env' => null, 'type' => 'number', 'min' => 0.1, 'max' => 1, 'label' => 'Warn at (share of budget)'],
        'dashboard.unlock_minutes' => ['env' => 'TESTPILOT_DASHBOARD_UNLOCK_MINUTES', 'type' => 'int', 'min' => 5, 'max' => 10080, 'label' => 'Stay unlocked for (minutes)'],
    ];

    /** Layers stored dashboard values over the config. Idempotent; cheap after the first call per process. */
    public function apply(): void
    {
        // Once per application instance (each request / job / test gets a fresh one).
        if (app()->bound('testpilot.settings.applied')) {
            return;
        }
        app()->instance('testpilot.settings.applied', true);

        try {
            $rows = Setting::query()->whereIn('key', array_keys(self::FIELDS))->get();
        } catch (Throwable) {
            return; // not migrated yet
        }

        foreach ($rows as $row) {
            if (! $this->locked($row->key)) {
                config(['testpilot.' . $row->key => $this->decode($row)]);
            }
        }
    }

    /** @return array<string, mixed> Every editable field with its value, source and lock state (secrets masked). */
    public function export(): array
    {
        $this->apply();
        $stored = Setting::query()->whereIn('key', array_keys(self::FIELDS))->get()->keyBy('key');

        $fields = [];
        foreach (self::FIELDS as $key => $def) {
            $value = config('testpilot.' . $key);
            $locked = $this->locked($key);
            $fields[$key] = [
                'key' => $key,
                'label' => $def['label'],
                'type' => $def['type'],
                'options' => $def['options'] ?? null,
                'min' => $def['min'] ?? null,
                'max' => $def['max'] ?? null,
                'env' => $def['env'],
                'locked' => $locked,
                'source' => $locked ? 'env' : ($stored->has($key) ? 'dashboard' : 'default'),
                'updated_by' => $stored->get($key)?->updated_by,
                'updated_at' => $stored->get($key)?->updated_at?->toIso8601String(),
                'value' => $def['type'] === 'secret' ? null : $value,
                'set' => $def['type'] === 'secret' ? is_string($value) && $value !== '' : null,
                'hint' => $def['type'] === 'secret' && is_string($value) && strlen($value) > 8 ? substr($value, 0, 7) . '…' . substr($value, -4) : null,
            ];
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $values key => new value; null/"" keeps a secret; "__default" removes the dashboard value
     *
     * @return list<string> Keys that changed.
     */
    public function update(array $values, string $actor): array
    {
        if (! $this->editable()) {
            throw new TestPilotException('Editing settings from the dashboard is turned off (TESTPILOT_SETTINGS_EDITABLE=false). Change them in .env.');
        }
        $changed = [];
        $rules = [];
        foreach ($values as $key => $value) {
            $def = self::FIELDS[$key] ?? throw new TestPilotException("Unknown setting \"{$key}\".");
            if ($this->locked($key)) {
                throw new TestPilotException("{$def['label']} is set in .env ({$def['env']}) and can only be changed there.");
            }
            if ($value !== '__default' && ! ($def['type'] === 'secret' && ($value === null || $value === ''))) {
                $rules[str_replace('.', '__', $key)] = $this->rules($def);
            }
        }

        $flat = [];
        foreach ($values as $key => $value) {
            $flat[str_replace('.', '__', $key)] = $value;
        }
        $validator = Validator::make($flat, $rules, [], array_combine(array_map(static fn ($k) => str_replace('.', '__', $k), array_keys(self::FIELDS)), array_column(self::FIELDS, 'label')));
        if ($validator->fails()) {
            throw new TestPilotException($validator->errors()->first());
        }

        foreach ($values as $key => $value) {
            $def = self::FIELDS[$key];
            if ($value === '__default') {
                Setting::query()->whereKey($key)->delete() && $changed[] = $key;
                continue;
            }
            if ($def['type'] === 'secret' && ($value === null || $value === '')) {
                continue;
            }

            $value = $this->cast($def, $value);
            Setting::query()->updateOrCreate(['key' => $key], [
                'value' => $def['type'] === 'secret' ? Crypt::encryptString((string) $value) : json_encode($value),
                'secret' => $def['type'] === 'secret',
                'updated_by' => $actor,
            ]);
            $changed[] = $key;
        }

        app()->forgetInstance('testpilot.settings.applied');
        $this->apply();

        return $changed;
    }

    // ---- dashboard passcode ---------------------------------------------------------------

    public function passcodeEnabled(): bool
    {
        return $this->envPasscode() !== null || $this->storedPasscode() !== null;
    }

    public function passcodeLocked(): bool
    {
        return $this->envPasscode() !== null;
    }

    /** @return array{enabled: bool, source: ?string, updated_by: ?string, updated_at: ?string, unlock_minutes: int} */
    public function passcodeStatus(): array
    {
        $row = Setting::query()->find(self::PASSCODE_KEY);

        return [
            'enabled' => $this->passcodeEnabled(),
            'source' => $this->envPasscode() !== null ? 'env' : ($row !== null ? 'dashboard' : null),
            'updated_by' => $row?->updated_by,
            'updated_at' => $row?->updated_at?->toIso8601String(),
            'unlock_minutes' => $this->unlockMinutes(),
        ];
    }

    public function verifyPasscode(string $input): bool
    {
        if (($env = $this->envPasscode()) !== null) {
            return hash_equals($env, $input);
        }
        $hash = $this->storedPasscode();

        return $hash !== null && Hash::check($input, $hash);
    }

    /** Sets, changes (new string) or removes (null) the passcode. The current one is required when one is set. */
    public function setPasscode(?string $current, ?string $new, string $actor): void
    {
        if (! $this->editable()) {
            throw new TestPilotException('Editing settings from the dashboard is turned off (TESTPILOT_SETTINGS_EDITABLE=false).');
        }
        if ($this->passcodeLocked()) {
            throw new TestPilotException('The passcode is set in .env (TESTPILOT_DASHBOARD_PASSCODE) and can only be changed there.');
        }
        if ($this->passcodeEnabled() && ! $this->verifyPasscode((string) $current)) {
            throw new TestPilotException('The current passcode is not correct.');
        }

        if ($new === null) {
            Setting::query()->whereKey(self::PASSCODE_KEY)->delete();

            return;
        }

        if (mb_strlen($new) < 6 || mb_strlen($new) > 64) {
            throw new TestPilotException('Use a passcode of 6 to 64 characters.');
        }

        Setting::query()->updateOrCreate(['key' => self::PASSCODE_KEY], ['value' => Hash::make($new), 'secret' => true, 'updated_by' => $actor]);
    }

    public function isUnlocked(Request $request): bool
    {
        if (! $this->passcodeEnabled()) {
            return true;
        }
        $state = $request->session()->get(self::SESSION_KEY);

        return is_array($state)
            && hash_equals($this->fingerprint(), (string) ($state['fp'] ?? ''))
            && (int) ($state['until'] ?? 0) > Carbon::now()->getTimestamp();
    }

    public function unlock(Request $request): void
    {
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, ['fp' => $this->fingerprint(), 'until' => Carbon::now()->addMinutes($this->unlockMinutes())->getTimestamp()]);
    }

    public function lock(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    // ---- internals --------------------------------------------------------------------------

    public function editable(): bool
    {
        return (bool) config('testpilot.settings.editable', true);
    }

    public function locked(string $key): bool
    {
        $env = self::FIELDS[$key]['env'] ?? null;

        return $env !== null && in_array($env, (array) config('testpilot.settings.env_locked', []), true);
    }

    private function unlockMinutes(): int
    {
        $this->apply();

        return max(5, (int) config('testpilot.dashboard.unlock_minutes', 720));
    }

    /** Changes whenever the passcode changes, so existing unlocked sessions are locked again. */
    private function fingerprint(): string
    {
        return hash('sha256', 'testpilot-passcode|' . ($this->envPasscode() ?? $this->storedPasscode() ?? ''));
    }

    private function envPasscode(): ?string
    {
        $value = config('testpilot.dashboard.passcode');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function storedPasscode(): ?string
    {
        try {
            return Setting::query()->find(self::PASSCODE_KEY)?->value;
        } catch (Throwable) {
            return null;
        }
    }

    private function decode(Setting $row): mixed
    {
        if ($row->secret) {
            try {
                return Crypt::decryptString($row->value);
            } catch (Throwable) {
                return null; // app key rotated: the secret must be entered again
            }
        }

        $def = self::FIELDS[$row->key] ?? null;
        $value = json_decode($row->value, true);

        return $def === null || $value === null ? $value : $this->cast($def, $value);
    }

    /** @param array<string, mixed> $def @return list<string> */
    private function rules(array $def): array
    {
        return match ($def['type']) {
            'enum' => ['required', 'in:' . implode(',', $def['options'])],
            'secret' => ['string', 'min:8', 'max:500'],
            'model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'url' => ['nullable', 'url', 'max:300'],
            'bool' => ['boolean'],
            'int' => ['required', 'integer', 'min:' . $def['min'], 'max:' . $def['max']],
            'number' => ['required', 'numeric', 'min:' . $def['min'], 'max:' . $def['max']],
            default => ['nullable', 'string'],
        };
    }

    /** @param array<string, mixed> $def */
    private function cast(array $def, mixed $value): mixed
    {
        return match ($def['type']) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            'int' => (int) $value,
            'number' => (float) $value,
            'model', 'url' => $value === null || $value === '' ? null : (string) $value,
            default => (string) $value,
        };
    }
}
