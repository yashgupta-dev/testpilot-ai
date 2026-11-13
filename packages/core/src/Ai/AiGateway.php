<?php

declare(strict_types=1);

namespace TestPilot\Ai;

use Throwable;

/**
 * The only path from TestPilot to an AI provider. Applies, in order:
 * data policy → budget check → secret redaction → model routing → response cache → provider call → usage recording.
 */
final class AiGateway
{
    public function __construct(
        private readonly AiProviderInterface $provider,
        private readonly AiDataPolicy $policy = AiDataPolicy::Cloud,
        private readonly UsageRecorder $usage = new NullUsageRecorder(),
        private readonly Redactor $redactor = new Redactor(),
        private readonly ModelRouter $router = new ModelRouter(),
        private readonly ?ResponseCache $cache = null,
        /** Purposes whose answers may be replayed. Repairs depend on a live failure and are never cached. */
        private readonly array $cachePurposes = ['plan', 'generate', 'validation'],
    ) {
    }

    public function send(AiRequest $request, ?string $targetKey = null): AiResponse
    {
        $safe = $this->prepare($request, $targetKey);
        $model = $safe->model ?? $this->provider->model();
        $key = $this->cacheable($safe) ? $safe->cacheKey($this->provider->name(), $model) : null;

        if ($key !== null && ($hit = $this->cache?->get($key)) !== null) {
            $hit = $hit->asCacheHit();
            $this->usage->record($safe, $hit, $this->provider->name(), $model, $targetKey, null);

            return $hit;
        }

        try {
            $response = $this->provider->complete($safe);
        } catch (Throwable $e) {
            $this->usage->record($safe, null, $this->provider->name(), $model, $targetKey, $e->getMessage());
            throw $e;
        }

        if ($key !== null) {
            $this->cache?->put($key, $response);
        }
        $this->usage->record($safe, $response, $this->provider->name(), $model, $targetKey, null);

        return $response;
    }

    /**
     * Policy, budget, redaction and routing without sending — used directly by the batch path,
     * so batched requests get exactly the same protections.
     */
    public function prepare(AiRequest $request, ?string $targetKey = null): AiRequest
    {
        $this->policy->assertAllows($this->provider);
        $this->usage->assertWithinBudget($request->purpose, $targetKey);

        $context = $request->context === null ? null : $this->redactor->redact($request->context);
        $task = $request->task === null ? null : $this->redactor->redact($request->task);
        $messages = [];
        foreach ($request->messages as $i => $m) {
            $messages[] = [
                'role' => $m['role'],
                'content' => $i === 0 && $context !== null && $task !== null ? AiRequest::join($context, $task) : $this->redactor->redact($m['content']),
            ];
        }

        $safe = $request->copy(messages: $messages, context: $context, task: $task);

        return $safe->withModel($this->router->route($safe));
    }

    /** Records a response that arrived outside send() (Message Batches). */
    public function recordExternal(AiRequest $prepared, ?AiResponse $response, ?string $targetKey, ?string $error = null): void
    {
        $this->usage->record($prepared, $response, $this->provider->name(), $prepared->model ?? $this->provider->model(), $targetKey, $error);
    }

    public function provider(): AiProviderInterface
    {
        return $this->provider;
    }

    public function router(): ModelRouter
    {
        return $this->router;
    }

    private function cacheable(AiRequest $request): bool
    {
        return $this->cache !== null && ! $request->bypassCache && in_array($request->purpose, $this->cachePurposes, true);
    }
}
