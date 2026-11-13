<?php

declare(strict_types=1);

return [

    /*
    | Project root. Defaults to base_path(); only change it for monorepos or tests.
    */
    'root' => env('TESTPILOT_ROOT'),

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    | Paths (relative to base_path()) scanned for TestPilot attributes. Files are parsed
    | statically; they are never included or executed during scanning.
    */
    'paths' => ['app'],

    'exclude' => [
        // 'app/Legacy/*',
    ],

    /*
    | Where generated tests live. Pending tests are verified but not yet approved and are
    | deliberately kept outside tests/ so they never run in your normal suite.
    */
    'generated_path' => 'tests/TestPilot/Generated',
    'pending_path' => 'storage/testpilot/pending',

    /*
    |--------------------------------------------------------------------------
    | Project defaults (lowest precedence; annotations override them)
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'modules' => [
            // 'fuzz' => false,
        ],
        'options' => [
            'unit' => ['max_scenarios' => 8],
            'fuzz' => ['cases' => 50],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Policy limits — annotations and AI output can never exceed these.
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_fuzz_cases' => 200,
        'max_scenarios' => 20,
        'max_rate_limit_attempts' => 500,
        'max_repair_attempts' => 3,
        'process_timeout' => 300,
        'ai_allowed' => env('TESTPILOT_AI_ALLOWED', true),
        'blocked_environments' => ['production', 'prod'],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    | driver: anthropic | openai | groq | openrouter | gemini | ollama | custom | fake
    |
    | Free options (no card needed; rate-limited; model defaults in TestPilot\Ai\ProviderFactory::DEFAULT_MODELS):
    |   groq       — key from console.groq.com            (default model llama-3.3-70b-versatile)
    |   openrouter — key from openrouter.ai, ":free" models (default google/gemma-4-31b-it:free)
    |   gemini     — key from aistudio.google.com         (default gemini-3.8-flash)
    | Check any provider with: php artisan testpilot:ai-check
    |   ollama     — local, no key; pair with policy=local (default qwen2.5-coder:7b, `ollama pull` it first)
    | Set free_tier=true for free Groq/Gemini keys so calls are recorded at $0 (Ollama and ":free" always are).
    | policy: off   — never call an AI provider
    |         local — only providers on localhost / private network (e.g. Ollama)
    |         cloud — cloud providers allowed; secrets are redacted before sending
    */
    'ai' => [
        'driver' => env('TESTPILOT_AI_DRIVER', 'anthropic'),
        'model' => env('TESTPILOT_AI_MODEL'),
        'api_key' => env('TESTPILOT_AI_API_KEY'),
        'base_url' => env('TESTPILOT_AI_BASE_URL'),
        'timeout' => (int) env('TESTPILOT_AI_TIMEOUT', 120),
        'policy' => env('TESTPILOT_AI_POLICY', 'cloud'),
        'free_tier' => (bool) env('TESTPILOT_AI_FREE_TIER', false),
        // Cap for the answer length (max_tokens). Groq's free tier counts it against 8000 tokens/minute, so the groq
        // driver defaults to 4096; other drivers use each step's own budget. Empty = default.
        'max_output_tokens' => env('TESTPILOT_AI_MAX_OUTPUT_TOKENS') !== null ? (int) env('TESTPILOT_AI_MAX_OUTPUT_TOKENS') : null,

        /*
         * Model routing — the cheapest model that does each step well. Empty values use 'model' above.
         * Defaults assume the anthropic driver. With another driver, claude-* entries are ignored and that
         * provider's model is used; set your own names here to route steps to different models of it.
         */
        'models' => [
            'plan' => env('TESTPILOT_AI_MODEL_PLAN', 'claude-sonnet-5-5'),
            'generate' => env('TESTPILOT_AI_MODEL_GENERATE', 'claude-sonnet-5-5'),
            'repair' => env('TESTPILOT_AI_MODEL_REPAIR', 'claude-haiku-5-5'),             // first repair: imports, mocks, typos
            'repair_escalation' => env('TESTPILOT_AI_MODEL_REPAIR_ESCALATION', 'claude-sonnet-5-5'), // later repairs
            'validation' => env('TESTPILOT_AI_MODEL_VALIDATION'),                         // validation review; empty = 'plan'
            'critical' => env('TESTPILOT_AI_MODEL_CRITICAL'),                             // e.g. claude-opus-5-5 for #[TestPilot(criticality: 'high')]
        ],

        /*
         * Prompt caching (Anthropic): the system prompt and each target's context are cached, so repairs
         * and re-plans pay ~10% of the input price for them. "5m" (write 1.25×) suits interactive use;
         * "1h" (write 2×) suits teams that approve plans later the same hour; "off" disables.
         */
        'prompt_cache' => env('TESTPILOT_AI_PROMPT_CACHE', '5m'),

        /*
         * Response cache: identical requests (same code, same prompts, same model) replay the previous
         * answer at no cost. "Plan again" always bypasses it. Use a shared store (redis/database) so the
         * whole team benefits.
         */
        'response_cache' => [
            'enabled' => (bool) env('TESTPILOT_AI_RESPONSE_CACHE', true),
            'store' => env('TESTPILOT_AI_CACHE_STORE'), // null = default cache store
            'ttl_days' => (int) env('TESTPILOT_AI_CACHE_DAYS', 14),
        ],

        // Message Batches (anthropic): plan many targets at 50% of the price; results usually within an hour.
        'batch' => [
            'poll_seconds' => (int) env('TESTPILOT_AI_BATCH_POLL', 60),
            'max_requests' => 500,
        ],

        // Budgets in USD. 0 disables a check. warn_at fires TestPilot\Laravel\Events\AiBudgetWarning once per month.
        'monthly_budget' => (float) env('TESTPILOT_AI_MONTHLY_BUDGET', 50),
        'target_monthly_budget' => (float) env('TESTPILOT_AI_TARGET_BUDGET', 0),
        'warn_at' => 0.8,

        /*
         * USD per 1M tokens, merged over TestPilot's built-in Claude prices (TestPilot\Ai\Pricing::DEFAULTS).
         * Prices change: check https://claude.com/pricing and override here. Keys: input, output,
         * cache_write_5m, cache_write_1h, cache_read (cache prices default to 1.25×, 2× and 0.1× of input).
         */
        'pricing' => [
            // 'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pest
    |--------------------------------------------------------------------------
    */
    'pest' => [
        'binary' => ['vendor/bin/pest'],
        // Bound with uses() in generated tests so they boot your application.
        'test_case' => 'Tests\\TestCase',
        'stability_runs' => 3,
        'env' => [
            // Extra environment variables for the test process, e.g. 'DB_CONNECTION' => 'sqlite'.
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation tests (#[Validation] on a controller method)
    |--------------------------------------------------------------------------
    | The baseline plan is generated from FormRequest::rules(). Routes that require auth are called as a
    | user created with this model's factory.
    |
    | ai: one extra AI call per plan that proposes checked numeric examples, proposes regex values checked
    | with preg_match, proposes unselected assumptions for custom rules, and reports possible rule gaps.
    | It follows the AI policy, budgets and model routing. Override per action with #[Validation(ai: false)].
    | Follows the AI policy, #[AI(enabled: false)] and budgets; a failed call falls back to the rules alone.
    */
    'validation' => [
        'ai' => (bool) env('TESTPILOT_VALIDATION_AI', true),
        'ai_additional_cases' => (int) env('TESTPILOT_VALIDATION_AI_ADDITIONAL_CASES', 6),
        'additional_cases' => (int) env('TESTPILOT_VALIDATION_ADDITIONAL_CASES', 2),
        'variation_seed' => (int) env('TESTPILOT_VALIDATION_VARIATION_SEED', 42),
        'user_model' => 'App\\Models\\User',
        'refresh_database' => true,
    ],

    'mutation' => [
        // Requires a coverage driver (PCOV recommended, or Xdebug in coverage mode).
        'enabled' => env('TESTPILOT_MUTATION', true),
        'min_score' => 60,
    ],

    /*
    | Short notes sent to the AI as "project conventions".
    */
    'conventions' => [
        'Laravel application tested with Pest.',
        'Use model factories when an Eloquent model is needed; never hit real external services.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'enabled' => env('TESTPILOT_DASHBOARD', true),
        'path' => env('TESTPILOT_PATH', 'testpilot'),
        'domain' => env('TESTPILOT_DOMAIN'),
        'middleware' => ['web'],
        // The dashboard refuses to load in these environments unless explicitly allowed.
        'allow_in_production' => false,

        // Optional passcode asked before the dashboard opens (on top of the auth callback). Set it here to
        // manage it from .env, or leave empty and set it in Settings → Security (stored hashed).
        'passcode' => env('TESTPILOT_DASHBOARD_PASSCODE'),
        'unlock_minutes' => (int) env('TESTPILOT_DASHBOARD_UNLOCK_MINUTES', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings editable from the dashboard
    |--------------------------------------------------------------------------
    | AI settings and the passcode can be changed in Settings (by people allowed by
    | TestPilotDashboard::manageSettingsUsing, default: approvers). Anything set in .env
    | wins and is shown locked. Turn editing off entirely with TESTPILOT_SETTINGS_EDITABLE=false.
    */
    'settings' => [
        'editable' => (bool) env('TESTPILOT_SETTINGS_EDITABLE', true),
        // Computed here (not at runtime) so it still works with `php artisan config:cache`.
        'env_locked' => array_values(array_filter([
            'TESTPILOT_AI_DRIVER', 'TESTPILOT_AI_FREE_TIER', 'TESTPILOT_AI_API_KEY', 'TESTPILOT_AI_MODEL', 'TESTPILOT_AI_BASE_URL', 'TESTPILOT_AI_POLICY',
            'TESTPILOT_AI_MODEL_PLAN', 'TESTPILOT_AI_MODEL_GENERATE', 'TESTPILOT_AI_MODEL_REPAIR', 'TESTPILOT_AI_MODEL_REPAIR_ESCALATION',
            'TESTPILOT_AI_MODEL_CRITICAL', 'TESTPILOT_AI_MODEL_VALIDATION', 'TESTPILOT_VALIDATION_AI_ADDITIONAL_CASES', 'TESTPILOT_AI_PROMPT_CACHE', 'TESTPILOT_AI_RESPONSE_CACHE', 'TESTPILOT_AI_CACHE_DAYS',
            'TESTPILOT_AI_MONTHLY_BUDGET', 'TESTPILOT_AI_TARGET_BUDGET', 'TESTPILOT_DASHBOARD_UNLOCK_MINUTES',
        ], static fn (string $name): bool => env($name) !== null)),
    ],

    'queue' => [
        'connection' => env('TESTPILOT_QUEUE_CONNECTION'),
        'name' => env('TESTPILOT_QUEUE', 'testpilot'),
    ],

    'git' => [
        // Used for the "changed recently" risk signal. Set to false if git is unavailable.
        'enabled' => true,
        'window_days' => 30,
    ],

    'retention_days' => 90,
];
