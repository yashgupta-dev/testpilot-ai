<?php

declare(strict_types=1);

use TestPilot\Ai\Http\CurlHttpClient;

function answer(int $status, string $raw = '', array $headers = []): array
{
    return ['status' => $status, 'body' => null, 'raw' => $raw, 'headers' => $headers];
}

it('waits as long as Groq asks in the body when there is no Retry-After header', function () {
    $groq = 'Rate limit reached for model `openai/gpt-oss-120b` … on tokens per minute (TPM): Limit 8000, Used 4292, Requested 4919. Please try again in 9.0825s.';

    expect((new CurlHttpClient())->retryDelay(answer(429, $groq), 1))->toBe(11)
        ->and((new CurlHttpClient())->retryDelay(answer(429, 'Please try again in 1m2.5s.'), 1))->toBe(60);
});

it('prefers the Retry-After header and retries rate limits up to four times', function () {
    $client = new CurlHttpClient();

    expect($client->retryDelay(answer(429, '', ['retry-after' => '7']), 1))->toBe(8)
        ->and($client->retryDelay(answer(429), 4))->toBe(20)
        ->and($client->retryDelay(answer(429), 5))->toBeNull();
});

it('does not retry quota limits that reset daily or monthly', function (string $quota) {
    expect((new CurlHttpClient())->retryDelay(answer(429, $quota), 1))->toBeNull();
})->with([
    'Groq daily token quota' => ['Limit 200000, Used 199337, Requested 5317 on tokens per day (TPD)'],
    'daily request quota' => ['The daily request limit has been reached'],
    'monthly token quota' => ['tokens per month limit exceeded'],
]);

it('keeps short backoff for server errors and does not retry client errors', function () {
    $client = new CurlHttpClient(maxRetries: 2, maxBackoffSeconds: 10);

    expect($client->retryDelay(answer(503), 1))->toBe(1)
        ->and($client->retryDelay(answer(503), 2))->toBe(2)
        ->and($client->retryDelay(answer(503), 3))->toBeNull()
        ->and($client->retryDelay(answer(400), 1))->toBeNull()
        ->and($client->retryDelay(answer(413), 1))->toBeNull();
});
