<?php

declare(strict_types=1);

namespace TestPilot\Ai\Providers;

use Closure;
use TestPilot\Ai\AiProviderInterface;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Exceptions\AiProviderException;

/**
 * Deterministic provider for tests, demos and CI. Returns queued responses, or the result of a callback.
 */
final class FakeProvider implements AiProviderInterface
{
    /** @var list<string|Closure(AiRequest): string> */
    private array $queue;

    /** @var list<AiRequest> */
    public array $requests = [];

    /** @param list<string|Closure(AiRequest): string> $responses */
    public function __construct(array $responses = [], private readonly string $model = 'fake-model')
    {
        $this->queue = $responses;
    }

    public function push(string|Closure $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new AiProviderException('FakeProvider has no queued response for "' . $request->purpose . '".');
        }

        $content = $next instanceof Closure ? $next($request) : $next;

        return new AiResponse($content, (int) (strlen(json_encode($request->messages) ?: '') / 4), (int) (strlen($content) / 4), $request->model ?? $this->model, 1);
    }

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isLocal(): bool
    {
        return true;
    }
}
