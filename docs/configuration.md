# Configuration

Publish with `php artisan testpilot:install` and edit `config/testpilot.php`.

## Attributes

| Attribute | Targets | Arguments |
|---|---|---|
| `#[TestPilot]` | class, method, function | `enabled`, `area`, `criticality` |
| `#[UnitTest]` | class, method, function | `enabled`, `maxScenarios`, `focus` |
| `#[Fuzz]` | class, method, function | `enabled`, `cases`, `seed` |
| `#[FeatureTest]` | class, method | `enabled`, `maxScenarios`, `fakes` (mail, notification, queue, http, event, storage), `focus` — AI-planned HTTP tests through the action's route (v0.6) |
| `#[Validation]` | class, method | `enabled`, `errorStatus` — reads the FormRequest's `rules()`, or inline `$request->validate()` / `Validator::make()` in the action; `errorStatus` is only needed when your own helper builds the failure response |
| `#[RateLimit]` | class, method | `enabled`, `maxAttempts`, `decaySeconds`, `lockout` — declared limit is the expectation; without numbers the route's `throttle:N,M` is used (v0.3) |
| `#[AI]` | class, method, function | `enabled` — `false` keeps this code away from AI providers |
| `#[SkipTest]` | class, method, function | `reason` |

Precedence: project `defaults` → class attributes → method attributes → `limits` (always wins). Module attributes are additive; an explicit `enabled: false` or `#[SkipTest]` at a closer scope wins. A bare `#[TestPilot]` enables unit tests. Private/protected methods are never called directly — TestPilot warns and tests them through public methods.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `TESTPILOT_AI_DRIVER` | `anthropic` | `anthropic`, `openai`, `gemini`, `ollama`, `custom`, `fake` |
| `TESTPILOT_AI_API_KEY` | – | Provider key (not needed for Ollama) |
| `TESTPILOT_AI_MODEL` | provider default | Model name |
| `TESTPILOT_AI_BASE_URL` | – | Required for `custom`; optional override otherwise |
| `TESTPILOT_AI_POLICY` | `cloud` | `off`, `local`, `cloud` |
| `TESTPILOT_AI_MONTHLY_BUDGET` | `50` | USD; `0` disables the check |
| `TESTPILOT_MUTATION` | `true` | Run `pest --mutate` during verification (needs PCOV/Xdebug) |
| `TESTPILOT_PATH` | `testpilot` | Dashboard URL prefix |
| `TESTPILOT_QUEUE` | `testpilot` | Queue name for jobs |

Set `ai.pricing` to your provider's current prices; they are used for cost estimates only and are not fetched automatically.

## Validation engine

```php
'validation' => [
    'user_model' => 'App\\Models\\User', // factory user for routes behind auth middleware
    'refresh_database' => true,           // used when exists/unique rules or auth are involved
],
```

CLI: `php artisan testpilot:generate "OrderController::store" --module=validation` (or `--module=ratelimit`, `--module=feature`). Rate-limit tests reuse `validation.user_model` for routes behind auth. The dashboard shows a **Module** switcher on targets that have both unit and validation work.

## CI

```yaml
- run: php artisan testpilot:scan
- run: php artisan testpilot:run --ci   # 0 pass, 1 failures, 2 nothing to run/config, 3 policy violation
```

`--ci` never generates or approves tests.
