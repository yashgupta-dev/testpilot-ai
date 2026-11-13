<?php

declare(strict_types=1);

use TestPilot\Ai\Http\CurlHttpClient;
use TestPilot\Exceptions\AiProviderException;

it('preserves numeric cURL option keys for GET and POST requests', function (bool $post) {
    $client = new CurlHttpClient(maxRetries: 0);

    $request = fn () => $post
        ? $client->postJson('http://127.0.0.1:1', [], ['test' => true], 1)
        : $client->get('http://127.0.0.1:1', [], 1);

    expect($request)->toThrow(AiProviderException::class, 'AI provider request failed:');
})->with([false, true]);

it('rejects unsupported URL schemes before making a cURL request', function () {
    expect(fn () => (new CurlHttpClient())->get('file:///etc/hosts', [], 1))
        ->toThrow(AiProviderException::class, 'Unsupported AI endpoint scheme');
});
