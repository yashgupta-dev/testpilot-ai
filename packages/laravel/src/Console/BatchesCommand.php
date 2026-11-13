<?php

declare(strict_types=1);

namespace TestPilot\Laravel\Console;

use Illuminate\Console\Command;
use Throwable;
use TestPilot\Laravel\Models\AiBatch;
use TestPilot\Laravel\Services\BatchPlanService;

final class BatchesCommand extends Command
{
    protected $signature = 'testpilot:batches {--poll : Check every unfinished batch once (schedule this when no queue worker runs)}';

    protected $description = 'List AI planning batches, or poll unfinished ones';

    public function handle(BatchPlanService $service): int
    {
        if ($this->option('poll')) {
            foreach (AiBatch::query()->where('status', AiBatch::IN_PROGRESS)->get() as $batch) {
                try {
                    $done = $service->poll($batch);
                    $this->line(($done ? '<fg=green>ended</>       ' : '<fg=yellow>in progress</> ') . $batch->id);
                } catch (Throwable $e) {
                    $this->line("<fg=red>error</>       {$batch->id}: {$e->getMessage()}");
                }
            }
        }

        $this->table(['Batch', 'Module', 'Status', 'Requests', 'Created', 'Ended'], AiBatch::query()->latest()->limit(20)->get()->map(fn (AiBatch $b): array => [
            $b->id, $b->module, $b->status, $b->request_count, $b->created_at?->diffForHumans(), $b->ended_at?->diffForHumans() ?? '—',
        ])->all());

        return 0;
    }
}
