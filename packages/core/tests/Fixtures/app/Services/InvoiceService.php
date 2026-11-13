<?php

declare(strict_types=1);

namespace App\Services;

use App\Concerns\FormatsMoney;
use App\Contracts\TaxRepository;
use InvalidArgumentException;
use TestPilot\Attributes\Fuzz;
use TestPilot\Attributes\SkipTest;
use TestPilot\Attributes\TestPilot;
use TestPilot\Attributes\UnitTest;

// If the scanner ever executed this file, this constant would be defined.
define('TESTPILOT_FIXTURE_EXECUTED', true);

#[TestPilot(area: 'Billing', criticality: 'high')]
#[UnitTest(maxScenarios: 6)]
final class InvoiceService extends BaseService
{
    use FormatsMoney;

    private string $currency = 'INR';

    public function __construct(private readonly TaxRepository $taxes)
    {
    }

    /**
     * Total of all line items after discount and tax.
     */
    #[Fuzz(cases: 500, seed: 48213)]
    public function calculateTotal(array $items, float $discount = 0.0, string $region = 'IN'): float
    {
        if ($discount < 0 || $discount > 100) {
            throw new InvalidArgumentException('Invalid discount');
        }

        $subtotal = 0.0;
        foreach ($items as $item) {
            if (($item['quantity'] ?? 0) < 0) {
                throw new InvalidArgumentException('Negative quantity');
            }
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 0);
        }

        $discounted = $subtotal * (1 - $discount / 100);

        return round($discounted * (1 + $this->taxes->rateFor($region)), 2);
    }

    #[SkipTest(reason: 'Deprecated, removed in v3')]
    public function legacyTotal(array $items): float
    {
        return 0.0;
    }

    #[UnitTest(enabled: false)]
    public function disabledUnit(): void
    {
    }

    #[UnitTest]
    private function helper(): int
    {
        return 1;
    }

    #[UnitTest(maxScenarios: 'ten', foo: 1)]
    public function badArguments(): void
    {
    }

    public function __toString(): string
    {
        return 'invoice';
    }

    public function makeAnonymous(): object
    {
        return new class {
            #[UnitTest]
            public function hidden(): void
            {
            }
        };
    }
}
