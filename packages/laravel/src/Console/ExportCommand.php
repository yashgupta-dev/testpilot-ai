<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use TestPilot\Exceptions\TestPilotException;
use TestPilot\Laravel\Services\TestCaseExportService;

final class ExportCommand extends Command
{
    protected $signature = 'testpilot:export
        {--format=xlsx : xlsx (Excel / Google Sheets, with Summary and AI rule gaps sheets) or csv (test cases only)}
        {--module= : Only one module: unit, validation, ratelimit or feature}
        {--target=* : Only these targets (key, e.g. "App\Http\Controllers\AdminController::register", or id)}
        {--selected : Only cases included in the plan}
        {--output= : File to write, relative to base_path (default storage/testpilot/exports/…)}';

    protected $description = 'Export planned test cases, their evidence and last results to a spreadsheet';

    public function handle(TestCaseExportService $exports): int
    {
        $targets = null;
        if ($this->option('target') !== []) {
            $targets = \TestPilot\Laravel\Models\Target::query()
                ->where(function ($q): void {
                    foreach ((array) $this->option('target') as $t) {
                        $q->orWhere('id', $t)->orWhere('key_hash', hash('sha256', ltrim((string) $t, '\\')));
                    }
                })
                ->pluck('id')->all();
            if ($targets === []) {
                $this->components->error('No matching targets. Run php artisan testpilot:scan first.');

                return self::FAILURE;
            }
        }

        try {
            $file = $exports->export((string) $this->option('format'), $this->option('module') ?: null, $targets, (bool) $this->option('selected'));
        } catch (TestPilotException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $relative = (string) ($this->option('output') ?: 'storage/testpilot/exports/' . $file['filename']);
        $path = base_path($relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        if (! str_starts_with((string) realpath(dirname($path)), (string) realpath(base_path()))) {
            $this->components->error('The output file must be inside the project.');

            return self::FAILURE;
        }

        file_put_contents($path, $file['body']);
        $this->components->info("{$file['rows']} test case(s) written to {$relative}");

        return self::SUCCESS;
    }
}
