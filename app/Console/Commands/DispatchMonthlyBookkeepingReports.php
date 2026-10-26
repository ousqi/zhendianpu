<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMonthlyBookkeepingReport;
use App\Models\Bookkeeping\Ledger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookkeeping:generate-monthly-reports {--period= : The report period in YYYY-MM format}')]
#[Description('Dispatch a monthly bookkeeping report job for every active ledger')]
class DispatchMonthlyBookkeepingReports extends Command
{
    public function handle(): int
    {
        $period = $this->option('period');
        if (! is_null($period) && (! is_string($period) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period))) {
            $this->error('The --period option must use YYYY-MM format.');

            return self::FAILURE;
        }
        $count = 0;
        Ledger::query()->where('status', 'active')->orderBy('id')->each(function (Ledger $ledger) use ($period, &$count): void {
            GenerateMonthlyBookkeepingReport::dispatch($ledger, $period);
            $count++;
        });
        $label = $period ?? "each ledger's preceding calendar month";
        $this->info("Dispatched {$count} monthly bookkeeping report job(s) for {$label}.");

        return self::SUCCESS;
    }
}
