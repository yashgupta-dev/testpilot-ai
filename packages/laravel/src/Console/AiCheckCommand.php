<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use Throwable;
use TestPilot\Ai\AiDataPolicy;
use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\JsonExtractor;
use TestPilot\Ai\ModelRouter;
use TestPilot\Ai\ProviderFactory;
use TestPilot\Ai\Providers\OpenAiCompatibleProvider;
use TestPilot\Laravel\Services\EloquentUsageRecorder;
use TestPilot\Laravel\Services\SettingsService;
use TestPilot\Validation\AiValidationAdvisor;
use TestPilot\Validation\FormRequestRulesExtractor;
use TestPilot\Validation\ValidationCaseGenerator;

/**
 * Checks that an AI provider works for TestPilot, end to end, with a tiny built-in sample (no project code is sent):
 *
 *   1. connection — the provider answers (key, URL, model)
 *   2. JSON       — the model returns a parseable JSON object, which every TestPilot step needs
 *   3. validation — the AI validation review on a sample rule set: regex values must pass preg_match
 *
 * Try another provider without changing .env:  --driver=groq --key-env=GROQ_API_KEY [--model=…]
 */
final class AiCheckCommand extends Command
{
    protected $signature = 'testpilot:ai-check
        {--driver= : Provider to check instead of the configured one (anthropic, openai, groq, openrouter, gemini, ollama, custom)}
        {--model= : Model to check (default: the configured / provider default model)}
        {--base-url= : Endpoint for custom or self-hosted providers}
        {--key-env= : Name of the environment variable holding the API key for --driver (the key is never printed)}
        {--skip-validation : Only check the connection and JSON}
        {--list-models : List the models the provider offers}';

    protected $description = 'Check that an AI provider (Claude, Gemini, Groq, OpenRouter, Ollama, …) works with TestPilot';

    private const SAMPLE = <<<'PHP'
    <?php
    namespace Sample\Http\Requests;
    use Illuminate\Foundation\Http\FormRequest;
    final class StoreCouponRequest extends FormRequest
    {
        public function rules(): array
        {
            return [
                'code' => ['required', 'string', 'regex:/^[A-Z]{4}-\d{2}$/'],
                'percent' => ['required', 'integer', 'min:1', 'max:90'],
                'password' => ['required', 'string', 'min:8'],
            ];
        }
    }
    PHP;

