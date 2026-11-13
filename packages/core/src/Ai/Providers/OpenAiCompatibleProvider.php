<?php

declare(strict_types=1);

namespace TestPilot\Ai\Providers;

use TestPilot\Ai\AiProviderInterface;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\Http\CurlHttpClient;
use TestPilot\Ai\Http\HttpClientInterface;
use TestPilot\Exceptions\AiQuotaExceededException;
use TestPilot\Exceptions\AiProviderException;

/**
 * Any endpoint that speaks the OpenAI Chat Completions format:
 * OpenAI, Azure OpenAI-compatible gateways, Google Gemini's OpenAI-compatible endpoint,
 * Ollama (http://localhost:11434/v1), LM Studio, vLLM, etc.
 */
final class OpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeoutSeconds = 120,
        // CurlHttpClient already retries 429/5xx (honouring Retry-After), which covers free-tier rate limits.
        private readonly HttpClientInterface $http = new CurlHttpClient(),
        /**
         * Upper bound for max_tokens. Free tiers (Groq) count input + max_tokens against a tokens-per-minute limit,
         * so asking for 8192 output tokens fails on an 8000 TPM tier before anything is generated.
         */
        private readonly ?int $maxOutputTokens = null,
    ) {
        if ($model === '') {
            throw new AiProviderException("No model configured for provider \"{$name}\". Set TESTPILOT_AI_MODEL.");
        }
    }

    public function complete(AiRequest $request): AiResponse
    {
        $started = hrtime(true);
        $messages = [['role' => 'system', 'content' => $request->system], ...$request->messages];
        $headers = $this->apiKey !== null && $this->apiKey !== '' ? ['Authorization' => 'Bearer ' . $this->apiKey] : [];
        if ($this->name === 'openrouter') {
            $headers['X-Title'] = 'TestPilot'; // OpenRouter's optional app attribution
        }

        $maxTokens = $this->maxOutputTokens !== null ? min($request->maxTokens, $this->maxOutputTokens) : $request->maxTokens;
        // Steps that expect a JSON object ask for JSON mode, so the provider itself guarantees valid JSON.
        $jsonMode = (bool) ($request->meta['json'] ?? false);
        $model = $request->model ?? $this->model;
        // gpt-oss is a reasoning model: its hidden reasoning counts against max_tokens. With Groq's capped budget
        // the default (medium) effort can spend it all and truncate the answer, so keep reasoning short.
        $reasoning = $this->name === 'groq' && str_contains($model, 'gpt-oss') ? ['reasoning_effort' => 'low'] : [];
        $send = fn (int $max, bool $json): array => $this->http->postJson(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            $headers,
            [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $max,
                'temperature' => $request->temperature,
                ...$reasoning,
                ...($json ? ['response_format' => ['type' => 'json_object']] : []),
            ],
            $this->timeoutSeconds,
        );

        // 429s (free-tier rate limits) are retried by the HTTP client, honouring Retry-After.
        $response = $send($maxTokens, $jsonMode);

        // Some models/providers reject JSON mode, or reject an answer that failed their JSON check
        // (Groq: "json_validate_failed"). Ask once more without it; JsonExtractor repairs common slips.
        if ($jsonMode && $response['status'] === 400) {
            $jsonMode = false;
            $response = $send($maxTokens, false);
        }

        // "Request too large … tokens per minute (TPM): Limit 8000, Requested 8486": the output budget counts too,
        // so ask once more with a budget that fits. Input alone over the limit cannot be fixed here.
        $smaller = self::fittingMaxTokens($response, $maxTokens);
        if ($smaller !== null) {
            $response = $send($smaller, $jsonMode);
        }

        $body = $response['body'];
        if ($response['status'] >= 400 || $body === null) {
            // Gemini wraps errors in a list: [{"error": {...}}].
            $error = is_array($body) && array_is_list($body) ? ($body[0] ?? []) : $body;
            $message = is_array($error) ? (string) ($error['error']['message'] ?? json_encode($error['error'] ?? $error)) : substr($response['raw'], 0, 300);
            $authProblem = in_array($response['status'], [401, 403], true)
                || ($response['status'] === 400 && preg_match('/authori[sz]ation|api[ _-]?key/i', $message) === 1);
            $longWindowQuota = $response['status'] === 429 && AiProviderException::isLongWindowQuota($message);
            $hint = match (true) {
                $longWindowQuota => ' (the provider quota is reached for a longer window; wait for its reset or switch provider/model)',
                $response['status'] === 429 => ' (rate limit of the provider; free tiers allow only a few requests per minute — try again shortly)',
                $response['status'] === 413 => ' (the request is larger than the provider allows per minute on this tier; use a model with a higher limit, a paid tier, or a smaller target)',
                $authProblem => ' (check TESTPILOT_AI_API_KEY for this provider)',
                $response['status'] === 404 => ' (model "' . ($request->model ?? $this->model) . '" may not exist here; list the available ones with php artisan testpilot:ai-check --list-models)',
                default => '',
            };
            $errorMessage = "{$this->name} returned HTTP {$response['status']}: {$message}{$hint}";
            if ($longWindowQuota) {
                throw new AiQuotaExceededException($errorMessage);
            }

            throw new AiProviderException($errorMessage);
        }

        return new AiResponse(
            content: (string) ($body['choices'][0]['message']['content'] ?? ''),
            inputTokens: (int) ($body['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($body['usage']['completion_tokens'] ?? 0),
            model: (string) ($body['model'] ?? $this->model),
            latencyMs: (int) ((hrtime(true) - $started) / 1_000_000),
            stopReason: isset($body['choices'][0]['finish_reason']) ? (string) $body['choices'][0]['finish_reason'] : null,
        );
    }

    /** A smaller max_tokens that fits a "Limit N, Requested M" tokens-per-minute rejection, or null. */
    private static function fittingMaxTokens(array $response, int $maxTokens): ?int
    {
        if (! in_array($response['status'], [413, 429], true)) {
            return null;
        }
        $body = $response['body'];
        $error = is_array($body) && array_is_list($body) ? ($body[0] ?? []) : $body;
        $message = is_array($error) ? (string) ($error['error']['message'] ?? '') : (string) $response['raw'];
        if (preg_match('/tokens per minute.*?Limit\s+(\d+),\s*Requested\s+(\d+)/is', $message, $m) !== 1) {
            return null;
        }

        $fit = $maxTokens - ((int) $m[2] - (int) $m[1]) - 256; // small margin for the provider's own estimate
        // Too little room left to write a test file: report the error instead of producing a cut-off answer.
        return $fit >= 1024 && $fit < $maxTokens ? $fit : null;
    }

    /**
     * Model ids the endpoint offers (GET /models), for diagnostics. Empty when the endpoint does not list models.
     *
     * @return list<string>
     */
    public function listModels(): array
    {
        $headers = $this->apiKey !== null && $this->apiKey !== '' ? ['Authorization' => 'Bearer ' . $this->apiKey] : [];
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/models', $headers, min(30, $this->timeoutSeconds));
        if ($response['status'] >= 400 || ! is_array($response['body']['data'] ?? null)) {
            return [];
        }

        $ids = array_values(array_filter(array_map(static fn ($m): string => is_array($m) ? (string) ($m['id'] ?? '') : '', $response['body']['data'])));
        sort($ids);

        return $ids;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isLocal(): bool
    {
        $host = (string) parse_url($this->baseUrl, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], true)
            || str_ends_with($host, '.local')
            || (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
    }
}
