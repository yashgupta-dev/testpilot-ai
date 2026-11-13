<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Services;

use SimpleXMLElement;

final class TestDatabaseReadiness
{
    /** @return array{config_file: ?string, driver: ?string, database: ?string, isolation: string, schema_status: string, detail: string, action: string} */
    public function inspect(string $root): array
    {
        $configFile = null;
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            if (is_file($root . DIRECTORY_SEPARATOR . $candidate)) {
                $configFile = $candidate;
                break;
            }
        }

        $base = [
            'config_file' => $configFile,
            'driver' => null,
            'database' => null,
            'isolation' => 'unknown',
            'schema_status' => 'not_verified',
            'detail' => 'TestPilot does not connect to or migrate the test database during this check.',
            'action' => 'Configure DB_CONNECTION and DB_DATABASE in phpunit.xml or .env.testing, and use a database dedicated to tests. Run your migrations through your test setup.',
        ];

        if ($configFile === null) {
            return [
                ...$base,
                'detail' => 'No phpunit.xml or phpunit.xml.dist was found at the project root.',
                'action' => 'Add a PHPUnit configuration with a testing database, or configure the test environment in .env.testing. Use a database dedicated to tests.',
            ];
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($root . DIRECTORY_SEPARATOR . $configFile, SimpleXMLElement::class, LIBXML_NONET);
            $parseErrors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
        if (! $xml instanceof SimpleXMLElement) {
            $line = $parseErrors[0]->line ?? null;

            return [
                ...$base,
                'detail' => "Could not parse {$configFile}" . ($line === null ? '.' : " at line {$line}."),
                'action' => "Fix the XML in {$configFile}, then check its DB_CONNECTION and DB_DATABASE test settings.",
            ];
        }

        $values = [];
        foreach (['env', 'server'] as $element) {
            foreach ($xml->xpath("//php/{$element}") ?: [] as $variable) {
                $name = strtoupper((string) ($variable['name'] ?? ''));
                if (in_array($name, ['DB_CONNECTION', 'DB_DATABASE'], true)) {
                    $value = (string) ($variable['value'] ?? '');
                    if ($value !== '') {
                        $values[$name] = $value;
                    }
                }
            }
        }

        $driver = $values['DB_CONNECTION'] ?? null;
        $databaseValue = $values['DB_DATABASE'] ?? null;
        $database = null;
        $isolation = 'unknown';

        if ($driver === 'sqlite' && $databaseValue !== null) {
            if ($databaseValue === ':memory:') {
                $database = 'SQLite in-memory';
                $isolation = 'isolated';
            } else {
                $fileName = basename(str_replace('\\', '/', $databaseValue));
                $database = preg_match('/^[A-Za-z0-9._-]+$/', $fileName) === 1 ? 'SQLite file: ' . $fileName : 'SQLite file (name omitted)';
                $isolation = preg_match('/(^|[_-])(test|testing)([_\-.]|$)/i', $fileName) === 1 ? 'isolated' : 'review';
            }
        } elseif ($databaseValue !== null) {
            $database = preg_match('/^[A-Za-z0-9._-]+$/', $databaseValue) === 1 ? $databaseValue : 'Configured (value omitted)';
            $isolation = preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $databaseValue) === 1 ? 'isolated' : 'review';
        }

        if ($driver === null || $databaseValue === null) {
            return [
                ...$base,
                'driver' => $driver,
                'database' => $database,
                'isolation' => $isolation,
                'detail' => "{$configFile} does not explicitly define both DB_CONNECTION and DB_DATABASE.",
            ];
        }

        return [
            ...$base,
            'driver' => $driver,
            'database' => $database,
            'isolation' => $isolation,
            'detail' => "Test database settings are declared in {$configFile}; isolation is a name-based hint and the schema has not been verified.",
            'action' => $isolation === 'isolated'
                ? 'Confirm this is a dedicated test database. Keep migrations in your test setup (for example, RefreshDatabase). Schema errors will be reported when tests run; TestPilot never migrates automatically.'
                : 'Confirm this is a dedicated test database before running tests. Keep migrations in your test setup; TestPilot never connects to or migrates it from Settings.',
        ];
    }
}
