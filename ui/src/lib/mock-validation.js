// Generated from the real validation engine (FormRequestRulesExtractor + ValidationCaseGenerator +
// ValidationTestBuilder) run against packages/core/tests/Fixtures — so the shapes match the API exactly.

export const validationScenarios = [
 {
  "id": "S1",
  "name": "Accepts a fully valid payload",
  "type": "positive",
  "module": "validation",
  "description": "A fully valid payload could not be built: 'coupon' must exist in coupons; create it with a factory. 'sku' must match a regular expression. 'notes' has a custom rule that may reject generated values.",
  "inputs": "valid payload",
  "expected": "no validation errors",
  "basis": "assumption",
  "basis_source": "Valid value derived from every rule in StoreOrderRequest::rules()",
  "confidence": 0,
  "priority": "high",
  "selected": false,
  "meta": {
   "field": null,
   "rule": "all rules",
   "action": "none",
   "value": null,
   "target_field": null,
   "expect": "no_errors",
   "todo": "A fully valid payload could not be built: 'coupon' must exist in coupons; create it with a factory. 'sku' must match a regular expression. 'notes' has a custom rule that may reject generated values.",
   "also_set": []
  }
 },
 {
  "id": "S2",
  "name": "email · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on email",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 21: 'email' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "email",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "email",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S3",
  "name": "email · email — rejects an invalid email",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"abc@\"",
  "expected": "422 with an error on email",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 21: 'email' => email",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "email",
   "rule": "email",
   "action": "set",
   "value": "abc@",
   "target_field": "email",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S4",
  "name": "email · max:100 — rejects 101 characters (max 100)",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "101 × \"t\"",
  "expected": "422 with an error on email",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 21: 'email' => max:100",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "email",
   "rule": "max:100",
   "action": "set",
   "value": "tp@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.com",
   "target_field": "email",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S5",
  "name": "email · max:100 — accepts exactly 100 characters (max boundary)",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "100 × \"t\"",
  "expected": "no error on email",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 21: 'email' => max:100",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "email",
   "rule": "max:100",
   "action": "set",
   "value": "tp@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.com",
   "target_field": "email",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S6",
  "name": "quantity · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on quantity",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 22: 'quantity' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "quantity",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "quantity",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S7",
  "name": "quantity · integer — rejects a non-integer value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"abc\"",
  "expected": "422 with an error on quantity",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 22: 'quantity' => integer",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "quantity",
   "rule": "integer",
   "action": "set",
   "value": "abc",
   "target_field": "quantity",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S8",
  "name": "quantity · integer — rejects a decimal number",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "1.5",
  "expected": "422 with an error on quantity",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 22: 'quantity' => integer",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "quantity",
   "rule": "integer",
   "action": "set",
   "value": 1.5,
   "target_field": "quantity",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S9",
  "name": "quantity · min:1 — rejects 0 (min 1)",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "0",
  "expected": "422 with an error on quantity",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 22: 'quantity' => min:1",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "quantity",
   "rule": "min:1",
   "action": "set",
   "value": 0,
   "target_field": "quantity",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S10",
  "name": "quantity · min:1 — accepts exactly 1 (min boundary)",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "1",
  "expected": "no error on quantity",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 22: 'quantity' => min:1",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "quantity",
   "rule": "min:1",
   "action": "set",
   "value": 1,
   "target_field": "quantity",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S11",
  "name": "quantity · max:100 — rejects 101 (max 100)",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "101",
  "expected": "422 with an error on quantity",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 22: 'quantity' => max:100",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "quantity",
   "rule": "max:100",
   "action": "set",
   "value": 101,
   "target_field": "quantity",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S12",
  "name": "quantity · max:100 — accepts exactly 100 (max boundary)",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "100",
  "expected": "no error on quantity",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 22: 'quantity' => max:100",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "quantity",
   "rule": "max:100",
   "action": "set",
   "value": 100,
   "target_field": "quantity",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S13",
  "name": "status · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on status",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 23: 'status' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "status",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "status",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S14",
  "name": "status · in:draft,placed — rejects a value outside the allowed list",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"tp-not-allowed-value\"",
  "expected": "422 with an error on status",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 23: 'status' => in:draft,placed",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "status",
   "rule": "in:draft,placed",
   "action": "set",
   "value": "tp-not-allowed-value",
   "target_field": "status",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S15",
  "name": "coupon · nullable — accepts null",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "null",
  "expected": "no error on coupon",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 24: 'coupon' => nullable",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "coupon",
   "rule": "nullable",
   "action": "set",
   "value": null,
   "target_field": "coupon",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S16",
  "name": "coupon · string — rejects a non-string value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "12345",
  "expected": "422 with an error on coupon",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 24: 'coupon' => string",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "coupon",
   "rule": "string",
   "action": "set",
   "value": 12345,
   "target_field": "coupon",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S17",
  "name": "coupon · exists:coupons,code — rejects a value that does not exist in coupons",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"tp-non-existent-999999\"",
  "expected": "422 with an error on coupon",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 24: 'coupon' => exists:coupons,code",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "coupon",
   "rule": "exists:coupons,code",
   "action": "set",
   "value": "tp-non-existent-999999",
   "target_field": "coupon",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S18",
  "name": "reference · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on reference",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 25: 'reference' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "reference",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "reference",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S19",
  "name": "reference · string — rejects a non-string value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "12345",
  "expected": "422 with an error on reference",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 25: 'reference' => string",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "reference",
   "rule": "string",
   "action": "set",
   "value": 12345,
   "target_field": "reference",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S20",
  "name": "reference · unique:orders,reference (with ->ignore()) — rejects a duplicate value",
  "type": "negative",
  "module": "validation",
  "description": "Needs an existing orders record; add one with a model factory and approve this case manually.",
  "inputs": "—",
  "expected": "422 with an error on reference",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 25: 'reference' => unique:orders,reference (with ->ignore())",
  "confidence": 0,
  "priority": "low",
  "selected": false,
  "meta": {
   "field": "reference",
   "rule": "unique:orders,reference (with ->ignore())",
   "action": "none",
   "value": null,
   "target_field": "reference",
   "expect": "error",
   "todo": "Needs an existing orders record; add one with a model factory and approve this case manually.",
   "also_set": []
  }
 },
 {
  "id": "S21",
  "name": "password · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on password",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 26: 'password' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "password",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "password",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S22",
  "name": "password · confirmed — rejects a mismatched confirmation",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"tp-mismatch-value\"",
  "expected": "422 with an error on password",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 26: 'password' => confirmed",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "password",
   "rule": "confirmed",
   "action": "set",
   "value": "tp-mismatch-value",
   "target_field": "password_confirmation",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S23",
  "name": "password · Password (min 10) — rejects a password shorter than 10 characters",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"aaaaaaaaa\"",
  "expected": "422 with an error on password",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 26: 'password' => Password (min 10)",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "password",
   "rule": "Password (min 10)",
   "action": "set",
   "value": "aaaaaaaaa",
   "target_field": "password",
   "expect": "error",
   "todo": null,
   "also_set": {
    "password_confirmation": "aaaaaaaaa"
   }
  }
 },
 {
  "id": "S24",
  "name": "items · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on items",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 27: 'items' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "items",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "items",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S25",
  "name": "items · array — rejects a non-array value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"not-an-array\"",
  "expected": "422 with an error on items",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 27: 'items' => array",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "items",
   "rule": "array",
   "action": "set",
   "value": "not-an-array",
   "target_field": "items",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S26",
  "name": "items · min:1 — rejects 0 items (min 1)",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "array of 0",
  "expected": "422 with an error on items",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 27: 'items' => min:1",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "items",
   "rule": "min:1",
   "action": "set",
   "value": [],
   "target_field": "items",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S27",
  "name": "items · min:1 — accepts exactly 1 items (min boundary)",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "array of 1",
  "expected": "no error on items",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 27: 'items' => min:1",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "items",
   "rule": "min:1",
   "action": "set",
   "value": [
    "x"
   ],
   "target_field": "items",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S28",
  "name": "items.*.price · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on items.0.price",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 28: 'items.*.price' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "items.0.price",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "items.0.price",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S29",
  "name": "items.*.price · numeric — rejects a non-numeric value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"abc\"",
  "expected": "422 with an error on items.0.price",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 28: 'items.*.price' => numeric",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "items.0.price",
   "rule": "numeric",
   "action": "set",
   "value": "abc",
   "target_field": "items.0.price",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S30",
  "name": "items.*.price · between:0,10000 — rejects -1 (min 0)",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "-1",
  "expected": "422 with an error on items.0.price",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 28: 'items.*.price' => between:0,10000",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "items.0.price",
   "rule": "between:0,10000",
   "action": "set",
   "value": -1,
   "target_field": "items.0.price",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S31",
  "name": "items.*.price · between:0,10000 — accepts exactly 0 (min boundary)",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "0",
  "expected": "no error on items.0.price",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 28: 'items.*.price' => between:0,10000",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "items.0.price",
   "rule": "between:0,10000",
   "action": "set",
   "value": 0,
   "target_field": "items.0.price",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S32",
  "name": "items.*.price · between:0,10000 — rejects 10001 (max 10000)",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "10001",
  "expected": "422 with an error on items.0.price",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 28: 'items.*.price' => between:0,10000",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "items.0.price",
   "rule": "between:0,10000",
   "action": "set",
   "value": 10001,
   "target_field": "items.0.price",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S33",
  "name": "items.*.price · between:0,10000 — accepts exactly 10000 (max boundary)",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "10000",
  "expected": "no error on items.0.price",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 28: 'items.*.price' => between:0,10000",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "items.0.price",
   "rule": "between:0,10000",
   "action": "set",
   "value": 10000,
   "target_field": "items.0.price",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S34",
  "name": "sku · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on sku",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 29: 'sku' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "sku",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "sku",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S35",
  "name": "sku · regex:/^[A-Z]{3}-\\d+$/ — pattern check",
  "type": "negative",
  "module": "validation",
  "description": "TestPilot cannot guarantee a value that breaks an arbitrary regular expression.",
  "inputs": "—",
  "expected": "422 with an error on sku",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 29: 'sku' => regex:/^[A-Z]{3}-\\d+$/",
  "confidence": 0,
  "priority": "low",
  "selected": false,
  "meta": {
   "field": "sku",
   "rule": "regex:/^[A-Z]{3}-\\d+$/",
   "action": "none",
   "value": null,
   "target_field": "sku",
   "expect": "error",
   "todo": "TestPilot cannot guarantee a value that breaks an arbitrary regular expression.",
   "also_set": []
  }
 },
 {
  "id": "S36",
  "name": "notes · nullable — accepts null",
  "type": "edge",
  "module": "validation",
  "description": "",
  "inputs": "null",
  "expected": "no error on notes",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 30: 'notes' => nullable",
  "confidence": 1,
  "priority": "medium",
  "selected": true,
  "meta": {
   "field": "notes",
   "rule": "nullable",
   "action": "set",
   "value": null,
   "target_field": "notes",
   "expect": "no_error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S37",
  "name": "notes · string — rejects a non-string value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "12345",
  "expected": "422 with an error on notes",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 30: 'notes' => string",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "notes",
   "rule": "string",
   "action": "set",
   "value": 12345,
   "target_field": "notes",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S38",
  "name": "notes · Closure rule — custom rule",
  "type": "negative",
  "module": "validation",
  "description": "Custom rule objects and closures need a human-written case (planned: AI-assisted).",
  "inputs": "—",
  "expected": "422 with an error on notes",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 30: 'notes' => Closure rule",
  "confidence": 0,
  "priority": "low",
  "selected": false,
  "meta": {
   "field": "notes",
   "rule": "Closure rule",
   "action": "none",
   "value": null,
   "target_field": "notes",
   "expect": "error",
   "todo": "Custom rule objects and closures need a human-written case (planned: AI-assisted).",
   "also_set": []
  }
 },
 {
  "id": "S39",
  "name": "delivery_date · required — rejects a missing value",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "(field missing)",
  "expected": "422 with an error on delivery_date",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 31: 'delivery_date' => required",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "delivery_date",
   "rule": "required",
   "action": "remove",
   "value": null,
   "target_field": "delivery_date",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S40",
  "name": "delivery_date · date — rejects an invalid date",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"not-a-date\"",
  "expected": "422 with an error on delivery_date",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 31: 'delivery_date' => date",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "delivery_date",
   "rule": "date",
   "action": "set",
   "value": "not-a-date",
   "target_field": "delivery_date",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 },
 {
  "id": "S41",
  "name": "delivery_date · after:today — rejects a date in the past",
  "type": "negative",
  "module": "validation",
  "description": "",
  "inputs": "\"2000-01-01\"",
  "expected": "422 with an error on delivery_date",
  "basis": "confirmed",
  "basis_source": "StoreOrderRequest::rules() line 31: 'delivery_date' => after:today",
  "confidence": 1,
  "priority": "high",
  "selected": true,
  "meta": {
   "field": "delivery_date",
   "rule": "after:today",
   "action": "set",
   "value": "2000-01-01",
   "target_field": "delivery_date",
   "expect": "error",
   "todo": null,
   "also_set": []
  }
 }
]

