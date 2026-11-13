<?php

declare(strict_types=1);

namespace TestPilot\Generation;

use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\ContextBuilder;
use TestPilot\Ai\PromptRegistry;
use TestPilot\Configuration\Module;
use TestPilot\Configuration\ResolvedConfig;
use TestPilot\DTO\TestTarget;
use TestPilot\Exceptions\InvalidAiResponseException;
use TestPilot\Fuzz\FuzzDatasetBuilder;

/**
 * Step 2 of generation: turns approved scenarios into one Pest test file, and repairs it when it fails.
 */
final class TestCodeGenerator
{
    /** @var array<string, array{0: string, 1: bool}> target key => [context sent with generate, feature?] */
    private array $contexts = [];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly ContextBuilder $context,
        private readonly GenerationOptions $options = new GenerationOptions(),
        private readonly PromptRegistry $prompts = new PromptRegistry(),
        private readonly FuzzDatasetBuilder $fuzz = new FuzzDatasetBuilder(),
    ) {
    }

    /**
     * @return array{code: string, fuzz_seed: int|null, model: string}
     */
    public function generate(TestTarget $target, ResolvedConfig $config, ScenarioPlan $plan): array
    {
        $scenarios = $plan->selected();
        if ($scenarios === []) {
            throw new InvalidAiResponseException('No scenarios were approved, so there is nothing to generate');
        }

        $fuzzSection = '';
        $seed = null;
        $hasFuzz = array_filter($scenarios, static fn (Scenario $s): bool => $s->module === 'fuzz') !== [];

        if ($hasFuzz && $config->isEnabled(Module::Fuzz)) {
            $dataset = $this->fuzz->build(
                $target,
                (int) $config->option(Module::Fuzz, 'cases', 50),
                ($s = $config->option(Module::Fuzz, 'seed')) !== null ? (int) $s : null,
            );

            if ($dataset !== null) {
                $seed = $dataset['seed'];
                $names = implode(', ', array_map(static fn (string $n): string => '$' . $n, array_keys($dataset['cases'][0] ?? [])));
                $fuzzSection = "Fuzz dataset (seed {$seed}). Parameters in order: {$names}."
                    . ($dataset['skipped'] !== [] ? ' Build valid values yourself for: $' . implode(', $', $dataset['skipped']) . '.' : '')
                    . "\nDeclare it as: dataset('fuzz_seed_{$seed}', <array below>); and use ->with('fuzz_seed_{$seed}').\n```php\n{$dataset['code']}\n```";
            }
        }

        $context = $this->context->build($target, $config);
        $this->contexts[$target->key] = [$context, false];
        $request = AiRequest::withContext(
            purpose: 'generate',
            system: $this->unitSystem($target),
            context: $context,
            task: $this->prompts->render('generate.user', [
                'scenarios' => (string) json_encode(array_map(static fn (Scenario $s): array => $s->toArray(), $scenarios), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'fuzz_dataset' => $fuzzSection,
            ]),
            maxTokens: 8192,
            promptVersion: $this->prompts->version(),
            meta: ['criticality' => $config->criticality, 'target' => $target->key],
        );

        $response = $this->ai->send($request, $target->key);

        return ['code' => self::extract($response), 'fuzz_seed' => $seed, 'model' => $response->model];
    }

    /**
     * @return array{code: string, fuzz_seed: null, model: string}
     */
    public function generateFeature(TestTarget $target, ResolvedConfig $config, ScenarioPlan $plan, string $httpContext): array
    {
        $scenarios = $plan->selected();
        if ($scenarios === []) {
            throw new InvalidAiResponseException('No scenarios were approved, so there is nothing to generate');
        }

        $context = rtrim($httpContext) . "\n\n" . $this->context->build($target, $config);
        $this->contexts[$target->key] = [$context, true];
        $request = AiRequest::withContext(
            purpose: 'generate',
            system: $this->featureSystem(),
            context: $context,
            task: $this->prompts->render('feature.generate.user', [
                'scenarios' => (string) json_encode(array_map(static fn (Scenario $s): array => $s->toArray(), $scenarios), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]),
            maxTokens: 8192,
            promptVersion: $this->prompts->version(),
            meta: ['criticality' => $config->criticality, 'target' => $target->key],
        );

        $response = $this->ai->send($request, $target->key);

        return ['code' => self::extract($response), 'fuzz_seed' => null, 'model' => $response->model];
    }

    /**
     * Repairs a failing test. Uses the same system prompt and target context as generation, so with
     * prompt caching the repair only pays full price for the failure output and the current file.
     * Attempt 1 is routed to the cheap repair model; later attempts escalate (see ModelRouter).
     */
    public function repair(TestTarget $target, string $code, string $failure, bool $feature = false, int $attempt = 1): string
    {
        [$context] = $this->contexts[$target->key] ?? [null];
        $system = $feature ? $this->featureSystem() : $this->unitSystem($target);
        $task = $this->prompts->render('repair.user', ['failure' => mb_substr($failure, 0, 6000), 'code' => $code]);
        $meta = ['attempt' => $attempt, 'target' => $target->key];

        $request = $context === null
            ? (new AiRequest('repair', $system, [['role' => 'user', 'content' => $task]], 8192, 0.2, $this->prompts->version(), null, null, null, $meta))
            : AiRequest::withContext('repair', $system, $context, $task, 8192, $this->prompts->version(), $meta);

        return self::extract($this->ai->send($request, $target->key));
    }

    /** Extracts the PHP file, naming truncation as the cause when the model ran out of output tokens. */
    private static function extract(AiResponse $response): string
    {
        try {
            return CodeExtractor::php($response->content);
        } catch (InvalidAiResponseException $e) {
            if (! in_array($response->stopReason, ['length', 'max_tokens'], true)) {
                throw $e;
            }

            throw new InvalidAiResponseException(
                "The AI response was cut off at the output token limit ({$response->outputTokens} tokens) before the PHP file was complete. "
                . 'Raise TESTPILOT_AI_MAX_OUTPUT_TOKENS, use a non-reasoning model, or select fewer scenarios',
                [],
                $e->raw,
            );
        }
    }

    private function unitSystem(TestTarget $target): string
    {
        return $this->prompts->render('generate.system', [
            'test_case_instruction' => $this->options->testCaseInstruction(),
            'mutates_instruction' => $this->options->mutatesInstruction($target->class),
        ]);
    }

    private function featureSystem(): string
    {
        return $this->prompts->render('feature.generate.system', ['test_case_instruction' => $this->options->testCaseInstruction()]);
    }
}
