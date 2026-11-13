<?php

declare(strict_types=1);

namespace Shop\Http\Controllers;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use TestPilot\Attributes\TestPilot;
use TestPilot\Attributes\Validation;

/** "Fat controller" style: no FormRequest classes, validation written inside each action. */
#[TestPilot(area: 'Inline')]
final class InlineController
{
    use ValidatesRequests;

    #[Validation]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'quantity' => 'required|integer|min:1|max:20',
            'note' => 'nullable|string|max:50',
        ]);

        return new JsonResponse(['ok' => true, 'quantity' => $data['quantity']], 201);
    }

    #[Validation]
    public function post(Request $request): JsonResponse
    {
        $rules = ['title' => 'required|string|max:10'];
        $this->validate($request, $rules);

        return new JsonResponse(['ok' => true], 201);
    }

    #[Validation]
    public function legacy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|size:6',
            'amount' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 400);
        }

        return response()->json(['ok' => true]);
    }

    #[Validation]
    public function form(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:20',
            'age' => 'required|integer|min:18',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        return redirect('/thanks');
    }

    /** Failure response built by a helper: the status cannot be read, so TestPilot asks for it. */
    #[Validation]
    public function unclear(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['sku' => 'required|string']);
        if ($validator->fails()) {
            return $this->failed($validator);
        }

        return new JsonResponse(['ok' => true]);
    }

    /** Same helper, but the status is declared on the attribute. */
    #[Validation(errorStatus: 400)]
    public function declared(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['sku' => 'required|string|max:8']);
        if ($validator->fails()) {
            return $this->failed($validator);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function failed(\Illuminate\Validation\Validator $validator): JsonResponse
    {
        return new JsonResponse(['error' => $validator->errors()->first()], 400);
    }
}