export const validationContext = {
 "request_class": "App\\Http\\Requests\\StoreOrderRequest",
 "request_file": "app/Http/Requests/StoreOrderRequest.php",
 "rules_line": 18,
 "method": "POST",
 "uri": "/api/orders",
 "authenticate": true,
 "authorize": "true",
 "complete": true,
 "fields": [
  {
   "field": "email",
   "line": 21,
   "type": "string",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "email",
     "params": [],
     "source": "email",
     "kind": "known",
     "note": null
    },
    {
     "name": "max",
     "params": [
      "100"
     ],
     "source": "max:100",
     "kind": "known",
     "note": null
    }
   ]
  },
  {
   "field": "quantity",
   "line": 22,
   "type": "integer",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "integer",
     "params": [],
     "source": "integer",
     "kind": "known",
     "note": null
    },
    {
     "name": "min",
     "params": [
      "1"
     ],
     "source": "min:1",
     "kind": "known",
     "note": null
    },
    {
     "name": "max",
     "params": [
      "100"
     ],
     "source": "max:100",
     "kind": "known",
     "note": null
    }
   ]
  },
  {
   "field": "status",
   "line": 23,
   "type": "string",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "in",
     "params": [
      "draft",
      "placed"
     ],
     "source": "Rule::in(…)",
     "kind": "known",
     "note": "in:draft,placed"
    }
   ]
  },
  {
   "field": "coupon",
   "line": 24,
   "type": "string",
   "rules": [
    {
     "name": "nullable",
     "params": [],
     "source": "nullable",
     "kind": "known",
     "note": null
    },
    {
     "name": "string",
     "params": [],
     "source": "string",
     "kind": "known",
     "note": null
    },
    {
     "name": "exists",
     "params": [
      "coupons",
      "code"
     ],
     "source": "Rule::exists(…)",
     "kind": "known",
     "note": "exists:coupons,code"
    }
   ]
  },
  {
   "field": "reference",
   "line": 25,
   "type": "string",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "string",
     "params": [],
     "source": "string",
     "kind": "known",
     "note": null
    },
    {
     "name": "unique",
     "params": [
      "orders",
      "reference"
     ],
     "source": "Rule::unique(…)",
     "kind": "known",
     "note": "unique:orders,reference (with ->ignore())"
    }
   ]
  },
  {
   "field": "password",
   "line": 26,
   "type": "string",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "confirmed",
     "params": [],
     "source": "confirmed",
     "kind": "known",
     "note": null
    },
    {
     "name": "password",
     "params": [
      "10"
     ],
     "source": "Password::min()",
     "kind": "known",
     "note": "Password (min 10)"
    }
   ]
  },
  {
   "field": "items",
   "line": 27,
   "type": "array",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "array",
     "params": [],
     "source": "array",
     "kind": "known",
     "note": null
    },
    {
     "name": "min",
     "params": [
      "1"
     ],
     "source": "min:1",
     "kind": "known",
     "note": null
    }
   ]
  },
  {
   "field": "items.*.price",
   "line": 28,
   "type": "numeric",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "numeric",
     "params": [],
     "source": "numeric",
     "kind": "known",
     "note": null
    },
    {
     "name": "between",
     "params": [
      "0",
      "10000"
     ],
     "source": "between:0,10000",
     "kind": "known",
     "note": null
    }
   ]
  },
  {
   "field": "sku",
   "line": 29,
   "type": "string",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "regex",
     "params": [
      "/^[A-Z]{3}-\\d+$/"
     ],
     "source": "regex:/^[A-Z]{3}-\\d+$/",
     "kind": "known",
     "note": null
    }
   ]
  },
  {
   "field": "notes",
   "line": 30,
   "type": "string",
   "rules": [
    {
     "name": "nullable",
     "params": [],
     "source": "nullable",
     "kind": "known",
     "note": null
    },
    {
     "name": "string",
     "params": [],
     "source": "string",
     "kind": "known",
     "note": null
    },
    {
     "name": "closure",
     "params": [],
     "source": "closure",
     "kind": "custom",
     "note": "Closure rule"
    }
   ]
  },
  {
   "field": "delivery_date",
   "line": 31,
   "type": "date",
   "rules": [
    {
     "name": "required",
     "params": [],
     "source": "required",
     "kind": "known",
     "note": null
    },
    {
     "name": "date",
     "params": [],
     "source": "date",
     "kind": "known",
     "note": null
    },
    {
     "name": "after",
     "params": [
      "today"
     ],
     "source": "after:today",
     "kind": "known",
     "note": null
    }
   ]
  }
 ],
 "baseline": {
  "email": "tp.user@example.com",
  "quantity": 1,
  "status": "draft",
  "coupon": "TestPilot value",
  "reference": "TestPilot value",
  "password": "Tp-Str0ng!Passw0rd",
  "password_confirmation": "Tp-Str0ng!Passw0rd",
  "items": [
   {
    "price": 0
   }
  ],
  "sku": "TestPilot value",
  "notes": "TestPilot value",
  "delivery_date": "2099-12-31"
 },
 "baseline_issues": [
  "'coupon' must exist in coupons; create it with a factory.",
  "'sku' must match a regular expression.",
  "'notes' has a custom rule that may reject generated values."
 ],
 "warnings": []
}

