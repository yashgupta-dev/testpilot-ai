<?php

declare(strict_types=1);

namespace TestPilot\Ai;

/**
 * Turns token counts into cost, including prompt-cache writes/reads and the batch discount, and
 * reports what the same call would have cost without caching (the "saved" figure in the dashboard).
 *
 * Prices are USD per 1M tokens and come from configuration: they change, so verify them against
 * your provider's pricing page. Missing cache prices fall back to Anthropic's documented multipliers
 * (5-minute write 1.25×, 1-hour write 2×, read 0.1× of base input).
 */
final class Pricing
{
    /** Anthropic's published defaults (Nov 2026). Override in config when prices change. */
    public const DEFAULTS = [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write_5m' => 5.00, 'cache_write_1h' => 8.00, 'cache_read' => 0.20],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write_5m' => 2.50, 'cache_write_1h' => 4.00, 'cache_read' => 0.10],
        'claude-haiku-5-5' => [
            'input' => 0.10, 'output' => 0.50, 'cache_write_5m' => 0.125, 'cache_write_1h' => 0.20, 'cache_read' => 0.01,
            // Haiku 5.5 bills prompts above 100K input tokens at higher rates.
            'long_context' => ['threshold' => 100_000, 'input' => 0.50, 'output' => 2.50, 'cache_write_5m' => 0.625, 'cache_write_1h' => 1.00, 'cache_read' => 0.05],
        ],
        'default' => ['input' => 2.00, 'output' => 10.00],
    ];

    public const BATCH_DISCOUNT = 0.5;

    /** @param array<string, array<string, mixed>> $prices */
    public function __construct(private readonly array $prices = self::DEFAULTS)
    {
    }

    /** @return array{cost: float, without_cache: float, saved: float} USD */
    public function cost(AiResponse $response): array
    {
        $p = $this->rates($response->model, $response->totalInputTokens());
        $multiplier = $response->batch ? self::BATCH_DISCOUNT : 1.0;

        $cost = ($response->inputTokens * $p['input']
            + $response->cacheWrite5mTokens * $p['cache_write_5m']
            + $response->cacheWrite1hTokens * $p['cache_write_1h']
            + $response->cacheReadTokens * $p['cache_read']
            + $response->outputTokens * $p['output']) / 1_000_000 * $multiplier;

        $withoutCache = ($response->totalInputTokens() * $p['input'] + $response->outputTokens * $p['output']) / 1_000_000 * $multiplier;

        if ($response->fromResponseCache) {
            // Nothing was sent: the whole original cost is saved.
            return ['cost' => 0.0, 'without_cache' => $cost, 'saved' => $cost];
        }

        return ['cost' => $cost, 'without_cache' => $withoutCache, 'saved' => max(0.0, $withoutCache - $cost)];
    }

    /** @return array{input: float, output: float, cache_write_5m: float, cache_write_1h: float, cache_read: float} */
    public function rates(string $model, int $promptTokens = 0): array
    {
        $price = $this->prices[$model] ?? $this->match($model) ?? $this->prices['default'] ?? self::DEFAULTS['default'];
        if (isset($price['long_context']) && $promptTokens > (int) $price['long_context']['threshold']) {
            $price = [...$price, ...$price['long_context']];
        }

        $input = (float) $price['input'];

        return [
            'input' => $input,
            'output' => (float) $price['output'],
            'cache_write_5m' => (float) ($price['cache_write_5m'] ?? $input * 1.25),
            'cache_write_1h' => (float) ($price['cache_write_1h'] ?? $input * 2.0),
            'cache_read' => (float) ($price['cache_read'] ?? $input * 0.1),
        ];
    }

    /** Dated snapshots (claude-sonnet-5-5-20260928) are priced like their alias. */
    private function match(string $model): ?array
    {
        foreach ($this->prices as $name => $price) {
            if ($name !== 'default' && str_starts_with($model, $name)) {
                return $price;
            }
        }

        return null;
    }
}
