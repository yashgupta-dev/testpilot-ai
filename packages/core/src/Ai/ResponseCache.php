<?php

declare(strict_types=1);

namespace TestPilot\Ai;

/**
 * Exact-match cache of AI responses. The key covers provider, model, prompt version, system prompt and the
 * full (redacted) conversation, so unchanged code + unchanged prompts replay the previous answer for free.
 * Requests marked bypassCache() (an explicit "plan again") always go to the provider.
 */
interface ResponseCache
{
    public function get(string $key): ?AiResponse;

    public function put(string $key, AiResponse $response): void;
}
