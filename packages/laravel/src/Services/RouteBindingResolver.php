<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Http\RouteBindings;
use TestPilot\Laravel\Models\Target;

/**
 * Decides how a generated test fills each {parameter} of a route:
 *   - type-hinted Eloquent model  → created with its factory (scoped children use ->for($parent));
 *   - type-hinted backed enum     → its first case;
 *   - anything else               → a placeholder "1".
 * Classes are only inspected (class_exists / reflection on declarations); no application code runs.
 */
final class RouteBindingResolver
{
    /** @param array<string, mixed> $route */
    public function resolve(Target $target, array $route): RouteBindings
    {
        $uri = (string) $route['uri'];
        $names = RouteBindings::parameterNames($uri);
        $fields = (array) ($route['binding_fields'] ?? []);
        $scoped = (bool) ($route['scoped'] ?? false) || $fields !== [];

        $hints = [];
        foreach ((array) ($target->data['parameters'] ?? []) as $parameter) {
            $hints[(string) $parameter['name']] = $parameter['class'] ?? null;
        }

        $parameters = [];
        $previousModel = null;
        foreach ($names as $name) {
            $class = $hints[$name] ?? null;
            $class = is_string($class) && class_exists($class) ? $class : (is_string($class) && enum_exists($class) ? $class : null);

            if ($class !== null && is_subclass_of($class, Model::class)) {
                if (! method_exists($class, 'factory')) {
                    throw new TestPilotException(sprintf(
                        '{%s} is bound to %s, which has no factory. Add the HasFactory trait and a %sFactory so TestPilot can create a record for the URL.',
                        $name,
                        class_basename($class),
                        class_basename($class),
                    ));
                }

                $parent = null;
                if ($scoped && $previousModel !== null && method_exists($class, Str::camel(class_basename($previousModel['class'])))) {
                    $parent = $previousModel['name'];
                }

                $parameters[] = ['name' => $name, 'kind' => 'model', 'class' => $class, 'field' => $fields[$name] ?? null, 'parent' => $parent];
                $previousModel = ['name' => $name, 'class' => $class];
                continue;
            }

            if ($class !== null && enum_exists($class) && is_subclass_of($class, BackedEnum::class)) {
                $parameters[] = ['name' => $name, 'kind' => 'enum', 'class' => $class];
                continue;
            }

            $parameters[] = ['name' => $name, 'kind' => 'scalar', 'value' => '1'];
        }

        return new RouteBindings($uri, $parameters);
    }
}
