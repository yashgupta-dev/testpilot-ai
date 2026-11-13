# TestPilot AI

**Annotation-driven, AI-planned, human-approved Pest tests for PHP and Laravel.**

Add an attribute to a method. TestPilot reads your code statically, asks your AI provider for test scenarios, lets you approve them, generates a Pest test, runs it in a separate process, repairs it, checks that it is stable (and, with a coverage driver, that it catches injected bugs), and only then asks a human to approve it into your test suite.

```php
use TestPilot\Attributes\{TestPilot, UnitTest, Fuzz};

#[TestPilot(area: 'Billing', criticality: 'high')]
#[UnitTest(maxScenarios: 6)]
final class InvoiceService
{
    #[Fuzz(cases: 50)]
    public function calculateTotal(array $items, float $discount = 0.0): float { /* … */ }
}
```

```bash
php artisan testpilot:scan
php artisan testpilot:generate "InvoiceService::calculateTotal"
php artisan testpilot:generate "OrderController::store" --module=validation   # no AI needed
php artisan testpilot:generate "AuthController::login" --module=ratelimit      # no AI needed
php artisan testpilot:review --approve=<id>
php artisan testpilot:run --ci
```

…or do it all from the dashboard at `/testpilot`.

## Status: v0.8 — Settings (AI in the UI, dashboard passcode), enterprise AI layer, HTTP feature tests, validation, rate limits

| Area | Status |
|---|---|
| Attributes (`TestPilot`, `UnitTest`, `Fuzz`, `Validation`, `RateLimit`, `AI`, `SkipTest`) | ✅ Done |
| AST scanner (classes, traits, inheritance, functions; never executes code) | ✅ Done |
| Configuration resolver (project → class → method → policy, with trace) | ✅ Done |
| AI providers: Anthropic, OpenAI, Gemini (OpenAI-compatible), Ollama/local, fake | ✅ Done |
| Secret redaction, data policy (off / local / cloud), monthly budget | ✅ Done |
| Scenario planning with schema validation and confirmed/assumption flags | ✅ Done |
| Pest test generation, safety check, run, repair loop, stability check | ✅ Done |
| "Suspected bug" handling instead of weakening confirmed assertions | ✅ Done |
| Mutation gate (`pest --mutate`) | ✅ Done — needs PCOV or Xdebug, otherwise reported as skipped |
| Seeded fuzz datasets written into the test file | ✅ Done |
| Laravel adapter: migrations, queue jobs, Artisan commands, JSON API, audit log | ✅ Done |
| Dashboard: Overview, Analytics, Targets, Test plan, Test evidence, Review queue, Runs, Suggestions, Settings | ✅ Done |
| Validation-rule tests from FormRequests (deterministic, no AI): every rule → valid/invalid HTTP requests against the real route; unenforced rules reported as suspected bugs | ✅ Done (v0.2) |
| Dashboard module switcher (Unit & fuzz / Validation) and the Field × Rule × Input × Expected × Result grid | ✅ Done (v0.2) |
| Rate-limit tests from throttle middleware / `#[RateLimit]` (deterministic, no AI): N allowed, N+1 → 429 + Retry-After, X-RateLimit headers, reset after the window via `travel()`, per-client isolation; weaker limits reported as suspected bugs | ✅ Done (v0.3) |
| Routes with URL parameters for validation and rate-limit tests: factory-created models, `{post:slug}` keys, scoped children via `->for()`, backed enums, placeholders for plain ids; 404s reported as setup problems, never as bugs | ✅ Done (v0.4) |
| Inline validation in controllers (no FormRequest): `$request->validate()`, `request()->validate()`, `$this->validate()`, `validateWithBag()`, `Validator::make()` with `->validate()`, a custom JSON status, or `back()->withErrors()` | ✅ Done (v0.5) |
| AI-planned HTTP feature tests for controller actions (`#[FeatureTest]`): real requests through the route, response + database + faked side effects (mail, notifications, queue, HTTP, events, storage), factories for setup, suspected bugs instead of weakened tests | ✅ Done (v0.6) |
| Enterprise AI layer for Claude: prompt caching, response cache, per-step model routing with repair escalation, Message Batches (50% off), exact cost + savings accounting, monthly / per-target budgets with an 80% warning event, AI usage dashboard | ✅ Done (v0.7) |
| Settings in the dashboard: AI provider / key / models / caching / budgets editable (`.env` values stay locked, key encrypted, changes audited), dashboard passcode with throttled unlock, integrations page (GitHub, GitLab, Bitbucket, Slack, Teams, Jira marked *coming soon*) | ✅ Done (v0.8) |
| GitHub / GitLab integrations | ⏳ Next |
| Line/branch coverage per target, business flows, release readiness, teams | ⏳ Next phase |
| Core PHP CLI (`vendor/bin/testpilot`), DocBlock annotations, Docker sandbox | ⏳ Later phases |

