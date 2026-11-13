<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\AiResponse;
use TestPilot\Ai\Http\HttpClientInterface;
use TestPilot\Ai\Providers\AnthropicProvider;
use TestPilot\Ai\Providers\FakeProvider;
use TestPilot\Exceptions\BudgetExceededException;
use TestPilot\Laravel\Events\AiBudgetWarning;
use TestPilot\Laravel\Models\AiBatch;
use TestPilot\Laravel\Models\AiRequest as AiRequestRow;
use TestPilot\Laravel\Models\Plan;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Services\BatchPlanService;
use TestPilot\Laravel\Services\EloquentUsageRecorder;
use TestPilot\Laravel\Services\ScanService;
use TestPilot\Laravel\Services\TestPilotManager;
use TestPilot\Laravel\Support\LaravelResponseCache;

/** Fake Anthropic HTTP layer: replays queued responses (closures are evaluated lazily) and records requests. */
final class FakeAnthropicHttp implements HttpClientInterface
{
    public array $sent = [];

    public function __construct(public array $queue = [])
    {
    }

    public function postJson(string $url, array $headers, array $body, int $timeoutSeconds): array
    {
        $this->sent[] = ['POST', $url, $body];

        return $this->next();
    }

    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $this->sent[] = ['GET', $url, null];

        return $this->next();
    }

    private function next(): array
    {
        $r = array_shift($this->queue);
        $r = $r instanceof Closure ? $r() : $r;

        return ['status' => $r['status'] ?? 200, 'body' => $r['body'] ?? null, 'raw' => $r['raw'] ?? json_encode($r['body'] ?? null), 'headers' => []];
    }
}

function useProvider($provider, bool $responseCache = false): void
{
    app()->singleton(TestPilotManager::class, fn ($app): TestPilotManager => new TestPilotManager(
        $app['config'],
        $app->make(EloquentUsageRecorder::class),
        $provider,
        $responseCache ? new LaravelResponseCache($app['cache']->store('array')) : null,
    ));
}

function claudeReply(string $text, array $usage = []): array
{
    return ['id' => 'msg', 'model' => 'claude-sonnet-5-5', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 300, 'output_tokens' => 400, ...$usage]];
}

it('records cost, prompt-cache savings, response-cache savings and batch savings separately', function () {
    $recorder = new EloquentUsageRecorder(50);
    $request = AiRequest::withContext('plan', 'S', 'C', 'T');

    $recorder->record($request, new AiResponse('x', 1_000, 2_000, 'claude-sonnet-5-5', 10, cacheReadTokens: 100_000), 'anthropic', 'claude-sonnet-5-5', 'A::b', null);
    $recorder->record($request, (new AiResponse('x', 10_000, 1_000, 'claude-sonnet-5-5', 10))->asCacheHit(), 'anthropic', 'claude-sonnet-5-5', 'A::b', null);
    $recorder->record($request, (new AiResponse('x', 1_000_000, 0, 'claude-opus-5-5', 10))->asBatch(), 'anthropic', 'claude-opus-5-5', 'A::c', null);

    [$live, $hit, $batch] = AiRequestRow::query()->orderBy('id')->get()->all();
    expect([$live->cost_micros, $live->saved_prompt_cache_micros, $live->cache_read_tokens])->toBe([32_000, 190_000, 100_000])
        ->and([$hit->status, $hit->cost_micros, $hit->saved_response_cache_micros, $hit->response_cache_hit])->toBe(['cache_hit', 0, 30_000, true])
        ->and([$batch->batch, $batch->cost_micros, $batch->saved_batch_micros])->toBe([true, 2_000_000, 2_000_000]);
});

it('warns once at 80% of the monthly budget and blocks at 100%, and enforces the per-target budget', function () {
    Event::fake([AiBudgetWarning::class]);
    $recorder = new EloquentUsageRecorder(monthlyBudget: 1.0, targetMonthlyBudget: 0.5);
    $request = AiRequest::withContext('plan', 'S', 'C', 'T');
    $spend = fn (int $input, string $target) => $recorder->record($request, new AiResponse('x', $input, 0, 'claude-sonnet-5-5', 1), 'anthropic', 'claude-sonnet-5-5', $target, null);

    $spend(250_000, 'A::one');   // $0.50 → A::one at its target budget
    expect(fn () => $recorder->assertWithinBudget('plan', 'A::one'))->toThrow(BudgetExceededException::class, 'This target used its monthly AI budget');
    $recorder->assertWithinBudget('plan', 'A::two');

    $spend(160_000, 'A::two');   // $0.82 total → warning
    $spend(10_000, 'A::two');    // still above 80%: no second warning
    Event::assertDispatchedTimes(AiBudgetWarning::class, 1);

    $spend(100_000, 'A::two');   // $1.04 total
    expect(fn () => $recorder->assertWithinBudget('plan', 'A::three'))->toThrow(BudgetExceededException::class, 'Monthly AI budget reached');
});

