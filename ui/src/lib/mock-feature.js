// Feature-module sample data, mirroring tests/Feature/FeatureWorkflowTest.php.

export const featureScenarios = [
 {
  "id": "S1",
  "name": "Places an order and charges price × quantity",
  "type": "positive",
  "module": "feature",
  "description": "",
  "inputs": "product price 100, stock 5; POST {quantity: 2}",
  "expected": "201; total 200; order saved; stock 3; SendInvoice queued",
  "basis": "confirmed",
  "basis_source": "line 37: $total = $product->price * $data['quantity']",
  "confidence": 0.95,
  "priority": "high",
  "selected": true
 },
 {
  "id": "S2",
  "name": "Applies the SAVE10 coupon",
  "type": "positive",
  "module": "feature",
  "description": "",
  "inputs": "price 100; POST {quantity: 2, coupon: \"SAVE10\"}",
  "expected": "201; total 180; order saved with coupon SAVE10",
  "basis": "confirmed",
  "basis_source": "line 38: SAVE10 → $total * 0.9",
  "confidence": 0.95,
  "priority": "high",
  "selected": true
 },
 {
  "id": "S3",
  "name": "Refuses an order larger than the stock",
  "type": "negative",
  "module": "feature",
  "description": "",
  "inputs": "stock 1; POST {quantity: 2}",
  "expected": "409 Out of stock; no order; nothing queued",
  "basis": "confirmed",
  "basis_source": "line 33: $product->stock < $data['quantity']",
  "confidence": 0.95,
  "priority": "high",
  "selected": true
 },
 {
  "id": "S4",
  "name": "Ignores an unknown coupon",
  "type": "edge",
  "module": "feature",
  "description": "",
  "inputs": "price 100; POST {quantity: 1, coupon: \"FREE\"}",
  "expected": "201; total 100",
  "basis": "confirmed",
  "basis_source": "line 38: only SAVE10 changes the total",
  "confidence": 0.9,
  "priority": "medium",
  "selected": true
 },
 {
  "id": "S5",
  "name": "Coupon codes are case-insensitive",
  "type": "edge",
  "module": "feature",
  "description": "",
  "inputs": "POST {coupon: \"save10\"}",
  "expected": "total 90?",
  "basis": "assumption",
  "basis_source": "not in source: comparison is case-sensitive",
  "confidence": 0.3,
  "priority": "low",
  "selected": false
 }
]

export const featureContext = {
 "method": "POST",
 "uri": "/api/checkout",
 "middleware": [
  "api"
 ],
 "authenticate": false,
 "user_model": "App\\Models\\User",
 "user_has_factory": true,
 "bindings": null,
 "url_setup": null,
 "validation": {
  "origin": "CheckoutController::store() → $request->validate()",
  "error_mode": "laravel",
  "error_status": 422,
  "fields": [
   "product_id: required|integer|exists:products,id",
   "quantity: required|integer|min:1|max:20",
   "coupon: nullable|string"
  ],
  "valid_payload": {}
 },
 "models": [
  {
   "class": "App\\Models\\Product",
   "table": "products",
   "fillable": [
    "name",
    "price",
    "stock"
   ],
   "factory": true
  },
  {
   "class": "App\\Models\\Customer",
   "table": "customers",
   "fillable": [],
   "factory": true
  },
  {
   "class": "App\\Models\\Order",
   "table": "orders",
   "fillable": [],
   "factory": true
  }
 ],
 "fakes": [
  "mail",
  "notification",
  "queue",
  "http"
 ]
}

export const featureCode = "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\Http;\nuse Illuminate\\Support\\Facades\\Mail;\nuse Illuminate\\Support\\Facades\\Notification;\nuse Illuminate\\Support\\Facades\\Queue;\nuse App\\Jobs\\SendInvoice;\nuse App\\Models\\Product;\n\nuses(\\Tests\\TestCase::class);\nuses(\\Illuminate\\Foundation\\Testing\\RefreshDatabase::class);\n\nbeforeEach(function () {\n    Mail::fake();\n    Notification::fake();\n    Queue::fake();\n    Http::preventStrayRequests();\n});\n\nit('[S1] places an order and charges price × quantity', function () {\n    $product = Product::factory()->create(['price' => 100, 'stock' => 5]);\n\n    $response = $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 2]);\n\n    $response->assertStatus(201);\n    expect((float) $response->json('total'))->toBe(200.0);\n    $this->assertDatabaseHas('orders', ['quantity' => 2, 'total' => 200, 'status' => 'placed']);\n    expect($product->fresh()->stock)->toEqual(3);\n    Queue::assertPushed(SendInvoice::class);\n});\n\nit('[S2] applies the SAVE10 coupon', function () {\n    $product = Product::factory()->create(['price' => 100, 'stock' => 5]);\n\n    $response = $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 2, 'coupon' => 'SAVE10']);\n\n    $response->assertStatus(201);\n    expect((float) $response->json('total'))->toBe(180.0);\n    $this->assertDatabaseHas('orders', ['total' => 180, 'coupon' => 'SAVE10']);\n});\n\nit('[S3] refuses an order larger than the stock', function () {\n    $product = Product::factory()->create(['price' => 100, 'stock' => 1]);\n\n    $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 2])\n        ->assertStatus(409)\n        ->assertJsonPath('message', 'Out of stock');\n\n    $this->assertDatabaseCount('orders', 0);\n    Queue::assertNothingPushed();\n});\n\nit('[S4] ignores an unknown coupon', function () {\n    $product = Product::factory()->create(['price' => 100, 'stock' => 5]);\n\n    $response = $this->postJson('/api/checkout', ['product_id' => $product->id, 'quantity' => 1, 'coupon' => 'FREE']);\n\n    $response->assertStatus(201);\n    expect((float) $response->json('total'))->toBe(100.0);\n});\n"

export const featureSource = "    #[FeatureTest(focus: ['coupons', 'stock'])]\n    public function store(Request $request): JsonResponse\n    {\n        $data = $request->validate([\n            'product_id' => 'required|integer|exists:products,id',\n            'quantity' => 'required|integer|min:1|max:20',\n            'coupon' => 'nullable|string',\n        ]);\n\n        $product = Product::findOrFail($data['product_id']);\n        if ($product->stock < $data['quantity']) {\n            return response()->json(['message' => 'Out of stock'], 409);\n        }\n\n        $total = $product->price * $data['quantity'];\n        if (($data['coupon'] ?? null) === 'SAVE10') {\n            $total = round($total * 0.9, 2);\n        }\n\n        $order = Order::create([...]);\n        $product->decrement('stock', $data['quantity']);\n        SendInvoice::dispatch($order);\n\n        return response()->json(['id' => $order->id, 'total' => $total], 201);\n    }"