Everything marked ✅ is covered by automated tests (see **Quality** below). Nothing marked ⏳ pretends to work: the API returns a clear "not implemented in this release" message and the UI labels it as planned.

## Repository layout

```
packages/
  core/      testpilot/core     framework-agnostic engine (no Laravel dependency)
  laravel/   testpilot/laravel  Laravel adapter, API, commands and the built dashboard (dist/)
ui/          Vue 3 + Tailwind source of the dashboard; `npm run build` writes to packages/laravel/dist
docs/        Architecture, configuration, security and development guides
```

## Quick start (Laravel)

Requirements: PHP 8.2+, Laravel 11/12, Pest 3+ in your app.

```bash
composer require --dev testpilot/laravel
php artisan testpilot:install
php artisan migrate
```

`.env`:

```dotenv
TESTPILOT_AI_DRIVER=anthropic        # anthropic | openai | groq | openrouter | gemini | ollama | custom | fake
TESTPILOT_AI_API_KEY=...
TESTPILOT_AI_MODEL=                  # optional for anthropic
TESTPILOT_AI_POLICY=cloud            # off | local | cloud
TESTPILOT_AI_MONTHLY_BUDGET=50
```

Add the generated-tests suite to `phpunit.xml`, add `storage/testpilot/` to `.gitignore`, run a queue worker (`php artisan queue:work --queue=testpilot`) and open `/testpilot`. The dashboard is only available in the `local` environment unless you define access rules:

```php
// AppServiceProvider::boot()
use TestPilot\Laravel\TestPilotDashboard;

TestPilotDashboard::auth(fn ($request) => $request->user()?->isAdmin());
TestPilotDashboard::approveUsing(fn ($request) => $request->user()?->can('approve-tests'));
```

Full details: [docs/configuration.md](docs/configuration.md).

## Try the demo target in the host Laravel app

The host app includes `App\Http\Controllers\TestPilotDemoController` as a small example: a `#[UnitTest]` helper, plus a `POST /api/testpilot-demo/calculate` action annotated for deterministic validation and rate-limit checks. The demo endpoint is available only in `local` and `testing` environments; it is throttled to five requests per minute.

```bash
php artisan testpilot:scan
php artisan testpilot:generate "TestPilotDemoController::calculate" --module=validation --accept-plan
php artisan testpilot:generate "TestPilotDemoController::calculate" --module=ratelimit --accept-plan
php artisan testpilot:generate "TestPilotDemoController::calculateTotal" --module=unit --accept-plan
```

Validation and rate-limit planning do not use AI. The unit-test plan does use the configured AI provider and can be blocked by its quota. Generated files remain pending human review; these commands do not automatically add tests to the normal suite.

## How it decides what to test

