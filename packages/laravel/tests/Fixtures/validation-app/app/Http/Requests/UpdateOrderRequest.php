<?php

declare(strict_types=1);

namespace Shop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['draft', 'placed', 'shipped'])],
            'quantity' => 'required|integer|min:1|max:50',
        ];
    }
}
