<?php

declare(strict_types=1);

use TestPilot\Ai\AiGateway;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\Batch\AnthropicBatchClient;
use TestPilot\Ai\Http\HttpClientInterface;
use TestPilot\Ai\ModelRouter;
use TestPilot\Ai\Pricing;
use TestPilot\Ai\Providers\AnthropicProvider;
use TestPilot\Ai\Providers\FakeProvider;
use TestPilot\Ai\ResponseCache;
use TestPilot\Ai\UsageRecorder;

/** Records requests and replays canned responses; no network. */
final class RecordingHttp implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, body: ?array}> */
    public array $sent = [];

    /** @param list<array{status: int, body: ?array, raw?: string}> $responses */
    public function __construct(public array $responses = [])
    {
    }

    public function postJson(string $url, array $headers, array $body, int $timeoutSeconds): array
    {
        $this->sent[] = ['method' => 'POST', 'url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->next();
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $this->sent[] = ['method' => 'GET', 'url' => $url, 'body' => null, 'headers' => $headers];

        return $this->next();
    }

    private function next(): array
    {
        $r = array_shift($this->responses) ?? ['status' => 500, 'body' => null];

        return ['status' => $r['status'], 'body' => $r['body'], 'raw' => $r['raw'] ?? json_encode($r['body']), 'headers' => []];
    }
}

final class ArrayResponseCache implements ResponseCache
{
    public array $items = [];

    public function get(string $key): ?AiResponse
    {
        return $this->items[$key] ?? null;
    }

    public function put(string $key, AiResponse $response): void
    {
        $this->items[$key] = $response;
    }
}

final class MemoryUsage implements UsageRecorder
{
    public array $records = [];

    public function assertWithinBudget(string $purpose, ?string $targetKey = null): void
    {
    }

    public function record(AiRequest $request, ?AiResponse $response, string $provider, string $model, ?string $targetKey, ?string $error): void
    {
        $this->records[] = ['purpose' => $request->purpose, 'model' => $model, 'cached' => $response?->fromResponseCache, 'error' => $error];
    }
}

function claudeMessage(string $text, array $usage = []): array
{
    return ['id' => 'msg_1', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 50, ...$usage]];
}

describe('Pricing', function () {
    it('prices cache writes, cache reads and output separately and reports the saving', function () {
        $r = new AiResponse('x', inputTokens: 1_000, outputTokens: 2_000, model: 'claude-sonnet-5-5', latencyMs: 0, cacheWrite5mTokens: 0, cacheReadTokens: 100_000);
        $c = (new Pricing())->cost($r);

        // 1k × $2 + 100k × $0.10 + 2k × $10 per 1M  vs  101k × $2 + 2k × $10 without caching
        expect(round($c['cost'], 6))->toBe(0.032)
            ->and(round($c['without_cache'], 6))->toBe(0.222)
            ->and(round($c['saved'], 6))->toBe(0.19);
    });

    it('halves batch usage, prices dated snapshots like their alias, and switches Haiku 5.5 to long-context rates', function () {
        $p = new Pricing();

        expect(round($p->cost(new AiResponse('', 1_000_000, 0, 'claude-opus-5-5', 0, batch: true))['cost'], 2))->toBe(2.0)
            ->and($p->rates('claude-sonnet-5-5-20260928')['input'])->toBe(2.0)
            ->and($p->rates('claude-haiku-5-5', 50_000)['input'])->toBe(0.10)
            ->and($p->rates('claude-haiku-5-5', 150_000)['input'])->toBe(0.50)
            ->and($p->rates('some-other-model')['cache_read'])->toBe(0.2); // default 2.00 × 0.1
    });

    it('counts a response-cache replay as fully saved', function () {
        $hit = (new AiResponse('x', 10_000, 1_000, 'claude-sonnet-5-5', 5))->asCacheHit();
        $c = (new Pricing())->cost($hit);

        expect($c['cost'])->toBe(0.0)->and(round($c['saved'], 6))->toBe(0.03);
    });
});

describe('ModelRouter', function () {
    $router = new ModelRouter(['plan' => 'sonnet', 'generate' => 'sonnet', 'repair' => 'haiku', 'repair_escalation' => 'sonnet', 'critical' => 'opus']);

    it('routes each step to its model and escalates repeated repairs', function () use ($router) {
        $r = fn (string $purpose, array $meta = []) => $router->route(new AiRequest($purpose, '', [], meta: $meta));

        expect($r('plan'))->toBe('sonnet')
            ->and($r('plan', ['criticality' => 'high']))->toBe('opus')
            ->and($r('generate', ['criticality' => 'high']))->toBe('opus')
            ->and($r('repair', ['attempt' => 1]))->toBe('haiku')
            ->and($r('repair', ['attempt' => 2]))->toBe('sonnet')
            ->and((new ModelRouter())->route(new AiRequest('plan', '', [])))->toBeNull();
    });
});

