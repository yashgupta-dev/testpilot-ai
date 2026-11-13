<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Support;

use Illuminate\Contracts\Cache\Repository;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\ResponseCache;

/** Stores AI responses in a Laravel cache store. Use redis/database so every developer and CI share it. */
final class LaravelResponseCache implements ResponseCache
{
    public function __construct(
        private readonly Repository $store,
        private readonly int $ttlDays = 14,
    ) {
    }

    public function get(string $key): ?AiResponse
    {
        $data = $this->store->get('testpilot:ai:' . $key);

        return is_array($data) ? AiResponse::fromArray($data) : null;
    }

    public function put(string $key, AiResponse $response): void
    {
        $this->store->put('testpilot:ai:' . $key, $response->toArray(), now()->addDays(max(1, $this->ttlDays)));
    }
}
