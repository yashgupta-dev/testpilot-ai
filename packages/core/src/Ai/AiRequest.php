<?php

declare(strict_types=1);

namespace TestPilot\Ai;

/**
 * One AI call. Prompts are split so providers that support prompt caching (Anthropic) can cache
 * the parts that repeat:
 *
 *   system   — the purpose's instructions (identical for every target)      → cache breakpoint 1
 *   context  — the target's source, outline and HTTP/validation context    → cache breakpoint 2
 *   task     — what changes per call (scenarios, a failure to repair, …)    → never cached
 *
 * $messages always holds the full conversation as plain strings (context + task joined), so providers
 * without block support and the tests see exactly what the model receives.
 */
final class AiRequest
{
    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     * @param array<string, mixed> $meta Routing hints: criticality, attempt, target.
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $system,
        public readonly array $messages,
        public readonly int $maxTokens = 4096,
        public readonly float $temperature = 0.2,
        public readonly string $promptVersion = 'v1',
        public readonly ?string $context = null,
        public readonly ?string $task = null,
        public readonly ?string $model = null,
        public readonly array $meta = [],
        public readonly bool $bypassCache = false,
    ) {
    }

    public static function single(string $purpose, string $system, string $user, int $maxTokens = 4096, string $promptVersion = 'v1'): self
    {
        return new self($purpose, $system, [['role' => 'user', 'content' => $user]], $maxTokens, 0.2, $promptVersion);
    }

    /** A request whose first user turn is [cacheable context][task]. */
    public static function withContext(string $purpose, string $system, string $context, string $task, int $maxTokens = 4096, string $promptVersion = 'v1', array $meta = []): self
    {
        return new self($purpose, $system, [['role' => 'user', 'content' => self::join($context, $task)]], $maxTokens, 0.2, $promptVersion, $context, $task, null, $meta);
    }

    public static function join(string $context, string $task): string
    {
        return rtrim($context) . "\n\n---\n\n" . ltrim($task);
    }

    public function withAppendedTurn(string $assistant, string $user): self
    {
        $messages = $this->messages;
        $messages[] = ['role' => 'assistant', 'content' => $assistant];
        $messages[] = ['role' => 'user', 'content' => $user];

        return $this->copy(messages: $messages);
    }

    public function withModel(?string $model): self
    {
        return $this->copy(model: $model);
    }

    public function withMeta(array $meta): self
    {
        return $this->copy(meta: [...$this->meta, ...$meta]);
    }

    public function fresh(bool $bypass = true): self
    {
        return $this->copy(bypassCache: $bypass);
    }

    /**
     * @param list<array{role: string, content: string}>|null $messages
     * @param array<string, mixed>|null $meta
     */
    public function copy(?string $system = null, ?array $messages = null, ?string $context = null, ?string $task = null, ?string $model = null, ?array $meta = null, ?bool $bypassCache = null, bool $clearModel = false): self
    {
        return new self(
            $this->purpose,
            $system ?? $this->system,
            $messages ?? $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->promptVersion,
            $context ?? $this->context,
            $task ?? $this->task,
            $clearModel ? null : ($model ?? $this->model),
            $meta ?? $this->meta,
            $bypassCache ?? $this->bypassCache,
        );
    }

    public function payloadHash(): string
    {
        return hash('sha256', $this->system . json_encode($this->messages));
    }

    /** Key for the response cache: everything that changes the answer, nothing that doesn't. */
    public function cacheKey(string $provider, string $model): string
    {
        return hash('sha256', json_encode([$provider, $model, $this->promptVersion, $this->maxTokens, $this->temperature, $this->system, $this->messages]) ?: '');
    }
}
