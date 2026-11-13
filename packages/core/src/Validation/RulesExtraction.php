<?php

declare(strict_types=1);

namespace TestPilot\Validation;

final class RulesExtraction
{
    /**
     * @param list<FieldRules> $fields
     * @param list<string>     $warnings
     */
    public function __construct(
        public readonly string $requestClass,
        public readonly string $file,
        public readonly array $fields,
        public readonly array $warnings,
        /** False when part of rules() could not be read statically. */
        public readonly bool $complete,
        /** "true", "false", "dynamic" or "missing" (FormRequest::authorize()). */
        public readonly string $authorize,
        public readonly int $rulesLine = 0,
        /** "form_request" (FormRequest::rules()) or "inline" ($request->validate() / Validator::make in the action). */
        public readonly string $origin = 'form_request',
        /**
         * For inline rules: call (e.g. "$request->validate()"), line, error_status (int|null),
         * standard_errors (true when Laravel's ValidationException is thrown → 422 + errors JSON).
         *
         * @var array<string, mixed>
         */
        public readonly array $inline = [],
    ) {
    }

    /** Where the rules come from, for evidence text: "StoreOrderRequest::rules()" or "OrderController::store() → $request->validate()". */
    public function label(): string
    {
        return $this->origin === 'inline'
            ? $this->requestClass . '() → ' . ($this->inline['call'] ?? 'inline rules')
            : $this->shortClass() . '::rules()';
    }

    public function shortClass(): string
    {
        return str_contains($this->requestClass, '\\') ? substr($this->requestClass, (int) strrpos($this->requestClass, '\\') + 1) : $this->requestClass;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'request_class' => $this->requestClass,
            'file' => $this->file,
            'fields' => array_map(static fn (FieldRules $f): array => $f->toArray(), $this->fields),
            'warnings' => $this->warnings,
            'complete' => $this->complete,
            'authorize' => $this->authorize,
            'rules_line' => $this->rulesLine,
            'origin' => $this->origin,
            'label' => $this->label(),
            'inline' => $this->inline,
        ];
    }
}