describe('AnthropicProvider', function () {
    it('puts cache breakpoints on the system prompt and the target context, never on the task', function (string $ttl, ?array $expected) {
        $provider = new AnthropicProvider('key', 'claude-sonnet-5-5', http: new RecordingHttp(), cacheTtl: $ttl);
        $payload = $provider->payload(AiRequest::withContext('plan', 'SYSTEM', 'CONTEXT', 'TASK')->withModel('claude-haiku-5-5'));

        expect($payload['model'])->toBe('claude-haiku-5-5')
            ->and($payload['system'][0]['cache_control'] ?? null)->toBe($expected)
            ->and($payload['messages'][0]['content'][0])->toBe(['type' => 'text', 'text' => 'CONTEXT', ...($expected === null ? [] : ['cache_control' => $expected])])
            ->and($payload['messages'][0]['content'][1])->toBe(['type' => 'text', 'text' => 'TASK']);
    })->with([
        '5 minutes' => ['5m', ['type' => 'ephemeral']],
        '1 hour' => ['1h', ['type' => 'ephemeral', 'ttl' => '1h']],
        'off' => ['off', null],
    ]);

    it('reads cache usage from the response', function () {
        $http = new RecordingHttp([['status' => 200, 'body' => claudeMessage('ok', [
            'cache_creation_input_tokens' => 3_000, 'cache_read_input_tokens' => 9_000,
            'cache_creation' => ['ephemeral_5m_input_tokens' => 1_000, 'ephemeral_1h_input_tokens' => 2_000],
        ])]]);
        $r = (new AnthropicProvider('key', 'claude-sonnet-5-5', http: $http))->complete(AiRequest::withContext('plan', 'S', 'C', 'T'));

        expect([$r->inputTokens, $r->cacheWrite5mTokens, $r->cacheWrite1hTokens, $r->cacheReadTokens, $r->outputTokens])->toBe([100, 1_000, 2_000, 9_000, 50])
            ->and($r->totalInputTokens())->toBe(12_100)
            ->and($http->sent[0]['headers']['anthropic-version'])->toBe('2023-06-01');
    });
});

describe('AiGateway', function () {
    it('replays plans and generations from the response cache, but never repairs or explicit re-plans', function () {
        $provider = new FakeProvider(['first', 'second', 'third', 'fourth']);
        $cache = new ArrayResponseCache();
        $usage = new MemoryUsage();
        $gateway = new AiGateway($provider, usage: $usage, router: new ModelRouter(['plan' => 'model-a']), cache: $cache);
        $plan = AiRequest::withContext('plan', 'S', 'C', 'T');

        $a = $gateway->send($plan);
        $b = $gateway->send($plan);
        $c = $gateway->send($plan->fresh());
        $d = $gateway->send(AiRequest::withContext('repair', 'S', 'C', 'fix'));
        $e = $gateway->send(AiRequest::withContext('repair', 'S', 'C', 'fix'));

        expect([$a->content, $b->content, $c->content, $d->content, $e->content])->toBe(['first', 'first', 'second', 'third', 'fourth'])
            ->and($b->fromResponseCache)->toBeTrue()
            ->and(count($provider->requests))->toBe(4)
            ->and($provider->requests[0]->model)->toBe('model-a')
            ->and(array_column($usage->records, 'cached'))->toBe([false, true, false, false, false]);
    });

    it('redacts the cached context and the task separately and keeps the joined message consistent', function () {
        $provider = new FakeProvider(['ok']);
        (new AiGateway($provider))->send(AiRequest::withContext('plan', 'S', "\$key = 'sk-ant-api03-abcdefghijklmnopqrstuvwxyz0123456789';", 'TASK'));

        $sent = $provider->requests[0];
        expect($sent->context)->not->toContain('sk-ant-api03-abcdefghijklmnopqrstuvwxyz')
            ->and($sent->messages[0]['content'])->toBe(AiRequest::join($sent->context, $sent->task));
    });
});

describe('AnthropicBatchClient', function () {
    it('submits prepared requests, polls status and maps JSONL results back to custom ids', function () {
        $jsonl = implode("\n", [
            json_encode(['custom_id' => 'plan_1', 'result' => ['type' => 'succeeded', 'message' => claudeMessage('{"ok":1}', ['cache_read_input_tokens' => 500])]]),
            json_encode(['custom_id' => 'plan_2', 'result' => ['type' => 'errored', 'error' => ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'too long']]]]),
            json_encode(['custom_id' => 'plan_3', 'result' => ['type' => 'expired']]),
        ]);
        $http = new RecordingHttp([
            ['status' => 200, 'body' => ['id' => 'msgbatch_1', 'processing_status' => 'in_progress', 'request_counts' => ['processing' => 3]]],
            ['status' => 200, 'body' => ['id' => 'msgbatch_1', 'processing_status' => 'ended', 'request_counts' => ['succeeded' => 1, 'errored' => 1, 'expired' => 1]]],
            ['status' => 200, 'body' => null, 'raw' => $jsonl],
        ]);
        $client = new AnthropicBatchClient(new AnthropicProvider('key', 'claude-sonnet-5-5', http: $http));

        $created = $client->create(['plan_1' => AiRequest::withContext('plan', 'S', 'C1', 'T'), 'plan_2' => AiRequest::withContext('plan', 'S', 'C2', 'T')]);
        $status = $client->retrieve('msgbatch_1');
        $results = $client->results('msgbatch_1');

        expect($created)->toMatchArray(['id' => 'msgbatch_1', 'status' => 'in_progress'])
            ->and($http->sent[0]['url'])->toEndWith('/v1/messages/batches')
            ->and(array_column($http->sent[0]['body']['requests'], 'custom_id'))->toBe(['plan_1', 'plan_2'])
            ->and($http->sent[0]['body']['requests'][0]['params']['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
            ->and($status['status'])->toBe('ended')
            ->and($results['plan_1']['response']->content)->toBe('{"ok":1}')
            ->and($results['plan_1']['response']->batch)->toBeTrue()
            ->and($results['plan_1']['response']->cacheReadTokens)->toBe(500)
            ->and($results['plan_2'])->toMatchArray(['type' => 'errored', 'error' => 'too long'])
            ->and($results['plan_3']['type'])->toBe('expired');
    });
});
