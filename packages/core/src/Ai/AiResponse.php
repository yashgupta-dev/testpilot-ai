<?php

declare(strict_types=1);

namespace TestPilot\Ai;

final class AiResponse
{
    /**
     * $inputTokens counts uncached input only (Anthropic semantics); cached tokens are reported separately
     * so cost and savings can be computed exactly.
     */
    public function __construct(
        public readonly string $content,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $model,
        public readonly int $latencyMs,
        public readonly ?string $stopReason = null,
        public readonly int $cacheWrite5mTokens = 0,
        public readonly int $cacheWrite1hTokens = 0,
        public readonly int $cacheReadTokens = 0,
        /** Served from TestPilot's own response cache: no provider call, no cost. */
        public readonly bool $fromResponseCache = false,
        /** Produced through the Message Batches API (discounted). */
        public readonly bool $batch = false,
    ) {
    }

    public function totalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheWrite5mTokens + $this->cacheWrite1hTokens + $this->cacheReadTokens;
    }

    /** Replayed from TestPilot's response cache. Token counts are kept so the dashboard can show what was saved. */
    public function asCacheHit(): self
    {
        return new self($this->content, $this->inputTokens, $this->outputTokens, $this->model, 0, $this->stopReason, $this->cacheWrite5mTokens, $this->cacheWrite1hTokens, $this->cacheReadTokens, true, false);
    }

    public function asBatch(): self
    {
        return new self($this->content, $this->inputTokens, $this->outputTokens, $this->model, $this->latencyMs, $this->stopReason, $this->cacheWrite5mTokens, $this->cacheWrite1hTokens, $this->cacheReadTokens, false, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'content' => $this->content, 'input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens, 'model' => $this->model,
            'latency_ms' => $this->latencyMs, 'stop_reason' => $this->stopReason, 'cache_write_5m' => $this->cacheWrite5mTokens,
            'cache_write_1h' => $this->cacheWrite1hTokens, 'cache_read' => $this->cacheReadTokens,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['content'], (int) ($data['input_tokens'] ?? 0), (int) ($data['output_tokens'] ?? 0), (string) ($data['model'] ?? ''),
            (int) ($data['latency_ms'] ?? 0), $data['stop_reason'] ?? null, (int) ($data['cache_write_5m'] ?? 0), (int) ($data['cache_write_1h'] ?? 0), (int) ($data['cache_read'] ?? 0),
        );
    }
}
