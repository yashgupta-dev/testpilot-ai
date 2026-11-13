<?php

declare(strict_types=1);

namespace TestPilot\Ai\Batch;

use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\Providers\AnthropicProvider;
use TestPilot\Exceptions\AiProviderException;

/**
 * Anthropic Message Batches API: many requests in one asynchronous job at 50% of standard prices
 * (prompt-cache discounts still apply on top). Most batches finish within an hour; the hard limit is 24h.
 *
 * TestPilot uses it to plan many targets at once (onboarding a codebase, nightly re-plans).
 */
final class AnthropicBatchClient
{
    public function __construct(private readonly AnthropicProvider $provider)
    {
    }

    /**
     * @param array<string, AiRequest> $requests custom_id => prepared request (already redacted and routed by the gateway)
     *
     * @return array{id: string, status: string}
     */
    public function create(array $requests): array
    {
        $items = [];
        foreach ($requests as $id => $request) {
            if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $id) !== 1) {
                throw new AiProviderException("Invalid batch custom_id \"{$id}\".");
            }
            $items[] = ['custom_id' => (string) $id, 'params' => $this->provider->payload($request)];
        }

        $response = $this->provider->http()->postJson($this->provider->baseUrl() . '/v1/messages/batches', $this->provider->headers(), ['requests' => $items], 120);

        return $this->status($response);
    }

    /** @return array{id: string, status: string, counts: array<string, int>} status: in_progress | canceling | ended */
    public function retrieve(string $batchId): array
    {
        $response = $this->provider->http()->get($this->provider->baseUrl() . '/v1/messages/batches/' . rawurlencode($batchId), $this->provider->headers(), 60);

        return $this->status($response);
    }

    /**
     * @return array<string, array{type: string, response: ?AiResponse, error: ?string}> custom_id => outcome
     *         type: succeeded | errored | canceled | expired
     */
    public function results(string $batchId): array
    {
        $response = $this->provider->http()->get($this->provider->baseUrl() . '/v1/messages/batches/' . rawurlencode($batchId) . '/results', $this->provider->headers(), 120);
        if ($response['status'] >= 400) {
            throw new AiProviderException("Anthropic batch results returned HTTP {$response['status']}.");
        }

        $out = [];
        foreach (preg_split('/\r?\n/', trim($response['raw'])) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['custom_id'])) {
                continue;
            }
            $type = (string) ($row['result']['type'] ?? 'errored');
            $out[(string) $row['custom_id']] = [
                'type' => $type,
                'response' => $type === 'succeeded' ? AnthropicProvider::parse((array) $row['result']['message'], $this->provider->model())->asBatch() : null,
                'error' => $type === 'succeeded' ? null : (string) ($row['result']['error']['error']['message'] ?? $row['result']['error']['message'] ?? $type),
            ];
        }

        return $out;
    }

    /** @param array{status: int, body: array<string, mixed>|null, raw: string} $response */
    private function status(array $response): array
    {
        $body = $response['body'];
        if ($response['status'] >= 400 || ! is_array($body) || ! isset($body['id'])) {
            $message = is_array($body) ? (string) ($body['error']['message'] ?? 'unknown error') : substr($response['raw'], 0, 300);
            throw new AiProviderException("Anthropic batch API returned HTTP {$response['status']}: {$message}");
        }

        return [
            'id' => (string) $body['id'],
            'status' => (string) ($body['processing_status'] ?? 'in_progress'),
            'counts' => array_map('intval', (array) ($body['request_counts'] ?? [])),
        ];
    }
}
