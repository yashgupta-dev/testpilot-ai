<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use TestPilot\Laravel\Services\AuditLogger;
use TestPilot\Laravel\TestPilotDashboard;

final class DashboardController extends Controller
{
    /** Built assets shipped with the package. Only these files can be served. */
    private const ASSETS = [
        'app.js' => 'application/javascript; charset=utf-8',
        'app.css' => 'text/css; charset=utf-8',
    ];

    public function index(Request $request): Response
    {
        $base = '/' . trim((string) config('testpilot.dashboard.path', 'testpilot'), '/');

        return response(View::make('testpilot::dashboard', [
            'config' => [
                'basePath' => $base,
                'apiPath' => $base . '/api',
                'assetVersion' => $this->assetVersion(),
                'user' => AuditLogger::currentActor(),
                'canApprove' => TestPilotDashboard::canApprove($request),
                'project' => (string) config('app.name', 'Laravel'),
                'environment' => app()->environment(),
            ],
        ]));
    }

    public function asset(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::ASSETS[$file]), 404);

        $path = dirname(__DIR__, 3) . '/dist/' . $file;
        abort_unless(is_file($path), 404, 'Dashboard assets are missing. Rebuild them with: npm run build (in the ui/ folder).');

        return response()->file($path, [
            'Content-Type' => self::ASSETS[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assetVersion(): string
    {
        $file = dirname(__DIR__, 3) . '/dist/app.js';

        return is_file($file) ? substr(md5((string) filemtime($file)), 0, 10) : 'dev';
    }
}
