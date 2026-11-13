<?php

declare(strict_types=1);

namespace TestPilot\Ai;

use TestPilot\Ai\Providers\AnthropicProvider;
use TestPilot\Ai\Providers\FakeProvider;
use TestPilot\Ai\Providers\OpenAiCompatibleProvider;
use TestPilot\Exceptions\ConfigurationException;

/**
 * Builds a provider from plain configuration, so frameworks only need to pass an array.
 */
final class ProviderFactory
{
    /**
     * Drivers with a free option, and the model used when TESTPILOT_AI_MODEL is empty. Free tiers are rate-limited
     * and their model line-ups change (checked 2026-11-13): override the model in .env or Settings when a default is
     * retired. `php artisan testpilot:ai-check` sends a tiny request and lists the models a provider offers.
     *
     *   groq       — free API key at console.groq.com (OpenAI-compatible, fast open models)
     *   openrouter — free API key at openrouter.ai; models ending in ":free" cost nothing
     *   gemini     — free API key at aistudio.google.com (free tier quota)
     *   ollama     — runs on your own machine, no key, nothing leaves it (works with TESTPILOT_AI_POLICY=local)
     */
    public const DEFAULT_MODELS = [
        'anthropic' => 'claude-sonnet-5-5',
        'groq' => 'llama-3.3-70b-versatile',
        'openrouter' => 'google/gemma-4-31b-it:free',
        'gemini' => 'gemini-3.8-flash',
        'ollama' => 'qwen2.5-coder:7b',
    ];

    public const BASE_URLS = [
        'anthropic' => 'https://api.anthropic.com',
        'openai' => 'https://api.openai.com/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
        'ollama' => 'http://localhost:11434/v1',
    ];

    public const DRIVERS = ['anthropic', 'openai', 'groq', 'openrouter', 'gemini', 'ollama', 'custom', 'fake'];

    /**
     * @param array{driver?: string, model?: string|null, api_key?: string|null, base_url?: string|null, timeout?: int} $config
     */
    public static function make(array $config): AiProviderInterface
    {
        $driver = strtolower((string) ($config['driver'] ?? 'anthropic'));
        $driver = $driver === 'claude' ? 'anthropic' : ($driver === 'openai-compatible' ? 'custom' : $driver);
        $model = (string) ($config['model'] ?? '');
        $model = $model !== '' ? $model : (self::DEFAULT_MODELS[$driver] ?? '');
        $key = (string) ($config['api_key'] ?? '');
        $timeout = (int) ($config['timeout'] ?? 120);
        $base = ($config['base_url'] ?? null) ?: (self::BASE_URLS[$driver] ?? null);
        // Groq's free tier counts max_tokens against an 8000 tokens-per-minute limit; 4096 leaves room for the input.
        $maxOutput = isset($config['max_output_tokens']) && (int) $config['max_output_tokens'] > 0
            ? (int) $config['max_output_tokens']
            : ($driver === 'groq' ? 4096 : null);

        return match ($driver) {
            'anthropic' => new AnthropicProvider($key, $model, $base, $timeout, cacheTtl: (string) ($config['prompt_cache'] ?? '5m')),
            'openai', 'groq', 'openrouter', 'gemini' => new OpenAiCompatibleProvider($driver, $model, $base, $key, $timeout, maxOutputTokens: $maxOutput),
            'ollama' => new OpenAiCompatibleProvider('ollama', $model, $base, $key ?: null, $timeout),
            'custom' => new OpenAiCompatibleProvider('custom', $model, (string) ($base ?: throw new ConfigurationException('base_url is required for the custom AI driver.')), $key, $timeout),
            'fake' => new FakeProvider(),
            default => throw new ConfigurationException("Unknown AI driver \"{$driver}\". Use " . implode(', ', self::DRIVERS) . '.'),
        };
    }
}