it('replays an unchanged plan from the response cache for free, but "plan again" asks the AI', function () {
    $ai = new FakeProvider();
    useProvider($ai, responseCache: true);
    app(ScanService::class)->scan();
    $target = Target::query()->where('key_hash', hash('sha256', 'Demo\Calculator::divide'))->firstOrFail();
    $ai->push(calculatorPlanJson())->push(calculatorPlanJson());

    $this->postJson("/testpilot/api/targets/{$target->id}/plan")->assertStatus(202);
    Plan::query()->delete(); // e.g. a teammate's machine sharing the cache store: same code, same prompt
    $this->postJson("/testpilot/api/targets/{$target->id}/plan")->assertStatus(202);
    $this->postJson("/testpilot/api/targets/{$target->id}/plan")->assertStatus(202); // a plan exists → explicit re-plan → fresh

    expect(count($ai->requests))->toBe(2)
        ->and(AiRequestRow::query()->orderBy('id')->pluck('status')->all())->toBe(['ok', 'cache_hit', 'ok'])
        ->and(AiRequestRow::query()->where('status', 'cache_hit')->value('saved_response_cache_micros'))->toBeGreaterThan(0)
        ->and($ai->requests[0]->model)->toBe('claude-sonnet-5-5'); // routed by testpilot.ai.models.plan
});

it('plans several targets in one discounted Message Batch and turns the results into plans', function () {
    $http = new FakeAnthropicHttp();
    useProvider(new AnthropicProvider('sk-test', 'claude-sonnet-5-5', http: $http));
    app(ScanService::class)->scan();
    $divide = Target::query()->where('key_hash', hash('sha256', 'Demo\Calculator::divide'))->firstOrFail();
    $invoice = Target::query()->where('key_hash', hash('sha256', 'App\Services\InvoiceService::calculateTotal'))->firstOrFail();

    $http->queue = [
        ['body' => ['id' => 'msgbatch_01', 'processing_status' => 'in_progress', 'request_counts' => ['processing' => 2]]],
        ['body' => ['id' => 'msgbatch_01', 'processing_status' => 'in_progress', 'request_counts' => ['processing' => 2]]],
        ['body' => ['id' => 'msgbatch_01', 'processing_status' => 'ended', 'request_counts' => ['succeeded' => 2]]],
        function () use ($divide, $invoice): array {
            $id = fn (Target $t) => Plan::query()->where('target_id', $t->id)->where('status', 'queued')->value('id');

            return ['raw' => implode("\n", [
                json_encode(['custom_id' => $id($divide), 'result' => ['type' => 'succeeded', 'message' => claudeReply(calculatorPlanJson(), ['cache_read_input_tokens' => 900])]]),
                json_encode(['custom_id' => $id($invoice), 'result' => ['type' => 'succeeded', 'message' => claudeReply('Sorry, I cannot do that.')]]),
            ])];
        },
    ];

    $this->postJson('/testpilot/api/ai/batches', ['module' => 'unit', 'targets' => [$divide->id, $invoice->id]])
        ->assertStatus(202)
        ->assertJsonPath('batch.requests', 2);

    $batch = AiBatch::query()->firstOrFail();
    $create = $http->sent[0];
    expect($create[1])->toEndWith('/v1/messages/batches')
        ->and(count($create[2]['requests']))->toBe(2)
        ->and($create[2]['requests'][0]['params']['model'])->toBe('claude-sonnet-5-5')
        ->and($create[2]['requests'][0]['params']['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($create[2]['requests'][0]['params']['messages'][0]['content'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($divide->refresh()->status)->toBe(Target::PLANNING);

    expect(app(BatchPlanService::class)->poll($batch))->toBeFalse();
    $this->postJson("/testpilot/api/ai/batches/{$batch->id}/poll")->assertOk()->assertJsonPath('ended', true);

    $dividePlan = Plan::query()->where('target_id', $divide->id)->firstOrFail();
    $invoicePlan = Plan::query()->where('target_id', $invoice->id)->firstOrFail();
    expect($batch->refresh()->status)->toBe(AiBatch::ENDED)
        ->and($dividePlan->status)->toBe('proposed')
        ->and(count($dividePlan->scenarios))->toBe(3)
        ->and($divide->refresh()->status)->toBe(Target::PLAN_READY)
        ->and($invoicePlan->status)->toBe('failed')
        ->and($invoicePlan->error)->toContain('Plan this target again to retry live')
        ->and($invoice->refresh()->status)->toBe(Target::NEEDS_ATTENTION)
        ->and(AiRequestRow::query()->where('batch', true)->count())->toBe(2)
        ->and(AiRequestRow::query()->where('batch', true)->sum('saved_batch_micros'))->toBeGreaterThan(0);

    $usage = $this->getJson('/testpilot/api/ai/usage')->assertOk()->json();
    expect($usage['totals']['calls'])->toBe(2)
        ->and($usage['totals']['saved']['batch'])->toBeGreaterThan(0)
        ->and($usage['batches'][0])->toMatchArray(['status' => 'ended', 'requests' => 2])
        ->and($usage['routing']['repair'])->toBe('claude-haiku-5-5')
        ->and(collect($usage['by_purpose'])->pluck('name')->all())->toBe(['plan']);
});

it('refuses batch planning when the provider has no batch API', function () {
    app(ScanService::class)->scan();
    $divide = Target::query()->where('key_hash', hash('sha256', 'Demo\Calculator::divide'))->firstOrFail();

    $this->postJson('/testpilot/api/ai/batches', ['module' => 'unit', 'targets' => [$divide->id]])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'needs the anthropic driver'));
});

it('plans from the command line, live or as a batch', function () {
    app(ScanService::class)->scan();
    $this->ai->push(calculatorPlanJson());

    $this->artisan('testpilot:plan', ['targets' => ['Calculator::divide']])->assertSuccessful();
    expect(Plan::query()->where('status', 'proposed')->count())->toBe(1);

    $this->artisan('testpilot:plan', ['targets' => ['Calculator::divide'], '--batch' => true])
        ->expectsOutputToContain('needs the anthropic driver')
        ->assertFailed();
});
