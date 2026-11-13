<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DynamicRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return array_merge($this->baseRules(), ['extra' => 'string']);
    }

    private function baseRules(): array
    {
        return ['name' => 'required'];
    }
}
