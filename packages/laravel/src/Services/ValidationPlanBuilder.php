<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use TestPilot\Configuration\Module;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Models\Target;
use TestPilot\Laravel\Support\TargetHydrator;
use TestPilot\Validation\AiValidationAdvisor;
use TestPilot\Validation\FormRequestRulesExtractor;
use TestPilot\Validation\InlineRulesExtractor;
use TestPilot\Validation\RulesExtraction;
use TestPilot\Validation\ValidationCaseGenerator;
use TestPilot\Validation\ValidationRule;
use Throwable;

/**
 * Builds a validation test plan for a controller method: finds its route and FormRequest,
 * reads rules() statically and turns every rule into concrete cases.
 *
 * Every known rule is handled without AI. When AI is allowed, one extra call (AiValidationAdvisor) proposes values for
 * regex and custom rules and reviews the rules for gaps; regex values are checked with preg_match before use. If the
 * call is not allowed or fails, the plan is exactly the deterministic one, with a warning.
 */
final class ValidationPlanBuilder
{
    public function __construct(
        private readonly TestPilotManager $manager,
        private readonly RouteBindingResolver $bindings,
        private readonly FormRequestRulesExtractor $extractor = new FormRequestRulesExtractor(),
        private readonly InlineRulesExtractor $inline = new InlineRulesExtractor(),
        private readonly ValidationCaseGenerator $cases = new ValidationCaseGenerator(),
    ) {
    }

