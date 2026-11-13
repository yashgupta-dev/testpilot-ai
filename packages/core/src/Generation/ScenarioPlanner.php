<?php

declare(strict_types=1);

namespace TestPilot\Generation;

use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\ContextBuilder;
use TestPilot\Ai\JsonExtractor;
use TestPilot\Ai\PromptRegistry;
use TestPilot\Configuration\Module;
use TestPilot\Configuration\ResolvedConfig;
use TestPilot\DTO\TestTarget;
use TestPilot\Exceptions\AiDisabledException;
use TestPilot\Exceptions\InvalidAiResponseException;

/**
 * Step 1 of generation: asks the AI for a structured, schema-validated scenario plan.
 * Invalid output gets exactly one corrective retry, then fails loudly.
 */
final class ScenarioPlanner
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly ContextBuilder $context,
        private readonly PromptRegistry $prompts = new PromptRegistry(),
        private readonly ScenarioPlanValidator $validator = new ScenarioPlanValidator(),
    ) {
    }

    public function plan(TestTarget $target, ResolvedConfig $config, bool $fresh = false): ScenarioPlan
    {
        return $this->ask($this->buildRequest($target, $config)->fresh($fresh), $target);
    }

    /**
     * Plans HTTP feature scenarios. $httpContext describes the route, setup that is available
     * (factories, user model), validation rules and fakes; the adapter builds it.
     */
    public function planFeature(TestTarget $target, ResolvedConfig $config, string $httpContext, bool $fresh = false): ScenarioPlan
    {
        return $this->ask($this->buildFeatureRequest($target, $config, $httpContext)->fresh($fresh), $target);
    }

    /** The unit/fuzz planning request, without sending it (also used for Message Batches). */
    public function buildRequest(TestTarget $target, ResolvedConfig $config): AiRequest
    {
        if (! $config->isEnabled(Module::Ai)) {
            throw new AiDisabledException("AI is disabled for {$target->key} (#[AI(enabled: false)] or policy).");
        }

        $modules = array_values(array_intersect(
            array_map(static fn (Module $m): string => $m->value, $config->activeModules()),
            ['unit', 'fuzz'],
        ));

        if ($modules === []) {
            throw new InvalidAiResponseException("No AI-planned module (unit or fuzz) is enabled for {$target->key}");
        }

        $max = (int) $config->option(Module::Unit, 'max_scenarios', 8);
        if ($config->isEnabled(Module::Fuzz)) {
            $max += 3; // room for invariant scenarios
        }

        $focus = (array) $config->option(Module::Unit, 'focus', []);

        return AiRequest::withContext(
            purpose: 'plan',
            system: $this->prompts->render('plan.system'),
            context: $this->context->build($target, $config),
            task: $this->prompts->render('plan.user', [
                'modules' => implode(', ', $modules),
                'max_scenarios' => (string) $max,
                'focus' => $focus === [] ? '' : 'Focus especially on: ' . implode(', ', array_map('strval', $focus)),
            ]),
            maxTokens: 4096,
            promptVersion: $this->prompts->version(),
            meta: ['criticality' => $config->criticality, 'modules' => $modules, 'max' => $max, 'target' => $target->key, 'json' => true],
        );
    }

    public function buildFeatureRequest(TestTarget $target, ResolvedConfig $config, string $httpContext): AiRequest
    {
        if (! $config->isEnabled(Module::Ai)) {
            throw new AiDisabledException("AI is disabled for {$target->key} (#[AI(enabled: false)] or policy).");
        }

        $max = (int) $config->option(Module::Feature, 'max_scenarios', 8);
        $focus = (array) $config->option(Module::Feature, 'focus', []);

        return AiRequest::withContext(
            purpose: 'plan',
            system: $this->prompts->render('feature.plan.system'),
            context: rtrim($httpContext) . "\n\n" . $this->context->build($target, $config),
            task: $this->prompts->render('feature.plan.user', [
                'max_scenarios' => (string) $max,
                'focus' => $focus === [] ? '' : 'Focus especially on: ' . implode(', ', array_map('strval', $focus)),
            ]),
            maxTokens: 4096,
            promptVersion: $this->prompts->version(),
            meta: ['criticality' => $config->criticality, 'modules' => ['feature'], 'max' => $max, 'target' => $target->key, 'json' => true],
        );
    }

    /**
     * Validates a reply into a plan, without a corrective retry (batch results arrive after the fact).
     *
     * @throws InvalidAiResponseException
     */
    public function fromResponse(AiRequest $request, TestTarget $target, AiResponse $response): ScenarioPlan
    {
        $modules = (array) $request->meta['modules'];
        $max = (int) $request->meta['max'];

        try {
            $data = JsonExtractor::object($response->content);
        } catch (InvalidAiResponseException) {
            throw new InvalidAiResponseException('The AI reply was not a single JSON object', [], $response->content);
        }

        $errors = $this->validator->validate($data, $target->key, $modules, $max);
        if ($errors !== []) {
            throw new InvalidAiResponseException('The AI scenario plan is invalid', $errors, $response->content);
        }

        return $this->toPlan($target, $data, $response);
    }

    private function ask(AiRequest $request, TestTarget $target): ScenarioPlan
    {
        $modules = (array) $request->meta['modules'];
        $max = (int) $request->meta['max'];
        $response = $this->ai->send($request, $target->key);
        $errors = [];

        try {
            $data = JsonExtractor::object($response->content);
            $errors = $this->validator->validate($data, $target->key, $modules, $max);
        } catch (InvalidAiResponseException $e) {
            $errors = ['the reply was not a single JSON object'];
        }

        if ($errors !== []) {
            $retry = $request->fresh()->withAppendedTurn(
                $response->content,
                "Your JSON does not match the schema:\n- " . implode("\n- ", $errors) . "\nReturn the corrected JSON object only.",
            );
            $response = $this->ai->send($retry, $target->key);
            $data = JsonExtractor::object($response->content);
            $errors = $this->validator->validate($data, $target->key, $modules, $max);

            if ($errors !== []) {
                throw new InvalidAiResponseException('The AI scenario plan is invalid after one retry', $errors, $response->content);
            }
        }

        return $this->toPlan($target, $data, $response);
    }

    /** @param array<string, mixed> $data */
    private function toPlan(TestTarget $target, array $data, AiResponse $response): ScenarioPlan
    {
        return new ScenarioPlan(
            targetKey: $target->key,
            scenarios: array_map(static fn (array $s): Scenario => Scenario::fromArray($s), $data['scenarios']),
            notes: (string) ($data['notes'] ?? ''),
            promptVersion: $this->prompts->version(),
            model: $response->model,
        );
    }
}
