<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:255',
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['required', Rule::in(['draft', 'placed'])],
            'coupon' => ['nullable', 'string', Rule::exists('coupons', 'code')],
            'reference' => ['required', 'string', Rule::unique('orders', 'reference')->ignore(5)],
            'password' => ['required', 'confirmed', Password::min(10)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'between:0,10000'],
            'sku' => ['required', 'regex:/^[A-Z]{3}-\d+$/'],
            'notes' => ['nullable', 'string', fn ($attribute, $value, $fail) => null],
            'delivery_date' => 'required|date|after:today',
        ];
    }
}
