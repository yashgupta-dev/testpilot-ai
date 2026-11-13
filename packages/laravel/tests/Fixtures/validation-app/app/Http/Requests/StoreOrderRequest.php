<?php

declare(strict_types=1);

namespace Shop\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:100',
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['required', Rule::in(['draft', 'placed'])],
            'notes' => ['nullable', 'string', 'max:500'],
            'delivery_date' => 'required|date|after:today',
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'between:0,10000'],
        ];
    }
}
