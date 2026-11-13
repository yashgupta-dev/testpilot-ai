<?php

declare(strict_types=1);

namespace TestPilot\Ai\Http;

use TestPilot\Exceptions\AiProviderException;

/**
 * Small dependency-free HTTP client. Retries rate limits and transient server errors with backoff.
 */
final class CurlHttpClient implements HttpClientInterface
{
    private const RETRYABLE = [408, 409, 425, 429, 500, 502, 503, 504, 529];

    /** Rate limits (429) on free tiers clear within a minute: wait as long as the provider asks, a few more times. */
    private const RATE_LIMIT_RETRIES = 4;
    private const RATE_LIMIT_MAX_WAIT = 60;

    public function __construct(
        private readonly int $maxRetries = 2,
        private readonly int $maxBackoffSeconds = 10,
    ) {
    }

    public function postJson(string $url, array $headers, array $body, int $timeoutSeconds): array
    {
        $this->assertSafeUrl($url);
        $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attempt = 0;

        while (true) {
            $attempt++;
            $result = $this->send($url, $headers, $payload, $timeoutSeconds);

            $delay = $this->retryDelay($result, $attempt);
            if ($delay === null) {
                return $result;
            }
            sleep($delay);
        }
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $this->assertSafeUrl($url);
        $attempt = 0;

        while (true) {
            $attempt++;
            $result = $this->send($url, $headers, null, $timeoutSeconds);

            $delay = $this->retryDelay($result, $attempt);
            if ($delay === null) {
                return $result;
            }
            sleep($delay);
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>}
     */
    private function send(string $url, array $headers, ?string $payload, int $timeoutSeconds): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new AiProviderException('Could not initialise cURL.');
        }

        $responseHeaders = [];
        $lines = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $options = $payload === null
            ? [CURLOPT_HTTPGET => true]
            : [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload];
        // cURL option IDs are numeric array keys; array spread would reindex them.
        $options += [
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
        ];
        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new AiProviderException("AI provider request failed: {$error}");
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => (string) $raw,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Seconds to wait before retrying, or null to give up. 429s honour Retry-After or "try again in 9.08s" / "1m2.5s"
     * from the body (Groq, OpenAI) for up to a minute; other retryable statuses use short exponential backoff.
     *
     * @param array{status: int, body: mixed, raw: string, headers: array<string, string>} $result
     */
    public function retryDelay(array $result, int $attempt): ?int
    {
        $status = $result['status'];
        if (! in_array($status, self::RETRYABLE, true)) {
            return null;
        }

        $retryAfter = (float) ($result['headers']['retry-after'] ?? 0);

        if ($status === 429) {
            if (AiProviderException::isLongWindowQuota($result['raw'])) {
                return null;
            }
            if ($attempt > max($this->maxRetries, self::RATE_LIMIT_RETRIES)) {
                return null;
            }
            if ($retryAfter <= 0 && preg_match('/try again in\s+(?:(\d+)m)?\s*([\d.]+)\s*s/i', $result['raw'], $m) === 1) {
                $retryAfter = (int) ($m[1] ?? 0) * 60 + (float) $m[2];
            }
            $wait = $retryAfter > 0 ? (int) ceil($retryAfter) + 1 : 5 * $attempt;

            return min(self::RATE_LIMIT_MAX_WAIT, max(1, $wait));
        }

        if ($attempt > $this->maxRetries) {
            return null;
        }

        return min($this->maxBackoffSeconds, max(1, $retryAfter > 0 ? (int) ceil($retryAfter) : 2 ** ($attempt - 1)));
    }

    private function assertSafeUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['https', 'http'], true)) {
            throw new AiProviderException("Unsupported AI endpoint scheme in {$url}");
        }
    }
}
