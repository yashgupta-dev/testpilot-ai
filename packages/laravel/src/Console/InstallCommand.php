<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'testpilot:install';

    protected $description = 'Publish the TestPilot config and migrations, and print the next steps';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'testpilot-config']);
        $this->call('vendor:publish', ['--tag' => 'testpilot-migrations']);

        $this->components->info('TestPilot installed. Next steps:');
        $this->line(<<<'TEXT'
          1. php artisan migrate
          2. Add to .env:
               TESTPILOT_AI_DRIVER=anthropic      # or openai, gemini, ollama
               TESTPILOT_AI_API_KEY=...
               TESTPILOT_AI_MODEL=...             # optional for anthropic
          3. Add a test suite to phpunit.xml so approved tests run with your suite:
               <testsuite name="TestPilot">
                   <directory>tests/TestPilot/Generated</directory>
               </testsuite>
          4. Add storage/testpilot/ to .gitignore (pending, unapproved tests live there).
          5. Annotate code with #[TestPilot] / #[UnitTest] / #[Fuzz], then:
               php artisan testpilot:scan
               open /testpilot (local environment) or run php artisan testpilot:generate "Class::method"
          6. Run a queue worker for dashboard actions: php artisan queue:work --queue=testpilot
        TEXT);

        return self::SUCCESS;
    }
}
