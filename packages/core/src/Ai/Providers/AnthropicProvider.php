<?php

declare(strict_types=1);

namespace TestPilot\Ai\Providers;

use TestPilot\Ai\AiProviderInterface;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\Http\CurlHttpClient;
use TestPilot\Ai\Http\HttpClientInterface;
use TestPilot\Exceptions\AiProviderException;

/**
 * Anthropic Messages API (Claude models), with prompt caching:
 *   breakpoint 1 on the system prompt (shared by every target of the same purpose),
 *   breakpoint 2 on the target context (shared by plan → generate → repairs of one target).
 * Blocks shorter than the model's minimum cacheable length are simply not cached; no error.
 */
final class AnthropicProvider implements AiProviderInterface
{
    public const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly int $timeoutSeconds = 120,
        private readonly HttpClientInterface $http = new CurlHttpClient(),
        /** "5m" (default, write 1.25× input) or "1h" (write 2× input; better when plan and generate are far apart). "off" disables. */
        private readonly string $cacheTtl = '5m',
    ) {
        if ($apiKey === '') {
            throw new AiProviderException('Anthropic API key is missing. Set TESTPILOT_AI_API_KEY.');
        }
    }

    public function complete(AiRequest $request): AiResponse
    {
        $started = hrtime(true);
        $response = $this->http->postJson(rtrim($this->baseUrl, '/') . '/v1/messages', $this->headers(), $this->payload($request), $this->timeoutSeconds);

        $body = $response['body'];
        if ($response['status'] >= 400 || $body === null) {
            $message = is_array($body) ? (string) ($body['error']['message'] ?? 'unknown error') : substr($response['raw'], 0, 300);
            throw new AiProviderException("Anthropic returned HTTP {$response['status']}: {$message}");
        }

        return self::parse($body, $request->model ?? $this->model, (int) ((hrtime(true) - $started) / 1_000_000));
    }

    /** @return array<string, mixed> Messages API params, also used inside Message Batches. */
    public function payload(AiRequest $request): array
    {
        $cache = $this->cacheTtl === 'off' ? null : ($this->cacheTtl === '1h' ? ['type' => 'ephemeral', 'ttl' => '1h'] : ['type' => 'ephemeral']);

        $messages = $request->messages;
        if ($request->context !== null && $request->task !== null && isset($messages[0])) {
            $messages[0] = ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $request->context, ...($cache === null ? [] : ['cache_control' => $cache])],
                ['type' => 'text', 'text' => $request->task],
            ]];
        }

        return [
            'model' => $request->model ?? $this->model,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'system' => [['type' => 'text', 'text' => $request->system, ...($cache === null ? [] : ['cache_control' => $cache])]],
            'messages' => $messages,
        ];
    }

    /** @param array<string, mixed> $body A Messages API response (or a batch result's "message"). */
    public static function parse(array $body, string $model, int $latencyMs = 0): AiResponse
    {
        $text = '';
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) $block['text'];
            }
        }

        $usage = (array) ($body['usage'] ?? []);
        $creation = (array) ($usage['cache_creation'] ?? []);
        $write1h = (int) ($creation['ephemeral_1h_input_tokens'] ?? 0);
        // Older responses only report the total; treat it as 5-minute writes.
        $write5m = isset($creation['ephemeral_5m_input_tokens']) ? (int) $creation['ephemeral_5m_input_tokens'] : max(0, (int) ($usage['cache_creation_input_tokens'] ?? 0) - $write1h);

        return new AiResponse(
            content: $text,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            model: (string) ($body['model'] ?? $model),
            latencyMs: $latencyMs,
            stopReason: isset($body['stop_reason']) ? (string) $body['stop_reason'] : null,
            cacheWrite5mTokens: $write5m,
            cacheWrite1hTokens: $write1h,
            cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
        );
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return ['x-api-key' => $this->apiKey, 'anthropic-version' => self::API_VERSION];
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function http(): HttpClientInterface
    {
        return $this->http;
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isLocal(): bool
    {
        return false;
    }
}