1. **Scan** — PHP files are parsed into ASTs (nikic/php-parser). Nothing is included or executed.
2. **Resolve** — project defaults, class attributes and method attributes are merged; policy limits clamp the result. The dashboard shows where each value came from.
3. **Risk** — complexity, recent git changes, missing tests, public routes and money/security keywords produce an explainable 0–100 score.
4. **Plan** — the AI receives the method source (secrets redacted) and the class outline (signatures only) and returns JSON scenarios, each marked *confirmed* (source-backed) or *assumption*. This describes the basis for an expectation; it does not mean a test ran or passed. Invalid JSON gets one corrective retry.
5. **Approve plan** — a human selects, edits or adds scenarios.
6. **Generate & verify** — one Pest file is generated, statically checked for dangerous code, linted, run in a child process, repaired up to N times, re-run in random order to catch flakiness, and mutation-tested.
7. **Review** — the verified test waits in `storage/testpilot/pending/`. Only an approval moves it into `tests/TestPilot/Generated/`. Developer-written tests are never overwritten.
8. **Run** — approved tests run from the dashboard, CLI or CI; results are stored per scenario.

A run is verified only when Pest reports passing test cases with assertions. An empty test run, skipped-only run, or assertion-free test is not proof of the expected behavior.

The dashboard's readiness list separates checks required to **run approved tests** from AI planning setup and optional recommendations. The project's test database cannot be fully validated without running its test suite; when a run reveals a missing table, column, driver, or connection, TestPilot reports it as a setup problem with a suggested next step instead of calling it an application bug.

**Settings → General** reads `DB_CONNECTION` and `DB_DATABASE` from the project's `phpunit.xml` (or `phpunit.xml.dist`) when present. It only reports the declared configuration; it does not connect to the database, verify its schema, or run migrations. SQLite `:memory:` is isolated; database names containing `test` or `testing` are only a naming hint, so confirm the database is dedicated to testing. Use your normal test setup, such as Laravel's `RefreshDatabase`, to run migrations. TestPilot never migrates a database automatically.

Run summaries use plain-language outcomes: **All checked tests passed**, **Some expected behavior did not pass**, **The test database is missing a table**, or **No behavior was verified**. Technical output stays available on the run detail page for developers.

## Validation tests (v0.2)

Put `#[Validation]` on a controller action that type-hints a `FormRequest` and is reachable through a route:

```php
#[Validation]
public function store(StoreOrderRequest $request): JsonResponse
```

TestPilot reads `rules()` **statically** (the request class is never instantiated to plan), builds one valid payload that satisfies every rule, then derives one case per rule: missing value for `required`, wrong type for `integer`/`numeric`/`array`/`string`/`date`/`email`, outside-the-list for `in`, and both sides of every `min`/`max`/`between`/`size` boundary. Each case changes exactly one field and asserts a `422` with an error on that field (or no error for boundary values). Cases expected to pass also require a successful 2xx response, so an HTTP 500 cannot count as valid. Cases run as real HTTP requests in a child Pest process.

