<?php

declare(strict_types=1);

use TestPilot\Ai\AiDataPolicy;
use TestPilot\Ai\AiRequest;
use TestPilot\Ai\Http\HttpClientInterface;
use TestPilot\Ai\ProviderFactory;
use TestPilot\Ai\Providers\OpenAiCompatibleProvider;
use TestPilot\Exceptions\AiDisabledException;
use TestPilot\Exceptions\AiQuotaExceededException;
use TestPilot\Exceptions\AiProviderException;
use TestPilot\TestPilot;

/** Replays queued HTTP answers and records what was sent. */
function scriptedHttp(array $answers): HttpClientInterface
{
    return new class ($answers) implements HttpClientInterface {
        public array $sent = [];

        public function __construct(private array $answers)
        {
        }

        public function postJson(string $url, array $headers, array $body, int $timeoutSeconds): array
        {
            $this->sent[] = compact('url', 'headers', 'body');

            return array_shift($this->answers);
        }

        public function get(string $url, array $headers, int $timeoutSeconds): array
        {
            throw new LogicException('not used');
        }
    };
}

function okAnswer(string $content = '{"ok":true}'): array
{
    return ['status' => 200, 'body' => ['choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5], 'model' => 'm'], 'raw' => '', 'headers' => []];
}

it('builds the free drivers with their endpoint and a default model', function (string $driver, string $model, string $host, bool $local) {
    $provider = ProviderFactory::make(['driver' => $driver, 'api_key' => 'k']);

    expect($provider->name())->toBe($driver)
        ->and($provider->model())->toBe($model)
        ->and($provider->isLocal())->toBe($local)
        ->and(ProviderFactory::BASE_URLS[$driver])->toContain($host);
})->with([
    ['groq', 'llama-3.3-70b-versatile', 'api.groq.com', false],
    ['openrouter', 'google/gemma-4-31b-it:free', 'openrouter.ai', false],
    ['gemini', 'gemini-3.8-flash', 'generativelanguage.googleapis.com', false],
    ['ollama', 'qwen2.5-coder:7b', 'localhost', true],
]);

it('keeps an explicit model and base URL', function () {
    $provider = ProviderFactory::make(['driver' => 'groq', 'model' => 'qwen/qwen3-32b', 'base_url' => 'https://proxy.example/v1']);

    expect($provider->model())->toBe('qwen/qwen3-32b');
});

it('lets only Ollama through a local-only data policy', function () {
    AiDataPolicy::Local->assertAllows(ProviderFactory::make(['driver' => 'ollama']));

    expect(fn () => AiDataPolicy::Local->assertAllows(ProviderFactory::make(['driver' => 'groq', 'api_key' => 'k'])))
        ->toThrow(AiDisabledException::class);
});

it('explains a rate limit, a bad key and an unknown model', function (int $status, string $hint) {
    $http = scriptedHttp([['status' => $status, 'body' => ['error' => ['message' => 'nope']], 'raw' => '', 'headers' => []]]);
    $provider = new OpenAiCompatibleProvider('openrouter', 'm:free', 'https://openrouter.ai/api/v1', 'k', 10, $http);

    expect(fn () => $provider->complete(AiRequest::single('plan', 's', 'u')))->toThrow(AiProviderException::class, $hint);
    expect($http->sent[0]['headers'])->toHaveKey('X-Title')
        ->and($http->sent[0]['url'])->toBe('https://openrouter.ai/api/v1/chat/completions');
})->with([
    [429, 'free tiers allow only a few requests per minute'],
    [401, 'check TESTPILOT_AI_API_KEY'],
    [404, 'testpilot:ai-check'],
]);

it('identifies a daily provider quota separately from a temporary rate limit', function () {
    $message = 'Rate limit reached on tokens per day (TPD): Limit 200000, Used 199337, Requested 5317. Please try again in 33m30s.';
    $http = scriptedHttp([['status' => 429, 'body' => ['error' => ['message' => $message]], 'raw' => '', 'headers' => []]]);
    $provider = new OpenAiCompatibleProvider('groq', 'model', 'https://api.groq.com/openai/v1', 'k', 10, $http);

    expect(fn () => $provider->complete(AiRequest::single('plan', 's', 'u')))
        ->toThrow(AiQuotaExceededException::class, 'provider quota is reached for a longer window');
});

it('reads Gemini errors, which come wrapped in a list', function () {
    $http = scriptedHttp([['status' => 400, 'body' => [['error' => ['code' => 400, 'message' => 'Missing or invalid Authorization header.']]], 'raw' => '', 'headers' => []]]);
    $provider = new OpenAiCompatibleProvider('gemini', 'gemini-3.8-flash', 'https://generativelanguage.googleapis.com/v1beta/openai', '', 10, $http);

    expect(fn () => $provider->complete(AiRequest::single('plan', 's', 'u')))
        ->toThrow(AiProviderException::class, 'gemini returned HTTP 400: Missing or invalid Authorization header. (check TESTPILOT_AI_API_KEY');
});

it('lists the models an endpoint offers', function () {
    $http = new class implements HttpClientInterface {
        public function postJson(string $url, array $headers, array $body, int $timeoutSeconds): array { throw new LogicException(); }
        public function get(string $url, array $headers, int $timeoutSeconds): array
        {
            return ['status' => 200, 'body' => ['data' => [['id' => 'b:free'], ['id' => 'a']]], 'raw' => '', 'headers' => []];
        }
    };

    expect((new OpenAiCompatibleProvider('ollama', 'x', 'http://localhost:11434/v1', null, 10, $http))->listModels())->toBe(['a', 'b:free']);
});

it('sends the OpenAI chat format and reads the reply', function () {
    $http = scriptedHttp([okAnswer('{"plan":1}')]);
    $provider = new OpenAiCompatibleProvider('groq', 'llama', 'https://api.groq.com/openai/v1', 'k', 10, $http);

    $response = $provider->complete(AiRequest::single('plan', 'system text', 'user text', 300));

    expect($response->content)->toBe('{"plan":1}')
        ->and($response->inputTokens)->toBe(10)
        ->and($http->sent[0]['headers']['Authorization'])->toBe('Bearer k')
        ->and($http->sent[0]['body'])->toMatchArray(['model' => 'llama', 'max_tokens' => 300])
        ->and($http->sent[0]['body']['messages'][0])->toBe(['role' => 'system', 'content' => 'system text']);
});

it('ignores the default Claude routes when another provider is configured', function () {
    $routes = ['plan' => 'claude-sonnet-5-5', 'repair' => 'claude-haiku-5-5', 'generate' => 'llama-3.1-8b-instant'];
    $groq = (new TestPilot(sys_get_temp_dir(), ['ai' => ['driver' => 'groq', 'api_key' => 'k', 'models' => $routes]]))->ai()->router();
    $claude = (new TestPilot(sys_get_temp_dir(), ['ai' => ['driver' => 'anthropic', 'api_key' => 'k', 'models' => $routes]]))->ai()->router();

    expect($groq->route(new AiRequest('plan', 's', [])))->toBeNull()           // → the provider's own model
        ->and($groq->route(new AiRequest('generate', 's', [])))->toBe('llama-3.1-8b-instant')
        ->and($claude->route(new AiRequest('plan', 's', [])))->toBe('claude-sonnet-5-5');
});

function tpmRejection(int $limit, int $requested): array
{
    $message = "Request too large for model `openai/gpt-oss-120b` in organization `org_x` service tier `on_demand` on tokens per minute (TPM): Limit {$limit}, Requested {$requested}, please reduce your message size and try again.";

    return ['status' => 413, 'body' => ['error' => ['message' => $message]], 'raw' => '', 'headers' => []];
}

it('caps max_tokens for the groq free tier', function () {
    $http = scriptedHttp([okAnswer()]);
    $provider = new OpenAiCompatibleProvider('groq', 'openai/gpt-oss-120b', 'https://api.groq.com/openai/v1', 'k', 10, $http, maxOutputTokens: 4096);
    $provider->complete(new AiRequest('generate', 's', [['role' => 'user', 'content' => 'u']], 8192));

    expect($http->sent[0]['body']['max_tokens'])->toBe(4096)
        ->and(ProviderFactory::make(['driver' => 'groq', 'api_key' => 'k']))->toBeInstanceOf(OpenAiCompatibleProvider::class);
});

it('retries once with a budget that fits a tokens-per-minute rejection', function () {
    $http = scriptedHttp([tpmRejection(8000, 8486), okAnswer('<?php // fixed')]);
    $provider = new OpenAiCompatibleProvider('groq', 'openai/gpt-oss-120b', 'https://api.groq.com/openai/v1', 'k', 10, $http);

    $response = $provider->complete(new AiRequest('repair', 's', [['role' => 'user', 'content' => 'u']], 8192));

    expect($response->content)->toBe('<?php // fixed')
        ->and($http->sent)->toHaveCount(2)
        ->and($http->sent[1]['body']['max_tokens'])->toBe(8192 - 486 - 256);
});

it('reports the rejection when the input alone is over the limit', function () {
    $http = scriptedHttp([tpmRejection(8000, 15000)]);
    $provider = new OpenAiCompatibleProvider('groq', 'openai/gpt-oss-120b', 'https://api.groq.com/openai/v1', 'k', 10, $http);

    expect(fn () => $provider->complete(new AiRequest('repair', 's', [['role' => 'user', 'content' => 'u']], 4096)))
        ->toThrow(AiProviderException::class, 'larger than the provider allows per minute');
    expect($http->sent)->toHaveCount(1);
});

it('asks for JSON mode on JSON steps and retries without it when the provider rejects it', function () {
    $http = scriptedHttp([
        ['status' => 400, 'body' => ['error' => ['message' => 'json_validate_failed', 'code' => 'json_validate_failed']], 'raw' => '', 'headers' => []],
        okAnswer('{"ok":true}'),
    ]);
    $provider = new OpenAiCompatibleProvider('groq', 'openai/gpt-oss-120b', 'https://api.groq.com/openai/v1', 'k', 10, $http);

    $response = $provider->complete(AiRequest::single('plan', 'Return JSON.', 'u')->withMeta(['json' => true]));

    expect($response->content)->toBe('{"ok":true}')
        ->and($http->sent[0]['body']['response_format'])->toBe(['type' => 'json_object'])
        ->and($http->sent[1]['body'])->not->toHaveKey('response_format');
});

it('does not ask for JSON mode when generating code', function () {
    $http = scriptedHttp([okAnswer('<?php')]);
    (new OpenAiCompatibleProvider('groq', 'm', 'https://api.groq.com/openai/v1', 'k', 10, $http))->complete(AiRequest::single('generate', 's', 'u'));

    expect($http->sent[0]['body'])->not->toHaveKey('response_format');
});
