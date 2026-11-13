<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use TestPilot\Http\RouteBindings;
use TestPilot\RateLimit\RateLimitCaseGenerator;
use TestPilot\RateLimit\RateLimitTestBuilder;
use TestPilot\Validation\ValidationTestBuilder;

it('lists URI parameter names, including custom keys and optional parameters', function () {
    expect(RouteBindings::parameterNames('/users/{user}/posts/{post:slug}/{page?}'))->toBe(['user', 'post', 'page'])
        ->and(RouteBindings::parameterNames('/api/orders'))->toBe([]);
});

it('builds the URL from factories, custom keys, enums and placeholders', function () {
    $bindings = new RouteBindings('/users/{user}/posts/{post}/{channel}/{page}', [
        ['name' => 'user', 'kind' => 'model', 'class' => 'App\Models\User', 'field' => null, 'parent' => null],
        ['name' => 'post', 'kind' => 'model', 'class' => 'App\Models\Post', 'field' => 'slug', 'parent' => 'user'],
        ['name' => 'channel', 'kind' => 'enum', 'class' => 'App\Enums\Channel'],
        ['name' => 'page', 'kind' => 'scalar', 'value' => '1'],
    ]);

    expect($bindings->setupLines())->toBe([
        '    $user = \App\Models\User::factory()->create();',
        '    $post = \App\Models\Post::factory()->for($user)->create();',
        "    \$this->testpilotUri = '/users/' . \$user->getRouteKey() . '/posts/' . \$post->slug . '/' . \\App\\Enums\\Channel::cases()[0]->value . '/' . '1';",
    ])
        ->and($bindings->usesDatabase())->toBeTrue()
        ->and($bindings->describe())->toBe([
            '{user} → User::factory()->create()',
            '{post:slug} → Post::factory()->for($user)->create()',
            '{channel} → Channel::cases()[0]',
            "{page} → '1' (placeholder)",
        ]);
});

it('creates the records in beforeEach and requests the built URL', function () {
    $bindings = (new RouteBindings('/api/orders/{order}', [['name' => 'order', 'kind' => 'model', 'class' => 'App\Models\Order', 'field' => null, 'parent' => null]]))->toArray();
    $scenario = ['id' => 'S1', 'name' => 'Accepts a fully valid payload', 'selected' => true, 'meta' => ['action' => 'none', 'expect' => 'no_errors', 'field' => null, 'todo' => null]];

    $validation = (new ValidationTestBuilder())->build(['request_class' => 'App\Http\Requests\UpdateOrderRequest', 'method' => 'PUT', 'uri' => '/api/orders/{order}', 'baseline' => ['status' => 'draft'], 'scenarios' => [$scenario], 'bindings' => $bindings]);
    $rateLimit = (new RateLimitTestBuilder())->build(['method' => 'DELETE', 'uri' => '/api/orders/{order}', 'max_attempts' => 2, 'decay_seconds' => 60, 'bindings' => $bindings,
        'scenarios' => (new RateLimitCaseGenerator())->generate(['max_attempts' => 2, 'decay_seconds' => 60, 'basis_source' => 'x'])]);

    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    expect($parser->parse($validation))->not->toBeNull()
        ->and($parser->parse($rateLimit))->not->toBeNull()
        ->and($validation)->toContain('$order = \App\Models\Order::factory()->create();')
        ->and($validation)->toContain('$this->putJson($this->testpilotUri, $payload)')
        ->and($rateLimit)->toContain('->deleteJson($test->testpilotUri)')
        ->and($rateLimit)->toContain("\$this->testpilotUri = '/api/orders/' . \$order->getRouteKey();");
});
