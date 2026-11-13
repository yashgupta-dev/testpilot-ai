<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use Throwable;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\Target;

/**
 * Everything the AI needs to write HTTP feature tests for one action, gathered without running it:
 * the route, auth, how to fill URL parameters, validation rules, which models it touches and whether
 * they have factories, and which side effects are faked.
 */
final class FeatureContextBuilder
{
    public const FAKES = ['mail', 'notification', 'queue', 'http', 'event', 'storage'];
    public const DEFAULT_FAKES = ['mail', 'notification', 'queue', 'http'];

    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly RouteBindingResolver $bindings,
        private readonly ValidationPlanBuilder $validation,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Target $target): array
    {
        $route = $target->route;
        if (! is_array($route)) {
            throw new TestPilotException("No route calls {$target->shortName()}. HTTP feature tests are generated for controller methods that handle a route; use #[UnitTest] for other classes." . str_replace('#[Validation]', '#[FeatureTest]', ValidationPlanBuilder::routedAlternatives($target)));
        }

        $bindings = $this->bindings->resolve($target, $route);
        $middleware = (array) ($route['middleware'] ?? []);
        $authenticate = collect($middleware)->contains(fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:') || str_contains($m, 'Authenticate'));
        $userModel = (string) config('testpilot.validation.user_model', 'App\\Models\\User');

        $requested = array_map('strtolower', (array) ($target->config['options']['feature']['fakes'] ?? []));
        $fakes = array_values(array_intersect(self::FAKES, $requested === [] ? self::DEFAULT_FAKES : $requested));

        $rules = null;
        try {
            $plan = $this->validation->build($target);
            $rules = [
                'origin' => $plan['context']['rules_origin'] ?? null,
                'error_mode' => $plan['context']['error_mode'] ?? 'laravel',
                'error_status' => $plan['context']['error_status'] ?? 422,
                'fields' => array_map(static fn (array $f): string => $f['field'] . ': ' . implode('|', array_map(static fn (array $r): string => $r['note'] ?? $r['source'], $f['rules'])), $plan['context']['fields']),
                'valid_payload' => $plan['context']['baseline'],
            ];
        } catch (Throwable) {
            // No readable validation: fine for feature tests (e.g. GET endpoints).
        }

        return [
            'method' => collect((array) $route['methods'])->first(fn (string $m): bool => $m !== 'HEAD') ?? 'GET',
            'uri' => $route['uri'],
            'middleware' => $middleware,
            'authenticate' => $authenticate,
            'user_model' => $userModel,
            'user_has_factory' => class_exists($userModel) && method_exists($userModel, 'factory'),
            'bindings' => $bindings->isEmpty() ? null : $bindings->toArray(),
            'url_setup' => $bindings->isEmpty() ? null : implode("\n", $bindings->setupLines('')),
            'validation' => $rules,
            'models' => $this->models($target),
            'fakes' => $fakes,
        ];
    }

    /** The context as text for the AI prompt. */
    public function describe(array $context): string
    {
        $lines = ['## HTTP context', "Route: {$context['method']} {$context['uri']}"];
        $lines[] = 'Middleware: ' . ($context['middleware'] === [] ? 'none' : implode(', ', $context['middleware']));
        $lines[] = $context['authenticate']
            ? "Requires a signed-in user: use \$this->actingAs(\\{$context['user_model']}::factory()->create())" . ($context['user_has_factory'] ? '' : ' (WARNING: the user model has no factory)')
            : "No auth middleware. Signed-in user model if the code needs one: \\{$context['user_model']}";

        if ($context['url_setup'] !== null) {
            $lines[] = "URL parameters — create the records like this, then request the URL built from them:\n```php\n{$context['url_setup']}\n```";
        }

        if ($context['validation'] !== null) {
            $v = $context['validation'];
            $failure = match ($v['error_mode']) {
                'status' => "HTTP {$v['error_status']} (custom response)",
                'redirect_errors' => '302 with session errors',
                default => '422 with JSON errors',
            };
            $lines[] = "Validation ({$v['origin']}; failures answer {$failure}):\n- " . implode("\n- ", $v['fields'])
                . "\nA request body that passes every rule: " . json_encode($v['valid_payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($context['models'] !== []) {
            $lines[] = "Models used by the action (factories are the ONLY way to create data):\n- " . implode("\n- ", array_map(
                static fn (array $m): string => "\\{$m['class']} (table {$m['table']}" . ($m['fillable'] === [] ? '' : ', fillable: ' . implode(', ', $m['fillable'])) . ') — ' . ($m['factory'] ? 'factory available' : 'NO factory'),
                $context['models'],
            ));
        }

        $lines[] = 'Fakes to start every test with: ' . ($context['fakes'] === [] ? 'none' : implode(', ', array_map(static fn (string $f): string => match ($f) {
            'mail' => 'Mail::fake()',
            'notification' => 'Notification::fake()',
            'queue' => 'Queue::fake()',
            'http' => 'Http::preventStrayRequests() (fake specific URLs with Http::fake([...]) if the action calls an API)',
            'event' => 'Event::fake()',
            'storage' => "Storage::fake('local')",
            default => $f,
        }, $context['fakes'])));

        return implode("\n\n", $lines);
    }

    /**
     * Eloquent models referenced in the action: type hints, static calls (Order::create), `new Order`, ::class.
     * Only declarations are inspected (default property values via reflection); nothing is executed.
     *
     * @return list<array{class: string, table: string, fillable: list<string>, factory: bool}>
     */
    private function models(Target $target): array
    {
        $paths = $this->manager->core()->paths;
        try {
            $ast = (new ParserFactory())->createForNewestSupportedVersion()->parse((string) file_get_contents($paths->resolveInside((string) $target->file))) ?? [];
        } catch (Throwable) {
            return [];
        }
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $finder = new NodeFinder();
        $method = $finder->findFirst($ast, static fn (Node $n): bool => $n instanceof Node\Stmt\ClassMethod && strcasecmp($n->name->toString(), (string) $target->method) === 0
            && $n->getStartLine() <= (int) $target->line + 5 && $n->getEndLine() >= (int) $target->line);
        if (! $method instanceof Node\Stmt\ClassMethod) {
            return [];
        }

        $names = [];
        foreach ($finder->findInstanceOf([$method], Node\Name::class) as $name) {
            $names[$name->toString()] = true;
        }

        $models = [];
        foreach (array_keys($names) as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $models[] = [
                'class' => $class,
                'table' => is_string($defaults['table'] ?? null) ? $defaults['table'] : \Illuminate\Support\Str::snake(\Illuminate\Support\Str::pluralStudly(class_basename($class))),
                'fillable' => array_values(array_map('strval', (array) ($defaults['fillable'] ?? []))),
                'factory' => method_exists($class, 'factory'),
            ];
        }

        return $models;
    }
}
