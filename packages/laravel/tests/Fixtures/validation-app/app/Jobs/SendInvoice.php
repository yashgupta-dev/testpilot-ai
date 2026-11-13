<?php

declare(strict_types=1);

namespace Shop\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Shop\Models\Order;

final class SendInvoice implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly Order $order)
    {
    }

    public function handle(): void
    {
        // Would call an external invoicing API. Faked in tests.
    }
}