- **Extra numeric examples**: TestPilot adds up to two seeded, valid numeric inputs per plan by default, when it can safely infer bounds from simple numeric rules. They are repeatable; set `TESTPILOT_VALIDATION_ADDITIONAL_CASES=0` to disable, or change `TESTPILOT_VALIDATION_VARIATION_SEED` to generate a different set. The configured count is capped at 20 per plan. These supplement, rather than replace, the explicit boundary cases.
- **No repeated approved cases**: when planning again for an unchanged target, cases already included in an approved validation test are omitted. If every executable case is already covered, TestPilot reports that instead of proposing a duplicate plan. A changed source fingerprint is treated as a new target version.
- **Rule-derived expectations**: deterministic expected results trace to `rules()`. Optional AI-proposed numeric inputs are independently evaluated against the supported rules before they are selected; uncheckable proposals are discarded.
- **Suspected bugs**: when some cases pass and others fail, the failures are reported as rules that are *declared but not enforced* (for example, the endpoint silently binds a weaker request).
- **Needs a human**: `unique`, `regex`, closures/rule objects, file rules and rules built at runtime (`array_merge(...)`) are listed but not generated. Boundaries that cannot exist are flagged too (e.g. `email|max:255` — Laravel already rejects addresses over 254 characters).
- **AI review (optional, `testpilot.validation.ai`, default on)**: one extra AI call per plan:
  - Up to six additional numeric inputs by default (`TESTPILOT_VALIDATION_AI_ADDITIONAL_CASES`, capped at 20), such as omitted optional fields, nulls, numeric strings and decimal values. TestPilot evaluates each candidate locally against the field's supported numeric rules, derives pass/fail itself, and omits duplicates. AI-generated outcomes are never trusted directly.
  - `regex` / `not_regex`: the AI proposes a matching and a non-matching value; TestPilot checks both with `preg_match` (and against the field's length / `in` rules) and retries once with the exact failure. Only checked values are used, so these cases stay **confirmed**.
  - Custom rule objects / closures: the AI reads the rule class (when it is inside the project) and proposes values. They cannot be checked locally, so they are **assumptions**, unselected by default; if one fails it is reported as "AI guess failed", never as a bug in your code.
  - Gap review: rules that look missing or weak for how the action uses the data (e.g. a hashed password with no `max`/`confirmed`). Shown in the plan as findings; never turned into tests.
  - The AI follows the AI policy, `#[AI(enabled: false)]`, budgets and model routing (`TESTPILOT_AI_MODEL_VALIDATION`, else the plan model). Turn it off per action with `#[Validation(ai: false)]`. If the call fails, the plan falls back to deterministic cases plus a warning.
- Routes with `{parameters}` are supported (see **URL parameters** below). Routes behind `auth` middleware get a factory user (`testpilot.validation.user_model`, default `App\Models\User`); `refresh_database` is used when `exists`/`unique`/auth are involved.

## Rate-limit tests (v0.3)

```php
#[RateLimit(maxAttempts: 5, decaySeconds: 60)]   // optional numbers: without them the route's throttle:5,1 is used
public function login(LoginRequest $request): JsonResponse
```

The expected limit comes from `#[RateLimit]` when it declares numbers, otherwise from the route's `throttle:N,M` middleware (parsed, never executed). Named limiters (`throttle:login`, defined with `RateLimiter::for`) are closures, so TestPilot asks you to declare the expected limit. Six cases are generated: N requests allowed, request N+1 gets 429, the 429 carries `Retry-After`, `X-RateLimit-Limit/Remaining` are correct, access returns after the window (`$this->travel()`, never `sleep()`), and a second client (IP, or user behind `auth`) is not blocked. Account lockout and limits above `limits.max_rate_limit_attempts` are left to a human.

If the declared limit and the route disagree, the declared one is tested, so a route that allows 10 attempts when 5 were promised shows up as a **suspected bug**. Tests send empty bodies: the throttle counts every request that reaches the route, whatever the controller answers.

## Free AI providers

No paid key needed. Pick one in `.env` (or **Settings → AI**):

| Driver | Key | Default model | Notes |
|---|---|---|---|
| `groq` | free at console.groq.com | `llama-3.3-70b-versatile` | Fast; a few requests per minute on the free tier |
| `openrouter` | free at openrouter.ai | `google/gemma-4-31b-it:free` | Any model ending in `:free` costs nothing |
| `gemini` | free at aistudio.google.com | `gemini-3.8-flash` | Free-tier daily quota |
| `ollama` | none | `qwen2.5-coder:7b` | Runs on your machine; `ollama pull` the model; works with `TESTPILOT_AI_POLICY=local` |

```dotenv
TESTPILOT_AI_DRIVER=groq
TESTPILOT_AI_API_KEY=gsk_...
TESTPILOT_AI_FREE_TIER=true   # record calls at $0 so the monthly budget is not used up (Ollama and ":free" models always are)
```

- The default per-step routes name Claude models; with another provider they are ignored and that provider's model is used. Set `TESTPILOT_AI_MODEL_*` to route steps to other models of the same provider.
- Temporary HTTP 429 rate limits are retried with provider timing. Daily, weekly, or monthly quota errors are not retried; generation is reported as blocked, with no test run claimed, and the dashboard suggests waiting for reset or switching provider/model.
- Free models follow the JSON format less reliably than Claude. Every answer is still schema-checked (and, for validation, checked with `preg_match`) with one corrective retry, so a weak answer fails loudly instead of producing a wrong test. Model names change: if a default is retired, set `TESTPILOT_AI_MODEL`.

### Check a provider

```bash
php artisan testpilot:ai-check                       # the configured provider
php artisan testpilot:ai-check --list-models         # what the provider offers
php artisan testpilot:ai-check --driver=groq --key-env=GROQ_API_KEY --model=openai/gpt-oss-120b
```

It sends a tiny built-in sample (no project code): connection, a JSON reply, and a validation review whose regex values must pass `preg_match`. It also checks the queue (below).

### Free-tier limits

- Groq's free tier counts the answer budget (`max_tokens`) against about 8000 tokens per minute, so the groq driver caps answers at 4096 tokens (`TESTPILOT_AI_MAX_OUTPUT_TOKENS` to change it). When a provider still answers "Limit N, Requested M", TestPilot retries once with a budget that fits; if the input alone is too large, the error says so.
- Reasoning models (`openai/gpt-oss-*`, `qwen3`) think before answering; TestPilot leaves room for that.

## Queue

Plan and generate jobs wait for the AI and for child Pest runs (up to 30 minutes). The queue connection's `retry_after` must be longer than that, or a slow job is handed out again and fails as *"has been attempted too many times"*. Add a connection to `config/queue.php`:

```php
'testpilot' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'testpilot', 'retry_after' => 1900, 'after_commit' => false],
```

```dotenv
TESTPILOT_QUEUE_CONNECTION=testpilot
```

and run the worker **on that connection**: `php artisan queue:work testpilot --queue=testpilot` (a worker started without the connection name uses the default connection's `retry_after`).

## Export test cases to a spreadsheet

Dashboard: **Targets → ⋯ → Export test cases to Excel (.xlsx)** (only the selected targets when some are ticked), or **⋯ → Export test cases (.xlsx)** on a target. CLI:

```bash
php artisan testpilot:export                              # storage/testpilot/exports/testpilot-test-cases_<date>.xlsx
php artisan testpilot:export --format=csv --module=validation --selected
php artisan testpilot:export --target="App\Http\Controllers\AdminController::register" --output=storage/app/register-cases.xlsx
```

API: `GET /testpilot/api/export?format=xlsx|csv&module=…&targets[]=…&selected=1` (same access rules as the dashboard; audited).

The `.xlsx` opens in Excel, Google Sheets (File → Import) and LibreOffice, and has three sheets:

- **Test cases**: one row per case of the latest plan per target and module: area, target, module, case id, name, type, field, rule, input, expected, basis (confirmed / assumption), evidence, confidence, priority, included, source (Rules, AI (checked), AI (assumption), AI plan, Reviewer), why it needs a human, plan status, approver, test status, last result and run, test file.
- **Summary**: per target and module: risk, criticality, cases, included, needs a human, passed, failed, statuses, mutation score, model.
- **AI rule gaps**: findings of the validation AI review (only when there are any).

CSV contains the *Test cases* sheet only, UTF-8 with BOM, and cells that a spreadsheet would run as a formula (`=`, `+`, `-`, `@`) are prefixed with `'`. No extra Composer package is needed (uses PHP's `zip` extension).

## Settings & dashboard passcode (v0.8)

**Settings → AI** edits the provider, API key, default model, per-step routing, prompt/response caching and budgets from the browser. Rules:

- **`.env` wins.** A value set in `.env` is shown locked (computed in the config file, so it still works with `config:cache`).
- **The API key is encrypted** with `APP_KEY`, never returned to the browser (only `sk-ant-…1234`), and can be tested with *Test connection*.
- **Every change is audited**; values of secrets are never logged. *Reset to default* removes a dashboard value.
- **Who may edit:** `TestPilotDashboard::manageSettingsUsing(fn ($request) => …)` (default: approvers). Turn editing off with `TESTPILOT_SETTINGS_EDITABLE=false`.
- Policy limits, scan paths and the Pest setup stay in code on purpose.

**Settings → Security** sets a dashboard passcode, asked on top of your app's login and the auth callback:

- Stored as a bcrypt hash, or set `TESTPILOT_DASHBOARD_PASSCODE` in `.env` (then it is locked in the UI).
- 5 wrong guesses per minute per user + IP, then HTTP 429; every failure is audited.
- Unlock lasts `TESTPILOT_DASHBOARD_UNLOCK_MINUTES` (default 12 hours) per browser; changing the passcode relocks every other browser; a lock button sits next to your name.

## AI cost control (v0.7) — Claude

Every AI call goes through one gateway: **data policy → budget → secret redaction → model routing → response cache → Claude → cost recording**.

| Lever | What it does | Typical effect |
|---|---|---|
| **Prompt caching** | The system prompt and each target's context (source, outline, HTTP/validation context) are sent as cached blocks. Plan → generate → repairs of one target reuse them. | Cached input billed at a fraction of the input price (Sonnet 5.5: $0.10 vs $2.00 per 1M) |
| **Response cache** | Same code + same prompt + same model → the previous answer is replayed, no API call. "Plan again" always asks fresh. Use a shared cache store so the team and CI benefit. | 100% saved on repeats |
| **Model routing** | Plan / generate on Sonnet, first repair on Haiku, later repairs escalate, optional Opus for `criticality: 'high'`. | Repairs ~20× cheaper |
| **Message Batches** | `php artisan testpilot:plan --all --batch` or *AI usage → Plan unplanned as batch*: one asynchronous job, results usually within an hour. | 50% off (stacks with caching) |
| **Budgets** | Monthly and per-target caps; `AiBudgetWarning` event once per month at 80%. | No surprise invoices |

```dotenv
TESTPILOT_AI_DRIVER=anthropic
TESTPILOT_AI_API_KEY=sk-ant-...
TESTPILOT_AI_PROMPT_CACHE=5m          # 5m | 1h | off
TESTPILOT_AI_CACHE_STORE=redis         # shared response cache
TESTPILOT_AI_MONTHLY_BUDGET=50
TESTPILOT_AI_TARGET_BUDGET=2
TESTPILOT_AI_MODEL_CRITICAL=claude-opus-5-5   # optional
```

Every call is recorded with fresh / cache-write / cache-read / output tokens, cost, and savings split into prompt cache, response cache and batch — shown on the **AI usage** page. Prompts and source code are never stored. Built-in prices are Claude's published rates as of November 2026; override them in `ai.pricing` when they change. Without a queue worker, schedule `php artisan testpilot:batches --poll`.

## HTTP feature tests — business logic in controllers (v0.6)

For "fat controllers" (validation, pricing, stock and saving all in one action):

```php
#[FeatureTest(focus: ['coupons', 'stock'])]          // optional: maxScenarios, fakes, focus
public function store(Request $request): JsonResponse { … }
```

1. **Plan** — the AI receives the action's source plus an *HTTP context* gathered without running anything: the route and middleware, whether a signed-in user is needed, how to fill URL parameters, the validation rules and a valid request body, the Eloquent models the action touches (table, fillable, factory or not) and the fakes to use. It returns request-level scenarios (*"SAVE10 coupon → 201, total 180, order saved"*), each **confirmed** (with the source line) or an **assumption**.
2. **Approve** — untick, edit, or add rules in plain English, as for unit tests.
3. **Generate & verify** — one Pest file with `RefreshDatabase`; every test sends a real request (`postJson('/api/checkout', …)`), asserts the status, key JSON fields, database rows and faked side effects (`Queue::assertPushed(SendInvoice::class)`). Same safety check, run, repair and stability gates as unit tests; mutation testing is skipped at HTTP level.
4. **Suspected bugs** — if the app breaks an approved, confirmed rule (e.g. SAVE10 stops applying), the repair step keeps the assertion and marks the scenario; it is never weakened to pass.

Default fakes: `Mail`, `Notification`, `Queue`, and `Http::preventStrayRequests()` (no real HTTP calls). Override with `#[FeatureTest(fakes: ['mail', 'event', 'storage'])]`. Models that need data must have factories; the plan says when one is missing. Exhaustive rule-by-rule validation stays in the validation module.

## Inline validation — no FormRequest needed (v0.5)

Fat controllers work as they are. Add `#[Validation]` and keep validating inside the action:

| Your code | What "rejected" means in the tests |
|---|---|
| `$request->validate([...])`, `request()->validate()`, `$this->validate($request, $rules)`, `validateWithBag()`, `Validator::make(...)->validate()` / `->validated()` | 422 with an error on that field (Laravel answers JSON test requests this way even in web controllers) |
| `if ($v->fails()) return response()->json(…, 400);` (or `abort(409)`) | that status code; the error body is not assumed |
| `if ($v->fails()) return back()->withErrors($v);` | 302 + that field's error in the session (`assertSessionHasErrors`) |
| A helper builds the failure response (`return $this->fail($v)`) | declare it: `#[Validation(errorStatus: 400)]` |

Rules may be a literal array or a variable assigned a literal array in the same method. Rules built at runtime are listed for a human. TestPilot warns when code runs *before* validation (e.g. `Order::create()`), or when validation only runs inside an `if`. A redirect without `->withErrors()` looks like success, so TestPilot asks you to add `withErrors()` or declare the status.

## URL parameters (v0.4)

Validation and rate-limit tests work on routes like `PUT /orders/{order}` and `PATCH /customers/{customer}/orders/{order:reference}`. TestPilot matches each `{parameter}` to the action's type-hinted argument (by name) and, inside `beforeEach`, creates what the URL needs:

| Parameter | Generated setup |
|---|---|
| Eloquent model (`Order $order`) | `$order = Order::factory()->create();` → URL uses `$order->getRouteKey()` |
| Custom key (`{order:reference}`) | URL uses `$order->reference` |
| Scoped child (custom key or `->scopeBindings()`) | `Order::factory()->for($customer)->create()` when `Order::customer()` exists |
| Backed enum (`Channel $channel`) | `Channel::cases()[0]->value` |
| Anything else (`int $id`) | the placeholder `1` (validation and throttling run before the controller looks anything up) |

Models need `HasFactory` and a factory; otherwise planning stops with a message saying exactly what to add. Tests that create records use `RefreshDatabase`. If the route answers **404**, the bindings did not resolve: TestPilot reports a setup problem instead of a suspected bug.

## Quality

```bash
cd packages/core && composer install && vendor/bin/pest      # 132 tests: scanner, resolver, AI (planning, caching, routing, pricing, batches), safety, validation and rate-limit engines, URL bindings, real Pest runs
cd packages/laravel && composer install && vendor/bin/pest   # 64 tests: unit, HTTP feature, validation, rate-limit and URL-parameter workflows, AI budgets, response cache, batch planning, settings and passcode on Laravel 12
cd ui && npm install && npm run build
```

The core integration tests run the real Pest binary against a sandbox project: generation, repair, suspected-bug detection, unsafe-code rejection and repair-budget exhaustion are all exercised for real.

## Security model

See [docs/security.md](docs/security.md). In short: static scanning only; generated code is checked for forbidden constructs before it ever runs; tests run in a child process with an allow-listed environment and a timeout; production environments are refused; writes are restricted to two directories; secrets are redacted before any AI call; every approval is audited.

## License

MIT
