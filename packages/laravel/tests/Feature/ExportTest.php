<?php

declare(strict_types=1);

use TestPilot\Laravel\Services\ScanService;

it('downloads planned test cases as xlsx and csv', function () {
    validationSandbox();
    app(ScanService::class)->scan();
    $target = \TestPilot\Laravel\Models\Target::query()->where('key_hash', hash('sha256', 'Shop\Http\Controllers\OrderController::store'))->firstOrFail();
    $this->postJson("/testpilot/api/targets/{$target->id}/plan", ['module' => 'validation'])->assertStatus(202);

    $xlsx = $this->get('/testpilot/api/export?format=xlsx')->assertOk();
    expect($xlsx->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and($xlsx->headers->get('Content-Disposition'))->toContain('.xlsx')
        ->and(substr((string) $xlsx->getContent(), 0, 2))->toBe('PK');

    $csv = (string) $this->get('/testpilot/api/export?format=csv&module=validation&selected=1')->assertOk()->getContent();
    expect($csv)->toContain('Test case', 'OrderController::store', 'Rules')
        ->and($csv)->not->toContain(',no,'); // only included cases
});

it('rejects unknown formats and modules', function () {
    $this->getJson('/testpilot/api/export?format=pdf')->assertStatus(422);
    $this->getJson('/testpilot/api/export?module=nope')->assertStatus(422);
});

it('writes the export from the command line', function () {
    validationSandbox();
    app(ScanService::class)->scan();

    $this->artisan('testpilot:export', ['--format' => 'csv', '--output' => 'storage/testpilot/exports/cases.csv'])->assertSuccessful();

    expect(file_get_contents(config('testpilot.root') . '/storage/testpilot/exports/cases.csv'))->toStartWith("\xEF\xBB\xBF");
})->skip(fn () => base_path() !== config('testpilot.root'), 'The command writes under base_path(); Testbench uses its own skeleton.');
