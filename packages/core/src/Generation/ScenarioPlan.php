<?php

declare(strict_types=1);

namespace TestPilot\Generation;

final class ScenarioPlan
{
    /** @param list<Scenario> $scenarios */
    public function __construct(
        public readonly string $targetKey,
        public readonly array $scenarios,
        public readonly string $notes = '',
        public readonly string $schemaVersion = '1',
        public readonly string $promptVersion = 'v1',
        public readonly ?string $model = null,
    ) {
    }

    /** @return list<Scenario> */
    public function selected(): array
    {
        return array_values(array_filter($this->scenarios, static fn (Scenario $s): bool => $s->selected));
    }

    /** @param list<string> $ids */
    public function withSelection(array $ids): self
    {
        $scenarios = array_map(static fn (Scenario $s): Scenario => $s->withSelected(in_array($s->id, $ids, true)), $this->scenarios);

        return new self($this->targetKey, $scenarios, $this->notes, $this->schemaVersion, $this->promptVersion, $this->model);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'target' => $this->targetKey,
            'scenarios' => array_map(static fn (Scenario $s): array => $s->toArray(), $this->scenarios),
            'notes' => $this->notes,
            'prompt_version' => $this->promptVersion,
            'model' => $this->model,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            targetKey: (string) $data['target'],
            scenarios: array_map(static fn (array $s): Scenario => Scenario::fromArray($s), (array) $data['scenarios']),
            notes: (string) ($data['notes'] ?? ''),
            schemaVersion: (string) ($data['schema_version'] ?? '1'),
            promptVersion: (string) ($data['prompt_version'] ?? 'v1'),
            model: isset($data['model']) ? (string) $data['model'] : null,
        );
    }
}