export const validationCode = "<?php\n\ndeclare(strict_types=1);\n\nuses(\\Tests\\TestCase::class);\nuses(\\Illuminate\\Foundation\\Testing\\RefreshDatabase::class);\n\n/**\n * A request body that satisfies every rule in StoreOrderRequest::rules().\n * Each test changes one field and checks only that field's error.\n */\nfunction testpilotValidPayload_5c04079b16(): array\n{\n    return [\n        'email' => 'tp.user@example.com',\n        'quantity' => 1,\n        'status' => 'draft',\n        'coupon' => 'TestPilot value',\n        'reference' => 'TestPilot value',\n        'password' => 'Tp-Str0ng!Passw0rd',\n        'password_confirmation' => 'Tp-Str0ng!Passw0rd',\n        'items' => [['price' => 0.0]],\n        'sku' => 'TestPilot value',\n        'notes' => 'TestPilot value',\n        'delivery_date' => '2099-12-31',\n    ];\n}\n\nbeforeEach(function () {\n    // The route requires authentication, so requests are sent as a freshly created user.\n    $this->actingAs(\\App\\Models\\User::factory()->create());\n});\n\nit('[S2] email · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'email');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['email']);\n});\n\nit('[S3] email · email — rejects an invalid email', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'email', 'abc@');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['email']);\n});\n\nit('[S4] email · max:100 — rejects 101 characters (max 100)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'email', 'tp@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.com');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['email']);\n});\n\nit('[S5] email · max:100 — accepts exactly 100 characters (max boundary)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'email', 'tp@aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.com');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['email']);\n});\n\nit('[S6] quantity · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'quantity');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['quantity']);\n});\n\nit('[S7] quantity · integer — rejects a non-integer value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'quantity', 'abc');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['quantity']);\n});\n\nit('[S8] quantity · integer — rejects a decimal number', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'quantity', 1.5);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['quantity']);\n});\n\nit('[S9] quantity · min:1 — rejects 0 (min 1)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'quantity', 0);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['quantity']);\n});\n\nit('[S10] quantity · min:1 — accepts exactly 1 (min boundary)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'quantity', 1);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['quantity']);\n});\n\nit('[S11] quantity · max:100 — rejects 101 (max 100)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'quantity', 101);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['quantity']);\n});\n\nit('[S12] quantity · max:100 — accepts exactly 100 (max boundary)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'quantity', 100);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['quantity']);\n});\n\nit('[S13] status · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'status');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['status']);\n});\n\nit('[S14] status · in:draft,placed — rejects a value outside the allowed list', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'status', 'tp-not-allowed-value');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['status']);\n});\n\nit('[S15] coupon · nullable — accepts null', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'coupon', null);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['coupon']);\n});\n\nit('[S16] coupon · string — rejects a non-string value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'coupon', 12345);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['coupon']);\n});\n\nit('[S17] coupon · exists:coupons,code — rejects a value that does not exist in coupons', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'coupon', 'tp-non-existent-999999');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['coupon']);\n});\n\nit('[S18] reference · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'reference');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['reference']);\n});\n\nit('[S19] reference · string — rejects a non-string value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'reference', 12345);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['reference']);\n});\n\nit('[S21] password · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'password');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['password']);\n});\n\nit('[S22] password · confirmed — rejects a mismatched confirmation', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'password_confirmation', 'tp-mismatch-value');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['password']);\n});\n\nit('[S23] password · Password (min 10) — rejects a password shorter than 10 characters', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'password', 'aaaaaaaaa');\n    data_set($payload, 'password_confirmation', 'aaaaaaaaa');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['password']);\n});\n\nit('[S24] items · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'items');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['items']);\n});\n\nit('[S25] items · array — rejects a non-array value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items', 'not-an-array');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['items']);\n});\n\nit('[S26] items · min:1 — rejects 0 items (min 1)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items', []);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['items']);\n});\n\nit('[S27] items · min:1 — accepts exactly 1 items (min boundary)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items', ['x']);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['items']);\n});\n\nit('[S28] items.*.price · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'items.0.price');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['items.0.price']);\n});\n\nit('[S29] items.*.price · numeric — rejects a non-numeric value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items.0.price', 'abc');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['items.0.price']);\n});\n\nit('[S30] items.*.price · between:0,10000 — rejects -1 (min 0)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items.0.price', -1);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['items.0.price']);\n});\n\nit('[S31] items.*.price · between:0,10000 — accepts exactly 0 (min boundary)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items.0.price', 0);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['items.0.price']);\n});\n\nit('[S32] items.*.price · between:0,10000 — rejects 10001 (max 10000)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items.0.price', 10001);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['items.0.price']);\n});\n\nit('[S33] items.*.price · between:0,10000 — accepts exactly 10000 (max boundary)', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'items.0.price', 10000);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['items.0.price']);\n});\n\nit('[S34] sku · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'sku');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['sku']);\n});\n\nit('[S36] notes · nullable — accepts null', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'notes', null);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertJsonMissingValidationErrors(['notes']);\n});\n\nit('[S37] notes · string — rejects a non-string value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'notes', 12345);\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['notes']);\n});\n\nit('[S39] delivery_date · required — rejects a missing value', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    \\Illuminate\\Support\\Arr::forget($payload, 'delivery_date');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['delivery_date']);\n});\n\nit('[S40] delivery_date · date — rejects an invalid date', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'delivery_date', 'not-a-date');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['delivery_date']);\n});\n\nit('[S41] delivery_date · after:today — rejects a date in the past', function () {\n    $payload = testpilotValidPayload_5c04079b16();\n    data_set($payload, 'delivery_date', '2000-01-01');\n\n    $this->postJson('/api/orders', $payload)\n        ->assertStatus(422)\n        ->assertJsonValidationErrors(['delivery_date']);\n});\n"

