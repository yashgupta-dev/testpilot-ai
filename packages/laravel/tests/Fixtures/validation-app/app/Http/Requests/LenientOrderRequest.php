<?php

declare(strict_types=1);

namespace Shop\Http\Requests;

/**
 * Simulates a deployed endpoint that does NOT enforce what StoreOrderRequest declares
 * (email length is unchecked), so TestPilot should report a suspected bug.
 */
final class LenientOrderRequest extends StoreOrderRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'email' => 'required|email'];
    }
}
