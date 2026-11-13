<?php

declare(strict_types=1);

namespace TestPilot\Ai\Http;

interface HttpClientInterface
{
    /**
     * Send a JSON POST request and return the decoded response.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     *
     * @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>}
     */
    public function postJson(string $url, array $headers, array $body, int $timeoutSeconds): array;

    /**
     * Send a GET request (Message Batches status and results). Same return shape; "raw" holds the body
     * as received, which may be JSON Lines.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>}
     */
    public function get(string $url, array $headers, int $timeoutSeconds): array;
}
