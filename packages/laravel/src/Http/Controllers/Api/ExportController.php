<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers\Api;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Services\AuditLogger;
use TestPilot\Laravel\Services\PlanService;
use TestPilot\Laravel\Services\TestCaseExportService;

final class ExportController extends ApiController
{
    public function __construct(
        private readonly TestCaseExportService $exports,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET api/export?format=xlsx|csv&module=validation&targets[]=…&selected=1 — downloads the test cases as a sheet. */
    public function download(Request $request): Response
    {
        $validated = $request->validate([
            'format' => ['nullable', 'in:' . implode(',', TestCaseExportService::FORMATS)],
            'module' => ['nullable', 'in:' . implode(',', PlanService::MODULES)],
            'targets' => ['nullable', 'array', 'max:500'],
            'targets.*' => ['string', 'max:26'],
            'selected' => ['nullable', 'boolean'],
        ]);

        try {
            $file = $this->exports->export(
                (string) ($validated['format'] ?? 'xlsx'),
                $validated['module'] ?? null,
                isset($validated['targets']) ? array_values($validated['targets']) : null,
                (bool) ($validated['selected'] ?? false),
            );
        } catch (TestPilotException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log('export.downloaded', null, ['format' => $validated['format'] ?? 'xlsx', 'module' => $validated['module'] ?? 'all', 'rows' => $file['rows']]);

        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