export const validationResults = {"S2":"passed","S3":"passed","S4":"failed","S5":"passed","S6":"passed","S7":"passed","S8":"passed","S9":"passed","S10":"passed","S11":"passed","S12":"passed","S13":"passed","S14":"passed","S15":"passed","S16":"passed","S17":"passed","S18":"passed","S19":"passed","S21":"passed","S22":"passed","S23":"passed","S24":"passed","S25":"passed","S26":"passed","S27":"passed","S28":"passed","S29":"passed","S30":"passed","S31":"passed","S32":"passed","S33":"passed","S34":"passed","S36":"passed","S37":"passed","S39":"passed","S40":"passed","S41":"passed"}

export const validationCaseCount = 37

export const bindingScenarios = [{"id":"S1","name":"Accepts a fully valid payload","type":"positive","module":"validation","description":"","inputs":"valid payload","expected":"no validation errors","basis":"confirmed","basis_source":"Valid value derived from every rule in UpdateOrderRequest::rules()","confidence":1,"priority":"high","selected":true,"meta":{"field":null,"rule":"all rules","action":"none","value":null,"target_field":null,"expect":"no_errors","todo":null,"also_set":[]}},{"id":"S2","name":"status · required — rejects a missing value","type":"negative","module":"validation","description":"","inputs":"(field missing)","expected":"422 with an error on status","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 20: 'status' => required","confidence":1,"priority":"high","selected":true,"meta":{"field":"status","rule":"required","action":"remove","value":null,"target_field":"status","expect":"error","todo":null,"also_set":[]}},{"id":"S3","name":"status · in:draft,placed,shipped — rejects a value outside the allowed list","type":"negative","module":"validation","description":"","inputs":"\"tp-not-allowed-value\"","expected":"422 with an error on status","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 20: 'status' => in:draft,placed,shipped","confidence":1,"priority":"high","selected":true,"meta":{"field":"status","rule":"in:draft,placed,shipped","action":"set","value":"tp-not-allowed-value","target_field":"status","expect":"error","todo":null,"also_set":[]}},{"id":"S4","name":"quantity · required — rejects a missing value","type":"negative","module":"validation","description":"","inputs":"(field missing)","expected":"422 with an error on quantity","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 21: 'quantity' => required","confidence":1,"priority":"high","selected":true,"meta":{"field":"quantity","rule":"required","action":"remove","value":null,"target_field":"quantity","expect":"error","todo":null,"also_set":[]}},{"id":"S5","name":"quantity · integer — rejects a non-integer value","type":"negative","module":"validation","description":"","inputs":"\"abc\"","expected":"422 with an error on quantity","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 21: 'quantity' => integer","confidence":1,"priority":"high","selected":true,"meta":{"field":"quantity","rule":"integer","action":"set","value":"abc","target_field":"quantity","expect":"error","todo":null,"also_set":[]}},{"id":"S6","name":"quantity · integer — rejects a decimal number","type":"negative","module":"validation","description":"","inputs":"1.5","expected":"422 with an error on quantity","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 21: 'quantity' => integer","confidence":1,"priority":"medium","selected":true,"meta":{"field":"quantity","rule":"integer","action":"set","value":1.5,"target_field":"quantity","expect":"error","todo":null,"also_set":[]}},{"id":"S7","name":"quantity · min:1 — rejects 0 (min 1)","type":"negative","module":"validation","description":"","inputs":"0","expected":"422 with an error on quantity","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 21: 'quantity' => min:1","confidence":1,"priority":"high","selected":true,"meta":{"field":"quantity","rule":"min:1","action":"set","value":0,"target_field":"quantity","expect":"error","todo":null,"also_set":[]}},{"id":"S8","name":"quantity · min:1 — accepts exactly 1 (min boundary)","type":"edge","module":"validation","description":"","inputs":"1","expected":"no error on quantity","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 21: 'quantity' => min:1","confidence":1,"priority":"medium","selected":true,"meta":{"field":"quantity","rule":"min:1","action":"set","value":1,"target_field":"quantity","expect":"no_error","todo":null,"also_set":[]}},{"id":"S9","name":"quantity · max:50 — rejects 51 (max 50)","type":"negative","module":"validation","description":"","inputs":"51","expected":"422 with an error on quantity","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 21: 'quantity' => max:50","confidence":1,"priority":"high","selected":true,"meta":{"field":"quantity","rule":"max:50","action":"set","value":51,"target_field":"quantity","expect":"error","todo":null,"also_set":[]}},{"id":"S10","name":"quantity · max:50 — accepts exactly 50 (max boundary)","type":"edge","module":"validation","description":"","inputs":"50","expected":"no error on quantity","basis":"confirmed","basis_source":"UpdateOrderRequest::rules() line 21: 'quantity' => max:50","confidence":1,"priority":"medium","selected":true,"meta":{"field":"quantity","rule":"max:50","action":"set","value":50,"target_field":"quantity","expect":"no_error","todo":null,"also_set":[]}}]

