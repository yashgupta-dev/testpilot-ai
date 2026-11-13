<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Support;

use TestPilot\DTO\ParameterInfo;
use TestPilot\DTO\RawAnnotation;
use TestPilot\DTO\TargetLocation;
use TestPilot\DTO\TargetType;
use TestPilot\DTO\TestTarget;
use TestPilot\Laravel\Models\Target;

/**
 * Rebuilds the core TestTarget DTO from a stored row, so planning and generation can run in a queue
 * worker without re-scanning the whole project.
 */
final class TargetHydrator
{
    /** @return array<string, mixed> */
    public static function dehydrate(TestTarget $target): array
    {
        return [...$target->toArray(), 'class_skeleton' => $target->classSkeleton];
    }

    public static function hydrate(Target $row): TestTarget
    {
        $data = (array) $row->data;

        return new TestTarget(
            key: (string) $data['key'],
            type: TargetType::from((string) $data['type']),
            class: $data['class'] ?? null,
            method: $data['method'] ?? null,
            function: $data['function'] ?? null,
            namespace: $data['namespace'] ?? null,
            location: new TargetLocation((string) $data['file'], (int) $data['line'], (int) $data['end_line']),
            signature: (string) $data['signature'],
            parameters: array_map(
                static fn (array $p): ParameterInfo => new ParameterInfo($p['name'], $p['type'], (bool) $p['has_default'], $p['default'], (bool) $p['variadic'], (bool) $p['by_ref'], $p['class'] ?? null),
                (array) ($data['parameters'] ?? []),
            ),
            returnType: $data['return_type'] ?? null,
            isStatic: (bool) ($data['is_static'] ?? false),
            classAnnotations: self::annotations((array) ($data['class_annotations'] ?? [])),
            annotations: self::annotations((array) ($data['annotations'] ?? [])),
            source: (string) $row->source,
            fingerprint: (string) $data['fingerprint'],
            complexity: (int) ($data['complexity'] ?? 1),
            classSkeleton: $data['class_skeleton'] ?? null,
            declaredIn: $data['declared_in'] ?? null,
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<RawAnnotation>
     */
    private static function annotations(array $items): array
    {
        return array_map(
            static fn (array $a): RawAnnotation => new RawAnnotation($a['name'], (array) $a['arguments'], (int) $a['line'], (string) $a['scope'], (array) ($a['warnings'] ?? [])),
            $items,
        );
    }
}
