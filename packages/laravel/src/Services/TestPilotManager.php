<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Contracts\Config\Repository;
use TestPilot\Ai\AiProviderInterface;
use TestPilot\TestPilot;

/**
 * Builds the framework-agnostic core from Laravel configuration.
 */
final class TestPilotManager
{
    private ?TestPilot $instance = null;

    public function __construct(
        private readonly Repository $config,
        private readonly EloquentUsageRecorder $usage,
        private readonly ?AiProviderInterface $provider = null,
        private readonly ?\TestPilot\Ai\ResponseCache $responseCache = null,
    ) {
    }

    public function core(): TestPilot
    {
        app(SettingsService::class)->apply();

        return $this->instance ??= new TestPilot(
            root: (string) ($this->config->get('testpilot.root') ?: base_path()),
            config: (array) $this->config->get('testpilot', []),
            environment: (string) app()->environment(),
            usage: $this->usage,
            provider: $this->provider,
            responseCache: $this->responseCache,
        );
    }

    /** A new instance built from the current config (after settings changed in this request). */
    public function fresh(): self
    {
        return new self($this->config, $this->usage, $this->provider, $this->responseCache);
    }

    /** Swap the AI provider (tests, demos). */
    public function withProvider(AiProviderInterface $provider): self
    {
        return new self($this->config, $this->usage, $provider, $this->responseCache);
    }
}
