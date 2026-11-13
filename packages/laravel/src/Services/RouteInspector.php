<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Maps controller methods to the routes that call them, with their middleware.
 * Used for the "public route" risk signal, for the UI and (next phase) for validation and rate-limit tests.
 */
final class RouteInspector
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $map = null;

    public function __construct(private readonly Router $router)
    {
    }

    /** @return array{methods: list<string>, uri: string, name: string|null, middleware: list<string>, throttle: string|null, binding_fields: array<string, string>, scoped: bool}|null */
    public function forTarget(string $class, string $method): ?array
    {
        $this->map ??= $this->build();

        return $this->map[strtolower(ltrim($class, '\\') . '::' . $method)] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    private function build(): array
    {
        $map = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uses = $route->getAction('uses');
            if (! is_string($uses)) {
                continue;
            }

            [$class, $action] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $throttle = null;
            foreach ($middleware as $item) {
                if (str_starts_with($item, 'throttle:') || str_contains($item, 'ThrottleRequests')) {
                    $throttle = $item;
                }
            }

            $map[strtolower(ltrim($class, '\\') . '::' . $action)] = [
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'uri' => '/' . ltrim($route->uri(), '/'),
                'name' => $route->getName(),
                'middleware' => $middleware,
                'throttle' => $throttle,
                // {order:reference} is stored as uri "orders/{order}" + binding field order → reference.
                'binding_fields' => array_map('strval', $route->bindingFields()),
                'scoped' => $route->enforcesScopedBindings(),
            ];
        }

        return $map;
    }
}