export const bindingContext = {"request_class":"App\\Http\\Requests\\UpdateOrderRequest","request_file":"app/Http/Requests/UpdateOrderRequest.php","rules_line":17,"method":"PATCH","uri":"/api/customers/{customer}/orders/{order}","bindings":{"uri":"/api/customers/{customer}/orders/{order}","parameters":[{"name":"customer","kind":"model","class":"App\\Models\\Customer","field":null,"parent":null},{"name":"order","kind":"model","class":"App\\Models\\Order","field":"reference","parent":"customer"}],"description":["{customer} → Customer::factory()->create()","{order:reference} → Order::factory()->for($customer)->create()"]},"authenticate":false,"authorize":"true","complete":true,"fields":[{"field":"status","line":20,"type":"string","rules":[{"name":"required","params":[],"source":"required","kind":"known","note":null},{"name":"in","params":["draft","placed","shipped"],"source":"Rule::in(…)","kind":"known","note":"in:draft,placed,shipped"}]},{"field":"quantity","line":21,"type":"integer","rules":[{"name":"required","params":[],"source":"required","kind":"known","note":null},{"name":"integer","params":[],"source":"integer","kind":"known","note":null},{"name":"min","params":["1"],"source":"min:1","kind":"known","note":null},{"name":"max","params":["50"],"source":"max:50","kind":"known","note":null}]}],"baseline":{"status":"draft","quantity":1},"baseline_issues":[],"warnings":[]}

