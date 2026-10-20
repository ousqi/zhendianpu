<?php

namespace App\Console\Commands;

use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Services\Bookkeeping\BookkeepingAccountService;
use App\Services\Bookkeeping\LegacyCashSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('app:import-legacy-cash-entries {--dry-run : Only report the reconciliation status} {--confirm : Mark the completed reconciliation as safe to retire} {--backup-reference= : Verified backup location or immutable reference required with --confirm} {--actor-id= : User ID recorded as the importer}')]
#[Description('Reconcile every legacy cash transaction before retiring the legacy business tables')]
class ImportLegacyCashEntries extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! Schema::hasTable('zdp_cash_transactions')) {
            $this->error('Legacy cash transactions are unavailable. Run this before the retirement migration.');

            return self::FAILURE;
        }
        $actorId = $this->option('actor-id');
        if (! is_numeric($actorId) || ! DB::table('users')->where('id', (int) $actorId)->exists()) {
            $this->error('Provide an existing --actor-id so imported records have an auditable actor.');

            return self::FAILURE;
        }
        $this->ensureReconciliationTables();
        $total = DB::table('zdp_cash_transactions')->count();
        $reconciled = DB::table('bookkeeping_legacy_cash_reconciliations')->where('source_table', 'zdp_cash_transactions')->count();
        $this->info("Legacy cash rows: {$total}; reconciled: {$reconciled}.");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::table('zdp_cash_transactions')->orderBy('id')->each(function (object $row) use ($actorId): void {
            $source = LegacyCashSnapshot::capture($row);
            $reconciliation = DB::table('bookkeeping_legacy_cash_reconciliations')
                ->where('source_table', 'zdp_cash_transactions')
                ->where('source_id', $row->id)
                ->first();
            if ($reconciliation) {
                $this->verifyExistingReconciliation($reconciliation, $source);

                return;
            }

            DB::transaction(function () use ($row, $actorId, $source): void {
                if ($row->voided_at !== null) {
                    $this->exclude($row, (int) $actorId, 'legacy_row_voided', $source);

                    return;
                }
                if (! in_array($row->direction, ['in', 'out'], true) || bccomp((string) $row->amount, '0', 2) !== 1) {
                    $this->exclude($row, (int) $actorId, 'invalid_direction_or_amount', $source);

                    return;
                }

                $direction = $row->direction === 'in' ? 'income' : 'expense';
                $category = Category::firstOrCreate(['name' => $direction === 'income' ? '其他收入' : '其他支出', 'direction' => $direction]);
                $account = app(BookkeepingAccountService::class)->defaultAccount();
                $entry = Entry::create([
                    'direction' => $direction,
                    'amount' => $row->amount,
                    'category_id' => $category->id,
                    'account_id' => $account->id,
                    'occurred_on' => $row->business_on,
                    'description' => $row->notes,
                    'source' => 'legacy_import',
                    'created_by' => (int) $actorId,
                    'updated_by' => (int) $actorId,
                ]);
                DB::table('bookkeeping_legacy_imports')->insert([
                    'source_table' => 'zdp_cash_transactions',
                    'source_id' => $row->id,
                    'entry_id' => $entry->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('bookkeeping_legacy_cash_reconciliations')->insert([
                    'source_table' => 'zdp_cash_transactions',
                    'source_id' => $row->id,
                    'source_type' => $row->source_type,
                    'source_business_id' => $row->source_business_id,
                    'status' => 'imported',
                    'entry_id' => $entry->id,
                    'source_snapshot' => $source['snapshot'],
                    'source_hash' => $source['hash'],
                    'reconciled_by' => (int) $actorId,
                    'reconciled_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });

        $reconciled = DB::table('bookkeeping_legacy_cash_reconciliations')->where('source_table', 'zdp_cash_transactions')->count();
        if ($this->option('confirm')) {
            $backupReference = $this->option('backup-reference');
            if (! is_string($backupReference) || trim($backupReference) === '') {
                $this->error('Provide --backup-reference with --confirm.');

                return self::FAILURE;
            }
            if ($reconciled !== $total) {
                $this->error("Reconciliation is incomplete: {$reconciled} of {$total} rows.");

                return self::FAILURE;
            }
            DB::table('bookkeeping_legacy_retirements')->updateOrInsert(
                ['source_table' => 'zdp_cash_transactions'],
                ['row_count' => $total, 'backup_reference' => trim($backupReference), 'confirmed_by' => (int) $actorId, 'confirmed_at' => now(), 'updated_at' => now(), 'created_at' => now()],
            );
            $this->info('Reconciliation and backup reference have been confirmed. The retirement migration may now drop legacy tables.');
        } else {
            $this->info("Reconciled {$reconciled} of {$total} rows. Review mappings and rerun with --confirm plus --backup-reference only after verification.");
        }

        return self::SUCCESS;
    }

    /** @param array{snapshot: string, hash: string} $source */
    private function exclude(object $row, int $actorId, string $reason, array $source): void
    {
        DB::table('bookkeeping_legacy_cash_reconciliations')->insert([
            'source_table' => 'zdp_cash_transactions',
            'source_id' => $row->id,
            'source_type' => $row->source_type,
            'source_business_id' => $row->source_business_id,
            'status' => 'excluded',
            'exclusion_reason' => $reason,
            'source_snapshot' => $source['snapshot'],
            'source_hash' => $source['hash'],
            'reconciled_by' => $actorId,
            'reconciled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureReconciliationTables(): void
    {
        if (! Schema::hasTable('bookkeeping_legacy_cash_reconciliations') || ! Schema::hasTable('bookkeeping_legacy_retirements')) {
            throw new \RuntimeException('Run the bookkeeping schema migration before reconciling legacy cash transactions.');
        }
    }

    /** @param array{snapshot: string, hash: string} $source */
    private function verifyExistingReconciliation(object $reconciliation, array $source): void
    {
        if (LegacyCashSnapshot::hash($reconciliation->source_snapshot) !== $source['hash']) {
            throw new \RuntimeException('Existing reconciliation no longer matches its legacy cash row.');
        }
        if (filled($reconciliation->source_hash) && $reconciliation->source_hash !== $source['hash']) {
            throw new \RuntimeException('Existing reconciliation has an invalid immutable source hash.');
        }
        if (blank($reconciliation->source_hash)) {
            DB::table('bookkeeping_legacy_cash_reconciliations')->where('id', $reconciliation->id)->update([
                'source_hash' => $source['hash'],
                'updated_at' => now(),
            ]);
        }
    }
}