    public function handle(SettingsService $settings, EloquentUsageRecorder $usage): int
    {
        $settings->apply();
        $config = (array) config('testpilot.ai', []);
        $override = $this->option('driver') !== null;

        if ($override) {
            $config['driver'] = (string) $this->option('driver');
            $config['model'] = $this->option('model');
            $config['base_url'] = $this->option('base-url');
            $config['api_key'] = $this->option('key-env') ? (string) env((string) $this->option('key-env'), '') : '';
            if ($this->option('key-env') && $config['api_key'] === '') {
                $this->components->warn('Environment variable ' . $this->option('key-env') . ' is empty.');
            }
        } elseif ($this->option('model')) {
            $config['model'] = (string) $this->option('model');
        }

        try {
            $provider = ProviderFactory::make($config);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $policy = AiDataPolicy::tryFrom((string) ($config['policy'] ?? 'cloud')) ?? AiDataPolicy::Cloud;
        // The check names its model explicitly, so per-step routes (which may name Claude models) are not used.
        $gateway = new AiGateway($provider, $policy, $usage, router: new ModelRouter());

        $this->components->twoColumnDetail('Provider', $provider->name() . ($provider->isLocal() ? ' (local)' : ' (cloud)'));
        $this->components->twoColumnDetail('Model', $provider->model());
        $this->components->twoColumnDetail('Data policy', $policy->value);
        $this->components->twoColumnDetail('Calls recorded at', (bool) ($config['free_tier'] ?? false) || $provider->name() === 'ollama' || str_ends_with($provider->model(), ':free') ? '$0 (free)' : 'list price');
        $this->checkQueue();
        $this->newLine();

        if ($this->option('list-models')) {
            $this->listModels($provider);
        }

        $ok = true;

        // 1 + 2: connection and JSON.
        try {
            $started = hrtime(true);
            $response = $gateway->send(AiRequest::single('test', 'You are a connectivity check for a test tool. Reply with JSON only.', 'Return exactly this JSON object, with the sum filled in: {"ok": true, "sum": <2 + 3>}', 1024)->withMeta(['json' => true])); // reasoning models (gpt-oss, qwen3) think first
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->components->twoColumnDetail('1. Connection', "<fg=green>OK</> {$ms} ms, {$response->inputTokens}+{$response->outputTokens} tokens, model {$response->model}");

            try {
                $json = JsonExtractor::object($response->content);
                $right = ($json['ok'] ?? null) === true && (int) ($json['sum'] ?? 0) === 5;
                $this->components->twoColumnDetail('2. JSON reply', $right ? '<fg=green>OK</>' : '<fg=yellow>parsed, but the content is wrong</> ' . mb_substr($response->content, 0, 80));
                $ok = $ok && $right;
            } catch (Throwable) {
                $this->components->twoColumnDetail('2. JSON reply', '<fg=red>FAILED</> not a JSON object: ' . mb_substr(trim($response->content), 0, 80));
                $ok = false;
            }
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('1. Connection', '<fg=red>FAILED</>');
            $this->components->error($e->getMessage());
            if ($provider instanceof OpenAiCompatibleProvider && ! $this->option('list-models') && str_contains($e->getMessage(), 'HTTP 404')) {
                $this->listModels($provider);
            }

            return self::FAILURE;
        }

        // 3: the validation AI review on a built-in sample.
        if (! $this->option('skip-validation')) {
            $ok = $this->checkValidation($gateway) && $ok;
        }

        $this->newLine();
        $ok
            ? $this->components->info('This provider works with TestPilot.')
            : $this->components->warn('The provider answers, but not reliably enough for every step. Try a larger model.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Plan and generate jobs wait for the AI (and Pest). If the queue's retry_after is shorter than the job, the queue
     * hands the running job out again and it fails as "attempted too many times".
     */
    private function checkQueue(): void
    {
        $name = config('testpilot.queue.connection') ?: config('queue.default');
        $connection = (array) config("queue.connections.{$name}", []);
        $retryAfter = (int) ($connection['retry_after'] ?? 0);
        $needed = (int) (new \ReflectionProperty(\TestPilot\Laravel\Jobs\GenerateTestsJob::class, 'timeout'))->getDefaultValue();

        if (($connection['driver'] ?? 'sync') === 'sync' || $retryAfter > $needed) {
            $this->components->twoColumnDetail('Queue', "{$name}" . ($retryAfter > 0 ? " (retry_after {$retryAfter}s)" : '') . ' <fg=green>OK</>');

            return;
        }

        $this->components->twoColumnDetail('Queue', "<fg=yellow>{$name}: retry_after {$retryAfter}s is shorter than TestPilot jobs ({$needed}s)</>");
        $this->components->warn('Slow AI calls will fail as "attempted too many times". Add a queue connection with retry_after above '
            . $needed . ' (see README → Queue) and set TESTPILOT_QUEUE_CONNECTION to it.');
    }

    private function checkValidation(AiGateway $gateway): bool
    {
        $rules = (new FormRequestRulesExtractor())->extract(self::SAMPLE, 'Sample\Http\Requests\StoreCouponRequest', 'sample/StoreCouponRequest.php');
        $generated = (new ValidationCaseGenerator())->generate($rules);
        $advisor = new AiValidationAdvisor($gateway);

        try {
            $started = hrtime(true);
            $advice = $advisor->advise($rules, $advisor->buildRequest($rules, $generated['baseline'], self::SAMPLE), null, true);
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('3. Validation review', '<fg=red>FAILED</> ' . mb_substr($e->getMessage(), 0, 120));

            return false;
        }

        $value = $advice->values['Q1'] ?? null;
        $this->components->twoColumnDetail(
            '3. Validation review',
            ($value !== null ? '<fg=green>OK</>' : '<fg=red>FAILED</>') . " {$ms} ms, {$advice->calls} call(s)",
        );
        $this->components->twoColumnDetail(
            '   regex /^[A-Z]{4}-\d{2}$/',
            $value === null
                ? 'no value passed preg_match' . ($advice->rejected === [] ? '' : ' — ' . mb_substr($advice->rejected[0]['reason'], 0, 90))
                : sprintf('valid %s, invalid %s (checked with preg_match)', json_encode($value['valid']), json_encode($value['invalid'])),
        );
        $this->components->twoColumnDetail('   rule gaps found', (string) count($advice->gaps) . ($advice->gaps === [] ? '' : ' — e.g. ' . mb_substr($advice->gaps[0]['field'] . ': ' . $advice->gaps[0]['issue'], 0, 80)));

        return $value !== null;
    }

    private function listModels(\TestPilot\Ai\AiProviderInterface $provider): void
    {
        if (! $provider instanceof OpenAiCompatibleProvider) {
            $this->components->info('Model listing is available for OpenAI-compatible providers (openai, groq, openrouter, gemini, ollama, custom).');

            return;
        }

        try {
            $models = $provider->listModels();
        } catch (Throwable $e) {
            $this->components->warn('Could not list models: ' . $e->getMessage());

            return;
        }
        if ($models === []) {
            $this->components->warn('The provider did not return a model list (check the key).');

            return;
        }

        // OpenRouter lists hundreds of paid models; show the free ones there.
        $free = $provider->name() === 'openrouter' ? array_values(array_filter($models, static fn (string $m): bool => str_ends_with($m, ':free'))) : [];
        $shown = $free !== [] ? $free : $models;
        $this->components->info(($free !== [] ? count($free) . ' free models' : count($models) . ' models') . ' available' . (count($shown) > 40 ? ' (first 40)' : '') . ':');
        foreach (array_slice($shown, 0, 40) as $model) {
            $this->line("  {$model}");
        }
        $this->newLine();
    }
}