    /** @return array{scenarios: list<array<string, mixed>>, context: array<string, mixed>, notes: string, model?: ?string, prompt_version?: string} */
    public function build(Target $target): array
    {
        $route = $target->route;
        if (! is_array($route)) {
            throw new TestPilotException("No route calls {$target->shortName()}. Validation tests are generated for controller methods that handle an HTTP route." . self::routedAlternatives($target));
        }

        $bindings = $this->bindings->resolve($target, $route);

        $paths = $this->manager->core()->paths;
        $requestClass = $this->formRequest($target);

        if ($requestClass !== null) {
            $file = (string) (new ReflectionClass($requestClass))->getFileName();
            $relative = $paths->relative($paths->resolveInside($file));
            $source = (string) file_get_contents($file);
            $extraction = $this->extractor->extract($source, $requestClass, $relative);
            if ($extraction->fields === []) {
                throw new TestPilotException('No rules could be read from ' . class_basename($requestClass) . '::rules(). ' . implode(' ', $extraction->warnings));
            }
        } else {
            // No FormRequest: read $request->validate([...]) / Validator::make(...) from the action itself.
            $relative = (string) $target->file;
            $declared = $target->config['options']['validation']['error_status'] ?? null;
            $extraction = $this->inline->extract(
                (string) file_get_contents($paths->resolveInside($relative)),
                (string) $target->class,
                (string) $target->method,
                $relative,
                $declared === null ? null : (int) $declared,
            );
            $this->assertUsable($extraction, $target);
            $source = (string) $target->source;
        }

        $additionalCases = max(0, min(20, (int) config('testpilot.validation.additional_cases', 2)));
        $variationSeed = (int) config('testpilot.validation.variation_seed', 42);
        $generated = $this->cases->generate($extraction, $additionalCases, $variationSeed);
        $variationCount = count(array_filter($generated['scenarios'], static fn (array $scenario): bool => ($scenario['meta']['rule'] ?? null) === 'additional numeric input'));
        $maxAiCases = max(0, min(20, (int) config('testpilot.validation.ai_additional_cases', 6)));
        [$generated, $ai] = $this->withAi($target, $extraction, $generated, $source, $requestClass === null ? null : (string) $target->source, $maxAiCases);
        $method = collect((array) $route['methods'])->first(fn (string $m): bool => $m !== 'HEAD') ?? 'POST';
        $middleware = (array) ($route['middleware'] ?? []);
        $authenticate = collect($middleware)->contains(fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:') || str_contains($m, 'Authenticate'));

        $warnings = $extraction->warnings;
        if (($ai['status'] ?? null) === 'failed') {
            $warnings[] = 'AI review skipped (' . $ai['reason'] . '). The plan uses the rules alone.';
        }
        if ($requestClass !== null && $extraction->authorize === 'false') {
            $warnings[] = class_basename($requestClass) . '::authorize() returns false, so every request will get 403 and these tests will fail.';
        }
        if ($requestClass !== null && $extraction->authorize === 'dynamic') {
            $warnings[] = class_basename($requestClass) . '::authorize() depends on runtime data; requests are sent ' . ($authenticate ? 'as a factory-created user.' : 'without a user.');
        }

        $selected = count(array_filter($generated['scenarios'], static fn (array $s): bool => $s['selected']));
        $skipped = count($generated['scenarios']) - $selected;

        return [
            'scenarios' => $generated['scenarios'],
            'model' => $ai['model'] ?? null,
            'prompt_version' => isset($ai['prompt_version']) ? 'validation@v2+ai' : 'validation@v1', // column is 16 characters
            'notes' => sprintf(
                '%d cases from %d fields in %s%s.',
                $selected,
                count($extraction->fields),
                $requestClass === null ? $extraction->label() : class_basename($requestClass) . '::rules()',
                $skipped > 0 ? "; {$skipped} need a human (unique records, regex, custom rules)" : '',
            ) . ($variationCount > 0 ? " {$variationCount} seeded numeric variation(s) (seed {$variationSeed})." : '') . self::aiNotes($ai),
            'context' => [
                'request_class' => $requestClass,
                'request_file' => $relative,
                'origin' => $extraction->origin,
                'rules_origin' => $extraction->label(),
                'error_mode' => $extraction->inline['error_mode'] ?? 'laravel',
                'error_status' => $extraction->inline['error_status'] ?? 422,
                'rules_line' => $extraction->rulesLine,
                'method' => $method,
                'uri' => $route['uri'],
                'bindings' => $bindings->isEmpty() ? null : $bindings->toArray(),
                'authenticate' => $authenticate,
                'authorize' => $extraction->authorize,
                'complete' => $extraction->complete,
                'fields' => array_map(static fn ($f): array => $f->toArray(), $extraction->fields),
                'baseline' => $generated['baseline'],
                'baseline_issues' => $generated['baseline_issues'],
                'numeric_variations' => ['count' => $variationCount, 'limit' => $additionalCases, 'seed' => $variationSeed],
                'warnings' => $warnings,
                'ai' => $ai,
            ],
        ];
    }

    /**
     * @param array{baseline: array<string, mixed>, baseline_issues: list<string>, scenarios: list<array<string, mixed>>} $generated
     *
     * @return array{0: array{baseline: array<string, mixed>, baseline_issues: list<string>, scenarios: list<array<string, mixed>>}, 1: array<string, mixed>}
     */
    private function withAi(Target $target, RulesExtraction $extraction, array $generated, string $source, ?string $actionSource, int $maxCases): array
    {
        $off = $this->aiDisabledReason($target);
        if ($off !== null) {
            return [$generated, ['status' => 'off', 'reason' => $off]];
        }

        $core = $this->manager->core();
        $advisor = $core->validationAdvisor();
        $criticality = $target->criticality;

        try {
            $request = $advisor->buildRequest($extraction, $generated['baseline'], $source, $actionSource, $this->ruleSources($extraction), [
                'criticality' => is_string($criticality) ? $criticality : null,
                'target' => $target->key,
            ], $maxCases, $generated['scenarios']);
            $advice = $advisor->advise($extraction, $request, (string) $target->key);
        } catch (Throwable $e) {
            report($e);

            return [$generated, ['status' => 'failed', 'reason' => mb_substr(class_basename($e) . ': ' . $e->getMessage(), 0, 300)]];
        }

        $planned = AiValidationAdvisor::apply($generated, $extraction, $advice);
        $addedCases = count(array_filter($planned['scenarios'], static fn (array $scenario): bool => ($scenario['meta']['rule'] ?? null) === 'AI-proposed numeric input'));

        return [$planned, ['status' => 'used', ...$advice->toArray(), 'cases_added' => $addedCases]];
    }

    /** Null when the AI review may run, otherwise why not. */
    private function aiDisabledReason(Target $target): ?string
    {
        $option = $target->config['options']['validation']['ai'] ?? null;
        if ($option === false) {
            return '#[Validation(ai: false)]';
        }
        if ($option === null && ! (bool) config('testpilot.validation.ai', true)) {
            return 'testpilot.validation.ai is off';
        }
        if (config('testpilot.ai.policy') === 'off') {
            return 'AI policy is off';
        }

        $core = $this->manager->core();
        if (! $core->resolver->resolve(TargetHydrator::hydrate($target))->isEnabled(Module::Ai)) {
            return 'AI is disabled for this target (#[AI(enabled: false)] or limits.ai_allowed)';
        }

        return null;
    }

    /**
     * Source of custom rule classes used in the rules, when they live inside the project, so the AI reads the
     * rule instead of guessing from its name.
     *
     * @return array<string, string>
     */
    private function ruleSources(RulesExtraction $extraction): array
    {
        $paths = $this->manager->core()->paths;
        $sources = [];
        foreach ($extraction->fields as $field) {
            foreach ($field->rules as $rule) {
                $class = $rule->kind === ValidationRule::CUSTOM ? ltrim((string) $rule->param(0), '\\') : '';
                if ($class === '' || isset($sources[$class]) || count($sources) >= 5 || ! class_exists($class)) {
                    continue;
                }
                try {
                    $file = (string) (new ReflectionClass($class))->getFileName();
                    $sources[$class] = (string) file_get_contents($paths->resolveInside($file));
                } catch (Throwable) {
                    // Outside the project (vendor) or unreadable: the AI only sees the class name.
                }
            }
        }

        return $sources;
    }

    /** @param array<string, mixed> $ai */
    private static function aiNotes(array $ai): string
    {
        if (($ai['status'] ?? null) !== 'used') {
            return '';
        }

        $values = (array) ($ai['values'] ?? []);
        $verified = count(array_filter($values, static fn (array $v): bool => (bool) $v['verified']));
        $caseCount = (int) ($ai['cases_added'] ?? count((array) ($ai['cases'] ?? [])));
        $gaps = (array) ($ai['gaps'] ?? []);

        $parts = [];
        if ($values !== []) {
            $parts[] = sprintf('AI proposed values for %d rule(s): %d checked locally, %d assumption(s) left unselected', count($values), $verified, count($values) - $verified);
        }
        if ($caseCount > 0) {
            $parts[] = sprintf('%d additional AI-proposed case(s) independently checked against supported numeric rules', $caseCount);
        }
        if (($rejected = count((array) ($ai['rejected'] ?? []))) > 0) {
            $parts[] = "{$rejected} AI answer(s) discarded after checking";
        }
        $parts[] = $gaps === [] ? 'AI review found no rule gaps' : sprintf('AI review flagged %d possible rule gap(s): ', count($gaps))
            . implode('; ', array_map(static fn (array $g): string => '[' . $g['severity'] . '] ' . ($g['field'] !== '' ? $g['field'] . ' — ' : '') . $g['issue'], $gaps));

        return ' ' . implode('. ', $parts) . '.';
    }

    /**
     * Routes whose controller method has the same name, e.g. "POST /admin/register is handled by
     * Avneesh\Admin\Http\Controllers\RegisterController::register". Helps when the annotated method is an unused copy.
     */
    public static function routedAlternatives(Target $target): string
    {
        $method = (string) $target->method;
        if ($method === '') {
            return '';
        }

        $found = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@') || ! str_ends_with($action, '@' . $method) || $action === $target->class . '@' . $method) {
                continue;
            }
            $verbs = implode('|', array_diff($route->methods(), ['HEAD']));
            $found[] = "{$verbs} /" . ltrim($route->uri(), '/') . ' is handled by ' . str_replace('@', '::', $action);
            if (count($found) === 3) {
                break;
            }
        }

        return $found === [] ? '' : ' Did you mean: ' . implode('; ', $found) . '? Put #[Validation] on that method instead.';
    }

    private function assertUsable(RulesExtraction $extraction, Target $target): void
    {
        if ($extraction->fields === []) {
            throw new TestPilotException(implode(' ', $extraction->warnings) ?: "No validation rules could be read from {$target->shortName()}.");
        }
        if (($extraction->inline['error_mode'] ?? 'laravel') === 'unknown') {
            throw new TestPilotException('TestPilot found the rules in ' . $extraction->label() . ', but not how failures are answered. ' . (string) (array_slice($extraction->warnings, -1)[0] ?? ''));
        }
    }

    /** @return class-string<FormRequest>|null */
    private function formRequest(Target $target): ?string
    {
        foreach ((array) ($target->data['parameters'] ?? []) as $parameter) {
            $class = $parameter['class'] ?? null;
            if (is_string($class) && class_exists($class) && is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }
}