export const inlineScenarios = [{"id":"S1","name":"Accepts a fully valid payload","type":"positive","module":"validation","description":"","inputs":"valid payload","expected":"no validation errors","basis":"confirmed","basis_source":"Valid value derived from every rule in CouponController::redeem() → Validator::make()","confidence":1,"priority":"high","selected":true,"meta":{"field":null,"rule":"all rules","action":"none","value":null,"target_field":null,"expect":"no_errors","todo":null,"also_set":[]}},{"id":"S2","name":"code · required — rejects a missing value","type":"negative","module":"validation","description":"","inputs":"(field missing)","expected":"422 with an error on code","basis":"confirmed","basis_source":"CouponController::redeem() → Validator::make() line 45: 'code' => required","confidence":1,"priority":"high","selected":true,"meta":{"field":"code","rule":"required","action":"remove","value":null,"target_field":"code","expect":"error","todo":null,"also_set":[]}},{"id":"S3","name":"code · string — rejects a non-string value","type":"negative","module":"validation","description":"","inputs":"12345","expected":"422 with an error on code","basis":"confirmed","basis_source":"CouponController::redeem() → Validator::make() line 45: 'code' => string","confidence":1,"priority":"high","selected":true,"meta":{"field":"code","rule":"string","action":"set","value":12345,"target_field":"code","expect":"error","todo":null,"also_set":[]}},{"id":"S4","name":"code · size:6 — rejects 7 characters (size 6)","type":"negative","module":"validation","description":"","inputs":"\"aaaaaaa\"","expected":"422 with an error on code","basis":"confirmed","basis_source":"CouponController::redeem() → Validator::make() line 45: 'code' => size:6","confidence":1,"priority":"high","selected":true,"meta":{"field":"code","rule":"size:6","action":"set","value":"aaaaaaa","target_field":"code","expect":"error","todo":null,"also_set":[]}},{"id":"S5","name":"amount · required — rejects a missing value","type":"negative","module":"validation","description":"","inputs":"(field missing)","expected":"422 with an error on amount","basis":"confirmed","basis_source":"CouponController::redeem() → Validator::make() line 46: 'amount' => required","confidence":1,"priority":"high","selected":true,"meta":{"field":"amount","rule":"required","action":"remove","value":null,"target_field":"amount","expect":"error","todo":null,"also_set":[]}},{"id":"S6","name":"amount · numeric — rejects a non-numeric value","type":"negative","module":"validation","description":"","inputs":"\"abc\"","expected":"422 with an error on amount","basis":"confirmed","basis_source":"CouponController::redeem() → Validator::make() line 46: 'amount' => numeric","confidence":1,"priority":"high","selected":true,"meta":{"field":"amount","rule":"numeric","action":"set","value":"abc","target_field":"amount","expect":"error","todo":null,"also_set":[]}},{"id":"S7","name":"amount · min:1 — rejects 0 (min 1)","type":"negative","module":"validation","description":"","inputs":"0","expected":"422 with an error on amount","basis":"confirmed","basis_source":"CouponController::redeem() → Validator::make() line 46: 'amount' => min:1","confidence":1,"priority":"high","selected":true,"meta":{"field":"amount","rule":"min:1","action":"set","value":0,"target_field":"amount","expect":"error","todo":null,"also_set":[]}},{"id":"S8","name":"amount · min:1 — accepts exactly 1 (min boundary)","type":"edge","module":"validation","description":"","inputs":"1","expected":"no error on amount","basis":"confirmed","basis_source":"CouponController::redeem() → Validator::make() line 46: 'amount' => min:1","confidence":1,"priority":"medium","selected":true,"meta":{"field":"amount","rule":"min:1","action":"set","value":1,"target_field":"amount","expect":"no_error","todo":null,"also_set":[]}}]

export const inlineContext = {"request_class":null,"request_file":"app/Http/Controllers/CouponController.php","rules_line":44,"origin":"inline","rules_origin":"CouponController::redeem() → Validator::make()","error_mode":"status","error_status":400,"method":"POST","uri":"/api/coupons/redeem","bindings":null,"authenticate":false,"authorize":"true","complete":true,"fields":[{"field":"code","line":45,"type":"string","rules":[{"name":"required","params":[],"source":"required","kind":"known","note":null},{"name":"string","params":[],"source":"string","kind":"known","note":null},{"name":"size","params":["6"],"source":"size:6","kind":"known","note":null}]},{"field":"amount","line":46,"type":"numeric","rules":[{"name":"required","params":[],"source":"required","kind":"known","note":null},{"name":"numeric","params":[],"source":"numeric","kind":"known","note":null},{"name":"min","params":["1"],"source":"min:1","kind":"known","note":null}]}],"baseline":{"code":"TestPi","amount":1},"baseline_issues":[],"warnings":["Validation failures return your own response (HTTP 400); tests check the status code, not the error body."]}
