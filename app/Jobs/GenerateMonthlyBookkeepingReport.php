<?php

namespace App\Jobs;

use App\Models\Bookkeeping\Ledger;
use App\Services\Bookkeeping\MonthlyBookkeepingReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMonthlyBookkeepingReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public Ledger $ledger, public ?string $period = null) {}

    public function handle(MonthlyBookkeepingReportService $reports): void
    {
        $reports->generate($this->ledger, $this->period);
    }
}
