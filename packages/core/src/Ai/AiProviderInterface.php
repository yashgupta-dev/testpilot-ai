<?php

declare(strict_types=1);

namespace TestPilot\Ai;

/**
 * Minimal contract every AI backend implements. TestPilot never depends on a specific vendor SDK.
 */
interface AiProviderInterface
{
    public function complete(AiRequest $request): AiResponse;

    /** Short provider id, e.g. "anthropic", "openai", "ollama". */
    public function name(): string;

    public function model(): string;

    /** True when requests never leave the machine/network (e.g. Ollama on localhost). */
    public function isLocal(): bool;
}
