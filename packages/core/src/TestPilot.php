<?php

declare(strict_types=1);

namespace TestPilot;

use TestPilot\Ai\AiDataPolicy;
use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiProviderInterface;
use TestPilot\Ai\ContextBuilder;
use TestPilot\Ai\NullUsageRecorder;
use TestPilot\Ai\ProviderFactory;
use TestPilot\Ai\UsageRecorder;
use TestPilot\Configuration\ConfigurationResolver;
use TestPilot\Configuration\PolicyLimits;
use TestPilot\Discovery\ProjectScanner;
use TestPilot\Execution\PestRunner;
use TestPilot\Generation\GenerationOptions;
use TestPilot\Generation\ScenarioPlanner;
use TestPilot\Generation\TestCodeGenerator;
use TestPilot\Security\EnvironmentGuard;
use TestPilot\Security\PathGuard;
use TestPilot\Storage\TestFileWriter;
use TestPilot\Verification\GenerationPipeline;

/**
 * Wires the core services from a plain configuration array. Framework adapters (Laravel, plain PHP CLI)
 * build one of these; nothing in core depends on a framework.
 */
final class TestPilot
{
    public readonly PathGuard $paths;
    public readonly PolicyLimits $limits;
    public readonly ConfigurationResolver $resolver;
    public readonly ProjectScanner $scanner;
    public readonly EnvironmentGuard $environment;
    public readonly PestRunner $runner;
    public readonly TestFileWriter $writer;

    private ?AiGateway $gateway = null;

    /** @param array<string, mixed> $config Same shape as the Laravel config/testpilot.php file. */
    public function __construct(
        string $root,
        private readonly array $config = [],
        string $environment = 'local',
        private readonly UsageRecorder $usage = new NullUsageRecorder(),
        private readonly ?AiProviderInterface $provider = null,
        private readonly ?\TestPilot\Ai\ResponseCache $responseCache = null,
    ) {
        $this->paths = new PathGuard($root);
        $this->limits = PolicyLimits::fromArray((array) ($config['limits'] ?? []));
        $this->resolver = ConfigurationResolver::fromArray((array) ($config['defaults'] ?? []), $this->limits);
        $this->scanner = new ProjectScanner($this->paths, array_values((array) ($config['exclude'] ?? [])));
        $this->environment = new EnvironmentGuard($environment, $this->limits->blockedEnvironments);

        $pest = (array) ($config['pest'] ?? []);
        $this->runner = new PestRunner(
            $this->paths,
            $this->environment,
            array_values((array) ($pest['binary'] ?? ['vendor/bin/pest'])),
            $this->limits->processTimeoutSeconds,
            (array) ($pest['env'] ?? []),
        );
        $this->writer = new TestFileWriter(
            $this->paths,
            (string) ($config['generated_path'] ?? 'tests/TestPilot/Generated'),
            (string) ($config['pending_path'] ?? 'storage/testpilot/pending'),
        );
    }

    /** @return list<string> */
    public function scanPaths(): array
    {
        return array_values((array) ($this->config['paths'] ?? ['app', 'src']));
    }

    public function ai(): AiGateway
    {
        if ($this->gateway !== null) {
            return $this->gateway;
        }

        $ai = (array) ($this->config['ai'] ?? []);

        $cache = (array) ($ai['response_cache'] ?? []);
        $provider = $this->provider ?? ProviderFactory::make($ai);

        // The routing defaults name Claude models; another provider (Groq, Gemini, Ollama, …) would reject them,
        // so they fall back to that provider's own model. Routes naming the provider's own models are kept.
        $claudeOnly = ! in_array($provider->name(), ['anthropic', 'fake'], true);
        $models = array_filter(
            (array) ($ai['models'] ?? []),
            static fn ($m): bool => is_string($m) && $m !== '' && ! ($claudeOnly && str_starts_with($m, 'claude-')),
        );

        return $this->gateway = new AiGateway(
            $provider,
            AiDataPolicy::tryFrom((string) ($ai['policy'] ?? 'cloud')) ?? AiDataPolicy::Cloud,
            $this->usage,
            new \TestPilot\Ai\Redactor(),
            new \TestPilot\Ai\ModelRouter($models),
            ($cache['enabled'] ?? true) ? $this->responseCache : null,
        );
    }

    /** Message Batches client, when the configured provider supports it (Anthropic). */
    public function batches(): ?\TestPilot\Ai\Batch\AnthropicBatchClient
    {
        $provider = $this->ai()->provider();

        return $provider instanceof \TestPilot\Ai\Providers\AnthropicProvider ? new \TestPilot\Ai\Batch\AnthropicBatchClient($provider) : null;
    }

    public function context(): ContextBuilder
    {
        return new ContextBuilder(array_values((array) ($this->config['conventions'] ?? [])));
    }

    public function planner(): ScenarioPlanner
    {
        return new ScenarioPlanner($this->ai(), $this->context());
    }

    public function validationAdvisor(): \TestPilot\Validation\AiValidationAdvisor
    {
        return new \TestPilot\Validation\AiValidationAdvisor($this->ai());
    }

    public function generator(): TestCodeGenerator
    {
        $pest = (array) ($this->config['pest'] ?? []);

        return new TestCodeGenerator(
            $this->ai(),
            $this->context(),
            new GenerationOptions(
                testCaseClass: isset($pest['test_case']) && $pest['test_case'] !== '' ? (string) $pest['test_case'] : null,
                declareMutates: (bool) (($this->config['mutation']['enabled'] ?? true)),
            ),
        );
    }

    public function pipeline(): GenerationPipeline
    {
        $mutation = (array) ($this->config['mutation'] ?? []);
        $pest = (array) ($this->config['pest'] ?? []);

        return new GenerationPipeline(
            generator: $this->generator(),
            runner: $this->runner,
            writer: $this->writer,
            maxRepairs: $this->limits->maxRepairAttempts,
            stabilityRuns: max(1, (int) ($pest['stability_runs'] ?? 3)),
            mutationEnabled: (bool) ($mutation['enabled'] ?? true),
            minMutationScore: (float) ($mutation['min_score'] ?? 60),
        );
    }
}
